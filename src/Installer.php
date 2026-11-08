<?php

declare(strict_types=1);

namespace PigLsp;

use Pig\Async\AbortSignal;
use Pig\Async\Async;
use Pig\Async\Future;
use Pig\CodingAgent\Extensions\ExtensionContext;
use Pig\Tui\Process;
use Throwable;

/**
 * Installing a missing language server — only when the user says yes, and asked once.
 *
 * A server that is not installed used to come back as an install hint for the model, and a model
 * told "run `composer global require …`" may well run it: the developer's global environment
 * changed because a tool call said so. Installing is the user's decision, so it is put to the user,
 * once per language per session; the model is told plainly not to do it itself.
 *
 * - **Asked once.** No is remembered for the session, and so is a failed install; `/lsp-install`
 *   asks again on purpose. Calls arriving while the question is open wait for the same answer —
 *   one dialog, not one per call.
 * - **Only with someone to ask.** No UI (`pig -p`, RPC without one, a script) means nothing is run.
 * - **Found where it was put.** composer, go and npm install into directories that are often not
 *   on PATH; each is asked where (`composer global config bin-dir`, `go env GOPATH`, `npm prefix
 *   -g`), and `Config` looks there, starting the server with that directory on its PATH.
 */
final class Installer
{
    /** Generous: `go install gopls` compiles, composer resolves a tree; both can take minutes. */
    public const float TIMEOUT = 300.0;

    /** Asking an installer where its programs go. */
    private const float QUERY_TIMEOUT = 20.0;

    public const string INSTALLED = 'installed';

    public const string DECLINED = 'declined';

    public const string FAILED = 'failed';

    /** Stopped part way (escape): not remembered, so the next call asks again. */
    public const string STOPPED = 'stopped';

    /** Nothing structured to run, or nothing to run it with. */
    public const string UNAVAILABLE = 'unavailable';

    /** Nobody to ask. */
    public const string NO_UI = 'no-ui';

    /** @var array<string, array{state: string, detail: string}> by server key: what this session decided */
    private array $outcomes = [];

    /** @var array<string, Future> by server key: the question or install in flight */
    private array $pending = [];

    /** @var list<string> directories installs here put servers in */
    private array $binDirs = [];

    /** @var array<string, true> by server key: the user was told there is nothing to install it with */
    private array $toldUnavailable = [];

    /** @return list<string> */
    public function binDirs(): array
    {
        return $this->binDirs;
    }

    /** @return array{state: string, detail: string}|null what this session decided for $key */
    public function outcome(string $key): ?array
    {
        return $this->outcomes[$key] ?? null;
    }

    /**
     * Ask the user to install $key's server, and do it if they agree.
     *
     * @param bool $again ask even when the answer this session was no, or the install failed —
     *        `/lsp-install`, where the user is the one asking
     * @return array{state: string, detail: string} `detail` is the installed binary's path, or what went wrong
     */
    public function offer(string $key, Config $config, ExtensionContext $ctx, ?AbortSignal $signal = null, bool $again = false): array
    {
        $decided = $this->outcomes[$key] ?? null;

        if ($decided !== null && !$again && in_array($decided['state'], [self::DECLINED, self::FAILED], true)) {
            return $decided;
        }

        $option = $config->installOption($key);

        if ($option === null) {
            if ($ctx->hasUi && !isset($this->toldUnavailable[$key])) {
                $this->toldUnavailable[$key] = true;
                self::quietly(static fn () => $ctx->ui->notify("pig-lsp: {$config->installHint($key)}", 'warning'));
            }

            return ['state' => self::UNAVAILABLE, 'detail' => ''];
        }

        if (!$ctx->hasUi) {
            return ['state' => self::NO_UI, 'detail' => ''];
        }

        if (!isset($this->pending[$key])) {
            $future = Async::spawn(fn (): array => $this->askAndInstall($key, $option, $config, $ctx, $signal));
            $this->pending[$key] = $future;
            $future->onComplete(function () use ($key, $future): void {
                if (($this->pending[$key] ?? null) === $future) {
                    unset($this->pending[$key]);
                }
            });
        }

        return $this->pending[$key]->await();
    }

    /**
     * @param array{binary: string, with: string, command: list<string>, display: string} $option
     * @return array{state: string, detail: string}
     */
    private function askAndInstall(string $key, array $option, Config $config, ExtensionContext $ctx, ?AbortSignal $signal): array
    {
        $languages = implode('/', array_map('strval', (array) ($config->server($key)['languages'] ?? [$key])));
        $yes = $ctx->ui->confirm(
            "Install {$option['binary']}?",
            "No {$languages} language server is installed, so pig falls back to grep for definitions, references and implementations.\n\n"
            . "pig-lsp can install {$option['binary']} by running:\n\n    {$option['display']}\n\n"
            . "This changes your global {$option['with']} environment. If you say no, it will not ask again this session (/lsp-install asks again).",
        );

        if (!$yes) {
            return $this->outcomes[$key] = ['state' => self::DECLINED, 'detail' => ''];
        }

        self::quietly(static fn () => $ctx->ui->setStatus('lsp', "LSP: installing {$option['binary']}…"));

        try {
            [$exit, $stdout, $stderr] = Process::runAsync($option['command'], self::TIMEOUT, $config->cwd, $signal);
        } finally {
            self::quietly(static fn () => $ctx->ui->setStatus('lsp', null));
        }

        if ($signal?->aborted() ?? false) {
            return ['state' => self::STOPPED, 'detail' => 'stopped before it finished'];
        }

        if ($exit !== 0) {
            $detail = ($exit === Process::STOPPED ? 'it did not finish within ' . (int) self::TIMEOUT . 's' : "exit code {$exit}") . self::tail($stderr . "\n" . $stdout);
            self::quietly(static fn () => $ctx->ui->notify("pig-lsp: installing {$option['binary']} failed ({$option['display']}): {$detail}", 'error'));

            return $this->outcomes[$key] = ['state' => self::FAILED, 'detail' => $detail];
        }

        foreach ($this->whereInstalled($option['with'], $config->cwd, $signal) as $directory) {
            if (!in_array($directory, $this->binDirs, true)) {
                $this->binDirs[] = $directory;
            }
        }

        $found = (new Config($config->cwd, null, $this->binDirs))->find($option['binary']);

        if ($found === null) {
            $detail = "`{$option['display']}` succeeded, but `{$option['binary']}` was not found on PATH or in "
                . ($this->binDirs === [] ? "{$option['with']}'s bin directory" : implode(', ', $this->binDirs));
            self::quietly(static fn () => $ctx->ui->notify("pig-lsp: {$detail}", 'error'));

            return $this->outcomes[$key] = ['state' => self::FAILED, 'detail' => $detail];
        }

        $onPath = Config::onPath(dirname($found));
        self::quietly(static fn () => $ctx->ui->notify(
            "pig-lsp: installed {$option['binary']} ({$found})." . ($onPath ? '' : ' ' . self::pathAdvice($found)),
            'info',
        ));

        return $this->outcomes[$key] = ['state' => self::INSTALLED, 'detail' => $found];
    }

    /** What to tell someone whose server landed off PATH. */
    public static function pathAdvice(string $binary): string
    {
        $directory = dirname($binary);

        return "{$directory} is not on PATH; pig-lsp finds it there anyway, but other tools will not — add `export PATH=\"{$directory}:\$PATH\"` to your shell profile.";
    }

    /**
     * Where $installer puts programs, by asking it — composer and go know, and theirs are the
     * directories most often missing from PATH.
     *
     * @return list<string>
     */
    private function whereInstalled(string $installer, string $cwd, ?AbortSignal $signal): array
    {
        $query = static function (array $command) use ($cwd, $signal): string {
            try {
                [$exit, $stdout] = Process::runAsync($command, self::QUERY_TIMEOUT, $cwd, $signal);
            } catch (Throwable) {
                return '';
            }

            $lines = array_values(array_filter(array_map('trim', explode("\n", (string) $stdout)), static fn (string $line): bool => $line !== ''));

            return $exit === 0 && $lines !== [] ? end($lines) : '';
        };

        $directories = match ($installer) {
            'composer' => [$query(['composer', 'global', 'config', 'bin-dir', '--absolute'])],
            'go' => [($bin = $query(['go', 'env', 'GOBIN'])) !== '' ? $bin : (($path = $query(['go', 'env', 'GOPATH'])) !== '' ? explode(PATH_SEPARATOR, $path)[0] . '/bin' : '')],
            'npm' => [($prefix = $query(['npm', 'prefix', '-g'])) !== '' ? $prefix . '/bin' : ''],
            'brew' => [($prefix = $query(['brew', '--prefix'])) !== '' ? $prefix . '/bin' : ''],
            default => [],
        };

        return array_values(array_filter($directories, static fn (string $directory): bool => $directory !== '' && is_dir($directory)));
    }

    /** The last lines of an installer's output, for a message. */
    private static function tail(string $output): string
    {
        $lines = array_values(array_filter(array_map('rtrim', explode("\n", $output)), static fn (string $line): bool => trim($line) !== ''));

        return $lines === [] ? '' : ":\n" . implode("\n", array_slice($lines, -8));
    }

    /** A UI call that must not fail what it reports on. */
    private static function quietly(\Closure $call): void
    {
        try {
            $call();
        } catch (Throwable) {
        }
    }
}
