<?php

declare(strict_types=1);

namespace PigLsp\Test\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pig\Async\Async;
use PigLsp\LspError;
use PigLsp\LspUnavailable;
use PigLsp\Test\FakeProject;

/** Each tool end to end: input → server request → text the model reads. */
final class ToolsTest extends TestCase
{
    private const string PAY = <<<'TXT'
        interface Pay {
            function charge(amount)
        }
        class WechatPay implements Pay {
            function charge(amount)
        }
        class AlipayPay implements Pay {
            function charge(amount)
        }
        TXT;

    private FakeProject $project;

    protected function setUp(): void
    {
        $this->project = new FakeProject();
        $this->project->write('src/pay.fk', self::PAY);
        $this->project->write('src/checkout.fk', "// 支付 😀\nlet x = pay.charge(1) + pay.charge(2)\n");
    }

    protected function tearDown(): void
    {
        $this->project->cleanup();
    }

    /** @param array<string, string> $env */
    private function ask(\Closure $fn, array $env = []): mixed
    {
        if ($env !== []) {
            $this->project->writeConfig($env);
        }

        $tools = $this->project->tools();

        return Async::run(fn () => $fn($tools, $this->project->root));
    }

    /** @return iterable<string, array{0: string}> */
    public static function encodings(): iterable
    {
        yield 'utf-16' => ['utf-16'];
        yield 'utf-8' => ['utf-8'];
    }

    #[DataProvider('encodings')]
    public function testDefinitionAtACallSiteAfterWideCharacters(string $encoding): void
    {
        $text = $this->ask(
            static fn ($tools, $cwd) => $tools->definition(['path' => 'src/checkout.fk', 'line' => 2, 'query' => 'charge', 'character' => 30], $cwd),
            ['FAKE_LSP_ENCODING' => $encoding],
        );

        // The fake answers the position it was asked: so the column survived both conversions.
        $second = mb_strrpos('let x = pay.charge(1) + pay.charge(2)', 'charge') + 1;
        self::assertStringContainsString("1 definition of `charge`:\nsrc/checkout.fk:2:{$second}  let x = pay.charge(1) + pay.charge(2)", $text);

        $asked = $this->project->receivedMethod('textDocument/definition')[0]['params']['position'];
        self::assertSame(['line' => 1, 'character' => $second - 1], $asked);
    }

    public function testAColumnAfterAnEmojiIsTwoUnitsInUtf16(): void
    {
        $this->project->write('src/emoji.fk', "😀 pay\n");
        $text = $this->ask(static fn ($tools, $cwd) => $tools->hover(['path' => 'src/emoji.fk', 'line' => 1, 'query' => 'pay'], $cwd));

        // Code point 2, UTF-16 unit 3.
        self::assertStringContainsString('fake hover 0:3 `pay`', $text);
        self::assertStringContainsString('Hover at src/emoji.fk:1:3', $text);
    }

    public function testReferencesWithTheirLines(): void
    {
        $text = $this->ask(static fn ($tools, $cwd) => $tools->references(['path' => 'src/pay.fk', 'line' => 2, 'query' => 'charge'], $cwd));

        self::assertStringContainsString('2 references of `charge`:', $text);
        self::assertStringContainsString('src/pay.fk:1:1  interface Pay {', $text);
        self::assertStringContainsString('src/pay.fk:2:14  function charge(amount)', $text);
        self::assertFalse($this->project->receivedMethod('textDocument/references')[0]['params']['context']['includeDeclaration']);
    }

    public function testImplementationsAndHowToTellWhichRuns(): void
    {
        $text = $this->ask(static fn ($tools, $cwd) => $tools->implementation(['path' => 'src/pay.fk', 'query' => 'Pay'], $cwd));

        self::assertStringContainsString('2 implementations of `Pay`:', $text);
        self::assertStringContainsString('src/pay.fk:4:7  class WechatPay implements Pay {', $text);
        self::assertStringContainsString('src/pay.fk:7:7  class AlipayPay implements Pay {', $text);
        self::assertStringContainsString('lsp_references on the interface name', $text);
    }

    public function testAServerWithoutImplementationSaysWhatToDoInstead(): void
    {
        $this->expectException(LspError::class);
        $this->expectExceptionMessage('does not support implementation lookups. Use lsp_references');

        $this->ask(static fn ($tools, $cwd) => $tools->implementation(['path' => 'src/pay.fk', 'query' => 'Pay'], $cwd), ['FAKE_LSP_NO_IMPLEMENTATION' => '1']);
    }

    public function testQueryAloneUsesTheDeclarationInTheFile(): void
    {
        $text = $this->ask(static fn ($tools, $cwd) => $tools->definition(['path' => 'src/pay.fk', 'query' => 'AlipayPay'], $cwd));

        self::assertStringContainsString('src/pay.fk:7:7  class AlipayPay implements Pay {', $text);
    }

    public function testSeveralDeclarationsOfANameAreSaid(): void
    {
        $text = $this->ask(static fn ($tools, $cwd) => $tools->hover(['path' => 'src/pay.fk', 'query' => 'charge'], $cwd));

        self::assertStringContainsString('Several declarations match; used Pay::charge (line 2). Others: WechatPay::charge (line 5), AlipayPay::charge (line 8)', $text);

        $qualified = $this->ask(static fn ($tools, $cwd) => $tools->hover(['path' => 'src/pay.fk', 'query' => 'AlipayPay::charge'], $cwd));
        self::assertStringContainsString('Hover at src/pay.fk:8:14', $qualified);
        self::assertStringNotContainsString('Several', $qualified);
    }

    public function testOutlineAndWorkspaceSearch(): void
    {
        [$outline, $search] = $this->ask(static fn ($tools, $cwd) => [
            $tools->symbols(['path' => 'src/pay.fk'], $cwd),
            $tools->symbols(['query' => 'Pay', 'language' => 'fake'], $cwd),
        ]);

        self::assertStringContainsString('6 symbol(s) in src/pay.fk:', $outline);
        self::assertStringContainsString("    1  interface Pay\n    2    method charge", $outline);
        self::assertStringContainsString('src/pay.fk:4:7  class WechatPay', $search);
    }

    public function testDiagnosticsForAFileAndForAll(): void
    {
        $this->project->write('src/bad.fk', "ok\n  ERROR one\nWARN two\n");

        [$file, $errorsOnly, $all] = $this->ask(static fn ($tools, $cwd) => [
            $tools->diagnostics(['path' => 'src/bad.fk'], $cwd),
            $tools->diagnostics(['path' => 'src/bad.fk', 'severity' => 'error'], $cwd),
            $tools->diagnostics(['path' => '*'], $cwd),
        ]);

        self::assertSame("src/bad.fk:2:3 error: found ERROR [fake]\nsrc/bad.fk:3:1 warning: found WARN [fake]", $file);
        self::assertSame('src/bad.fk:2:3 error: found ERROR [fake]', $errorsOnly);
        self::assertStringContainsString('src/bad.fk:2:3 error', $all);
    }

    public function testRenameIsAPreviewOnly(): void
    {
        $text = $this->ask(static fn ($tools, $cwd) => $tools->rename(['path' => 'src/pay.fk', 'line' => 1, 'query' => 'Pay', 'newName' => 'Payment'], $cwd));

        self::assertStringContainsString('Rename to `Payment` at src/pay.fk:1:11: 3 edit(s) across 1 file(s) — NOT applied.', $text);
        self::assertStringContainsString("  4: - class WechatPay implements Pay {\n     + class WechatPay implements Payment {", $text);
        self::assertSame(self::PAY, file_get_contents($this->project->root . '/src/pay.fk'));
    }

    public function testCodeActionsListedWithTheirEdits(): void
    {
        $this->project->write('src/bad.fk', "ok\n  ERROR one\n");
        $text = $this->ask(static fn ($tools, $cwd) => $tools->codeActions(['path' => 'src/bad.fk', 'line' => 2, 'query' => 'ERROR'], $cwd));

        self::assertStringContainsString('1. Fix the ERROR [quickfix] (preferred)', $text);
        self::assertStringContainsString('+ fixedERROR one', $text);
        self::assertStringContainsString('2. Run a server command [refactor] — the server applies this one itself', $text);
        $asked = $this->project->receivedMethod('textDocument/codeAction')[0]['params'];
        self::assertCount(1, $asked['context']['diagnostics']);
    }

    public function testAFileChangedOnDiskIsResyncedBeforeTheQuery(): void
    {
        $text = $this->ask(function ($tools, $cwd) {
            $tools->hover(['path' => 'src/pay.fk', 'query' => 'Pay'], $cwd);
            $this->project->write('src/pay.fk', "\n\n" . self::PAY);

            return $tools->hover(['path' => 'src/pay.fk', 'query' => 'Pay'], $cwd);
        });

        self::assertStringContainsString('Hover at src/pay.fk:3:', $text);
        self::assertSame(2, $this->project->receivedMethod('textDocument/didChange')[0]['params']['textDocument']['version']);
    }

    public function testUnknownFilesAndMissingServersAreExplained(): void
    {
        $this->project->write('notes.txt', 'x');
        $this->project->write('main.dart', 'void main() {}');

        $this->ask(function ($tools, $cwd): void {
            try {
                $tools->hover(['path' => 'notes.txt', 'line' => 1, 'query' => 'x'], $cwd);
                self::fail('expected no server');
            } catch (LspUnavailable $error) {
                self::assertStringContainsString('No language server is known for notes.txt', $error->getMessage());
            }

            try {
                $tools->hover(['path' => 'nope.fk', 'line' => 1, 'query' => 'x'], $cwd);
                self::fail('expected no such file');
            } catch (LspError $error) {
                self::assertStringContainsString('No such file', $error->getMessage());
            }
        });

        $this->project->writeConfig(options: ['servers' => ['dart' => ['command' => 'no-such-dart-binary']]]);

        $this->expectException(LspUnavailable::class);
        $this->expectExceptionMessage('Install the Dart SDK');
        Async::run(fn () => $this->project->tools()->hover(['path' => 'main.dart', 'line' => 1, 'query' => 'main'], $this->project->root));
    }

    public function testAServerThatFailsToStartIsReportedAndRetriedLater(): void
    {
        $this->project->writeConfig(['FAKE_LSP_FAIL_START' => '1']);
        $manager = $this->project->manager();
        $tools = $this->project->tools($manager);

        Async::run(function () use ($tools): void {
            try {
                $tools->hover(['path' => 'src/pay.fk', 'query' => 'Pay'], $this->project->root);
                self::fail('expected a failed start');
            } catch (LspUnavailable $error) {
                self::assertStringContainsString('could not start', $error->getMessage());
                self::assertStringContainsString('refusing to start', $error->getMessage());
            }

            try {
                $tools->hover(['path' => 'src/pay.fk', 'query' => 'Pay'], $this->project->root);
                self::fail('expected the backoff');
            } catch (LspUnavailable $error) {
                self::assertStringContainsString('will be restarted in', $error->getMessage());
            }
        });

        self::assertSame('failed', $manager->status()[0]['state'] === 'stopped' ? 'failed' : $manager->status()[0]['state']);
        self::assertStringContainsString('refusing to start', (string) $manager->status()[0]['error']);
    }

    public function testACrashedServerIsRestartedOnTheNextQuery(): void
    {
        $this->project->writeConfig(['FAKE_LSP_CRASH_ON' => 'textDocument/references']);
        $manager = $this->project->manager();
        $tools = $this->project->tools($manager);

        Async::run(function () use ($tools, $manager): void {
            $first = $manager->runningClients();
            self::assertSame([], $first);
            $tools->hover(['path' => 'src/pay.fk', 'query' => 'Pay'], $this->project->root);
            $pid = $manager->runningClients()[0]->pid();

            try {
                $tools->references(['path' => 'src/pay.fk', 'query' => 'Pay'], $this->project->root);
                self::fail('expected the crash');
            } catch (LspError $error) {
                self::assertStringContainsString('exited (code 3)', $error->getMessage());
            }

            self::assertSame([], $manager->runningClients());
            Async::delay(1.1); // the first restart waits a second

            $text = $tools->hover(['path' => 'src/pay.fk', 'query' => 'Pay'], $this->project->root);
            self::assertStringContainsString('fake hover', $text);
            self::assertNotSame($pid, $manager->runningClients()[0]->pid());
        });
    }

    public function testASlowStartIsWaitedForUpToALimit(): void
    {
        $this->project->writeConfig(['FAKE_LSP_START_DELAY' => '1500'], ['startWait' => 300]);
        $manager = $this->project->manager();
        $tools = $this->project->tools($manager);

        Async::run(function () use ($tools): void {
            try {
                $tools->hover(['path' => 'src/pay.fk', 'query' => 'Pay'], $this->project->root);
                self::fail('expected still starting');
            } catch (LspUnavailable $error) {
                self::assertStringContainsString('still starting', $error->getMessage());
            }

            Async::delay(1.6);
            self::assertStringContainsString('fake hover', $tools->hover(['path' => 'src/pay.fk', 'query' => 'Pay'], $this->project->root));
        });
    }
}
