<?php

declare(strict_types=1);

namespace PigLsp;

/**
 * What the model reads back: every location as `path:line:col` and the text on that line.
 *
 * Upstream printed bare `path:line:col`, so the model had to read each file to learn whether a
 * reference was a call, an import or a docblock — the very question an LSP tool is asked to
 * spare it. The line's text answers most of that for the price of one line. Results are kept in
 * file order (project files before `vendor/` and `node_modules/`), deduplicated, and capped, with
 * what was left out counted rather than dropped in silence.
 */
final class Format
{
    /** The longest line shown; minified code would otherwise flood the context. */
    private const int MAX_LINE_LENGTH = 200;

    private const array KINDS = [
        1 => 'file', 2 => 'module', 3 => 'namespace', 4 => 'package', 5 => 'class', 6 => 'method',
        7 => 'property', 8 => 'field', 9 => 'constructor', 10 => 'enum', 11 => 'interface',
        12 => 'function', 13 => 'variable', 14 => 'constant', 15 => 'string', 16 => 'number',
        17 => 'boolean', 18 => 'array', 19 => 'object', 20 => 'key', 21 => 'null', 22 => 'enum member',
        23 => 'struct', 24 => 'event', 25 => 'operator', 26 => 'type parameter',
    ];

    private const array SEVERITIES = [1 => 'error', 2 => 'warning', 3 => 'info', 4 => 'hint'];

    /** @var array<string, list<string>|null> path → lines, read once per answer */
    private array $files = [];

    public function __construct(private readonly string $cwd, private readonly string $encoding = Text::UTF16)
    {
    }

    public static function kind(int $kind): string
    {
        return self::KINDS[$kind] ?? 'symbol';
    }

    public static function severity(int $severity): string
    {
        return self::SEVERITIES[$severity] ?? 'error';
    }

    /**
     * Definition, references and implementation answers — `Location`, `Location[]` or
     * `LocationLink[]` — as one flat, sorted, deduplicated list.
     *
     * @return list<array{path: string, line: int, character: int}> 0-based, character in code points
     */
    public function locations(mixed $result): array
    {
        if (!is_array($result) || $result === []) {
            return [];
        }

        if (isset($result['uri']) || isset($result['targetUri'])) {
            $result = [$result];
        }

        $seen = [];
        $locations = [];

        foreach ($result as $location) {
            if (!is_array($location)) {
                continue;
            }

            $uri = $location['targetUri'] ?? $location['uri'] ?? null;
            $range = $location['targetSelectionRange'] ?? $location['targetRange'] ?? $location['range'] ?? null;

            if (!is_string($uri) || !is_array($range)) {
                continue;
            }

            $path = Text::uriToPath($uri);
            $line = (int) ($range['start']['line'] ?? 0);
            $character = Text::fromServerColumn($this->lineText($path, $line), (int) ($range['start']['character'] ?? 0), $this->encoding);
            $key = "{$path}:{$line}:{$character}";

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $locations[] = ['path' => $path, 'line' => $line, 'character' => $character];
        }

        usort($locations, fn (array $a, array $b): int => [$this->rank($a['path']), $a['path'], $a['line'], $a['character']]
            <=> [$this->rank($b['path']), $b['path'], $b['line'], $b['character']]);

        return $locations;
    }

    /**
     * Locations as lines of `path:line:col  text`, files kept together and separated by a blank
     * line, at most $limit of them.
     *
     * @param list<array{path: string, line: int, character: int}> $locations
     */
    public function locationList(array $locations, int $limit = 50): string
    {
        $out = [];
        $previous = null;

        foreach (array_slice($locations, 0, $limit) as $location) {
            if ($previous !== null && $previous !== $location['path']) {
                $out[] = '';
            }

            $previous = $location['path'];
            $out[] = $this->where($location['path'], $location['line'], $location['character'])
                . '  ' . $this->shown($this->lineText($location['path'], $location['line']));
        }

        $left = count($locations) - $limit;

        if ($left > 0) {
            $files = count(array_unique(array_map(static fn (array $l): string => $l['path'], array_slice($locations, $limit))));
            $out[] = '';
            $out[] = "… and {$left} more in {$files} file(s). Pass a larger `limit`, or narrow the question.";
        }

        return implode("\n", $out);
    }

    /** How many files a list of locations spans. */
    public static function fileCount(array $locations): int
    {
        return count(array_unique(array_map(static fn (array $l): string => $l['path'], $locations)));
    }

    /** `path:line:col`, 1-based, relative to the project when it is inside it. */
    public function where(string $path, int $line, int $character): string
    {
        return Text::relative($path, $this->cwd) . ':' . ($line + 1) . ':' . ($character + 1);
    }

    /** A hover answer as plain text (markdown kept: models read it well). */
    public static function hover(mixed $result, int $maxLength = 4000): string
    {
        if (!is_array($result)) {
            return '';
        }

        $contents = $result['contents'] ?? null;
        $parts = [];

        foreach (is_array($contents) && array_is_list($contents) ? $contents : [$contents] as $part) {
            if (is_string($part)) {
                $parts[] = $part;
            } elseif (is_array($part) && is_string($part['value'] ?? null)) {
                // MarkedString with a language is a code block; MarkupContent is already text.
                $parts[] = isset($part['language']) && is_string($part['language'])
                    ? "```{$part['language']}\n{$part['value']}\n```"
                    : $part['value'];
            }
        }

        $text = trim(implode("\n\n", array_filter(array_map('trim', $parts), static fn (string $p): bool => $p !== '')));

        return mb_strlen($text, 'UTF-8') > $maxLength ? mb_substr($text, 0, $maxLength, 'UTF-8') . "\n…" : $text;
    }

    /**
     * A file's outline, indented by nesting, `line  kind name  detail`.
     *
     * @param list<array{name: string, kind: int, container: ?string, depth: int, range: array<string, mixed>, selection: array<string, mixed>, detail: ?string}> $symbols
     */
    public function outline(array $symbols, int $limit = 200): string
    {
        $out = [];

        foreach (array_slice($symbols, 0, $limit) as $symbol) {
            $line = (int) ($symbol['selection']['start']['line'] ?? 0);
            $out[] = str_pad((string) ($line + 1), 5, ' ', STR_PAD_LEFT) . '  ' . str_repeat('  ', $symbol['depth'])
                . self::kind($symbol['kind']) . ' ' . $symbol['name']
                . ($symbol['detail'] !== null ? '  ' . $this->shown($symbol['detail']) : '');
        }

        if (count($symbols) > $limit) {
            $out[] = '… and ' . (count($symbols) - $limit) . ' more symbols.';
        }

        return implode("\n", $out);
    }

    /**
     * `workspace/symbol` answers as `path:line:col  kind Container::name`.
     *
     * @param array<int, mixed> $result
     */
    public function workspaceSymbols(array $result, int $limit = 50): string
    {
        $rows = [];

        foreach ($result as $symbol) {
            if (!is_array($symbol) || !is_string($symbol['name'] ?? null) || !is_string($symbol['location']['uri'] ?? null)) {
                continue;
            }

            $path = Text::uriToPath($symbol['location']['uri']);
            $start = $symbol['location']['range']['start'] ?? null;
            $line = is_array($start) ? (int) ($start['line'] ?? 0) : null;
            $character = $line !== null ? Text::fromServerColumn($this->lineText($path, $line), (int) ($start['character'] ?? 0), $this->encoding) : 0;
            $container = is_string($symbol['containerName'] ?? null) && $symbol['containerName'] !== '' ? $symbol['containerName'] . '::' : '';
            $rows[] = [
                'rank' => [$this->rank($path), $path, $line ?? 0],
                'text' => ($line !== null ? $this->where($path, $line, $character) : Text::relative($path, $this->cwd))
                    . '  ' . self::kind((int) ($symbol['kind'] ?? 0)) . ' ' . $container . $symbol['name'],
            ];
        }

        usort($rows, static fn (array $a, array $b): int => $a['rank'] <=> $b['rank']);

        // Matches inside a server's bundled stubs (phpactor's `phar://…`) only when nothing on
        // disk matched: they are not files the model can open or change.
        $onDisk = array_values(array_filter($rows, static fn (array $row): bool => $row['rank'][0] < 3));
        $bundled = count($rows) - count($onDisk);

        if ($onDisk !== []) {
            $rows = $onDisk;
        }

        $out = array_map(static fn (array $row): string => $row['text'], array_slice($rows, 0, $limit));

        if (count($rows) > $limit) {
            $out[] = '… and ' . (count($rows) - $limit) . ' more. Make the query more specific.';
        }

        if ($onDisk !== [] && $bundled > 0) {
            $out[] = "({$bundled} more in the server's bundled stubs, not shown.)";
        }

        return implode("\n", $out);
    }

    /**
     * Diagnostics as `path:line:col severity: message [source]`, errors first.
     *
     * @param list<array<string, mixed>> $diagnostics
     */
    public function diagnostics(string $path, array $diagnostics, int $limit = 50, int $minSeverity = 4): string
    {
        $rows = [];

        foreach ($diagnostics as $diagnostic) {
            $severity = (int) ($diagnostic['severity'] ?? 1);

            if ($severity > $minSeverity) {
                continue;
            }

            $line = (int) ($diagnostic['range']['start']['line'] ?? 0);
            $character = Text::fromServerColumn($this->lineText($path, $line), (int) ($diagnostic['range']['start']['character'] ?? 0), $this->encoding);
            $message = trim(preg_replace('/\s+/', ' ', (string) ($diagnostic['message'] ?? '')) ?? '');
            $source = is_string($diagnostic['source'] ?? null) && $diagnostic['source'] !== '' ? " [{$diagnostic['source']}]" : '';
            $rows[] = [
                'rank' => [$severity, $line, $character],
                'text' => $this->where($path, $line, $character) . ' ' . self::severity($severity) . ': ' . $message . $source,
            ];
        }

        usort($rows, static fn (array $a, array $b): int => $a['rank'] <=> $b['rank']);
        $out = array_map(static fn (array $row): string => $row['text'], array_slice($rows, 0, $limit));

        if (count($rows) > $limit) {
            $out[] = '… and ' . (count($rows) - $limit) . ' more.';
        }

        return implode("\n", $out);
    }

    /**
     * A `WorkspaceEdit` as what it would do: per file, each changed line before and after.
     * Nothing is written — the model applies what it agrees with using its own edit tool.
     *
     * @param array<string, mixed> $edit
     * @return array{text: string, files: int, edits: int}
     */
    public function workspaceEdit(array $edit, int $limit = 80): array
    {
        $byFile = [];
        $operations = [];

        foreach (is_array($edit['changes'] ?? null) ? $edit['changes'] : [] as $uri => $edits) {
            $byFile[Text::uriToPath((string) $uri)] = is_array($edits) ? array_values($edits) : [];
        }

        foreach (is_array($edit['documentChanges'] ?? null) ? $edit['documentChanges'] : [] as $change) {
            if (!is_array($change)) {
                continue;
            }

            $kind = $change['kind'] ?? null;

            if ($kind === 'create' && is_string($change['uri'] ?? null)) {
                $operations[] = 'create ' . Text::relative(Text::uriToPath($change['uri']), $this->cwd);
            } elseif ($kind === 'rename' && is_string($change['oldUri'] ?? null) && is_string($change['newUri'] ?? null)) {
                $operations[] = 'rename ' . Text::relative(Text::uriToPath($change['oldUri']), $this->cwd) . ' → ' . Text::relative(Text::uriToPath($change['newUri']), $this->cwd);
            } elseif ($kind === 'delete' && is_string($change['uri'] ?? null)) {
                $operations[] = 'delete ' . Text::relative(Text::uriToPath($change['uri']), $this->cwd);
            } elseif (is_string($change['textDocument']['uri'] ?? null)) {
                $path = Text::uriToPath($change['textDocument']['uri']);
                $byFile[$path] = [...($byFile[$path] ?? []), ...(is_array($change['edits'] ?? null) ? array_values($change['edits']) : [])];
            }
        }

        $out = [];
        $count = 0;
        $shown = 0;

        uksort($byFile, fn (string $a, string $b): int => [$this->rank($a), $a] <=> [$this->rank($b), $b]);

        foreach ($byFile as $path => $edits) {
            $count += count($edits);

            if ($shown >= $limit) {
                continue;
            }

            $out[] = Text::relative($path, $this->cwd) . ' (' . count($edits) . ' edit' . (count($edits) === 1 ? '' : 's') . ')';

            foreach ($this->editedLines($path, $edits) as $row) {
                if ($shown++ >= $limit) {
                    break;
                }

                $out[] = $row;
            }
        }

        if ($shown > $limit) {
            $out[] = '… more edits not shown.';
        }

        foreach ($operations as $operation) {
            $out[] = $operation;
        }

        return ['text' => implode("\n", $out), 'files' => count($byFile), 'edits' => $count];
    }

    /**
     * Each line an edit list touches, before and after; edits spanning lines are summarised.
     *
     * @param list<array<string, mixed>> $edits
     * @return list<string>
     */
    private function editedLines(string $path, array $edits): array
    {
        $single = [];
        $rows = [];

        foreach ($edits as $edit) {
            $start = $edit['range']['start'] ?? null;
            $end = $edit['range']['end'] ?? null;

            if (!is_array($start) || !is_array($end)) {
                continue;
            }

            if ((int) $start['line'] === (int) $end['line'] && !str_contains((string) ($edit['newText'] ?? ''), "\n")) {
                $single[(int) $start['line']][] = $edit;
            } else {
                $rows[(int) $start['line']] = '  ' . ((int) $start['line'] + 1) . '-' . ((int) $end['line'] + 1)
                    . ': replaced by ' . json_encode(mb_strimwidth((string) ($edit['newText'] ?? ''), 0, 160, '…', 'UTF-8'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
        }

        foreach ($single as $line => $lineEdits) {
            $before = $this->lineText($path, $line);
            // Right to left, so each edit's columns still mean what they meant.
            usort($lineEdits, static fn (array $a, array $b): int => (int) $b['range']['start']['character'] <=> (int) $a['range']['start']['character']);
            $after = $before;

            foreach ($lineEdits as $edit) {
                $from = Text::fromServerColumn($before, (int) $edit['range']['start']['character'], $this->encoding);
                $to = Text::fromServerColumn($before, (int) $edit['range']['end']['character'], $this->encoding);
                $after = mb_substr($after, 0, $from, 'UTF-8') . (string) ($edit['newText'] ?? '') . mb_substr($after, $to, null, 'UTF-8');
            }

            $rows[$line] = '  ' . ($line + 1) . ': - ' . $this->shown($before) . "\n" . str_repeat(' ', strlen((string) ($line + 1)) + 4) . '+ ' . $this->shown($after);
        }

        ksort($rows);

        return array_values($rows);
    }

    /** Line $line (0-based) of $path as it is on disk, '' when unreadable. */
    public function lineText(string $path, int $line): string
    {
        if (!array_key_exists($path, $this->files)) {
            $text = is_file($path) && filesize($path) < 8 * 1024 * 1024 ? file_get_contents($path) : false;
            $this->files[$path] = is_string($text) ? Text::lines($text) : null;
        }

        return $this->files[$path][$line] ?? '';
    }

    private function shown(string $text): string
    {
        $text = trim($text);

        return mb_strlen($text, 'UTF-8') > self::MAX_LINE_LENGTH ? mb_substr($text, 0, self::MAX_LINE_LENGTH, 'UTF-8') . '…' : $text;
    }

    /**
     * 0 for the project's own files, 1 for its dependencies, 2 for anything else on disk, 3 for
     * what is not a file at all (a server's bundled stubs, `phar://…`).
     */
    private function rank(string $path): int
    {
        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $path) === 1) {
            return 3;
        }

        $relative = Text::relative($path, $this->cwd);

        if ($relative === $path) {
            return 2;
        }

        return preg_match('#(^|/)(vendor|node_modules|\.dart_tool|target|build)/#', $relative) === 1 ? 1 : 0;
    }
}
