<?php

declare(strict_types=1);

namespace PigLsp\Test\Integration;

use PHPUnit\Framework\TestCase;
use Pig\Ai\TextContent;
use Pig\Async\Async;
use Pig\CodingAgent\Extensions\ExtensionApi;
use Pig\CodingAgent\Extensions\ExtensionLoader;
use Pig\CodingAgent\Extensions\Events\SessionShutdownEvent;
use Pig\CodingAgent\Extensions\Events\ToolResultEvent;
use Pig\CodingAgent\Extensions\ExtensionContext;
use PigLsp\Config;

/**
 * The question the package exists for, against a real server: a `Pay` interface with a WeChat and
 * an Alipay implementation, a call through the interface — which code can run there?
 *
 * Needs phpactor: `phpactor` on PATH, or PHPACTOR_BIN pointing at the executable or phpactor.phar.
 * Skipped without it. The extension is loaded as pig loads it and finds phpactor through its
 * built-in server table, so this also covers that table.
 */
final class PhpactorTest extends TestCase
{
    private static ?string $root = null;

    private static ?string $originalPath = null;

    private static ExtensionApi $api;

    private static ExtensionContext $ctx;

    public static function setUpBeforeClass(): void
    {
        $binary = getenv('PHPACTOR_BIN') ?: Config::which('phpactor');

        if (!is_string($binary) || $binary === '' || !is_file($binary)) {
            return;
        }

        self::$originalPath = (string) getenv('PATH');

        if (str_ends_with($binary, '.phar')) {
            // A phar is run by PHP; phpactor also starts itself again for diagnostics, by name,
            // so it has to be reachable as `phpactor` on PATH — as an installed phpactor is.
            $bin = sys_get_temp_dir() . '/pig-lsp-phpactor-bin-' . getmypid();
            @mkdir($bin, 0o755, true);
            file_put_contents($bin . '/phpactor', "#!/bin/sh\nexec " . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($binary) . " \"\$@\"\n");
            chmod($bin . '/phpactor', 0o755);
            putenv('PATH=' . $bin . PATH_SEPARATOR . self::$originalPath);
        } else {
            putenv('PATH=' . dirname($binary) . PATH_SEPARATOR . self::$originalPath);
        }

        $root = sys_get_temp_dir() . '/pig-lsp-phpactor-' . bin2hex(random_bytes(4));
        self::$root = $root;
        $files = [
            'composer.json' => '{"name": "demo/pay", "autoload": {"psr-4": {"App\\\\": "src/"}}}',
            'src/Pay/Pay.php' => "<?php\n\nnamespace App\\Pay;\n\ninterface Pay\n{\n    public function charge(int \$amount): string;\n}\n",
            'src/Pay/WechatPay.php' => "<?php\n\nnamespace App\\Pay;\n\nfinal class WechatPay implements Pay\n{\n    public function charge(int \$amount): string\n    {\n        return \"wechat:{\$amount}\";\n    }\n}\n",
            'src/Pay/AlipayPay.php' => "<?php\n\nnamespace App\\Pay;\n\nfinal class AlipayPay implements Pay\n{\n    public function charge(int \$amount): string\n    {\n        return \"alipay:{\$amount}\";\n    }\n}\n",
            'src/Checkout.php' => "<?php\n\nnamespace App;\n\nuse App\\Pay\\Pay;\n\nfinal class Checkout\n{\n    public function __construct(private Pay \$pay)\n    {\n    }\n\n    public function run(int \$金额): string\n    {\n        \$备注 = '支付'; return \$this->pay->charge(\$金额) . \$备注;\n    }\n}\n",
            'src/PayFactory.php' => "<?php\n\nnamespace App;\n\nuse App\\Pay\\AlipayPay;\nuse App\\Pay\\Pay;\nuse App\\Pay\\WechatPay;\n\nfinal class PayFactory\n{\n    public static function make(string \$channel): Pay\n    {\n        return match (\$channel) {\n            'wechat' => new WechatPay(),\n            'alipay' => new AlipayPay(),\n        };\n    }\n}\n",
        ];

        foreach ($files as $relative => $text) {
            @mkdir(dirname($root . '/' . $relative), 0o755, true);
            file_put_contents($root . '/' . $relative, $text);
        }

        [$loaded, $errors] = ExtensionLoader::load($root, cliPaths: [dirname(__DIR__, 2) . '/extensions/lsp.php'], home: $root . '/.home', discover: false);
        self::assertSame([], $errors);
        self::$api = $loaded[0]->api;
        self::$ctx = new ExtensionContext($root);
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$root === null) {
            return;
        }

        foreach (self::$api->handlers('session_shutdown') as $handler) {
            $handler(new SessionShutdownEvent(), self::$ctx);
        }

        exec('rm -rf ' . escapeshellarg(self::$root));
        putenv('PATH=' . self::$originalPath);
    }

    protected function setUp(): void
    {
        if (self::$root === null) {
            self::markTestSkipped('phpactor is not installed (put it on PATH, or set PHPACTOR_BIN to it or to phpactor.phar)');
        }
    }

    /** @param array<string, mixed> $params */
    private static function call(string $tool, array $params): string
    {
        foreach (self::$api->tools() as $candidate) {
            if ($candidate->name === $tool) {
                return Async::run(static fn () => ($candidate->execute)('id', $params, null, self::$ctx, null))->content[0]->text;
            }
        }

        self::fail("no tool {$tool}");
    }

    /**
     * phpactor answers implementations from its index, which it builds in the background after
     * starting; ask until the index has them (a minute at most, on a cold cache).
     *
     * @param array<string, mixed> $params
     */
    private static function callUntil(string $tool, array $params, string $expected): string
    {
        $deadline = microtime(true) + 90;

        do {
            try {
                $text = self::call($tool, $params);
            } catch (\Throwable $error) {
                $text = $error->getMessage(); // "still starting", or the index not there yet
            }

            if (str_contains($text, $expected)) {
                return $text;
            }

            Async::run(static fn () => Async::delay(1.0));
        } while (microtime(true) < $deadline);

        self::fail("never got `{$expected}`; last answer:\n{$text}");
    }

    public function testTheInterfaceHasBothImplementations(): void
    {
        $text = self::callUntil('lsp_implementation', ['path' => 'src/Pay/Pay.php', 'query' => 'Pay'], '2 implementations');

        self::assertStringContainsString('src/Pay/AlipayPay.php:5:13  final class AlipayPay implements Pay', $text);
        self::assertStringContainsString('src/Pay/WechatPay.php:5:13  final class WechatPay implements Pay', $text);
        self::assertStringContainsString('lsp_references on the interface name', $text);
    }

    public function testACallThroughTheInterfaceReachesBothMethods(): void
    {
        $text = self::callUntil('lsp_implementation', ['path' => 'src/Checkout.php', 'line' => 15, 'query' => 'charge'], '2 implementations of `charge`');

        self::assertStringContainsString('src/Pay/AlipayPay.php:7:5  public function charge(int $amount): string', $text);
        self::assertStringContainsString('src/Pay/WechatPay.php:7:5  public function charge(int $amount): string', $text);
    }

    public function testDefinitionOfTheCallIsTheInterfaceMethod(): void
    {
        // The call sits after Chinese text on its line: the column must be counted in characters.
        $text = self::callUntil('lsp_definition', ['path' => 'src/Checkout.php', 'line' => 15, 'query' => 'charge'], 'definition');

        self::assertStringContainsString("1 definition of `charge`:\nsrc/Pay/Pay.php:7:5  public function charge(int \$amount): string;", $text);
    }

    public function testReferencesToTheInterfaceFindTheFactory(): void
    {
        $text = self::callUntil('lsp_references', ['path' => 'src/Pay/Pay.php', 'query' => 'Pay'], 'PayFactory.php');

        self::assertStringContainsString('src/Checkout.php:9:41  public function __construct(private Pay $pay)', $text);
        self::assertStringContainsString('src/PayFactory.php:11:51  public static function make(string $channel): Pay', $text);
    }

    public function testRenamePreviewReachesEveryImplementation(): void
    {
        $text = self::callUntil('lsp_rename', ['path' => 'src/Pay/Pay.php', 'line' => 7, 'query' => 'charge', 'newName' => 'pay'], 'across 4 file(s)');

        self::assertStringContainsString('+ $备注 = \'支付\'; return $this->pay->pay($金额) . $备注;', $text);
        self::assertStringContainsString('NOT applied', $text);
    }

    public function testABrokenEditGetsItsErrorsAppended(): void
    {
        // The server is running by now (the tests above started it).
        self::callUntil('lsp_hover', ['path' => 'src/PayFactory.php', 'line' => 11, 'query' => 'make'], 'Hover');

        $path = self::$root . '/src/PayFactory.php';
        file_put_contents($path, str_replace('new AlipayPay()', 'new AliPayy()', (string) file_get_contents($path)));

        $deadline = microtime(true) + 30;

        do {
            $result = Async::run(static function () {
                foreach (self::$api->handlers('tool_result') as $handler) {
                    $answer = $handler(new ToolResultEvent('edit', 'c', ['path' => 'src/PayFactory.php'], [new TextContent('Edited.')]), self::$ctx);

                    if ($answer !== null) {
                        return $answer;
                    }
                }

                return null;
            });

            if ($result !== null) {
                break;
            }

            // A cold index can make the first check slower than the wait; touch and edit again.
            touch($path);
            file_put_contents($path, (string) file_get_contents($path) . "\n");
        } while (microtime(true) < $deadline);

        self::assertNotNull($result, 'no errors were appended');
        self::assertStringContainsString('⚠ LSP (php): 1 error in src/PayFactory.php after this change:', $result->content[1]->text);
        self::assertStringContainsString('src/PayFactory.php:15:29 error: Class "AliPayy" not found [phpactor]', $result->content[1]->text);
    }
}
