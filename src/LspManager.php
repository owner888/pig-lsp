<?php

declare(strict_types=1);

namespace PigLsp;

use Pig\Async\Async;
use Pig\Async\Deferred;
use Pig\Async\Future;
use Pig\Async\Loop;
use Throwable;

/**
 * The running servers: one per server and workspace root, started the first time a file of
 * theirs is asked about, restarted when one dies.
 *
 * **A query waits for a starting server, up to a point.** Upstream answered the first call with
 * "starting, retry shortly" and the model, told nothing else, often gave up on LSP for the rest
 * of the task. Here the call waits up to `startWait` (15s by default), which covers phpactor,
 * intelephense, gopls and pyright; only a slow starter (jdtls, rust-analyzer on a big
 * workspace) gets the retry message, with how long it has been starting.
 *
 * **A crash is restarted on the next query**, at most three times in a row with growing pauses
 * (1s, 2s, 4s…) — upstream's numbers. A server that keeps dying is left stopped and `/lsp` says
 * why; `/lsp-restart` clears the count.
 */
final class LspManager
{
    private const int MAX_RESTARTS = 3;

    /** @var array<string, LspClient> by "key@root" */
    private array $clients = [];

    /** @var array<string, Future> by "key@root": starts in flight */
    private array $starting = [];

    /** @var array<string, array{count: int, retryAt: float, error: string}> by "key@root" */
    private array $failures = [];

    public function __construct(private Config $config)
    {
    }

    public function config(): Config
    {
        return $this->config;
    }

    /** Re-read the configuration (after `/lsp-restart`): servers already running keep theirs. */
    public function reconfigure(Config $config): void
    {
        $this->config = $config;
    }

    /**
     * The client for $path, started if need be.
     *
     * @return array{client: LspClient, languageId: string, key: string}
     * @throws LspUnavailable with a message meant for the model
     */
    public function clientForFile(string $path): array
    {
        $languageId = $this->config->languageFor($path);

        if ($languageId === null) {
            throw new LspUnavailable('No language server is known for ' . basename($path) . ' (by its extension). Configure one in `.pig/lsp.json` (`servers.<lang>.command`, `extensions`).');
        }

        $key = $this->config->serverKeyForLanguage($languageId);

        if ($key === null) {
            throw new LspUnavailable("No language server is configured for {$languageId} files. Configure one in `.pig/lsp.json` (`servers.{$languageId}.command`).");
        }

        return ['client' => $this->ensure($key, $this->config->rootFor($key, $path)), 'languageId' => $languageId, 'key' => $key];
    }

    /**
     * The running client for $path, without starting anything — for the hooks, which must never
     * make an edit wait for a server to boot.
     *
     * @return array{client: LspClient, languageId: string, key: string}|null
     */
    public function runningClientForFile(string $path): ?array
    {
        $languageId = $this->config->languageFor($path);
        $key = $languageId !== null ? $this->config->serverKeyForLanguage($languageId) : null;

        if ($languageId === null || $key === null) {
            return null;
        }

        $client = $this->clients[$this->id($key, $this->config->rootFor($key, $path))] ?? null;

        return $client !== null && $client->running() ? ['client' => $client, 'languageId' => $languageId, 'key' => $key] : null;
    }

    /** The client for server $key at the project root, started if need be. */
    public function clientForKey(string $key): LspClient
    {
        if ($this->config->command($key) === null && $this->config->server($key) === []) {
            throw new LspUnavailable("Unknown language server '{$key}'. Known: " . implode(', ', $this->config->serverKeys()) . '.');
        }

        // One already running for this server serves, whatever its root.
        foreach ($this->clients as $id => $client) {
            if (str_starts_with($id, $key . '@') && $client->running()) {
                return $client;
            }
        }

        return $this->ensure($key, rtrim($this->config->cwd, '/'));
    }

    /**
     * The server keys a workspace-wide question (`lsp_symbols` with a query) should go to: the
     * running ones, otherwise the ones whose root markers the project has.
     *
     * @return list<string>
     */
    public function projectServerKeys(): array
    {
        $running = [];

        foreach ($this->clients as $id => $client) {
            if ($client->running()) {
                $running[] = substr($id, 0, (int) strpos($id, '@'));
            }
        }

        if ($running !== []) {
            return array_values(array_unique($running));
        }

        $keys = [];

        foreach ($this->config->serverKeys() as $key) {
            foreach (is_array($this->config->server($key)['rootMarkers'] ?? null) ? $this->config->server($key)['rootMarkers'] : [] as $marker) {
                if (is_string($marker) && file_exists(rtrim($this->config->cwd, '/') . '/' . $marker)) {
                    $keys[] = $key;

                    break;
                }
            }
        }

        return $keys;
    }

    /** Start $key's server at $root in the background, if it is installed and not running. */
    public function prestart(string $key): void
    {
        $key = $this->config->serverKeyForLanguage($key) ?? $key;

        if ($this->config->command($key) === null) {
            return;
        }

        $id = $this->id($key, rtrim($this->config->cwd, '/'));

        if (!isset($this->clients[$id]) && !isset($this->starting[$id])) {
            $this->begin($key, rtrim($this->config->cwd, '/'));
        }
    }

    /**
     * @return list<array{key: string, root: string, state: string, server: string, pid: ?int, uptime: float, documents: int, diagnostics: int, progress: list<string>, error: ?string, stderr: string}>
     */
    public function status(): array
    {
        $rows = [];

        foreach ($this->clients as $id => $client) {
            [$key, $root] = explode('@', $id, 2);
            $rows[] = [
                'key' => $key,
                'root' => $root,
                'state' => isset($this->starting[$id]) ? LspClient::STARTING : $client->state(),
                'server' => $client->running() ? $client->serverName() : implode(' ', $client->command),
                'pid' => $client->running() ? $client->pid() : null,
                'uptime' => $client->uptime(),
                'documents' => $client->openDocumentCount(),
                'diagnostics' => array_sum(array_map('count', $client->allDiagnostics())),
                'progress' => $client->progress(),
                'error' => ($this->failures[$id]['error'] ?? '') !== '' ? $this->failures[$id]['error'] : null,
                'stderr' => $client->stderr(),
            ];
        }

        foreach ($this->failures as $id => $failure) {
            if (!isset($this->clients[$id])) {
                [$key, $root] = explode('@', $id, 2);
                $rows[] = [
                    'key' => $key, 'root' => $root, 'state' => 'failed', 'server' => '', 'pid' => null, 'uptime' => 0.0,
                    'documents' => 0, 'diagnostics' => 0, 'progress' => [], 'error' => $failure['error'], 'stderr' => '',
                ];
            }
        }

        return $rows;
    }

    /** @return list<LspClient> the servers that are up */
    public function runningClients(): array
    {
        return array_values(array_filter($this->clients, static fn (LspClient $client): bool => $client->running()));
    }

    /**
     * Stop the servers for $key (a server key or language id), or all of them, and forget their
     * failures so the next query starts them afresh.
     *
     * @return int how many were stopped
     */
    public function restart(?string $key = null): int
    {
        if ($key !== null && $key !== '') {
            $key = $this->config->serverKeyForLanguage($key) ?? $key;
        }

        $stopped = 0;

        foreach ($this->clients as $id => $client) {
            if ($key === null || $key === '' || str_starts_with($id, $key . '@')) {
                unset($this->clients[$id], $this->starting[$id]);
                $client->shutdown();
                $stopped++;
            }
        }

        foreach (array_keys($this->failures) as $id) {
            if ($key === null || $key === '' || str_starts_with($id, $key . '@')) {
                unset($this->failures[$id]);
            }
        }

        return $stopped;
    }

    public function shutdownAll(): void
    {
        foreach ($this->clients as $client) {
            try {
                $client->shutdown();
            } catch (Throwable) {
            }
        }

        $this->clients = [];
        $this->starting = [];
    }

    // ---- starting -------------------------------------------------------------------------------

    private function ensure(string $key, string $root): LspClient
    {
        $id = $this->id($key, $root);
        $client = $this->clients[$id] ?? null;

        if ($client !== null && $client->running() && !isset($this->starting[$id])) {
            return $client;
        }

        if (!isset($this->starting[$id])) {
            $failure = $this->failures[$id] ?? null;

            if ($failure !== null && $failure['error'] !== '' && $failure['count'] >= self::MAX_RESTARTS) {
                throw new LspUnavailable("The {$key} language server failed {$failure['count']} times and was left stopped: {$failure['error']}\nFix the cause, then run /lsp-restart {$key}.");
            }

            if ($failure !== null && $failure['error'] !== '' && microtime(true) < $failure['retryAt']) {
                throw new LspUnavailable(sprintf("The {$key} language server stopped (%s); it will be restarted in %.0fs.", $failure['error'], ceil($failure['retryAt'] - microtime(true))));
            }

            if ($this->config->command($key) === null) {
                throw new LspUnavailable($this->config->installHint($key));
            }

            $this->begin($key, $root);
        }

        $future = $this->starting[$id];

        if (!self::awaitWithin($future, $this->config->startWait())) {
            $client = $this->clients[$id];

            throw new LspUnavailable(sprintf(
                'The %s language server is still starting (%.0fs so far%s). Try again in a little while.',
                $key,
                $client->uptime(),
                $client->progress() !== [] ? '; ' . implode(', ', $client->progress()) : '',
            ));
        }

        $client = $this->clients[$id] ?? null;

        if ($client === null || !$client->running()) {
            $error = $this->failures[$id]['error'] ?? 'it exited';

            throw new LspUnavailable("The {$key} language server could not start: {$error}");
        }

        return $client;
    }

    private function begin(string $key, string $root): void
    {
        $id = $this->id($key, $root);
        $command = (array) $this->config->command($key);
        $client = new LspClient(
            name: $key,
            command: $command,
            root: $root,
            env: $this->config->env($key),
            initializationOptions: $this->config->initializationOptions($key),
            settings: $this->config->settings($key),
            requestTimeout: $this->config->requestTimeout(),
        );
        $this->clients[$id] = $client;

        $client->onExitListener(function (?int $code) use ($id, $client): void {
            // A server stopped on purpose is removed from $clients first; one still there died.
            if (($this->clients[$id] ?? null) !== $client) {
                return;
            }

            if ($client->uptime() > 60.0) {
                unset($this->failures[$id]);
            }

            $detail = trim(substr($client->stderr(), -300));
            $this->fail($id, 'exited' . ($code !== null ? " with code {$code}" : '') . ($detail !== '' ? ": {$detail}" : ''));
        });

        $startTimeout = $this->config->startTimeout();
        $future = Async::spawn(static function () use ($client, $startTimeout): void {
            $client->start($startTimeout);
        });
        $this->starting[$id] = $future;

        $future->onComplete(function (?Throwable $error) use ($id, $client): void {
            if (($this->starting[$id] ?? null) !== null) {
                unset($this->starting[$id]);
            }

            if ($error !== null) {
                if (($this->clients[$id] ?? null) === $client) {
                    $this->fail($id, $error->getMessage());
                }

                return;
            }

            // Started: the error is history, but the count stays until the server has stayed up a
            // while (fail()), so one that dies right after every start still stops being restarted.
            if (isset($this->failures[$id])) {
                $this->failures[$id]['error'] = '';
            }
        });
    }

    private function fail(string $id, string $error): void
    {
        $count = ($this->failures[$id]['count'] ?? 0) + 1;
        $this->failures[$id] = [
            'count' => $count,
            'retryAt' => microtime(true) + min(30.0, 2 ** ($count - 1)),
            'error' => $error,
        ];
    }

    private function id(string $key, string $root): string
    {
        return $key . '@' . $root;
    }

    /** Wait for $future at most $seconds; true if it completed (with a value or an error). */
    private static function awaitWithin(Future $future, float $seconds): bool
    {
        if ($future->isComplete()) {
            return true;
        }

        if ($seconds <= 0) {
            return false;
        }

        $deferred = new Deferred();
        $timer = Loop::get()->delay($seconds, static function () use ($deferred): void {
            if (!$deferred->isComplete()) {
                $deferred->complete(false);
            }
        });
        $future->onComplete(static function () use ($deferred): void {
            if (!$deferred->isComplete()) {
                $deferred->complete(true);
            }
        });

        try {
            return (bool) $deferred->future->await();
        } finally {
            Loop::get()->cancel($timer);
        }
    }
}
