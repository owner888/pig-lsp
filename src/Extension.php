<?php

declare(strict_types=1);

namespace PigLsp;

use Fiber;
use Pig\Ai\TextContent;
use Pig\Async\AbortSignal;
use Pig\CodingAgent\Extensions\ExtensionApi;
use Pig\CodingAgent\Extensions\Events\ToolResultEvent;
use Pig\CodingAgent\Extensions\ExtensionContext;
use Pig\CodingAgent\Extensions\Results\ToolResultEventResult;
use Throwable;

/**
 * The wiring: tools, the read/write/edit hook, `/lsp`, `/lsp-restart` and `/lsp-install`, start and
 * stop.
 *
 * One manager per working directory, made on first use, because the extension is loaded before
 * the session knows its directory and settings.
 */
final class Extension
{
    /** @var array<string, LspManager> by working directory */
    private array $managers = [];

    private bool $shutdownRegistered = false;

    private readonly Installer $installer;

    public function __construct(private readonly ExtensionApi $pig)
    {
        $this->installer = new Installer();
    }

    public static function register(ExtensionApi $pig): self
    {
        $extension = new self($pig);
        $tools = new Tools(
            fn (string $cwd): LspManager => $extension->manager($cwd),
            fn (LspNotInstalled $missing, ExtensionContext $ctx, ?AbortSignal $signal): string => $extension->notInstalled($missing, $ctx, $signal),
        );

        foreach ($tools->definitions(
            fn (): float => $extension->lastManager()?->config()->diagnosticsWait() ?? 3.0,
            fn (ExtensionContext $ctx) => $extension->updateStatus($ctx),
        ) as $tool) {
            $pig->registerTool($tool);
        }

        $pig->on('tool_result', fn (ToolResultEvent $event, ExtensionContext $ctx): ?ToolResultEventResult => $extension->onToolResult($event, $ctx));

        $pig->on('session_start', function (mixed $event, ExtensionContext $ctx) use ($extension): void {
            foreach ($extension->manager($ctx->cwd)->config()->autoStart() as $key) {
                $extension->manager($ctx->cwd)->prestart($key);
            }
        });

        $pig->on('session_shutdown', function () use ($extension): void {
            $extension->shutdown();
        });

        $pig->registerCommand('lsp', function (string $args, ExtensionContext $ctx) use ($extension): void {
            $ctx->ui->notify($extension->statusReport($ctx->cwd), 'info');
        }, 'Language servers: which are running, for which project, and why one failed');

        $pig->registerCommand('lsp-restart', function (string $args, ExtensionContext $ctx) use ($extension): void {
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

        $pig->registerCommand('lsp-install', function (string $args, ExtensionContext $ctx) use ($extension): void {
            $extension->installCommand(trim($args), $ctx);
        }, 'Install a missing language server (asks first): /lsp-install php');

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

        return new Config($cwd, $settings, $this->installer->binDirs());
    }

    /**
     * A tool found $missing's server not installed: offer to install it, and say how it went.
     *
     * @return string a note for the tool's result when the server is now installed (the call then
     *         goes on); otherwise this throws what the model should be told
     * @throws LspError
     */
    public function notInstalled(LspNotInstalled $missing, ExtensionContext $ctx, ?AbortSignal $signal = null): string
    {
        $config = $this->manager($ctx->cwd)->config();
        $outcome = $this->installer->offer($missing->key, $config, $ctx, $signal);

        if ($outcome['state'] === Installer::INSTALLED) {
            $this->reconfigureAll();

            return self::installedNote($missing->key, $outcome['detail']);
        }

        throw new LspError(self::notInstalledMessage($missing->key, $outcome, $config));
    }

    /**
     * What the model is told when a server is missing and stays missing. The same last sentence
     * every time: a model told how a server is installed tends to install it, and that is the
     * user's call, not the model's.
     *
     * @param array{state: string, detail: string} $outcome
     */
    public static function notInstalledMessage(string $key, array $outcome, Config $config): string
    {
        $option = $config->installOption($key);
        $server = $option['binary'] ?? "{$key} language server";
        $why = match ($outcome['state']) {
            Installer::DECLINED => "The user was asked whether to install {$server} and declined for this session.",
            Installer::FAILED => "The user agreed to install {$server}, but installing it failed: {$outcome['detail']}",
            Installer::STOPPED => "Installing {$server} was stopped before it finished.",
            Installer::NO_UI => 'Nothing was installed: this session has no one to ask (servers are only installed when the user agrees).',
            default => 'There is nothing pig-lsp can install it with, so the user has been shown how to install it.',
        };
        $forUser = $outcome['state'] === Installer::NO_UI || $outcome['state'] === Installer::UNAVAILABLE
            ? ' If the user asks how to get one: ' . self::hintOnly($config->installHint($key))
            : '';

        return "No {$key} language server is installed. {$why}{$forUser}\n"
            . 'Do not install it yourself with bash (or any other tool) — that changes the user\'s global environment and is theirs to decide. '
            . 'Use grep and read instead, and say the results are textual matches, not from a language server.';
    }

    /** What a tool result says above its answer when the server was installed for it. */
    private static function installedNote(string $key, string $binary): string
    {
        $note = "(The {$key} language server was missing; the user agreed and pig-lsp installed it: {$binary}.";

        return $note . (Config::onPath(dirname($binary)) ? ')' : ' ' . Installer::pathAdvice($binary) . ' Tell the user.)');
    }

    /** The prose part of an install hint, without the "No … is installed (looked for …)." lead. */
    private static function hintOnly(string $hint): string
    {
        $parts = explode('). ', $hint, 2);

        return count($parts) === 2 ? $parts[1] : $hint;
    }

    /** `/lsp-install [language]`: the user asks for it, so it is asked again even after a no. */
    public function installCommand(string $language, ExtensionContext $ctx): void
    {
        $manager = $this->manager($ctx->cwd);
        $config = $manager->config();

        if ($language !== '') {
            $keys = [$config->serverKeyForLanguage($language) ?? $language];
        } else {
            $keys = array_values(array_filter($manager->projectServerKeys(), static fn (string $key): bool => $config->command($key) === null));
        }

        $keys = array_values(array_filter($keys, static fn (string $key): bool => in_array($key, $config->serverKeys(), true)));

        if ($keys === []) {
            $missing = array_values(array_filter($config->serverKeys(), static fn (string $key): bool => $config->command($key) === null));
            $ctx->ui->notify(
                ($language !== '' ? "No language server is configured for {$language}." : 'Every language server this project needs is installed.')
                . ($missing !== [] ? ' Not installed: ' . implode(', ', $missing) . ' — /lsp-install <language>.' : ''),
                'info',
            );

            return;
        }

        foreach ($keys as $key) {
            if ($config->command($key) !== null) {
                $ctx->ui->notify("The {$key} language server is already installed: " . implode(' ', $config->command($key)), 'info');

                continue;
            }

            $outcome = $this->installer->offer($key, $config, $ctx, null, true);

            if ($outcome['state'] === Installer::INSTALLED) {
                $this->reconfigureAll();
                $config = $manager->config();
            } elseif ($outcome['state'] === Installer::UNAVAILABLE) {
                $ctx->ui->notify("pig-lsp cannot install the {$key} language server itself: " . self::hintOnly($config->installHint($key)), 'warning');
            } elseif ($outcome['state'] === Installer::NO_UI) {
                $ctx->ui->notify("Installing needs someone to confirm it; run /lsp-install {$key} in an interactive session.", 'warning');
            }
            // Installed, declined and failed have been reported by the installer, or need no word.
        }

        $this->updateStatus($ctx);
    }

    /** After an install: every manager looks for servers again, in the new directories too. */
    private function reconfigureAll(): void
    {
        foreach ($this->managers as $cwd => $manager) {
            $manager->reconfigure($this->config((string) $cwd));
        }
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
    public function onToolResult(ToolResultEvent $event, ExtensionContext $ctx): ?ToolResultEventResult
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

    public function updateStatus(ExtensionContext $ctx): void
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
            $available[] = $key . ': ' . ($command !== null ? implode(' ', $command) : 'not installed' . $this->installState($key, $config));
        }

        $out[] = '';
        $out[] = 'Servers: ' . implode('; ', $available);
        $out[] = 'Config: ' . ($config->sources === [] ? 'built-in defaults' : implode(', ', $config->sources));

        foreach ($config->problems as $problem) {
            $out[] = 'Config problem: ' . $problem;
        }

        return implode("\n", $out);
    }

    /** Why a missing server is still missing, for `/lsp`. */
    private function installState(string $key, Config $config): string
    {
        $outcome = $this->installer->outcome($key);

        if ($outcome !== null && $outcome['state'] === Installer::DECLINED) {
            return " (install declined this session — /lsp-install {$key})";
        }

        if ($outcome !== null && $outcome['state'] === Installer::FAILED) {
            return ' (install failed: ' . str_replace("\n", ' / ', trim($outcome['detail'])) . " — /lsp-install {$key})";
        }

        $option = $config->installOption($key);

        return $option !== null ? " (installable: {$option['display']} — /lsp-install {$key})" : '';
    }

    private static function duration(float $seconds): string
    {
        return $seconds < 60 ? sprintf('%ds', (int) $seconds) : ($seconds < 3600 ? sprintf('%dm', (int) ($seconds / 60)) : sprintf('%.1fh', $seconds / 3600));
    }
}
