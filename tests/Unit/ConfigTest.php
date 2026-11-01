<?php

declare(strict_types=1);

namespace PigLsp\Test\Unit;

use PHPUnit\Framework\TestCase;
use PigLsp\Config;

final class ConfigTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/pig-lsp-config-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/.pig', 0o755, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    public function testLanguagesByExtension(): void
    {
        $config = new Config($this->root);

        self::assertSame('php', $config->languageFor('/x/A.php'));
        self::assertSame('dart', $config->languageFor('/x/main.dart'));
        self::assertSame('typescriptreact', $config->languageFor('/x/App.TSX'));
        self::assertNull($config->languageFor('/x/README'));
        self::assertSame('typescript', $config->serverKeyForLanguage('javascriptreact'));
        self::assertSame('php', $config->serverKeyForLanguage('php'));
        self::assertNull($config->serverKeyForLanguage('cobol'));
    }

    public function testLaterSourcesOverrideEarlierOnes(): void
    {
        file_put_contents($this->root . '/.pi-lsp.json', json_encode([
            'servers' => ['php' => ['command' => 'intelephense', 'args' => ['--stdio']]],
            'autoInjectDiagnostics' => ['php'],
            'maxDiagnostics' => 5,
        ]));
        file_put_contents($this->root . '/.pig/lsp.json', json_encode(['maxDiagnostics' => 7]));

        $config = new Config($this->root, ['maxDiagnostics' => 3, 'diagnosticsWait' => 1500]);

        self::assertSame(['settings (lsp)', '.pi-lsp.json', '.pig/lsp.json'], $config->sources);
        self::assertSame(7, $config->maxDiagnostics());
        self::assertSame(1.5, $config->diagnosticsWait());
        self::assertSame('intelephense', $config->server('php')['command']);
        self::assertTrue($config->autoDiagnostics('php'));
        self::assertFalse($config->autoDiagnostics('dart'));
    }

    public function testAServerConfiguredByLanguageIdLandsOnItsServer(): void
    {
        file_put_contents($this->root . '/.pig/lsp.json', json_encode(['servers' => ['typescriptreact' => ['settings' => ['a' => 1]]]]));

        self::assertSame(['a' => 1], (new Config($this->root))->settings('typescript'));
    }

    public function testANewServerWithItsOwnExtensions(): void
    {
        file_put_contents($this->root . '/.pig/lsp.json', json_encode(['servers' => ['zig' => ['command' => PHP_BINARY, 'extensions' => ['.zig', 'zon']]]]));
        $config = new Config($this->root);

        self::assertSame('zig', $config->languageFor('/x/build.zon'));
        self::assertSame([PHP_BINARY], $config->command('zig'));
    }

    public function testADisabledServerIsNotOffered(): void
    {
        file_put_contents($this->root . '/.pig/lsp.json', json_encode(['servers' => ['java' => ['disabled' => true]]]));
        $config = new Config($this->root);

        self::assertNull($config->serverKeyForLanguage('java'));
        self::assertNotContains('java', $config->serverKeys());
    }

    public function testAMissingServerGetsAnInstallHint(): void
    {
        file_put_contents($this->root . '/.pig/lsp.json', json_encode(['servers' => ['dart' => ['command' => 'definitely-not-installed-dart']]]));
        $config = new Config($this->root);

        self::assertNull($config->command('dart'));
        self::assertStringContainsString('definitely-not-installed-dart', $config->installHint('dart'));
        self::assertStringContainsString('Dart SDK', $config->installHint('dart'));
        self::assertStringContainsString('phpactor', (new Config($this->root))->installHint('php'));
    }

    public function testAnInstallIsOfferedOnlyWithItsInstallerAtHand(): void
    {
        $bin = $this->root . '/bin';
        mkdir($bin);
        $path = (string) getenv('PATH');

        foreach (['composer', 'npm'] as $tool) {
            file_put_contents("{$bin}/{$tool}", "#!/bin/sh\n");
            chmod("{$bin}/{$tool}", 0o755);
        }

        try {
            putenv("PATH={$bin}");
            $config = new Config($this->root);

            // phpactor first; intelephense only when there is no composer to install phpactor with.
            self::assertSame(
                ['binary' => 'phpactor', 'with' => 'composer', 'command' => ["{$bin}/composer", 'global', 'require', 'phpactor/phpactor'], 'display' => 'composer global require phpactor/phpactor'],
                $config->installOption('php'),
            );
            self::assertSame(['npm', 'install', '-g', 'pyright'], explode(' ', $config->installOption('python')['display'] ?? ''));
            self::assertNull($config->installOption('go'), 'no go on PATH');
            self::assertNull($config->installOption('dart'), 'nothing structured to run');

            unlink("{$bin}/composer");
            self::assertSame('intelephense', (new Config($this->root))->installOption('php')['binary'] ?? null);

            // The developer's own command is the developer's to install.
            file_put_contents($this->root . '/.pig/lsp.json', json_encode(['servers' => ['php' => ['command' => 'my-php-server']]]));
            self::assertNull((new Config($this->root))->installOption('php'));
        } finally {
            putenv("PATH={$path}");
        }
    }

    public function testJdtlsIsOfferedThroughBrewOnMacOsOnly(): void
    {
        $bin = $this->root . '/bin';
        mkdir($bin);
        file_put_contents("{$bin}/brew", "#!/bin/sh\n");
        chmod("{$bin}/brew", 0o755);
        $path = (string) getenv('PATH');

        try {
            putenv("PATH={$bin}");
            $option = (new Config($this->root))->installOption('java');
        } finally {
            putenv("PATH={$path}");
        }

        self::assertSame(PHP_OS_FAMILY === 'Darwin' ? 'brew install jdtls' : null, $option['display'] ?? null);
    }

    public function testAServerOffPathIsFoundInAnInstallersDirectoryAndGetsItOnItsPath(): void
    {
        $vendor = $this->root . '/vendor/bin';
        mkdir($vendor, 0o755, true);
        file_put_contents("{$vendor}/phpactor", "#!/bin/sh\n");
        chmod("{$vendor}/phpactor", 0o755);
        $path = (string) getenv('PATH');

        try {
            putenv('PATH=/nonexistent');
            self::assertNull((new Config($this->root))->command('php'));

            $config = new Config($this->root, null, [$vendor]);
            self::assertSame(["{$vendor}/phpactor", 'language-server'], $config->command('php'));
            // phpactor starts itself again by name for diagnostics.
            self::assertSame("{$vendor}:/nonexistent", $config->env('php')['PATH']);
        } finally {
            putenv("PATH={$path}");
        }

        $home = (string) getenv('HOME');
        mkdir("{$home}/.config/composer/vendor/bin", 0o755, true);
        copy("{$vendor}/phpactor", "{$home}/.config/composer/vendor/bin/phpactor");
        chmod("{$home}/.config/composer/vendor/bin/phpactor", 0o755);

        try {
            putenv('PATH=/nonexistent');
            self::assertSame("{$home}/.config/composer/vendor/bin/phpactor", (new Config($this->root))->command('php')[0] ?? null);
        } finally {
            putenv("PATH={$path}");
            exec('rm -rf ' . escapeshellarg("{$home}/.config"));
        }
    }

    public function testBrokenJsonIsAProblemNotACrash(): void
    {
        file_put_contents($this->root . '/.pig/lsp.json', '{nope');
        $config = new Config($this->root);

        self::assertSame([], $config->sources);
        self::assertStringContainsString('.pig/lsp.json', $config->problems[0]);
    }

    public function testTheRootIsTheProjectWhenItIsOneOfThatLanguage(): void
    {
        touch($this->root . '/composer.json');
        mkdir($this->root . '/packages/a/src', 0o755, true);
        touch($this->root . '/packages/a/composer.json');
        $config = new Config($this->root);

        self::assertSame($this->root, $config->rootFor('php', $this->root . '/packages/a/src/A.php'));
    }

    public function testOtherwiseTheNearestMarkerAboveTheFile(): void
    {
        mkdir($this->root . '/app/lib', 0o755, true);
        touch($this->root . '/app/pubspec.yaml');
        $config = new Config($this->root);

        self::assertSame($this->root . '/app', $config->rootFor('dart', $this->root . '/app/lib/main.dart'));
        self::assertSame($this->root, $config->rootFor('go', $this->root . '/app/lib/x.go'));
    }

    public function testDiagnosticsAreOnByDefault(): void
    {
        self::assertTrue((new Config($this->root))->autoDiagnostics('php'));
        self::assertFalse((new Config($this->root, ['autoDiagnostics' => false]))->autoDiagnostics('php'));
    }
}
