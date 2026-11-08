<?php

declare(strict_types=1);

namespace PigLsp;

use Closure;
use Pig\Agent\AgentToolResult;
use Pig\Agent\ToolReplay;
use Pig\Ai\TextContent;
use Pig\Async\AbortSignal;
use Pig\CodingAgent\CustomTools\CustomTool;
use Pig\CodingAgent\Extensions\ExtensionContext;

/**
 * The `lsp_*` tools: what each asks the server, and how the answer reads.
 *
 * Each operation is a plain method returning text, so the tests can call it without a session;
 * `definitions()` wraps them as pig tools. A problem the model can act on (no server, symbol not
 * on that line, server still starting) is thrown as an `LspError`, which pig shows the model as
 * a failed call with that message.
 */
final class Tools
{
    /** Shared by every tool that takes a position. */
    private const array POSITION = [
        'path' => ['type' => 'string', 'description' => 'File the symbol is in (relative to the project, or absolute).'],
        'line' => ['type' => 'integer', 'description' => '1-based line of the symbol. With `query`, the column is found for you — the most reliable way to point at a call site.'],
        'query' => ['type' => 'string', 'description' => 'The symbol\'s name as written (`charge`, `Pay::charge`, `$amount`). Without `line`: the declaration of that name in the file, else its first occurrence.'],
        'character' => ['type' => 'integer', 'description' => '1-based column, in characters. Only needed without `query`.'],
    ];

    /**
     * @param Closure(string): LspManager $manager the manager for a working directory
     * @param (Closure(LspNotInstalled, ExtensionContext, ?AbortSignal): string)|null $notInstalled what
     *        to do when a call finds its server missing: offer to install it and return a note for
     *        the result (the call is then tried again), or throw the `LspError` the model sees
     */
    public function __construct(private readonly Closure $manager, private readonly ?Closure $notInstalled = null)
    {
    }

    // ---- operations -----------------------------------------------------------------------------

    /** @param array<string, mixed> $params */
    public function definition(array $params, string $cwd, ?AbortSignal $signal = null): string
    {
        return $this->locate('textDocument/definition', 'definitionProvider', 'Definition', $params, $cwd, $signal);
    }

    /** @param array<string, mixed> $params */
    public function implementation(array $params, string $cwd, ?AbortSignal $signal = null): string
    {
        return $this->locate('textDocument/implementation', 'implementationProvider', 'Implementation', $params, $cwd, $signal);
    }

    /** @param array<string, mixed> $params */
    public function references(array $params, string $cwd, ?AbortSignal $signal = null): string
    {
        return $this->locate('textDocument/references', 'referencesProvider', 'Reference', $params, $cwd, $signal, [
            'context' => ['includeDeclaration' => (bool) ($params['includeDeclaration'] ?? false)],
        ]);
    }

    /** @param array<string, mixed> $params */
    public function hover(array $params, string $cwd, ?AbortSignal $signal = null): string
    {
        $at = $this->position($params, $cwd, $signal);
        $this->requireProvider($at, 'hoverProvider', 'hover');
        $result = $at['client']->request('textDocument/hover', $at['params'], null, $signal);
        $text = Format::hover($result);

        return $this->heading($at, 'Hover')
            . ($text !== '' ? "\n" . $text : "\nNo hover information here." . $this->indexing($at['client']));
    }

    /** @param array<string, mixed> $params */
    public function symbols(array $params, string $cwd, ?AbortSignal $signal = null): string
    {
        $manager = ($this->manager)($cwd);
        $path = is_string($params['path'] ?? null) && trim($params['path']) !== '' ? Text::absolute($params['path'], $cwd) : null;
        $query = is_string($params['query'] ?? null) ? trim($params['query']) : '';
        $limit = self::limit($params, 200);

        if ($path !== null) {
            ['client' => $client, 'languageId' => $languageId] = $this->openFile($manager, $path);
            $this->requireProvider(['client' => $client], 'documentSymbolProvider', 'document symbols');
            $result = $client->request('textDocument/documentSymbol', ['textDocument' => ['uri' => Text::pathToUri($path)]], null, $signal);
            $symbols = PositionResolver::flattenSymbols(is_array($result) ? $result : []);

            if ($query !== '') {
                $symbols = array_values(array_filter($symbols, static fn (array $symbol): bool => stripos($symbol['name'], PositionResolver::lastSegment($query)) !== false));
            }

            $format = new Format($cwd, $client->positionEncoding());
            $where = Text::relative($path, $cwd);

            if ($symbols === []) {
                return $query !== '' ? "No symbols matching `{$query}` in {$where}." : "No symbols in {$where}." . $this->indexing($client);
            }

            return count($symbols) . " symbol(s) in {$where}" . ($query !== '' ? " matching `{$query}`" : '') . ":\n" . $format->outline($symbols, $limit);
        }

        if ($query === '') {
            throw new LspError('Give `path` for a file\'s outline, or `query` to search the whole workspace.');
        }

        $language = is_string($params['language'] ?? null) && $params['language'] !== '' ? $params['language'] : null;
        $keys = $language !== null ? [$manager->config()->serverKeyForLanguage($language) ?? $language] : $manager->projectServerKeys();

        if ($keys === []) {
            throw new LspError('No language server is running and the project\'s language could not be told from its files. Pass `language` (php, dart, typescript, python, rust, go, java), or `path` of a file.');
        }

        $sections = [];
        $problems = [];
        $limit = self::limit($params, 50);

        foreach ($keys as $key) {
            try {
                $client = $manager->clientForKey($key);
                $this->requireProvider(['client' => $client], 'workspaceSymbolProvider', 'workspace symbol search');
                $client->refreshOpenDocuments();
                $result = $client->request('workspace/symbol', ['query' => $query], null, $signal);
            } catch (LspNotInstalled $error) {
                // Alone, it is the call's answer, and the install offer goes with it; next to
                // servers that did answer, it is a line — not a dialog in the middle of a search.
                if (count($keys) === 1) {
                    throw $error;
                }

                $problems[] = "No {$key} language server is installed.";

                continue;
            } catch (LspUnavailable | LspError $error) {
                $problems[] = $error->getMessage();

                continue;
            }

            $text = is_array($result) && $result !== [] ? (new Format($cwd, $client->positionEncoding()))->workspaceSymbols($result, $limit) : '';
            $sections[] = $text !== '' ? ($keys === [$key] ? '' : "[{$key}]\n") . $text : "No {$key} symbols match `{$query}`." . $this->indexing($client);
        }

        if ($sections === []) {
            throw new LspError(implode("\n", $problems));
        }

        return implode("\n\n", [...$sections, ...$problems]);
    }

    /** @param array<string, mixed> $params */
    public function diagnostics(array $params, string $cwd, float $wait = 3.0, ?AbortSignal $signal = null): string
    {
        $manager = ($this->manager)($cwd);
        $target = is_string($params['path'] ?? null) ? trim($params['path']) : '';
        $minSeverity = match ($params['severity'] ?? 'all') {
            'error' => 1,
            'warning' => 2,
            default => 4,
        };
        $limit = self::limit($params, 50);

        if ($target === '' || $target === '*') {
            $sections = [];

            foreach ($manager->runningClients() as $client) {
                $format = new Format($cwd, $client->positionEncoding());

                foreach ($client->allDiagnostics() as $uri => $items) {
                    $text = $format->diagnostics(Text::uriToPath($uri), $items, $limit, $minSeverity);

                    if ($text !== '') {
                        $sections[] = $text;
                    }
                }
            }

            if ($manager->runningClients() === []) {
                return 'No language server is running yet. Ask about a file (`path`) to start one.';
            }

            return $sections === [] ? 'No problems reported in the files the language servers have checked.' : implode("\n\n", $sections);
        }

        $path = Text::absolute($target, $cwd);
        $uri = Text::pathToUri($path);
        ['client' => $client, 'key' => $key] = $manager->clientForFile($path);
        $generation = $client->diagnosticGeneration($uri);
        $changed = $this->openFile($manager, $path, $client)['changed'];

        if ($changed || $generation === 0) {
            $client->waitForDiagnostics($uri, $generation, $wait, $manager->config()->diagnosticsSettle($key));
        }

        $text = (new Format($cwd, $client->positionEncoding()))->diagnostics($path, $client->diagnostics($uri), $limit, $minSeverity);
        $where = Text::relative($path, $cwd);

        return $text === '' ? "No problems reported in {$where}." . $this->indexing($client) : $text;
    }

    /** @param array<string, mixed> $params */
    public function rename(array $params, string $cwd, ?AbortSignal $signal = null): string
    {
        $newName = is_string($params['newName'] ?? null) ? trim($params['newName']) : '';

        if ($newName === '') {
            throw new LspError('Give `newName`.');
        }

        $at = $this->position($params, $cwd, $signal);
        $this->requireProvider($at, 'renameProvider', 'rename');
        $provider = $at['client']->capabilities()['renameProvider'] ?? null;

        if (is_array($provider) && !empty($provider['prepareProvider'])) {
            $prepared = $at['client']->request('textDocument/prepareRename', $at['params'], null, $signal);

            if ($prepared === null) {
                throw new LspError('The symbol at ' . $at['where'] . ' cannot be renamed (the server says so). Line: ' . trim($at['lineText']));
            }
        }

        $result = $at['client']->request('textDocument/rename', [...$at['params'], 'newName' => $newName], null, $signal);

        if (!is_array($result)) {
            return $this->heading($at, 'Rename') . "\nThe server proposed no edits.";
        }

        $preview = (new Format($cwd, $at['client']->positionEncoding()))->workspaceEdit($result);

        return sprintf(
            "Rename to `%s` at %s: %d edit(s) across %d file(s) — NOT applied.\n%s\n\nThese changes are a preview. Apply them with the edit tool (check each one: a rename can reach strings and docs the developer did not mean).",
            $newName,
            $at['where'],
            $preview['edits'],
            $preview['files'],
            $preview['text'],
        );
    }

    /** @param array<string, mixed> $params */
    public function codeActions(array $params, string $cwd, ?AbortSignal $signal = null): string
    {
        $at = $this->position($params, $cwd, $signal);
        $this->requireProvider($at, 'codeActionProvider', 'code actions');
        $start = $at['params']['position'];
        $end = $start;

        if (isset($params['endLine']) && is_numeric($params['endLine'])) {
            $endLine = max($start['line'], (int) $params['endLine'] - 1);
            $endText = Text::line($at['text'], $endLine);
            $endCharacter = isset($params['endCharacter']) && is_numeric($params['endCharacter'])
                ? (int) $params['endCharacter'] - 1
                : mb_strlen($endText, 'UTF-8');
            $end = ['line' => $endLine, 'character' => Text::toServerColumn($endText, $endCharacter, $at['client']->positionEncoding())];
        }

        // Quick fixes are offered for the diagnostics sent with the request: a file just opened has
        // none until the server has checked it.
        if ($at['changed'] || $at['generation'] === 0) {
            $at['client']->waitForDiagnostics($at['uri'], $at['generation'], $at['wait'], $at['settle']);
        }

        $diagnostics = array_values(array_filter(
            $at['client']->diagnostics($at['uri']),
            static fn (array $d): bool => (int) ($d['range']['start']['line'] ?? -1) <= $end['line'] && (int) ($d['range']['end']['line'] ?? -1) >= $start['line'],
        ));
        $context = ['diagnostics' => $diagnostics];

        if (is_string($params['kind'] ?? null) && $params['kind'] !== '') {
            $context['only'] = [$params['kind']];
        }

        $result = $at['client']->request('textDocument/codeAction', [
            'textDocument' => $at['params']['textDocument'],
            'range' => ['start' => $start, 'end' => $end],
            'context' => $context,
        ], null, $signal);

        if (!is_array($result) || $result === []) {
            return $this->heading($at, 'Code actions') . "\nNone available here.";
        }

        $format = new Format($cwd, $at['client']->positionEncoding());
        $out = [];

        foreach (array_values($result) as $index => $action) {
            if (!is_array($action) || !is_string($action['title'] ?? null)) {
                continue;
            }

            $line = ($index + 1) . '. ' . $action['title']
                . (is_string($action['kind'] ?? null) && $action['kind'] !== '' ? " [{$action['kind']}]" : '')
                . (!empty($action['isPreferred']) ? ' (preferred)' : '');

            if (is_array($action['edit'] ?? null)) {
                $preview = $format->workspaceEdit($action['edit'], 12);
                $line .= "\n" . preg_replace('/^/m', '   ', $preview['text']);
            } elseif (isset($action['command']) || isset($action['data'])) {
                $line .= ' — the server applies this one itself (a command); pig does not run server commands, so make the change with the edit tool.';
            }

            $out[] = $line;
        }

        return $this->heading($at, 'Code actions') . "\n" . implode("\n", $out) . "\n\nNone of these were applied.";
    }

    // ---- the pig tools --------------------------------------------------------------------------

    /**
     * @param Closure(): float $diagnosticsWait
     * @param Closure(ExtensionContext): void $afterCall run after every call (the status line)
     * @return list<CustomTool>
     */
    public function definitions(Closure $diagnosticsWait, Closure $afterCall): array
    {
        $notInstalled = $this->notInstalled;
        $tool = function (string $name, string $label, string $description, array $properties, array $required, Closure $run) use ($afterCall, $notInstalled): CustomTool {
            return new CustomTool(
                name: $name,
                label: $label,
                description: $description,
                parameters: ['type' => 'object', 'properties' => $properties, 'required' => $required],
                execute: static function (string $id, array $params, ?Closure $onUpdate, ExtensionContext $ctx, ?AbortSignal $signal = null) use ($run, $afterCall, $notInstalled): AgentToolResult {
                    try {
                        try {
                            return new AgentToolResult([new TextContent($run($params, $ctx->cwd, $signal))]);
                        } catch (LspNotInstalled $missing) {
                            if ($notInstalled === null) {
                                throw new LspError($missing->getMessage());
                            }

                            // Installed (or it throws what the model should hear instead): the
                            // original request goes on as if the server had been there all along.
                            $note = $notInstalled($missing, $ctx, $signal);
                            $text = $run($params, $ctx->cwd, $signal);

                            return new AgentToolResult([new TextContent($note !== '' ? $note . "\n\n" . $text : $text)]);
                        }
                    } finally {
                        $afterCall($ctx);
                    }
                },
                replay: ToolReplay::Safe,
            );
        };
        $limit = ['limit' => ['type' => 'integer', 'description' => 'At most this many results (default 50).']];

        return [
            $tool(
                'lsp_definition',
                'Go to definition',
                'Where a symbol is defined, by the language server: the exact class, method or function a name refers to — not every line that mentions it, as grep would. Point at a usage (`path` + `line` + `query`) to learn where that call goes.',
                self::POSITION,
                ['path'],
                fn (array $p, string $cwd, ?AbortSignal $s): string => $this->definition($p, $cwd, $s),
            ),
            $tool(
                'lsp_references',
                'Find references',
                'Every place a symbol is used, by the language server — real references only, told apart from same-named methods of other classes, comments and strings. Use before renaming or changing a signature.',
                [...self::POSITION, 'includeDeclaration' => ['type' => 'boolean', 'description' => 'Include the declaration itself (default false).'], ...$limit],
                ['path'],
                fn (array $p, string $cwd, ?AbortSignal $s): string => $this->references($p, $cwd, $s),
            ),
            $tool(
                'lsp_implementation',
                'Find implementations',
                'The classes that implement an interface (or extend an abstract class), or the concrete methods behind an interface method. Point at the interface or its method — at its declaration or at a call through it. When several implementations come back, which one runs is decided where the object is created or bound (a container binding, a factory, a config value): find that with lsp_references on the interface and read it, rather than guessing.',
                [...self::POSITION, ...$limit],
                ['path'],
                fn (array $p, string $cwd, ?AbortSignal $s): string => $this->implementation($p, $cwd, $s),
            ),
            $tool(
                'lsp_hover',
                'Hover',
                'The type and documentation of a symbol, by the language server: a variable\'s inferred type, a method\'s signature and docblock.',
                self::POSITION,
                ['path'],
                fn (array $p, string $cwd, ?AbortSignal $s): string => $this->hover($p, $cwd, $s),
            ),
            $tool(
                'lsp_symbols',
                'Symbols',
                'With `path`: the file\'s outline (classes, methods, functions, with lines), optionally filtered by `query`. With `query` alone: search the whole workspace for symbols by name.',
                [
                    'path' => ['type' => 'string', 'description' => 'A file, for its outline.'],
                    'query' => ['type' => 'string', 'description' => 'A name (or part of one) to look for.'],
                    'language' => ['type' => 'string', 'description' => 'For a workspace search: which language server to ask (php, dart, typescript, python, rust, go, java). Inferred from the project when left out.'],
                    ...$limit,
                ],
                [],
                fn (array $p, string $cwd, ?AbortSignal $s): string => $this->symbols($p, $cwd, $s),
            ),
            $tool(
                'lsp_diagnostics',
                'Diagnostics',
                'Errors and warnings the language server reports for a file (`path`), or for every file it has checked (`"*"`). Edits already get their new errors appended automatically while a server is running; use this to check a file before or without editing it.',
                [
                    'path' => ['type' => 'string', 'description' => 'A file, or "*" for all files the servers have checked.'],
                    'severity' => ['type' => 'string', 'enum' => ['error', 'warning', 'all'], 'description' => 'The least severe level to show (default all).'],
                    ...$limit,
                ],
                ['path'],
                fn (array $p, string $cwd, ?AbortSignal $s): string => $this->diagnostics($p, $cwd, $diagnosticsWait(), $s),
            ),
            $tool(
                'lsp_rename',
                'Rename preview',
                'What renaming a symbol everywhere would change, by the language server — every edit, file by file, with each line before and after. Nothing is written: apply the edits with the edit tool.',
                [...self::POSITION, 'newName' => ['type' => 'string', 'description' => 'The new name.']],
                ['path', 'newName'],
                fn (array $p, string $cwd, ?AbortSignal $s): string => $this->rename($p, $cwd, $s),
            ),
            $tool(
                'lsp_code_actions',
                'Code actions',
                'The quick fixes and refactorings the language server offers at a position or range (import a class, implement missing methods, …), with the edits each would make. Nothing is applied.',
                [
                    ...self::POSITION,
                    'endLine' => ['type' => 'integer', 'description' => '1-based last line of a range (default: the position only).'],
                    'endCharacter' => ['type' => 'integer', 'description' => '1-based column where the range ends (default: end of endLine).'],
                    'kind' => ['type' => 'string', 'description' => 'Only actions of this kind: quickfix, refactor, source.organizeImports, …'],
                ],
                ['path'],
                fn (array $p, string $cwd, ?AbortSignal $s): string => $this->codeActions($p, $cwd, $s),
            ),
        ];
    }

    // ---- shared ---------------------------------------------------------------------------------

    /**
     * @param array<string, mixed> $extra
     * @param array<string, mixed> $params
     */
    private function locate(string $method, string $provider, string $noun, array $params, string $cwd, ?AbortSignal $signal, array $extra = []): string
    {
        $at = $this->position($params, $cwd, $signal);

        if (!$at['client']->supports($provider)) {
            $hint = $provider === 'implementationProvider'
                ? ' Use lsp_references on the interface (or method) and look for the `implements` / `extends` lines instead.'
                : '';

            throw new LspError($at['client']->serverName() . ' does not support ' . strtolower($noun) . ' lookups.' . $hint);
        }

        $at['client']->refreshOpenDocuments();
        $result = $at['client']->request($method, [...$at['params'], ...$extra], null, $signal);
        $format = new Format($cwd, $at['client']->positionEncoding());
        $locations = $format->locations($result);
        $limit = self::limit($params, 50);
        $name = $at['symbol'] !== '' ? "`{$at['symbol']}`" : 'the symbol';

        if ($locations === []) {
            return $this->heading($at, $noun) . "\nNo " . strtolower($noun) . " found for {$name}." . $this->indexing($at['client']);
        }

        $count = count($locations);
        $files = Format::fileCount($locations);
        $summary = "{$count} " . strtolower($noun) . ($count === 1 ? '' : 's') . " of {$name}" . ($files > 1 ? " in {$files} files" : '') . ':';
        $text = $this->heading($at, $noun, false) . $summary . "\n" . $format->locationList($locations, $limit);

        if ($noun === 'Implementation' && $count > 1) {
            $text .= "\n\nWhich of these runs is decided where the object is created or bound — a DI container binding, a factory, a config value. To find it: lsp_references on the interface name, then read the binding or factory branch. If it depends on runtime input (config, a request parameter), say so rather than picking one.";
        }

        return $text . $this->indexing($at['client']);
    }

    /**
     * Resolve the input to a server position, the file open and up to date on the server.
     *
     * @param array<string, mixed> $params
     * @return array{client: LspClient, uri: string, path: string, text: string, lineText: string, symbol: string, where: string, note: ?string, changed: bool, generation: int, wait: float, settle: float, params: array{textDocument: array{uri: string}, position: array{line: int, character: int}}}
     */
    private function position(array $params, string $cwd, ?AbortSignal $signal): array
    {
        if (!is_string($params['path'] ?? null) || trim($params['path']) === '') {
            throw new LspError('Give `path`: the file the symbol is in.');
        }

        $path = Text::absolute($params['path'], $cwd);
        $manager = ($this->manager)($cwd);
        $uri = Text::pathToUri($path);
        $generation = is_file($path) ? $manager->clientForFile($path)['client']->diagnosticGeneration($uri) : 0;
        ['client' => $client, 'text' => $text, 'changed' => $changed] = $this->openFile($manager, $path);
        $resolved = PositionResolver::resolve($text, $params, $client, $uri, $signal);
        $lineText = Text::line($text, $resolved['line']);
        $symbol = is_string($params['query'] ?? null) && trim($params['query']) !== ''
            ? PositionResolver::lastSegment($params['query'])
            : self::wordAt($lineText, $resolved['character']);

        return [
            'client' => $client,
            'uri' => $uri,
            'path' => $path,
            'text' => $text,
            'lineText' => $lineText,
            'symbol' => $symbol,
            'where' => Text::relative($path, $cwd) . ':' . ($resolved['line'] + 1) . ':' . ($resolved['character'] + 1),
            'note' => $resolved['note'],
            'changed' => $changed,
            'generation' => $generation,
            'wait' => $manager->config()->diagnosticsWait(),
            'settle' => $manager->config()->diagnosticsSettle($manager->clientForFile($path)['key']),
            'params' => [
                'textDocument' => ['uri' => $uri],
                'position' => ['line' => $resolved['line'], 'character' => Text::toServerColumn($lineText, $resolved['character'], $client->positionEncoding())],
            ],
        ];
    }

    /**
     * @return array{client: LspClient, languageId: string, text: string, changed: bool}
     */
    private function openFile(LspManager $manager, string $path, ?LspClient $client = null): array
    {
        if (!is_file($path)) {
            throw new LspError('No such file: ' . $path);
        }

        $found = $manager->clientForFile($path);
        $client ??= $found['client'];
        $uri = Text::pathToUri($path);
        $before = $client->isOpen($uri) ? $client->documentText($uri) : null;
        $text = $client->syncFromDisk($path, $found['languageId']) ?? '';

        return ['client' => $client, 'languageId' => $found['languageId'], 'text' => $text, 'changed' => $before !== $text];
    }

    /** @param array{client: LspClient} $at */
    private function requireProvider(array $at, string $provider, string $what): void
    {
        if (!$at['client']->supports($provider)) {
            throw new LspError($at['client']->serverName() . " does not support {$what}.");
        }
    }

    /** @param array{where: string, note: ?string, lineText: string} $at */
    private function heading(array $at, string $noun, bool $withLine = true): string
    {
        $head = $at['note'] !== null ? $at['note'] . "\n" : '';

        return $head . ($withLine ? "{$noun} at {$at['where']}  " . trim($at['lineText']) : '');
    }

    private function indexing(LspClient $client): string
    {
        $progress = $client->progress();

        return $progress !== [] ? "\n(The server is still busy — " . implode(', ', $progress) . ' — so results may be incomplete.)' : '';
    }

    /** The identifier around a code-point column, for headings when no query was given. */
    private static function wordAt(string $lineText, int $character): string
    {
        $chars = mb_str_split($lineText, 1, 'UTF-8');
        $isWord = static fn (?string $c): bool => $c !== null && preg_match('/^[\p{L}\p{N}_$]$/u', $c) === 1;
        $start = $character;
        $end = $character;

        while ($start > 0 && $isWord($chars[$start - 1] ?? null)) {
            $start--;
        }

        while ($isWord($chars[$end] ?? null)) {
            $end++;
        }

        return implode('', array_slice($chars, $start, $end - $start));
    }

    /** @param array<string, mixed> $params */
    private static function limit(array $params, int $default): int
    {
        return isset($params['limit']) && is_numeric($params['limit']) ? max(1, min(500, (int) $params['limit'])) : $default;
    }
}
