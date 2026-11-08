<?php

declare(strict_types=1);

namespace PigLsp\Test\Integration;

use PHPUnit\Framework\TestCase;
use Pig\Ai\TextContent;
use Pig\Async\Async;
use Pig\CodingAgent\CustomTools\CustomTool;
use Pig\CodingAgent\Extensions\ExtensionApi;
use Pig\CodingAgent\Extensions\ExtensionLoader;
use Pig\CodingAgent\Extensions\Events\SessionShutdownEvent;
use Pig\CodingAgent\Extensions\Events\ToolResultEvent;
use Pig\CodingAgent\Extensions\ExtensionContext;
use Pig\CodingAgent\Extensions\Results\ToolResultEventResult;
use Pig\CodingAgent\Test\Extensions\NoticingUi;
use PigLsp\Test\FakeProject;

/** The package as pig loads it: the entry file through ExtensionLoader, then its hooks and commands. */
final class ExtensionTest extends TestCase
{
    private FakeProject $project;

    private ExtensionApi $api;

    private NoticingUi $ui;

    private ExtensionContext $ctx;

    protected function setUp(): void
    {
        $this->project = new FakeProject();
        $this->project->write('src/a.fk', "class A {\n    function run()\n}\n");

        [$loaded, $errors] = ExtensionLoader::load(
            $this->project->root,
            cliPaths: [dirname(__DIR__, 2) . '/extensions/lsp.php'],
            home: $this->project->root . '/.home',
            discover: false,
        );

        self::assertSame([], $errors);
        self::assertCount(1, $loaded);
        $this->api = $loaded[0]->api;
        $this->ui = new NoticingUi();
        $this->ctx = new ExtensionContext($this->project->root, ui: $this->ui, hasUi: true);
    }

    protected function tearDown(): void
    {
        foreach ($this->api->handlers('session_shutdown') as $handler) {
            $handler(new SessionShutdownEvent(), $this->ctx);
        }

        $this->project->cleanup();
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

    private function edit(string $tool, string $path): ?ToolResultEventResult
    {
        $result = null;

        foreach ($this->api->handlers('tool_result') as $handler) {
            $result = $handler(new ToolResultEvent($tool, 'call-1', ['path' => $path], [new TextContent('Edited.')]), $this->ctx) ?? $result;
        }

        return $result;
    }

    public function testEverythingIsRegistered(): void
    {
        $names = array_map(static fn (CustomTool $tool): string => $tool->name, $this->api->tools());

        self::assertSame(
            ['lsp_definition', 'lsp_references', 'lsp_implementation', 'lsp_hover', 'lsp_symbols', 'lsp_diagnostics', 'lsp_rename', 'lsp_code_actions'],
            $names,
        );
        self::assertArrayHasKey('lsp', $this->api->commands());
        self::assertArrayHasKey('lsp-restart', $this->api->commands());
        self::assertNotEmpty($this->api->handlers('tool_result'));
        self::assertSame('safe', $this->tool('lsp_references')->replay->value);
    }

    public function testAToolRunsThroughPigsInterface(): void
    {
        $result = Async::run(fn () => ($this->tool('lsp_symbols')->execute)('id', ['path' => 'src/a.fk'], null, $this->ctx, null));

        self::assertInstanceOf(TextContent::class, $result->content[0]);
        self::assertStringContainsString('class A', $result->content[0]->text);
    }

    public function testAnEditGetsItsErrorsAppendedWhileTheServerRuns(): void
    {
        Async::run(function (): void {
            // Nothing running yet: an edit is left alone and nothing is started.
            self::assertNull($this->edit('edit', 'src/a.fk'));
            self::assertSame([], $this->project->receivedMethod('initialize'));

            ($this->tool('lsp_hover')->execute)('id', ['path' => 'src/a.fk', 'query' => 'A'], null, $this->ctx, null);

            $this->project->write('src/a.fk', "class A {\n    ERROR here\n    WARN only\n}\n");
            $result = $this->edit('edit', 'src/a.fk');

            self::assertNotNull($result);
            self::assertSame('Edited.', $result->content[0]->text);
            self::assertSame("⚠ LSP (fake): 1 error in src/a.fk after this change:\nsrc/a.fk:2:5 error: found ERROR [fake]", $result->content[1]->text);

            // Fixed: nothing appended.
            $this->project->write('src/a.fk', "class A {\n}\n");
            self::assertNull($this->edit('write', 'src/a.fk'));

            // Reading a new file opens it on the running server.
            $this->project->write('src/b.fk', "class B {}\n");
            self::assertNull($this->edit('read', 'src/b.fk'));
            Async::delay(0.2); // for the fake to log it
        });

        $opened = array_map(static fn (array $m): string => basename($m['params']['textDocument']['uri']), $this->project->receivedMethod('textDocument/didOpen'));
        self::assertSame(['a.fk', 'b.fk'], $opened);
        self::assertNotEmpty($this->project->receivedMethod('textDocument/didSave'));
    }

    public function testErrorsAreCappedAndCanBeTurnedOff(): void
    {
        Async::run(function (): void {
            ($this->tool('lsp_hover')->execute)('id', ['path' => 'src/a.fk', 'query' => 'A'], null, $this->ctx, null);

            $this->project->write('src/a.fk', str_repeat("ERROR\n", 14));
            $text = $this->edit('edit', 'src/a.fk')->content[1]->text;

            self::assertStringContainsString('14 errors', $text);
            self::assertSame(10, substr_count($text, 'error: found ERROR'));
            self::assertStringContainsString('… and 4 more.', $text);
        });

        $this->project->writeConfig(options: ['autoInjectDiagnostics' => false]);
        ($this->api->commands()['lsp-restart']->handler)('', $this->ctx);

        Async::run(function (): void {
            ($this->tool('lsp_hover')->execute)('id', ['path' => 'src/a.fk', 'query' => 'ERROR', 'line' => 1], null, $this->ctx, null);
            $this->project->write('src/a.fk', "ERROR\nERROR\n");
            self::assertNull($this->edit('edit', 'src/a.fk'));
        });
    }

    public function testStatusAndRestartCommands(): void
    {
        Async::run(fn () => ($this->tool('lsp_hover')->execute)('id', ['path' => 'src/a.fk', 'query' => 'A'], null, $this->ctx, null));

        ($this->api->commands()['lsp']->handler)('', $this->ctx);
        $status = end($this->ui->notices);
        self::assertStringContainsString('fake  running  fake-lsp 1.0  pid', $status);
        self::assertStringContainsString('root: ' . $this->project->root, $status);
        self::assertStringContainsString('Config: .pig/lsp.json', $status);

        ($this->api->commands()['lsp-restart']->handler)('fake', $this->ctx);
        self::assertStringContainsString('Stopped 1 language server(s) for fake', end($this->ui->notices));
        self::assertCount(1, $this->project->receivedMethod('exit'));

        ($this->api->commands()['lsp']->handler)('', $this->ctx);
        self::assertStringContainsString('No language server started yet', end($this->ui->notices));
    }

    public function testSessionShutdownStopsTheServers(): void
    {
        Async::run(fn () => ($this->tool('lsp_hover')->execute)('id', ['path' => 'src/a.fk', 'query' => 'A'], null, $this->ctx, null));

        foreach ($this->api->handlers('session_shutdown') as $handler) {
            $handler(new SessionShutdownEvent(), $this->ctx);
        }

        self::assertCount(1, $this->project->receivedMethod('exit'));
    }
}
