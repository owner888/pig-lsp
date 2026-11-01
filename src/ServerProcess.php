<?php

declare(strict_types=1);

namespace PigLsp;

use Closure;
use Fiber;
use Pig\Async\Async;
use Pig\Async\Loop;
use Pig\Tui\Process;
use RuntimeException;

/**
 * A language server as a child process, framed messages on its stdio.
 *
 * The same shape as pig's MCP stdio transport — pipes watched by the loop, the last 64KB of stderr
 * kept for `/lsp` to show, stdin closed then SIGTERM then SIGKILL on the way out — with two
 * differences that come from how an LSP client is used:
 *
 * - **Writes are queued, not awaited.** A notification such as `didChange` is sent from a
 *   `tool_result` hook, which is not always inside a coroutine; a write that had to suspend there
 *   would fail. The queue drains through an `onWritable` watcher, in order, so a large `didOpen`
 *   and the request after it can never interleave.
 * - **Close works outside a coroutine too.** pig's shutdown callback runs after the loop has
 *   stopped; a server left running there would outlive the session (rust-analyzer and jdtls hold
 *   gigabytes). Without a fiber the waits are plain sleeps.
 */
final class ServerProcess
{
    private const int MAX_STDERR_BYTES = 64 * 1024;

    /** @var resource|null */
    private $process = null;

    /** @var resource|null */
    private $stdin = null;

    /** @var resource|null */
    private $stdout = null;

    /** @var resource|null */
    private $stderr = null;

    private ?string $stdoutWatcher = null;

    private ?string $stderrWatcher = null;

    private ?string $stdinWatcher = null;

    private string $writeBuffer = '';

    private string $stderrBuffer = '';

    private Framing $framing;

    private ?int $pid = null;

    private bool $exited = false;

    private ?int $exitCode = null;

    /**
     * @param list<string>                         $command  the executable, then its arguments
     * @param array<string, string>                $env      added to this process's environment
     * @param Closure(array<string, mixed>): void   $onMessage
     * @param Closure(?int): void                   $onExit   told the exit code when it is known
     */
    public function __construct(
        private readonly array $command,
        private readonly string $cwd,
        private readonly array $env,
        private readonly Closure $onMessage,
        private readonly Closure $onExit,
    ) {
        $this->framing = new Framing();
    }

    public function start(): void
    {
        $pipes = [];
        $problem = null;
        // proc_open() warns as well as answering false; the warning is the only place a reason is.
        set_error_handler(static function (int $no, string $message) use (&$problem): bool {
            $problem = $message;

            return true;
        });

        try {
            $process = proc_open(
                $this->command,
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                $this->cwd,
                [...self::environment(), ...$this->env],
            );
        } finally {
            restore_error_handler();
        }

        if (!is_resource($process)) {
            throw new RuntimeException('Could not start ' . $this->command[0] . ($problem !== null ? ": {$problem}" : ''));
        }

        $this->process = $process;
        [$this->stdin, $this->stdout, $this->stderr] = [$pipes[0], $pipes[1], $pipes[2]];
        stream_set_blocking($this->stdin, false);
        stream_set_blocking($this->stdout, false);
        stream_set_blocking($this->stderr, false);

        $status = proc_get_status($process);
        $this->pid = is_int($status['pid'] ?? null) ? $status['pid'] : null;

        // An array command execs directly: a missing binary is a child that already exited (127),
        // not a warning. Say it here, with whatever the child managed to print.
        if (!$status['running']) {
            $this->drainStderr();
            $code = (int) ($status['exitcode'] ?? -1);
            $this->teardown();

            throw new RuntimeException(
                $this->command[0] . " exited with code {$code} as it started"
                . ($this->stderrBuffer !== '' ? ': ' . trim(substr($this->stderrBuffer, -600)) : ''),
            );
        }

        $loop = Loop::get();
        $this->stdoutWatcher = $loop->onReadable($this->stdout, fn () => $this->readStdout());
        $this->stderrWatcher = $loop->onReadable($this->stderr, fn () => $this->readStderr());
    }

    public function pid(): ?int
    {
        return $this->pid;
    }

    public function running(): bool
    {
        return $this->process !== null && !$this->exited;
    }

    public function exitCode(): ?int
    {
        return $this->exitCode;
    }

    /** What the server wrote to stderr, the last 64KB. */
    public function stderr(): string
    {
        return $this->stderrBuffer;
    }

    /** @param array<string, mixed> $message */
    public function send(array $message): void
    {
        if (!$this->running() || !is_resource($this->stdin)) {
            throw new LspError('The language server is not running');
        }

        $this->writeBuffer .= Framing::encode($message);
        $this->flush();
    }

    /**
     * Stop the server: stdin closed, then SIGTERM, then SIGKILL, waiting up to $timeout between.
     * The LSP `shutdown`/`exit` handshake is the client's job and comes before this.
     */
    public function close(float $timeout = 2.0): void
    {
        if ($this->process === null) {
            return;
        }

        // Whatever is queued (the `exit` notification, usually) gets one try to leave first.
        if ($this->writeBuffer !== '' && is_resource($this->stdin)) {
            stream_set_blocking($this->stdin, true);
            $this->writeQuietly($this->writeBuffer);
            $this->writeBuffer = '';
        }

        $this->cancelWatcher($this->stdinWatcher);

        if (is_resource($this->stdin)) {
            fclose($this->stdin);
            $this->stdin = null;
        }

        $deadline = microtime(true) + $timeout;
        $terminated = false;

        while ($this->processRunning()) {
            if (microtime(true) >= $deadline) {
                if ($terminated) {
                    break;
                }

                $this->signal(15);
                $terminated = true;
                $deadline = microtime(true) + $timeout;
            }

            self::pause(0.05);
        }

        // SIGKILL for a server that ignored SIGTERM; SIGTERM for children of a wrapper (`npx`,
        // a shell script) that would otherwise outlive the process that started them.
        $this->signal($this->processRunning() ? 9 : 15);
        $this->markExited();
        $this->teardown();
    }

    // ---- writing --------------------------------------------------------------------------------

    private function flush(): void
    {
        if ($this->writeBuffer === '' || !is_resource($this->stdin)) {
            $this->cancelWatcher($this->stdinWatcher);

            return;
        }

        $written = $this->writeQuietly($this->writeBuffer);

        if ($written === false) {
            // A broken pipe: the server is gone, and the reader will see EOF and say so.
            $this->writeBuffer = '';
            $this->cancelWatcher($this->stdinWatcher);

            return;
        }

        $this->writeBuffer = (string) substr($this->writeBuffer, $written);

        if ($this->writeBuffer === '') {
            $this->cancelWatcher($this->stdinWatcher);
        } elseif ($this->stdinWatcher === null) {
            $this->stdinWatcher = Loop::get()->onWritable($this->stdin, fn () => $this->flush());
        }
    }

    /** fwrite() that answers false instead of warning on a broken pipe (EPIPE). */
    private function writeQuietly(string $bytes): int|false
    {
        if (!is_resource($this->stdin)) {
            return false;
        }

        set_error_handler(static fn (): bool => true);

        try {
            return fwrite($this->stdin, $bytes);
        } finally {
            restore_error_handler();
        }
    }

    // ---- reading --------------------------------------------------------------------------------

    private function readStdout(): void
    {
        if (!is_resource($this->stdout)) {
            return;
        }

        $chunk = fread($this->stdout, 262144);

        if (is_string($chunk) && $chunk !== '') {
            foreach ($this->framing->push($chunk) as $message) {
                ($this->onMessage)($message);
            }
        }

        if (is_resource($this->stdout) && feof($this->stdout)) {
            $this->onGone();
        }
    }

    private function readStderr(): void
    {
        if (!is_resource($this->stderr)) {
            return;
        }

        $chunk = fread($this->stderr, 65536);

        if (is_string($chunk) && $chunk !== '') {
            $this->appendStderr($chunk);
        }

        if (feof($this->stderr)) {
            $this->cancelWatcher($this->stderrWatcher);
        }
    }

    private function drainStderr(): void
    {
        if (is_resource($this->stderr)) {
            $chunk = stream_get_contents($this->stderr);

            if (is_string($chunk) && $chunk !== '') {
                $this->appendStderr($chunk);
            }
        }
    }

    private function appendStderr(string $chunk): void
    {
        $this->stderrBuffer .= $chunk;

        if (strlen($this->stderrBuffer) > self::MAX_STDERR_BYTES) {
            $this->stderrBuffer = substr($this->stderrBuffer, -self::MAX_STDERR_BYTES);
        }
    }

    /** stdout reached EOF: the server exited or closed it, which for LSP is the same thing. */
    private function onGone(): void
    {
        $this->drainStderr();
        $this->signal(15);
        $this->markExited();
        $this->teardown();
    }

    // ---- stopping -------------------------------------------------------------------------------

    private function processRunning(): bool
    {
        if (!is_resource($this->process)) {
            return false;
        }

        $status = proc_get_status($this->process);

        if (!$status['running'] && $this->exitCode === null && ($status['exitcode'] ?? -1) >= 0) {
            $this->exitCode = (int) $status['exitcode'];
        }

        return $status['running'];
    }

    private function markExited(): void
    {
        if ($this->exited) {
            return;
        }

        $this->processRunning();
        $this->exited = true;
        ($this->onExit)($this->exitCode);
    }

    private function signal(int $signal): void
    {
        // Only while the child is unreaped: after proc_close() its pid may belong to someone else.
        if ($this->pid !== null && is_resource($this->process)) {
            Process::killTree($this->pid, $signal);
        }
    }

    private function cancelWatcher(?string &$watcher): void
    {
        if ($watcher !== null) {
            Loop::get()->cancel($watcher);
            $watcher = null;
        }
    }

    /** Watchers off before the pipes close: stream_select() drops a closed stream silently. */
    private function teardown(): void
    {
        $this->cancelWatcher($this->stdoutWatcher);
        $this->cancelWatcher($this->stderrWatcher);
        $this->cancelWatcher($this->stdinWatcher);

        foreach ([$this->stdin, $this->stdout, $this->stderr] as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }

        $this->stdin = $this->stdout = $this->stderr = null;

        if (is_resource($this->process)) {
            // proc_close() waits for the child; by now it was told to go, and SIGKILL is next if not.
            if ($this->processRunning()) {
                $this->signal(9);
            }

            proc_close($this->process);
        }

        $this->process = null;
    }

    private static function pause(float $seconds): void
    {
        if (Fiber::getCurrent() !== null) {
            Async::delay($seconds);
        } else {
            usleep((int) ($seconds * 1_000_000));
        }
    }

    /** @return array<string, string> */
    private static function environment(): array
    {
        $env = [];

        foreach (getenv() as $name => $value) {
            if (is_string($value)) {
                $env[(string) $name] = $value;
            }
        }

        return $env;
    }
}
