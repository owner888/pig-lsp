<?php

declare(strict_types=1);

namespace PigLsp\Test\Integration;

use PHPUnit\Framework\TestCase;
use Pig\Async\Async;
use Pig\CodingAgent\CustomTools\CustomTool;
use Pig\CodingAgent\Extensions\ExtensionApi;
use Pig\CodingAgent\Extensions\ExtensionLoader;
use Pig\CodingAgent\Hooks\Events\SessionShutdownEvent;
use Pig\CodingAgent\Hooks\HookContext;
use PigLsp\Config;
use PigLsp\LspError;
use PigLsp\Test\AnsweringUi;
use PigLsp\Test\FakeProject;

/**
 * Asking to install a missing server, with a fake `composer` on PATH that installs a fake server
 * (`fakels`, the fake language server behind a shell script) into a directory that is not.
 */
final class InstallTest extends TestCase
{
    private FakeProject $project;

    private ExtensionApi $api;

    private AnsweringUi $ui;

    private HookContext $ctx;

    /** On PATH: the fake composer. */
    private string $bin;

    /** Not on PATH: where the fake composer installs `fakels`, and says so when asked. */
    private string $vendorBin;

    private string $path;

    protected function setUp(): void
    {
        $this->project = new FakeProject();
        $this->project->write('src/a.fk', "class A {\n    function run()\n}\n");
        file_put_contents($this->project->root . '/.pig/lsp.json', json_encode([
            'servers' => [
                'fake' => [
                    'candidates' => [['fakels']],
                    'installers' => [['binary' => 'fakels', 'with' => 'composer', 'command' => ['composer', 'global', 'require', 'fake/fakels']]],
                    'install' => 'Install fakels by hand.',
                    'env' => ['FAKE_LSP_LOG' => $this->project->log],
                    'extensions' => ['.fk'],
                    'languages' => ['fake'],
                ],
            ],
            'startWait' => 10000,
        ], JSON_UNESCAPED_SLASHES));

        $this->bin = $this->project->root . '/.tools/bin';
        $this->vendorBin = $this->project->root . '/.tools/composer/vendor/bin';
        mkdir($this->bin, 0o755, true);
        $this->composer($this->vendorBin);

        $this->path = (string) getenv('PATH');
        putenv('PATH=' . $this->bin . PATH_SEPARATOR . $this->path);

        [$loaded, $errors] = ExtensionLoader::load(
            $this->project->root,
            cliPaths: [dirname(__DIR__, 2) . '/extensions/lsp.php'],
            home: $this->project->root . '/.home',
            discover: false,
        );

        self::assertSame([], $errors);
        $this->api = $loaded[0]->api;
        $this->ui = new AnsweringUi();
        $this->ctx = new HookContext($this->project->root, ui: $this->ui, hasUi: true);
    }

    protected function tearDown(): void
    {
        foreach ($this->api->handlers('session_shutdown') as $handler) {
            $handler(new SessionShutdownEvent(), $this->ctx);
        }

        putenv('PATH=' . $this->path);
        exec('rm -rf ' . escapeshellarg((string) getenv('HOME') . '/.composer'));
        $this->project->cleanup();
    }

    public function testYesInstallsTheServerAndTheRequestGoesOn(): void
    {
        $this->ui->answers = [true];

        $text = $this->symbols();

        self::assertStringContainsString('class A', $text);
        self::assertStringContainsString('the user agreed and pig-lsp installed it: ' . $this->vendorBin . '/fakels', $text);
        self::assertStringContainsString($this->vendorBin . ' is not on PATH', $text);
        self::assertCount(1, $this->ui->prompts);
        self::assertSame('Install fakels?', $this->ui->prompts[0]['title']);
        self::assertStringContainsString('No fake language server is installed', $this->ui->prompts[0]['message']);
        self::assertStringContainsString('composer global require fake/fakels', $this->ui->prompts[0]['message']);
        self::assertStringContainsString('changes your global composer environment', $this->ui->prompts[0]['message']);
        self::assertContains('lsp: LSP: installing fakels…', $this->ui->statuses);
        self::assertSame(1, $this->installs());

        // The server started from the directory it was found in, and with it on its PATH.
        self::assertStringContainsString('fake: ' . $this->vendorBin . '/fakels', $this->lspReport());
        self::assertStringStartsWith($this->vendorBin . PATH_SEPARATOR, (new Config($this->project->root, null, [$this->vendorBin]))->env('fake')['PATH']);

        // Installed: the next call just works, and nobody is asked again.
        self::assertStringContainsString('class A', $this->symbols());
        self::assertCount(1, $this->ui->prompts);
    }

    public function testNoIsRememberedForTheSession(): void
    {
        $this->ui->answers = [false];

        $first = $this->failure();
        $second = $this->failure();

        self::assertCount(1, $this->ui->prompts);
        self::assertSame(0, $this->installs());

        foreach ([$first, $second] as $message) {
            self::assertStringContainsString('The user was asked whether to install fakels and declined for this session.', $message);
            self::assertStringContainsString('Do not install it yourself with bash', $message);
            self::assertStringContainsString('say the results are textual', $message);
            self::assertStringNotContainsString('composer global require', $message);
        }

        self::assertStringContainsString('fake: not installed (install declined this session — /lsp-install fake)', $this->lspReport());
    }

    public function testCallsArrivingWhileTheUserDecidesShareOneQuestion(): void
    {
        $this->ui->answers = [true];
        $this->ui->thinking = 0.3;

        $texts = Async::run(function (): array {
            $futures = [];

            foreach (['src/a.fk', 'src/a.fk', 'src/a.fk'] as $path) {
                $futures[] = Async::spawn(fn () => ($this->tool('lsp_symbols')->execute)('id', ['path' => $path], null, $this->ctx, null)->content[0]->text);
            }

            return array_map(static fn ($future) => $future->await(), $futures);
        });

        self::assertCount(1, $this->ui->prompts);
        self::assertSame(1, $this->installs());

        foreach ($texts as $text) {
            self::assertStringContainsString('class A', $text);
        }
    }

    public function testAFailedInstallIsReportedRememberedAndCanBeRetriedOnRequest(): void
    {
        touch($this->bin . '/fail');
        $this->ui->answers = [true, true];

        $message = $this->failure();

        self::assertStringContainsString('The user agreed to install fakels, but installing it failed: exit code 2', $message);
        self::assertStringContainsString('Your requirements could not be resolved', $message);
        self::assertStringContainsString('Do not install it yourself with bash', $message);
        self::assertTrue((bool) array_filter($this->ui->notices, static fn (string $n): bool => str_starts_with($n, '[error] pig-lsp: installing fakels failed')));
        self::assertStringContainsString('fake: not installed (install failed: exit code 2', $this->lspReport());

        // Failed is remembered: no second question from a tool…
        $this->failure();
        self::assertCount(1, $this->ui->prompts);

        // …but the user asking for it is asked again.
        unlink($this->bin . '/fail');
        Async::run(fn () => ($this->api->commands()['lsp-install']->handler)('fake', $this->ctx));
        self::assertCount(2, $this->ui->prompts);
        self::assertSame(2, $this->installs());
        self::assertStringContainsString('class A', $this->symbols());
    }

    public function testFindsTheServerInComposersUsualDirectoryWhenComposerWillNotSay(): void
    {
        $fallback = (string) getenv('HOME') . '/.composer/vendor/bin';
        $this->composer($fallback, answersBinDir: false);
        $this->ui->answers = [true];

        $text = $this->symbols();

        self::assertStringContainsString('class A', $text);
        self::assertStringContainsString('installed it: ' . $fallback . '/fakels', $text);
        self::assertStringContainsString($fallback . ' is not on PATH', $text);
    }

    public function testWithoutAUserNothingIsAskedOrInstalled(): void
    {
        $this->ctx = new HookContext($this->project->root, ui: $this->ui, hasUi: false);

        $message = $this->failure();

        self::assertStringContainsString('Nothing was installed: this session has no one to ask', $message);
        self::assertStringContainsString('Do not install it yourself with bash', $message);
        self::assertStringContainsString('Install fakels by hand.', $message);
        self::assertSame([], $this->ui->prompts);
        self::assertSame(0, $this->installs());
    }

    public function testWithoutTheInstallerTheUserIsToldOnceAndNothingIsAsked(): void
    {
        // PATH is only the tools directory, now without composer: the machine running the tests may
        // well have a real one, and it must not be offered.
        unlink($this->bin . '/composer');
        putenv('PATH=' . $this->bin);

        $first = $this->failure();
        $this->failure();

        self::assertStringContainsString('There is nothing pig-lsp can install it with', $first);
        self::assertStringContainsString('Do not install it yourself with bash', $first);
        self::assertSame([], $this->ui->prompts);
        self::assertCount(1, array_filter($this->ui->notices, static fn (string $n): bool => str_starts_with($n, '[warning] pig-lsp: No fake language server is installed')));
        self::assertStringNotContainsString('installable', $this->lspReport());
    }

    public function testLspShowsWhatCanBeInstalled(): void
    {
        self::assertStringContainsString('fake: not installed (installable: composer global require fake/fakels — /lsp-install fake)', $this->lspReport());
    }

    // ---- helpers --------------------------------------------------------------------------------

    /**
     * A fake composer: `global require` writes `fakels` into $target (or fails, when a `fail`
     * file sits next to it); `global config bin-dir --absolute` names $target, as composer does
     * after a line of chatter — unless it is not to answer.
     */
    private function composer(string $target, bool $answersBinDir = true): void
    {
        $server = sprintf("#!/bin/sh\nexec %s %s \"\$@\"\n", escapeshellarg(PHP_BINARY), escapeshellarg(FakeProject::fixture()));
        $script = <<<SH
            #!/bin/sh
            here="\$(cd "\$(dirname "\$0")" && pwd)"
            echo "\$*" >> "\$here/calls.log"
            case "\$*" in
              "global config bin-dir --absolute")
                [ {$this->flag($answersBinDir)} = 1 ] || exit 1
                echo "Changed current directory to /somewhere"
                echo {$this->quote($target)}
                ;;
              "global require "*)
                if [ -f "\$here/fail" ]; then
                  echo "Loading composer repositories with package information"
                  echo "Your requirements could not be resolved to an installable set of packages." >&2
                  exit 2
                fi
                mkdir -p {$this->quote($target)}
                cat > {$this->quote($target . '/fakels')} <<'SERVER'
            {$server}SERVER
                chmod +x {$this->quote($target . '/fakels')}
                ;;
              *) exit 1 ;;
            esac
            SH;
        file_put_contents($this->bin . '/composer', $script . "\n");
        chmod($this->bin . '/composer', 0o755);
    }

    private function flag(bool $value): string
    {
        return $value ? '1' : '0';
    }

    private function quote(string $value): string
    {
        return escapeshellarg($value);
    }

    /** How many times the fake composer was asked to install. */
    private function installs(): int
    {
        $log = $this->bin . '/calls.log';

        return is_file($log) ? substr_count((string) file_get_contents($log), 'global require') : 0;
    }

    private function tool(string $name): CustomTool
    {
        foreach ($this->api->tools() as $tool) {
            if ($tool->name === $name) {
                return $tool;
            }
        }

        self::fail("no tool {$name}");
    }

    private function symbols(): string
    {
        return Async::run(fn () => ($this->tool('lsp_symbols')->execute)('id', ['path' => 'src/a.fk'], null, $this->ctx, null))->content[0]->text;
    }

    /** The message a call that cannot be answered gives the model. */
    private function failure(): string
    {
        try {
            $this->symbols();
        } catch (LspError $error) {
            return $error->getMessage();
        }

        self::fail('the call succeeded');
    }

    private function lspReport(): string
    {
        ($this->api->commands()['lsp']->handler)('', $this->ctx);

        return (string) end($this->ui->notices);
    }
}
