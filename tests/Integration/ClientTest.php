<?php

declare(strict_types=1);

namespace PigLsp\Test\Integration;

use PHPUnit\Framework\TestCase;
use Pig\Async\AbortController;
use Pig\Async\AbortError;
use Pig\Async\Async;
use PigLsp\LspClient;
use PigLsp\LspError;
use PigLsp\LspTimeoutError;
use PigLsp\Test\FakeProject;
use PigLsp\Text;

final class ClientTest extends TestCase
{
    private FakeProject $project;

    /** @var list<LspClient> */
    private array $clients = [];

    protected function setUp(): void
    {
        $this->project = new FakeProject();
    }

    protected function tearDown(): void
    {
        foreach ($this->clients as $client) {
            $client->shutdown();
        }

        $this->project->cleanup();
    }

    private function started(array $env = []): LspClient
    {
        $client = $this->project->client($env);
        $this->clients[] = $client;
        $client->start(10.0);

        return $client;
    }

    public function testHandshakeAndTheServersOwnRequests(): void
    {
        Async::run(function (): void {
            $client = $this->started();

            self::assertTrue($client->running());
            self::assertSame('fake-lsp 1.0', $client->serverName());
            self::assertTrue($client->supports('implementationProvider'));
            self::assertSame(Text::UTF16, $client->positionEncoding());

            // Give the fake a moment to send its requests and receive our answers.
            $client->request('textDocument/hover', ['textDocument' => ['uri' => 'file:///none'], 'position' => ['line' => 0, 'character' => 0]]);
            Async::delay(0.1);
        });

        $initialize = $this->project->receivedMethod('initialize')[0];
        self::assertTrue($initialize['params']['capabilities']['textDocument']['implementation']['linkSupport']);
        self::assertSame(['utf-16', 'utf-8'], $initialize['params']['capabilities']['general']['positionEncodings']);
        self::assertSame(Text::pathToUri($this->project->root), $initialize['params']['rootUri']);
        self::assertCount(1, $this->project->receivedMethod('initialized'));
        self::assertSame(['fake' => ['answer' => 42]], $this->project->receivedMethod('workspace/didChangeConfiguration')[0]['params']['settings']);

        $responses = [];
        foreach ($this->project->received() as $message) {
            if (!isset($message['method']) && isset($message['id'])) {
                $responses[$message['id']] = $message;
            }
        }

        self::assertSame([['answer' => 42]], $responses[1000]['result']);
        self::assertArrayHasKey('result', $responses[1001]);
        self::assertNull($responses[1001]['result']);
        self::assertSame(-32601, $responses[1002]['error']['code']);
    }

    public function testTheServerCanChooseUtf8(): void
    {
        Async::run(function (): void {
            self::assertSame(Text::UTF8, $this->started(['FAKE_LSP_ENCODING' => 'utf-8'])->positionEncoding());
        });
    }

    public function testARequestThatIsNotAnsweredTimesOutAndIsCancelled(): void
    {
        Async::run(function (): void {
            $client = $this->started(['FAKE_LSP_HANG_ON' => 'textDocument/definition']);
            $started = microtime(true);

            try {
                $client->request('textDocument/definition', ['textDocument' => ['uri' => 'file:///x'], 'position' => ['line' => 0, 'character' => 0]], 0.3);
                self::fail('expected a timeout');
            } catch (LspTimeoutError $error) {
                self::assertStringContainsString('did not answer textDocument/definition within 0.3s', $error->getMessage());
            }

            self::assertLessThan(2.0, microtime(true) - $started);
            // Still usable afterwards.
            self::assertNotNull($client->request('textDocument/hover', ['textDocument' => ['uri' => 'file:///x'], 'position' => ['line' => 0, 'character' => 0]]));
        });

        $cancel = $this->project->receivedMethod('$/cancelRequest');
        $definition = $this->project->receivedMethod('textDocument/definition');
        self::assertCount(1, $cancel);
        self::assertSame($definition[0]['id'], $cancel[0]['params']['id']);
    }

    public function testAnAbortCancelsTheRequest(): void
    {
        Async::run(function (): void {
            $client = $this->started(['FAKE_LSP_HANG_ON' => 'textDocument/references']);
            $controller = new AbortController();
            \Pig\Async\Loop::get()->delay(0.1, static fn () => $controller->abort());

            $this->expectException(AbortError::class);
            $client->request('textDocument/references', [], 10.0, $controller->signal);
        });
    }

    public function testACrashFailsWhatIsPendingWithTheServersLastWords(): void
    {
        $exits = [];

        Async::run(function () use (&$exits): void {
            $client = $this->started(['FAKE_LSP_CRASH_ON' => 'textDocument/hover']);
            $client->onExitListener(static function (?int $code) use (&$exits): void {
                $exits[] = $code;
            });

            try {
                $client->request('textDocument/hover', [], 5.0);
                self::fail('expected the crash to fail the request');
            } catch (LspError $error) {
                self::assertStringContainsString('exited', $error->getMessage());
                self::assertStringContainsString('crashing on textDocument/hover', $error->getMessage());
            }

            self::assertFalse($client->running());
            self::assertSame(3, $client->exitCode());

            $this->expectException(LspError::class);
            $client->request('textDocument/hover', []);
        });

        self::assertSame([3], $exits);
    }

    public function testAServerThatCannotStartSaysWhy(): void
    {
        Async::run(function (): void {
            try {
                $this->started(['FAKE_LSP_FAIL_START' => '1']);
                self::fail('expected the start to fail');
            } catch (LspError $error) {
                self::assertStringContainsString('refusing to start', $error->getMessage());
            }
        });
    }

    public function testDocumentsOpenOnceThenChangeWithRisingVersions(): void
    {
        $path = $this->project->write('a.fk', "one\n");

        Async::run(function () use ($path): void {
            $client = $this->started();
            $uri = Text::pathToUri($path);

            self::assertSame("one\n", $client->syncFromDisk($path, 'fake'));
            self::assertFalse($client->syncText($uri, "one\n", 'fake'), 'unchanged text is not resent');
            self::assertTrue($client->syncText($uri, "two\n", 'fake'));

            // Changed on disk behind the client's back (as bash would): refreshed before the next query.
            $this->project->write('a.fk', "three\n");
            $client->refreshOpenDocuments();
            self::assertSame("three\n", $client->documentText($uri));

            unlink($path);
            $client->refreshOpenDocuments();
            self::assertFalse($client->isOpen($uri));
            Async::delay(0.1);
        });

        self::assertCount(1, $this->project->receivedMethod('textDocument/didOpen'));
        $changes = $this->project->receivedMethod('textDocument/didChange');
        self::assertSame([2, 3], array_map(static fn (array $m): int => $m['params']['textDocument']['version'], $changes));
        self::assertSame("three\n", $changes[1]['params']['contentChanges'][0]['text']);
        self::assertCount(1, $this->project->receivedMethod('textDocument/didClose'));
    }

    public function testDiagnosticsAreAwaitedNotSleptFor(): void
    {
        $path = $this->project->write('b.fk', "fine\n");

        Async::run(function () use ($path): void {
            $client = $this->started(['FAKE_LSP_DIAG_DELAY' => '200']);
            $uri = Text::pathToUri($path);
            $client->syncFromDisk($path, 'fake');
            self::assertTrue($client->waitForDiagnostics($uri, 0, 3.0));
            self::assertSame([], $client->diagnostics($uri));

            $generation = $client->diagnosticGeneration($uri);
            $started = microtime(true);
            $client->syncText($uri, "x ERROR here\nWARN\n", 'fake');
            self::assertTrue($client->waitForDiagnostics($uri, $generation, 3.0));
            $waited = microtime(true) - $started;

            self::assertGreaterThan(0.15, $waited);
            self::assertLessThan(1.5, $waited);
            self::assertSame([1, 2], array_column($client->diagnostics($uri), 'severity'));

            // Nothing new coming: the wait ends at its limit, false.
            self::assertFalse($client->waitForDiagnostics($uri, $client->diagnosticGeneration($uri), 0.2));
        });
    }

    public function testASettleWindowWaitsOutAnEmptyFirstPublish(): void
    {
        $path = $this->project->write('c.fk', "fine\n");

        Async::run(function () use ($path): void {
            $client = $this->started(['FAKE_LSP_TWO_STEP' => '150']);
            $uri = Text::pathToUri($path);
            $client->syncFromDisk($path, 'fake');
            $client->waitForDiagnostics($uri, 0, 3.0, 0.4);

            $generation = $client->diagnosticGeneration($uri);
            $client->syncText($uri, "ERROR\n", 'fake');
            $client->waitForDiagnostics($uri, $generation, 3.0);
            self::assertSame([], $client->diagnostics($uri), 'without a settle window the clearing publish is taken');

            $generation = $client->diagnosticGeneration($uri);
            $client->syncText($uri, "ERROR again\n", 'fake');
            $started = microtime(true);
            $client->waitForDiagnostics($uri, $generation, 3.0, 0.4);
            self::assertCount(1, $client->diagnostics($uri));
            self::assertLessThan(1.5, microtime(true) - $started);
        });
    }

    public function testShutdownIsPolite(): void
    {
        Async::run(function (): void {
            $client = $this->started();
            $pid = $client->pid();
            $client->shutdown();

            self::assertFalse($client->running());
            self::assertFalse(posix_kill((int) $pid, 0) && !str_contains((string) @file_get_contents("/proc/{$pid}/status"), 'zombie'));
        });

        self::assertCount(1, $this->project->receivedMethod('shutdown'));
        self::assertCount(1, $this->project->receivedMethod('exit'));
    }

    public function testShutdownWorksOutsideACoroutine(): void
    {
        $client = null;
        Async::run(function () use (&$client): void {
            $client = $this->started();
        });

        $client->shutdown();
        self::assertFalse($client->running());
        self::assertCount(1, $this->project->receivedMethod('exit'));
    }
}
