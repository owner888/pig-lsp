<?php

declare(strict_types=1);

namespace PigLsp;

use Fiber;
use Pig\Ai\TextContent;
use Pig\CodingAgent\Extensions\ExtensionApi;
use Pig\CodingAgent\Hooks\Events\ToolResultEvent;
use Pig\CodingAgent\Hooks\HookContext;
use Pig\CodingAgent\Hooks\Results\ToolResultEventResult;
use Throwable;

/**
 * The wiring: tools, the read/write/edit hook, `/lsp` and `/lsp-restart`, start and stop.
 *
 * One manager per working directory, made on first use, because the extension is loaded before
 * the session knows its directory and settings.
 */
final class Extension
{
    /** @var array<string, LspManager> by working directory */
    private array $managers = [];

    private bool $shutdownRegistered = false;

    public function __construct(private readonly ExtensionApi $pi)
    {
    }

    public static function register(ExtensionApi $pi): self
    {
        $extension = new self($pi);
        $tools = new Tools(fn (string $cwd): LspManager => $extension->manager($cwd));

        foreach ($tools->definitions(
            fn (): float => $extension->lastManager()?->config()->diagnosticsWait() ?? 3.0,
            fn (HookContext $ctx) => $extension->updateStatus($ctx),
        ) as $tool) {
            $pi->registerTool($tool);
        }

        $pi->on('tool_result', fn (ToolResultEvent $event, HookContext $ctx): ?ToolResultEventResult => $extension->onToolResult($event, $ctx));

        $pi->on('session_start', function (mixed $event, HookContext $ctx) use ($extension): void {
            foreach ($extension->manager($ctx->cwd)->config()->autoStart() as $key) {
                $extension->manager($ctx->cwd)->prestart($key);
            }
        });

        $pi->on('session_shutdown', function () use ($extension): void {
            $extension->shutdown();
        });

        $pi->registerCommand('lsp', function (string $args, HookContext $ctx) use ($extension): void {
            $ctx->ui->notify($extension->statusReport($ctx->cwd), 'info');
        }, 'Language servers: which are running, for which project, and why one failed');

        $pi->registerCommand('lsp-restart', function (string $args, HookContext $ctx) use ($extension): void {
            $key = trim($args);
            $manager = $extension->manager($ctx->cwd);
            $stopped = $manager->restart($key !== '' ? $key : null);
            // Config is read again, so an edited `.pig/lsp.json` applies from the next query.
            $manager->reconfigure($extension->config($ctx->cwd));
            $extension->updateStatus($ctx);
            $ctx->ui->notify(
                $stopped > 0
                    ? "Stopped {$stopped} language server(s)" . ($key !== '' ? " for {$key}" : '') . '; the next LSP query starts them again.'
                    : 'No language server was running' . ($key !== '' ? " for {$key}" : '') . '; configuration re-read.',
                'info',
            );
        }, 'Stop language servers (all, or one: /lsp-restart php) and re-read the configuration');

        return $extension;
    }

    public function manager(string $cwd): LspManager
    {
        $cwd = rtrim($cwd, '/') ?: '/';

        if (!isset($this->managers[$cwd])) {
            $this->managers[$cwd] = new LspManager($this->config($cwd));
        }

        if (!$this->shutdownRegistered) {
            $this->shutdownRegistered = true;
            // The backstop for an exit that skips session_shutdown (a fatal error, a signal pig
            // turned into exit()): a language server left behind holds memory until reboot.
            register_shutdown_function(fn () => $this->shutdown());
        }

        return $this->managers[$cwd];
    }

    public function config(string $cwd): Config
    {
        $settings = null;

        try {
            $value = $this->pi->getSettings()?->get('lsp');
            $settings = is_array($value) ? $value : null;
        } catch (Throwable) {
        }

        return new Config($cwd, $settings);
    }

    public function lastManager(): ?LspManager
    {
        return $this->managers === [] ? null : end($this->managers);
    }

    public function shutdown(): void
    {
        foreach ($this->managers as $manager) {
            $manager->shutdownAll();
        }

        $this->managers = [];
    }

    /**
     * After pig's own file tools: keep the servers' copies current, and append the errors an
     * edit introduced while the server for that language is running.
     */
    public function onToolResult(ToolResultEvent $event, HookContext $ctx): ?ToolResultEventResult
    {
        if ($event->isError || !in_array($event->toolName, ['read', 'write', 'edit'], true)) {
            return null;
        }

        $path = $event->input['path'] ?? $event->input['file_path'] ?? null;

        if (!is_string($path) || $path === '' || !isset($this->managers[rtrim($ctx->cwd, '/') ?: '/'])) {
            // No manager yet means no server was ever asked for: nothing to keep in sync.
            return null;
        }

        $manager = $this->manager($ctx->cwd);
        $path = Text::absolute($path, $ctx->cwd);
        $running = $manager->runningClientForFile($path);

        if ($running === null) {
            return null;
        }

        ['client' => $client, 'languageId' => $languageId, 'key' => $key] = $running;
        $uri = Text::pathToUri($path);

        try {
            if ($event->toolName === 'read') {
                $client->syncFromDisk($path, $languageId);

                return null;
            }

            $generation = $client->diagnosticGeneration($uri);
            $before = $client->isOpen($uri) ? $client->documentText($uri) : null;
            $text = $client->syncFromDisk($path, $languageId);
            $client->didSave($uri);

            $config = $manager->config();

            if ($text === null || !$config->autoDiagnostics($languageId) || Fiber::getCurrent() === null) {
                return null;
            }

            if ($before !== $text || $generation === 0) {
                $client->waitForDiagnostics($uri, $generation, $config->diagnosticsWait(), $config->diagnosticsSettle($key));
            }

            $appendix = self::errorAppendix($client, $path, $uri, $ctx->cwd, $key, $config->maxDiagnostics());
        } catch (Throwable) {
            // A hook must never fail the edit it is reporting on.
            return null;
        } finally {
            $this->updateStatus($ctx);
        }

        if ($appendix === null) {
            return null;
        }

        return new ToolResultEventResult(content: [...$event->content, new TextContent($appendix)]);
    }

    /** The errors (not warnings) in $uri, as appended to an edit; null when there are none. */
    public static function errorAppendix(LspClient $client, string $path, string $uri, string $cwd, string $key, int $max): ?string
    {
        $errors = array_values(array_filter($client->diagnostics($uri), static fn (array $d): bool => (int) ($d['severity'] ?? 1) === 1));

        if ($errors === []) {
            return null;
        }

        $format = new Format($cwd, $client->positionEncoding());
        $count = count($errors);
        $text = "⚠ LSP ({$key}): {$count} error" . ($count === 1 ? '' : 's') . ' in ' . Text::relative($path, $cwd) . " after this change:\n"
            . $format->diagnostics($path, $errors, $max, 1);

        // Format already says "… and N more."; nothing to add.
        return $text;
    }

    public function updateStatus(HookContext $ctx): void
    {
        $manager = $this->managers[rtrim($ctx->cwd, '/') ?: '/'] ?? null;
        $running = $manager?->runningClients() ?? [];

        try {
            if ($running === []) {
                $ctx->ui->setStatus('lsp', null);

                return;
            }

            $names = array_values(array_unique(array_map(static fn (LspClient $client): string => $client->name, $running)));
            $errors = 0;

            foreach ($running as $client) {
                foreach ($client->allDiagnostics() as $items) {
                    $errors += count(array_filter($items, static fn (array $d): bool => (int) ($d['severity'] ?? 1) === 1));
                }
            }

            $ctx->ui->setStatus('lsp', 'LSP: ' . implode(', ', $names) . ($errors > 0 ? " ({$errors} error" . ($errors === 1 ? '' : 's') . ')' : ''));
        } catch (Throwable) {
        }
    }

    public function statusReport(string $cwd): string
    {
        $manager = $this->manager($cwd);
        $config = $manager->config();
        $rows = $manager->status();
        $out = [];

        if ($rows === []) {
            $out[] = 'No language server started yet; they start on the first LSP query for a file of theirs.';
        }

        foreach ($rows as $row) {
            $line = sprintf('%s  %s  %s', $row['key'], $row['state'], $row['server']);

            if ($row['pid'] !== null) {
                $line .= sprintf('  pid %d, up %s, %d open, %d diagnostic(s)', $row['pid'], self::duration($row['uptime']), $row['documents'], $row['diagnostics']);
            }

            $line .= "\n    root: " . $row['root'];

            if ($row['progress'] !== []) {
                $line .= "\n    busy: " . implode(', ', $row['progress']);
            }

            if ($row['error'] !== null) {
                $line .= "\n    error: " . $row['error'];
            }

            if ($row['state'] !== LspClient::RUNNING && trim($row['stderr']) !== '') {
                $line .= "\n    stderr: " . str_replace("\n", "\n            ", trim(substr($row['stderr'], -600)));
            }

            $out[] = $line;
        }

        $available = [];

        foreach ($config->serverKeys() as $key) {
            $command = $config->command($key);
            $available[] = $key . ': ' . ($command !== null ? implode(' ', $command) : 'not installed');
        }

        $out[] = '';
        $out[] = 'Servers: ' . implode('; ', $available);
        $out[] = 'Config: ' . ($config->sources === [] ? 'built-in defaults' : implode(', ', $config->sources));

        foreach ($config->problems as $problem) {
            $out[] = 'Config problem: ' . $problem;
        }

        return implode("\n", $out);
    }

    private static function duration(float $seconds): string
    {
        return $seconds < 60 ? sprintf('%ds', (int) $seconds) : ($seconds < 3600 ? sprintf('%dm', (int) ($seconds / 60)) : sprintf('%.1fh', $seconds / 3600));
    }
}
