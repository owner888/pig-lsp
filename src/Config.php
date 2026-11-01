<?php

declare(strict_types=1);

namespace PigLsp;

/**
 * Which server answers for which file, and how the extension behaves — the built-in table, then
 * the developer's settings, then the project's own file, each overriding the one before.
 *
 * Sources, in order:
 * 1. built in (below);
 * 2. pig's settings, key `lsp` (`~/.pig/agent/settings.json`, or the project's settings);
 * 3. `.pi-lsp.json` in the project root — upstream's file, so a project set up for pi works as is;
 * 4. `.pig/lsp.json` in the project root.
 *
 * Field names are upstream's where upstream has the field: `servers.{key}.command/args/env/
 * initializationOptions/settings`, `autoStart`, `autoInjectDiagnostics`. pig's additions:
 * `servers.{key}.disabled/extensions/rootMarkers`, `maxDiagnostics`, `diagnosticsWait`,
 * `requestTimeout`, `startTimeout`. upstream's `lombokJar` is for its jdtls start-up and has no
 * meaning here (jdtls's own `--jvm-arg=-javaagent:` does the same).
 */
final class Config
{
    /**
     * The built-in servers. `candidates` are tried in order and the first whose executable is on
     * PATH is used; `install` is what the model is told when none is.
     */
    private const array BUILTIN = [
        'php' => [
            'languages' => ['php'],
            'candidates' => [['phpactor', 'language-server'], ['intelephense', '--stdio']],
            'rootMarkers' => ['composer.json', '.phpactor.json', '.phpactor.yml'],
            // phpactor waits a second after a change before checking it; an agent edits seldom
            // and wants the answer with the edit, so a tenth. intelephense ignores the key.
            'initializationOptions' => ['language_server.diagnostic_sleep_time' => 100],
            'install' => 'Install phpactor (`composer global require phpactor/phpactor`, or download phpactor.phar from its GitHub releases into a directory on PATH as `phpactor`), or intelephense (`npm install -g intelephense`).',
        ],
        'dart' => [
            'languages' => ['dart'],
            'candidates' => [['dart', 'language-server', '--protocol=lsp']],
            'rootMarkers' => ['pubspec.yaml'],
            'install' => 'Install the Dart SDK (or Flutter, which includes it) and put `dart` on PATH.',
        ],
        'typescript' => [
            'languages' => ['typescript', 'typescriptreact', 'javascript', 'javascriptreact'],
            'candidates' => [['typescript-language-server', '--stdio']],
            'rootMarkers' => ['tsconfig.json', 'jsconfig.json', 'package.json'],
            'install' => 'Install typescript-language-server: `npm install -g typescript-language-server typescript`.',
        ],
        'python' => [
            'languages' => ['python'],
            'candidates' => [['pyright-langserver', '--stdio'], ['basedpyright-langserver', '--stdio'], ['pylsp']],
            'rootMarkers' => ['pyproject.toml', 'pyrightconfig.json', 'setup.py', 'setup.cfg', 'requirements.txt'],
            'install' => 'Install pyright: `npm install -g pyright` (or `pip install pyright`).',
        ],
        'rust' => [
            'languages' => ['rust'],
            'candidates' => [['rust-analyzer']],
            'rootMarkers' => ['Cargo.toml'],
            'install' => 'Install rust-analyzer: `rustup component add rust-analyzer`.',
        ],
        'go' => [
            'languages' => ['go'],
            'candidates' => [['gopls']],
            'rootMarkers' => ['go.work', 'go.mod'],
            'install' => 'Install gopls: `go install golang.org/x/tools/gopls@latest` (and make sure `$(go env GOPATH)/bin` is on PATH).',
        ],
        'java' => [
            'languages' => ['java'],
            'candidates' => [['jdtls']],
            'rootMarkers' => ['pom.xml', 'build.gradle', 'build.gradle.kts', 'settings.gradle', 'settings.gradle.kts'],
            'install' => 'Install Eclipse JDT Language Server and put its `jdtls` launcher on PATH (`brew install jdtls` on macOS).',
        ],
    ];

    /** File extension → LSP language id; upstream's table plus Dart and PHP templates. */
    private const array EXTENSIONS = [
        'php' => 'php', 'phtml' => 'php', 'inc' => 'php',
        'dart' => 'dart',
        'ts' => 'typescript', 'mts' => 'typescript', 'cts' => 'typescript',
        'tsx' => 'typescriptreact',
        'js' => 'javascript', 'mjs' => 'javascript', 'cjs' => 'javascript',
        'jsx' => 'javascriptreact',
        'py' => 'python', 'pyi' => 'python',
        'rs' => 'rust',
        'go' => 'go',
        'java' => 'java',
        'c' => 'c', 'h' => 'c', 'cpp' => 'cpp', 'cc' => 'cpp', 'cxx' => 'cpp', 'hpp' => 'cpp',
        'cs' => 'csharp', 'rb' => 'ruby', 'kt' => 'kotlin', 'kts' => 'kotlin', 'scala' => 'scala',
        'ex' => 'elixir', 'exs' => 'elixir', 'lua' => 'lua', 'sh' => 'shellscript', 'bash' => 'shellscript',
        'zsh' => 'shellscript', 'swift' => 'swift', 'zig' => 'zig', 'json' => 'json', 'html' => 'html',
        'htm' => 'html', 'css' => 'css', 'vue' => 'vue',
    ];

    /** @var array<string, array<string, mixed>> server key → spec, merged */
    private array $servers;

    /** @var array<string, string> extension → language id, merged */
    private array $extensions;

    /** @var array<string, mixed> the merged top-level options */
    private array $options;

    /** @var list<string> config files that were read, for `/lsp` */
    public readonly array $sources;

    /** @var list<string> problems met reading them, for `/lsp` */
    public readonly array $problems;

    /**
     * @param array<string, mixed>|null $settings the `lsp` key of pig's settings
     */
    public function __construct(public readonly string $cwd, ?array $settings = null)
    {
        $layers = [];
        $sources = [];
        $problems = [];

        if (is_array($settings) && $settings !== []) {
            $layers[] = $settings;
            $sources[] = 'settings (lsp)';
        }

        foreach (['.pi-lsp.json', '.pig/lsp.json'] as $file) {
            $path = rtrim($cwd, '/') . '/' . $file;

            if (!is_file($path)) {
                continue;
            }

            $decoded = json_decode((string) file_get_contents($path), true);

            if (!is_array($decoded)) {
                $problems[] = "{$file}: not valid JSON (" . json_last_error_msg() . ')';

                continue;
            }

            $layers[] = $decoded;
            $sources[] = $file;
        }

        $servers = [];

        foreach (self::BUILTIN as $key => $spec) {
            $servers[$key] = $spec + ['builtin' => true];
        }

        $options = [];
        $extensions = self::EXTENSIONS;

        foreach ($layers as $layer) {
            foreach ((is_array($layer['servers'] ?? null) ? $layer['servers'] : []) as $key => $spec) {
                if (!is_array($spec)) {
                    continue;
                }

                // A language id names the server that already serves it (`typescriptreact` →
                // `typescript`), so a config written per language id upstream-style lands there.
                $key = self::serverKeyFor((string) $key, $servers) ?? (string) $key;
                $servers[$key] = array_replace($servers[$key] ?? ['languages' => [$key]], $spec);

                foreach (is_array($spec['extensions'] ?? null) ? $spec['extensions'] : [] as $extension) {
                    if (is_string($extension) && $extension !== '') {
                        $extensions[strtolower(ltrim($extension, '.'))] = $servers[$key]['languages'][0] ?? $key;
                    }
                }
            }

            unset($layer['servers']);
            $options = array_replace($options, $layer);
        }

        $this->servers = $servers;
        $this->extensions = $extensions;
        $this->options = $options;
        $this->sources = $sources;
        $this->problems = $problems;
    }

    /** The LSP language id for $path, or null when no language is known for it. */
    public function languageFor(string $path): ?string
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return $this->extensions[$extension] ?? null;
    }

    /** The server key that serves $languageId, or null when none is configured for it. */
    public function serverKeyForLanguage(string $languageId): ?string
    {
        $key = self::serverKeyFor($languageId, $this->servers);

        if ($key === null || !empty($this->servers[$key]['disabled'])) {
            return null;
        }

        return $key;
    }

    /** @return list<string> every server key that is not disabled */
    public function serverKeys(): array
    {
        return array_values(array_filter(array_keys($this->servers), fn (string $key): bool => empty($this->servers[$key]['disabled'])));
    }

    /** @return array<string, mixed> */
    public function server(string $key): array
    {
        return $this->servers[$key] ?? [];
    }

    /**
     * The command line that starts $key's server: the configured one, or the first built-in
     * candidate on PATH. Null when nothing is installed.
     *
     * @return list<string>|null
     */
    public function command(string $key): ?array
    {
        $spec = $this->servers[$key] ?? null;

        if ($spec === null) {
            return null;
        }

        if (is_string($spec['command'] ?? null) && $spec['command'] !== '') {
            $args = array_values(array_map('strval', is_array($spec['args'] ?? null) ? $spec['args'] : []));
            $binary = self::which($spec['command'], $this->cwd);

            return $binary !== null ? [$binary, ...$args] : null;
        }

        foreach (is_array($spec['candidates'] ?? null) ? $spec['candidates'] : [] as $candidate) {
            $binary = self::which($candidate[0], $this->cwd);

            if ($binary !== null) {
                return [$binary, ...array_slice($candidate, 1)];
            }
        }

        return null;
    }

    /** What to tell the model when $key's server is not installed. */
    public function installHint(string $key): string
    {
        $spec = $this->servers[$key] ?? [];
        $tried = is_string($spec['command'] ?? null)
            ? [$spec['command']]
            : array_map(static fn (array $candidate): string => $candidate[0], is_array($spec['candidates'] ?? null) ? $spec['candidates'] : []);
        $hint = is_string($spec['install'] ?? null) ? $spec['install'] : "Set `servers.{$key}.command` in `.pig/lsp.json` or the `lsp` setting.";

        return "No {$key} language server found on PATH (looked for: " . implode(', ', $tried) . "). {$hint}";
    }

    /**
     * The workspace root for $path: the project directory when it is a project of this language
     * (one server for the whole project, as upstream had), otherwise the nearest directory above
     * the file holding one of the server's root markers — a file outside the project gets its own
     * root instead of being forced into one that knows nothing about it.
     */
    public function rootFor(string $key, string $path): string
    {
        $markers = array_values(array_filter(
            is_array($this->servers[$key]['rootMarkers'] ?? null) ? $this->servers[$key]['rootMarkers'] : [],
            'is_string',
        ));
        $cwd = rtrim($this->cwd, '/');
        $insideCwd = str_starts_with($path, $cwd . '/');

        if ($insideCwd && ($markers === [] || self::hasMarker($cwd, $markers))) {
            return $cwd;
        }

        $directory = dirname($path);

        while (true) {
            if (self::hasMarker($directory, $markers)) {
                return $directory;
            }

            $parent = dirname($directory);

            if ($parent === $directory || ($insideCwd && $directory === $cwd)) {
                break;
            }

            $directory = $parent;
        }

        return $insideCwd ? $cwd : dirname($path);
    }

    /** @return array<string, string> */
    public function env(string $key): array
    {
        $env = $this->servers[$key]['env'] ?? [];

        return is_array($env) ? array_map('strval', $env) : [];
    }

    public function initializationOptions(string $key): mixed
    {
        return $this->servers[$key]['initializationOptions'] ?? null;
    }

    /** @return array<string, mixed> */
    public function settings(string $key): array
    {
        $settings = $this->servers[$key]['settings'] ?? [];

        return is_array($settings) ? $settings : [];
    }

    /**
     * Whether edits to a $languageId file get the server's errors appended. Upstream's
     * `autoInjectDiagnostics` (true, false or a list of language ids); `autoDiagnostics` also
     * accepted. On by default.
     */
    public function autoDiagnostics(string $languageId): bool
    {
        $value = $this->options['autoDiagnostics'] ?? $this->options['autoInjectDiagnostics'] ?? true;

        if (is_array($value)) {
            $key = $this->serverKeyForLanguage($languageId);

            return in_array($languageId, $value, true) || ($key !== null && in_array($key, $value, true));
        }

        return $value !== false;
    }

    /** At most this many error lines appended to an edit; upstream's 10. */
    public function maxDiagnostics(): int
    {
        return max(1, (int) ($this->options['maxDiagnostics'] ?? 10));
    }

    /** How long an edit waits for the server's diagnostics, in seconds (`diagnosticsWait`, ms). */
    public function diagnosticsWait(): float
    {
        return max(0.1, (int) ($this->options['diagnosticsWait'] ?? 3000) / 1000);
    }

    /**
     * After the first diagnostics for a change arrive, how long the server must stay quiet before
     * they are taken as final, in seconds (`diagnosticsSettle`, ms) — see
     * LspClient::waitForDiagnostics(). Per server (`servers.<key>.diagnosticsSettle`) or global.
     */
    public function diagnosticsSettle(?string $key = null): float
    {
        $value = ($key !== null ? ($this->servers[$key]['diagnosticsSettle'] ?? null) : null) ?? $this->options['diagnosticsSettle'] ?? 400;

        return max(0.0, (int) $value / 1000);
    }

    /** How long a query waits for an answer, in seconds (`requestTimeout`, ms). */
    public function requestTimeout(): float
    {
        return max(1.0, (int) ($this->options['requestTimeout'] ?? 30000) / 1000);
    }

    /** How long a server gets to start and finish `initialize`, in seconds (`startTimeout`, ms). */
    public function startTimeout(): float
    {
        return max(1.0, (int) ($this->options['startTimeout'] ?? 60000) / 1000);
    }

    /**
     * How long a tool call waits for a server that is still starting before saying so, in seconds.
     * Short on purpose: jdtls or rust-analyzer can take a minute, and a tool call should not.
     */
    public function startWait(): float
    {
        return max(0.0, (int) ($this->options['startWait'] ?? 15000) / 1000);
    }

    /** @return list<string> server keys (or language ids) to start with the session */
    public function autoStart(): array
    {
        $value = $this->options['autoStart'] ?? [];

        return is_array($value) ? array_values(array_filter($value, 'is_string')) : [];
    }

    /** An executable's full path, from PATH (or relative to $cwd when it has a slash). */
    public static function which(string $command, string $cwd = '.'): ?string
    {
        if ($command === '') {
            return null;
        }

        if (str_starts_with($command, '~/')) {
            $command = (string) getenv('HOME') . substr($command, 1);
        }

        if (str_contains($command, '/')) {
            $path = str_starts_with($command, '/') ? $command : rtrim($cwd, '/') . '/' . $command;

            return is_file($path) && is_executable($path) ? $path : null;
        }

        foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $directory) {
            if ($directory === '') {
                continue;
            }

            $path = rtrim($directory, '/') . '/' . $command;

            if (is_file($path) && is_executable($path)) {
                return $path;
            }
        }

        return null;
    }

    /** @param array<string, array<string, mixed>> $servers */
    private static function serverKeyFor(string $name, array $servers): ?string
    {
        if (isset($servers[$name])) {
            return $name;
        }

        foreach ($servers as $key => $spec) {
            if (in_array($name, is_array($spec['languages'] ?? null) ? $spec['languages'] : [], true)) {
                return (string) $key;
            }
        }

        return null;
    }

    /** @param list<string> $markers */
    private static function hasMarker(string $directory, array $markers): bool
    {
        foreach ($markers as $marker) {
            if (file_exists($directory . '/' . $marker)) {
                return true;
            }
        }

        return false;
    }
}
