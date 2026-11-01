<?php

declare(strict_types=1);

namespace PigLsp\Test;

use PigLsp\Config;
use PigLsp\LspClient;
use PigLsp\LspManager;
use PigLsp\Tools;

/**
 * A throwaway project whose `.fk` files are served by the fake language server.
 */
final class FakeProject
{
    public readonly string $root;

    public readonly string $log;

    /** @var list<LspManager> */
    private array $managers = [];

    /** @param array<string, string> $env for the fake server (FAKE_LSP_*) */
    public function __construct(array $env = [], array $options = [], ?string $command = null)
    {
        $this->root = sys_get_temp_dir() . '/pig-lsp-project-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/.pig', 0o755, true);
        $this->log = $this->root . '/fake-lsp.log';
        $this->writeConfig($env, $options, $command);
    }

    public static function fixture(): string
    {
        return __DIR__ . '/fixtures/fake-lsp.php';
    }

    /** @param array<string, string> $env */
    public function writeConfig(array $env = [], array $options = [], ?string $command = null): void
    {
        $config = [
            'servers' => [
                'fake' => [
                    'command' => $command ?? PHP_BINARY,
                    'args' => $command === null ? [self::fixture()] : [],
                    'env' => ['FAKE_LSP_LOG' => $this->log, ...$env],
                    'extensions' => ['.fk'],
                    'languages' => ['fake'],
                    'settings' => ['fake' => ['answer' => 42]],
                ],
            ],
            'startWait' => 10000,
            'diagnosticsWait' => 2000,
            ...$options,
        ];
        file_put_contents($this->root . '/.pig/lsp.json', json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    public function write(string $relative, string $text): string
    {
        $path = $this->root . '/' . $relative;

        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0o755, true);
        }

        file_put_contents($path, $text);
        // A rewrite inside the same second keeps the mtime; nudge it so the change is seen.
        clearstatcache();
        touch($path, (filemtime($path) ?: time()) + random_int(1, 1000));

        return $path;
    }

    public function config(?array $settings = null): Config
    {
        return new Config($this->root, $settings);
    }

    public function manager(): LspManager
    {
        $manager = new LspManager($this->config());
        $this->managers[] = $manager;

        return $manager;
    }

    public function tools(?LspManager $manager = null): Tools
    {
        $manager ??= $this->manager();

        return new Tools(static fn (string $cwd): LspManager => $manager);
    }

    /** A client on the fake server, not yet started. */
    public function client(array $env = []): LspClient
    {
        return new LspClient(
            name: 'fake',
            command: [PHP_BINARY, self::fixture()],
            root: $this->root,
            env: ['FAKE_LSP_LOG' => $this->log, ...$env],
            settings: ['fake' => ['answer' => 42]],
            requestTimeout: 5.0,
        );
    }

    /**
     * Every message the fake server received.
     *
     * @return list<array<string, mixed>>
     */
    public function received(): array
    {
        if (!is_file($this->log)) {
            return [];
        }

        return array_values(array_map(
            static fn (string $line): array => json_decode($line, true),
            array_filter(explode("\n", (string) file_get_contents($this->log))),
        ));
    }

    /** @return list<array<string, mixed>> the messages received with method $method */
    public function receivedMethod(string $method): array
    {
        return array_values(array_filter($this->received(), static fn (array $m): bool => ($m['method'] ?? null) === $method));
    }

    public function cleanup(): void
    {
        foreach ($this->managers as $manager) {
            $manager->shutdownAll();
        }

        exec('rm -rf ' . escapeshellarg($this->root));
    }
}
