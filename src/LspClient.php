<?php

declare(strict_types=1);

namespace PigLsp;

use Closure;
use Fiber;
use Pig\Async\AbortError;
use Pig\Async\AbortSignal;
use Pig\Async\Deferred;
use Pig\Async\Loop;
use Throwable;

/**
 * One language server, spoken to over JSON-RPC: requests with timeouts, notifications, the
 * requests the server sends back, the documents it has open and the diagnostics it published.
 *
 * Where it differs from upstream, and why:
 *
 * - **Every request has a timeout** and honours the tool call's abort signal. On either, the
 *   server is sent `$/cancelRequest`, so it stops working on an answer nobody wants.
 * - **The server's own requests are all answered.** gopls and rust-analyzer ask to create
 *   progress tokens and to register capabilities; a request left unanswered can stall a server
 *   that waits for it. Unknown ones get MethodNotFound, as the spec asks.
 * - **`implementation`, `typeDefinition` and the position encodings are declared.** Upstream did
 *   not declare `implementation`, and some servers only offer what a client says it can take.
 * - **A document is (re)synced before every query** from what is on disk, not only when pig's
 *   read/write/edit tools touched it: a file changed by `sed` in bash, or never read at all, is
 *   otherwise a stale or unknown document to the server.
 * - **Diagnostics are awaited, not slept for.** Each publish bumps a per-file generation; a
 *   caller that just changed a file waits for the next one, up to a limit, instead of upstream's
 *   fixed 1.5 seconds that was both too long for a fast server and too short for a slow one.
 */
final class LspClient
{
    public const string STARTING = 'starting';

    public const string RUNNING = 'running';

    public const string STOPPED = 'stopped';

    /** Documents kept open at once; the least recently used is closed past this. */
    private const int MAX_OPEN_DOCUMENTS = 100;

    private ?ServerProcess $process = null;

    private string $state = self::STOPPED;

    private int $nextId = 1;

    /** @var array<int, array{deferred: Deferred, timer: ?string, method: string}> */
    private array $pending = [];

    /** @var array<string, mixed> the server's capabilities, from `initialize` */
    private array $capabilities = [];

    /** @var array<string, mixed> */
    private array $serverInfo = [];

    private string $positionEncoding = Text::UTF16;

    /** @var array<string, array{version: int, text: string, languageId: string, mtime: ?int}> by URI, oldest first */
    private array $documents = [];

    /** @var array<string, list<array<string, mixed>>> by URI */
    private array $diagnostics = [];

    /** @var array<string, int> by URI: how many times diagnostics were published for it */
    private array $generations = [];

    /** @var array<string, list<Deferred>> by URI: callers waiting for the next publish */
    private array $diagnosticWaiters = [];

    /** @var array<string, string> progress tokens in flight, by token: their titles */
    private array $progress = [];

    private ?int $exitCode = null;

    private float $startedAt = 0.0;

    /** @var list<Closure(?int): void> */
    private array $exitListeners = [];

    /** @var list<string> */
    private array $log = [];

    /**
     * @param list<string>         $command
     * @param array<string, string> $env
     * @param array<string, mixed>  $settings              answered to `workspace/configuration`
     *                                                      and sent once after `initialized`
     */
    public function __construct(
        public readonly string $name,
        public readonly array $command,
        public readonly string $root,
        private readonly array $env = [],
        private readonly mixed $initializationOptions = null,
        private readonly array $settings = [],
        private readonly float $requestTimeout = 30.0,
    ) {
    }

    /**
     * Start the server and run the handshake. Must run inside a coroutine; throws when the
     * server cannot be started or does not finish `initialize` within $timeout.
     */
    public function start(float $timeout = 60.0): void
    {
        $this->state = self::STARTING;
        $this->startedAt = microtime(true);
        $this->process = new ServerProcess(
            $this->command,
            $this->root,
            $this->env,
            fn (array $message) => $this->dispatch($message),
            fn (?int $code) => $this->onExit($code),
        );

        try {
            $this->process->start();
            $result = $this->request('initialize', $this->initializeParams(), $timeout);
        } catch (Throwable $error) {
            $stderr = $this->process->stderr();
            $this->process->close(0.5);
            $this->state = self::STOPPED;

            $detail = trim(substr($stderr, -800));

            throw new LspError($error->getMessage() . ($detail !== '' && !str_contains($error->getMessage(), $detail) ? "\n" . $detail : ''));
        }

        $this->capabilities = is_array($result['capabilities'] ?? null) ? $result['capabilities'] : [];
        $this->serverInfo = is_array($result['serverInfo'] ?? null) ? $result['serverInfo'] : [];
        $encoding = $this->capabilities['positionEncoding'] ?? Text::UTF16;
        $this->positionEncoding = in_array($encoding, [Text::UTF8, Text::UTF32], true) ? $encoding : Text::UTF16;

        $this->notify('initialized', new \stdClass());

        if ($this->settings !== []) {
            $this->notify('workspace/didChangeConfiguration', ['settings' => $this->settings]);
        }

        $this->state = self::RUNNING;
    }

    public function state(): string
    {
        return $this->state;
    }

    public function running(): bool
    {
        return $this->state === self::RUNNING;
    }

    public function pid(): ?int
    {
        return $this->process?->pid();
    }

    public function exitCode(): ?int
    {
        return $this->exitCode;
    }

    public function stderr(): string
    {
        return $this->process?->stderr() ?? '';
    }

    public function uptime(): float
    {
        return $this->startedAt > 0 ? microtime(true) - $this->startedAt : 0.0;
    }

    /** @return array<string, mixed> */
    public function capabilities(): array
    {
        return $this->capabilities;
    }

    /** Whether the server offers $provider (`definitionProvider`, `implementationProvider`, …). */
    public function supports(string $provider): bool
    {
        $value = $this->capabilities[$provider] ?? null;

        return $value !== null && $value !== false;
    }

    public function serverName(): string
    {
        $name = $this->serverInfo['name'] ?? null;

        return is_string($name) && $name !== '' ? $name . (is_string($this->serverInfo['version'] ?? null) ? ' ' . $this->serverInfo['version'] : '') : $this->name;
    }

    public function positionEncoding(): string
    {
        return $this->positionEncoding;
    }

    /** @return list<string> titles of the work the server reports in progress (indexing, …) */
    public function progress(): array
    {
        return array_values($this->progress);
    }

    /** @param Closure(?int): void $listener */
    public function onExitListener(Closure $listener): void
    {
        $this->exitListeners[] = $listener;
    }

    /** @return list<string> protocol problems worth showing in `/lsp` */
    public function log(): array
    {
        return $this->log;
    }

    // ---- JSON-RPC -------------------------------------------------------------------------------

    /**
     * Send a request and wait for its answer.
     *
     * @throws LspTimeoutError when the server takes longer than $timeout
     * @throws LspError        when it answers with an error or exits first
     * @throws AbortError      when $signal fires first
     */
    public function request(string $method, mixed $params, ?float $timeout = null, ?AbortSignal $signal = null): mixed
    {
        if ($this->process === null || !$this->process->running()) {
            throw new LspError("The {$this->name} language server is not running");
        }

        if (Fiber::getCurrent() === null) {
            throw new LspError('LSP requests must run inside a coroutine');
        }

        $signal?->throwIfAborted();

        $id = $this->nextId++;
        $deferred = new Deferred();
        $timeout ??= $this->requestTimeout;
        $timer = Loop::get()->delay($timeout, function () use ($id, $method, $timeout): void {
            $this->settle($id, new LspTimeoutError(sprintf('%s did not answer %s within %ss', $this->name, $method, rtrim(rtrim(number_format($timeout, 1), '0'), '.'))));
            $this->cancelOnServer($id);
        });
        $this->pending[$id] = ['deferred' => $deferred, 'timer' => $timer, 'method' => $method];

        $abortListener = $signal?->onAbort(function () use ($id): void {
            $this->settle($id, new AbortError('Aborted'));
            $this->cancelOnServer($id);
        });

        try {
            $this->process->send(['jsonrpc' => '2.0', 'id' => $id, 'method' => $method, 'params' => $params]);

            return $deferred->future->await();
        } finally {
            if ($abortListener !== null) {
                $signal?->removeListener($abortListener);
            }
        }
    }

    public function notify(string $method, mixed $params): void
    {
        if ($this->process === null || !$this->process->running()) {
            return;
        }

        $this->process->send(['jsonrpc' => '2.0', 'method' => $method, 'params' => $params]);
    }

    /**
     * Stop the server politely: `shutdown`, `exit`, then the process ladder. Inside a coroutine
     * the server gets up to 3 seconds to answer `shutdown`; outside one (pig exiting) it is sent
     * `exit` straight away.
     */
    public function shutdown(): void
    {
        if ($this->process === null) {
            return;
        }

        if ($this->process->running() && $this->state === self::RUNNING && Fiber::getCurrent() !== null) {
            try {
                $this->request('shutdown', null, 3.0);
            } catch (Throwable) {
                // A server that will not shut down cleanly is stopped all the same.
            }
        }

        try {
            if ($this->process->running()) {
                $this->notify('exit', null);
            }
        } catch (Throwable) {
        }

        $this->state = self::STOPPED;
        $this->process->close(Fiber::getCurrent() !== null ? 2.0 : 0.5);
    }

    // ---- documents ------------------------------------------------------------------------------

    /**
     * Make sure the server sees $path as it is on disk now, and return that text.
     *
     * Opens it the first time; sends the full new text when the file changed since (by any tool,
     * or by hand). A file that does not exist is closed if it was open, and null comes back.
     */
    public function syncFromDisk(string $path, string $languageId): ?string
    {
        $text = is_file($path) ? file_get_contents($path) : false;
        $uri = Text::pathToUri($path);

        if (!is_string($text)) {
            $this->closeDocument($uri);

            return null;
        }

        $this->syncText($uri, $text, $languageId, self::mtime($path));

        return $text;
    }

    /**
     * Re-sync every open document whose file changed on disk since it was last sent — by a
     * command, the developer's editor, or `git checkout` — so a cross-file answer is not computed
     * from a stale copy. Only the changed ones are read.
     */
    public function refreshOpenDocuments(): void
    {
        clearstatcache();

        foreach ($this->documents as $uri => $document) {
            $path = Text::uriToPath($uri);
            $mtime = self::mtime($path);

            if ($mtime === null) {
                $this->closeDocument($uri);

                continue;
            }

            if ($mtime !== $document['mtime']) {
                $text = file_get_contents($path);

                if (is_string($text)) {
                    $this->syncText($uri, $text, $document['languageId'], $mtime);
                }
            }
        }
    }

    private static function mtime(string $path): ?int
    {
        $mtime = is_file($path) ? filemtime($path) : false;

        return is_int($mtime) ? $mtime : null;
    }

    /** Tell the server $uri now holds $text: didOpen the first time, didChange (full) after. */
    public function syncText(string $uri, string $text, string $languageId, ?int $mtime = null): bool
    {
        $document = $this->documents[$uri] ?? null;

        if ($document !== null) {
            // Most recently used goes to the end.
            unset($this->documents[$uri]);

            if ($document['text'] === $text) {
                $this->documents[$uri] = ['mtime' => $mtime] + $document;

                return false;
            }

            $version = $document['version'] + 1;
            $this->documents[$uri] = ['version' => $version, 'text' => $text, 'languageId' => $languageId, 'mtime' => $mtime];
            // A whole-document change has no range, which every sync kind accepts.
            $this->notify('textDocument/didChange', [
                'textDocument' => ['uri' => $uri, 'version' => $version],
                'contentChanges' => [['text' => $text]],
            ]);

            return true;
        }

        $this->documents[$uri] = ['version' => 1, 'text' => $text, 'languageId' => $languageId, 'mtime' => $mtime];
        $this->notify('textDocument/didOpen', [
            'textDocument' => ['uri' => $uri, 'languageId' => $languageId, 'version' => 1, 'text' => $text],
        ]);

        while (count($this->documents) > self::MAX_OPEN_DOCUMENTS) {
            $this->closeDocument((string) array_key_first($this->documents));
        }

        return true;
    }

    /** A save notification, for servers that only re-check a file when it is saved. */
    public function didSave(string $uri): void
    {
        $sync = $this->capabilities['textDocumentSync'] ?? null;

        if (!isset($this->documents[$uri]) || !is_array($sync) || empty($sync['save'])) {
            return;
        }

        $params = ['textDocument' => ['uri' => $uri]];

        if (is_array($sync['save']) && !empty($sync['save']['includeText'])) {
            $params['text'] = $this->documents[$uri]['text'];
        }

        $this->notify('textDocument/didSave', $params);
    }

    public function closeDocument(string $uri): void
    {
        if (!isset($this->documents[$uri])) {
            return;
        }

        unset($this->documents[$uri]);
        $this->notify('textDocument/didClose', ['textDocument' => ['uri' => $uri]]);
    }

    public function isOpen(string $uri): bool
    {
        return isset($this->documents[$uri]);
    }

    /** The text the server was last given for $uri, if it is open. */
    public function documentText(string $uri): ?string
    {
        return $this->documents[$uri]['text'] ?? null;
    }

    public function openDocumentCount(): int
    {
        return count($this->documents);
    }

    // ---- diagnostics ----------------------------------------------------------------------------

    /** @return list<array<string, mixed>> the last diagnostics published for $uri */
    public function diagnostics(string $uri): array
    {
        return $this->diagnostics[$uri] ?? [];
    }

    /** @return array<string, list<array<string, mixed>>> by URI, empty lists left out */
    public function allDiagnostics(): array
    {
        return array_filter($this->diagnostics, static fn (array $list): bool => $list !== []);
    }

    /** How many times diagnostics have been published for $uri; pass it to waitForDiagnostics(). */
    public function diagnosticGeneration(string $uri): int
    {
        return $this->generations[$uri] ?? 0;
    }

    /**
     * Wait until diagnostics are published for $uri after $afterGeneration, then until the server
     * has been quiet for $settle seconds — all within $timeout. True if any were published.
     *
     * The quiet period is for servers that publish in steps: phpactor first clears a changed
     * file's diagnostics (an empty publish, at once), then publishes each provider's findings as
     * they finish. Taking the first publish would report "no errors" for every edit. A server
     * that pulls (`textDocument/diagnostic`) is asked instead.
     */
    public function waitForDiagnostics(string $uri, int $afterGeneration, float $timeout, float $settle = 0.0): bool
    {
        if ($this->supports('diagnosticProvider')) {
            return $this->pullDiagnostics($uri, $timeout);
        }

        $deadline = microtime(true) + $timeout;

        if ($this->diagnosticGeneration($uri) <= $afterGeneration && !$this->nextPublish($uri, $timeout)) {
            return false;
        }

        while ($settle > 0 && ($left = $deadline - microtime(true)) > 0) {
            if (!$this->nextPublish($uri, min($settle, $left))) {
                break;
            }
        }

        return true;
    }

    /** Wait at most $timeout for the next publish for $uri; true if one came. */
    private function nextPublish(string $uri, float $timeout): bool
    {
        $deferred = new Deferred();
        $this->diagnosticWaiters[$uri][] = $deferred;
        $timer = Loop::get()->delay($timeout, static function () use ($deferred): void {
            if (!$deferred->isComplete()) {
                $deferred->complete(false);
            }
        });

        try {
            return (bool) $deferred->future->await();
        } finally {
            Loop::get()->cancel($timer);
            $this->diagnosticWaiters[$uri] = array_values(array_filter(
                $this->diagnosticWaiters[$uri] ?? [],
                static fn (Deferred $waiter): bool => $waiter !== $deferred,
            ));
        }
    }

    /** LSP 3.17 pull diagnostics, stored as if they had been published. */
    private function pullDiagnostics(string $uri, float $timeout): bool
    {
        try {
            $report = $this->request('textDocument/diagnostic', ['textDocument' => ['uri' => $uri]], $timeout);
        } catch (LspError) {
            return false;
        }

        if (is_array($report) && ($report['kind'] ?? 'full') === 'full' && is_array($report['items'] ?? null)) {
            $this->storeDiagnostics($uri, array_values($report['items']));
        }

        return true;
    }

    /** @param list<array<string, mixed>> $items */
    private function storeDiagnostics(string $uri, array $items): void
    {
        $this->diagnostics[$uri] = $items;
        $this->generations[$uri] = ($this->generations[$uri] ?? 0) + 1;

        foreach ($this->diagnosticWaiters[$uri] ?? [] as $waiter) {
            if (!$waiter->isComplete()) {
                $waiter->complete(true);
            }
        }

        $this->diagnosticWaiters[$uri] = [];
    }

    // ---- incoming -------------------------------------------------------------------------------

    /** @param array<string, mixed> $message */
    private function dispatch(array $message): void
    {
        $method = $message['method'] ?? null;
        $hasId = array_key_exists('id', $message) && $message['id'] !== null;

        if (!is_string($method)) {
            if ($hasId && is_int($message['id'])) {
                $this->onResponse($message['id'], $message);
            }

            return;
        }

        if ($hasId) {
            $this->onServerRequest($message['id'], $method, $message['params'] ?? null);

            return;
        }

        $this->onNotification($method, $message['params'] ?? null);
    }

    /** @param array<string, mixed> $message */
    private function onResponse(int $id, array $message): void
    {
        if (isset($message['error']) && is_array($message['error'])) {
            $code = is_int($message['error']['code'] ?? null) ? $message['error']['code'] : null;
            $text = is_string($message['error']['message'] ?? null) ? $message['error']['message'] : 'Unknown error';
            $method = $this->pending[$id]['method'] ?? 'request';
            $this->settle($id, new LspError("{$this->name}: {$method} failed: {$text}", $code));

            return;
        }

        $this->settle($id, null, $message['result'] ?? null);
    }

    private function onServerRequest(int|string $id, string $method, mixed $params): void
    {
        $result = null;
        $error = null;

        switch ($method) {
            case 'workspace/configuration':
                $result = [];

                foreach (is_array($params['items'] ?? null) ? $params['items'] : [] as $item) {
                    $section = is_array($item) && is_string($item['section'] ?? null) ? $item['section'] : null;
                    $result[] = $this->settingsSection($section);
                }

                break;

            case 'workspace/workspaceFolders':
                $result = [['uri' => Text::pathToUri($this->root), 'name' => basename($this->root)]];

                break;

            case 'window/workDoneProgress/create':
            case 'client/registerCapability':
            case 'client/unregisterCapability':
            case 'window/showMessageRequest':
            case 'workspace/diagnostic/refresh':
            case 'workspace/semanticTokens/refresh':
            case 'workspace/inlayHint/refresh':
            case 'workspace/codeLens/refresh':
            case 'workspace/inlineValue/refresh':
                $result = null;

                break;

            case 'workspace/applyEdit':
                // Edits are the agent's to make with its own tools, where the developer sees them.
                $result = ['applied' => false, 'failureReason' => 'pig applies edits with its own tools'];

                break;

            case 'window/showDocument':
                $result = ['success' => false];

                break;

            default:
                $error = ['code' => -32601, 'message' => "Method not found: {$method}"];
        }

        try {
            $this->process?->send($error !== null
                ? ['jsonrpc' => '2.0', 'id' => $id, 'error' => $error]
                : ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result]);
        } catch (LspError) {
        }
    }

    private function onNotification(string $method, mixed $params): void
    {
        switch ($method) {
            case 'textDocument/publishDiagnostics':
                if (is_array($params) && is_string($params['uri'] ?? null)) {
                    $items = is_array($params['diagnostics'] ?? null) ? array_values($params['diagnostics']) : [];
                    // Some servers send a URI spelled differently from the one opened
                    // (`%3A` for `:`); keyed through the path, both find the same entry.
                    $this->storeDiagnostics(Text::pathToUri(Text::uriToPath($params['uri'])), $items);
                }

                break;

            case '$/progress':
                $token = is_array($params) ? (string) json_encode($params['token'] ?? '') : '';
                $value = is_array($params) && is_array($params['value'] ?? null) ? $params['value'] : [];

                if (($value['kind'] ?? null) === 'begin') {
                    $this->progress[$token] = is_string($value['title'] ?? null) ? $value['title'] : 'working';
                } elseif (($value['kind'] ?? null) === 'end') {
                    unset($this->progress[$token]);
                }

                break;

            case 'window/showMessage':
                if (is_array($params) && ($params['type'] ?? 4) <= 1 && is_string($params['message'] ?? null)) {
                    $this->remember($params['message']);
                }

                break;
        }
    }

    private function settingsSection(?string $section): mixed
    {
        if ($section === null || $section === '') {
            return $this->settings === [] ? null : $this->settings;
        }

        if (array_key_exists($section, $this->settings)) {
            return $this->settings[$section];
        }

        $value = $this->settings;

        foreach (explode('.', $section) as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) {
                return null;
            }

            $value = $value[$part];
        }

        return $value;
    }

    private function settle(int $id, ?Throwable $error, mixed $result = null): void
    {
        $pending = $this->pending[$id] ?? null;

        if ($pending === null) {
            return;
        }

        unset($this->pending[$id]);

        if ($pending['timer'] !== null) {
            Loop::get()->cancel($pending['timer']);
        }

        if ($pending['deferred']->isComplete()) {
            return;
        }

        $error !== null ? $pending['deferred']->error($error) : $pending['deferred']->complete($result);
    }

    private function cancelOnServer(int $id): void
    {
        try {
            $this->notify('$/cancelRequest', ['id' => $id]);
        } catch (Throwable) {
        }
    }

    private function onExit(?int $code): void
    {
        $this->exitCode = $code;
        // A server that dies while starting is reported by start() throwing; telling the
        // listeners as well would count the one failure twice.
        $wasRunning = $this->state === self::RUNNING;
        $this->state = self::STOPPED;
        $this->documents = [];

        $detail = trim(substr($this->process?->stderr() ?? '', -400));
        $message = "The {$this->name} language server exited" . ($code !== null ? " (code {$code})" : '')
            . ($detail !== '' ? ": {$detail}" : '');

        foreach (array_keys($this->pending) as $id) {
            $this->settle($id, new LspError($message));
        }

        foreach ($this->diagnosticWaiters as $waiters) {
            foreach ($waiters as $waiter) {
                if (!$waiter->isComplete()) {
                    $waiter->complete(false);
                }
            }
        }

        $this->diagnosticWaiters = [];

        if ($wasRunning) {
            foreach ($this->exitListeners as $listener) {
                $listener($code);
            }
        }
    }

    private function remember(string $line): void
    {
        $this->log[] = $line;
        $this->log = array_slice($this->log, -20);
    }

    /** @return array<string, mixed> */
    private function initializeParams(): array
    {
        $rootUri = Text::pathToUri($this->root);

        return [
            'processId' => getmypid() ?: null,
            'clientInfo' => ['name' => 'pig-lsp'],
            'rootUri' => $rootUri,
            'rootPath' => $this->root,
            'workspaceFolders' => [['uri' => $rootUri, 'name' => basename($this->root)]],
            'initializationOptions' => $this->initializationOptions,
            'capabilities' => [
                'general' => ['positionEncodings' => [Text::UTF16, Text::UTF8]],
                'workspace' => [
                    'configuration' => true,
                    'workspaceFolders' => true,
                    'symbol' => ['dynamicRegistration' => false],
                    'didChangeConfiguration' => ['dynamicRegistration' => false],
                    'workspaceEdit' => ['documentChanges' => true, 'resourceOperations' => ['create', 'rename', 'delete']],
                    'applyEdit' => false,
                ],
                'textDocument' => [
                    'synchronization' => ['dynamicRegistration' => false, 'didSave' => true, 'willSave' => false, 'willSaveWaitUntil' => false],
                    'hover' => ['contentFormat' => ['markdown', 'plaintext']],
                    'definition' => ['linkSupport' => true],
                    'declaration' => ['linkSupport' => true],
                    'typeDefinition' => ['linkSupport' => true],
                    'implementation' => ['linkSupport' => true],
                    'references' => [],
                    'documentSymbol' => ['hierarchicalDocumentSymbolSupport' => true],
                    'rename' => ['prepareSupport' => true],
                    'codeAction' => [
                        'codeActionLiteralSupport' => ['codeActionKind' => ['valueSet' => [
                            '', 'quickfix', 'refactor', 'refactor.extract', 'refactor.inline', 'refactor.rewrite', 'source', 'source.organizeImports', 'source.fixAll',
                        ]]],
                        'isPreferredSupport' => true,
                        'resolveSupport' => ['properties' => ['edit']],
                    ],
                    'publishDiagnostics' => ['relatedInformation' => true, 'versionSupport' => true],
                    'diagnostic' => ['dynamicRegistration' => false],
                ],
                'window' => ['workDoneProgress' => true, 'showMessage' => []],
            ],
        ];
    }
}
