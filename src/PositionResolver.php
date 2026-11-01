<?php

declare(strict_types=1);

namespace PigLsp;

use Pig\Async\AbortSignal;

/**
 * Turns what the model knows — a file, a line, a name — into the position the server needs.
 *
 * Models count columns badly and lines nearly right, so the preferred input is **line + symbol**:
 * the column is found on that line by a whole-word search. That also reaches what upstream could
 * not: upstream resolved a name only through the file's own document symbols, so it found
 * declarations and never a call site — and "where does this call go?" is asked at a call site.
 *
 * With **symbol alone**, declarations in the file come first (exact name, then `Class::method` or
 * `Class.method`, then the same name in another case), then the first whole-word occurrence in the
 * text. Upstream fell back to a substring match, which silently picked `payment` for `pay`; here
 * a near miss is an error that lists the candidates, and a choice among several is said out loud.
 */
final class PositionResolver
{
    /** How far from the given line a symbol is looked for, when it is not on the line itself. */
    private const int NEARBY_LINES = 3;

    /**
     * @param array{line?: mixed, character?: mixed, query?: mixed} $input line and character
     *        1-based, character in characters (not bytes)
     * @return array{line: int, character: int, note: ?string} 0-based, character in code points
     * @throws LspError with a message for the model when nothing fits
     */
    public static function resolve(string $text, array $input, ?LspClient $client = null, ?string $uri = null, ?AbortSignal $signal = null): array
    {
        $lines = Text::lines($text);
        $line = isset($input['line']) && is_numeric($input['line']) ? (int) $input['line'] : null;
        $character = isset($input['character']) && is_numeric($input['character']) ? (int) $input['character'] : null;
        $query = isset($input['query']) && is_string($input['query']) ? trim($input['query']) : '';

        if ($line !== null && ($line < 1 || $line > count($lines))) {
            throw new LspError("Line {$line} is outside the file, which has " . count($lines) . ' lines.');
        }

        if ($line !== null && $character !== null && $query === '') {
            $length = mb_strlen($lines[$line - 1], 'UTF-8');

            return ['line' => $line - 1, 'character' => max(0, min($character - 1, $length)), 'note' => null];
        }

        if ($query === '') {
            throw new LspError('Give `query` (the symbol\'s name), with `line` when you know it; or `line` and `character`.');
        }

        $name = self::lastSegment($query);

        if ($line !== null) {
            $found = self::findOnLine($lines[$line - 1], $name, $character);

            if ($found !== null) {
                return ['line' => $line - 1, 'character' => $found, 'note' => null];
            }

            for ($distance = 1; $distance <= self::NEARBY_LINES; $distance++) {
                foreach ([$line - $distance, $line + $distance] as $candidate) {
                    if ($candidate >= 1 && $candidate <= count($lines)) {
                        $found = self::findOnLine($lines[$candidate - 1], $name, null);

                        if ($found !== null) {
                            return ['line' => $candidate - 1, 'character' => $found, 'note' => "`{$name}` is not on line {$line}; used line {$candidate}."];
                        }
                    }
                }
            }

            throw new LspError("`{$name}` does not appear on line {$line} (or within " . self::NEARBY_LINES . " lines of it). Line {$line} is: " . trim($lines[$line - 1]));
        }

        $symbols = [];

        if ($client !== null && $uri !== null && $client->supports('documentSymbolProvider')) {
            try {
                $result = $client->request('textDocument/documentSymbol', ['textDocument' => ['uri' => $uri]], null, $signal);
                $symbols = self::flattenSymbols(is_array($result) ? $result : []);
            } catch (LspError) {
                // The text search below still works without the outline.
            }
        }

        $match = self::matchSymbol($symbols, $query);

        if ($match !== null) {
            [$symbol, $others] = $match;
            $position = self::symbolPosition($symbol, $lines, $client?->positionEncoding() ?? Text::UTF16);
            $note = $others === [] ? null : 'Several declarations match; used ' . self::describe($symbol, $position['line'])
                . '. Others: ' . implode(', ', array_map(
                    fn (array $other): string => self::describe($other, self::symbolPosition($other, $lines, $client?->positionEncoding() ?? Text::UTF16)['line']),
                    array_slice($others, 0, 5),
                )) . '. Pass `line` to choose.';

            return ['line' => $position['line'], 'character' => $position['character'], 'note' => $note];
        }

        foreach ($lines as $index => $lineText) {
            $found = self::findOnLine($lineText, $name, null);

            if ($found !== null) {
                return ['line' => $index, 'character' => $found, 'note' => "No declaration named `{$query}` in this file; used its first occurrence, line " . ($index + 1) . '. Pass `line` to choose another.'];
            }
        }

        $close = [];

        foreach ($symbols as $symbol) {
            if (stripos($symbol['name'], $name) !== false || stripos($name, $symbol['name']) !== false) {
                $close[] = $symbol['name'];
            }
        }

        throw new LspError("`{$query}` was not found in this file." . ($close !== [] ? ' Similar names: ' . implode(', ', array_slice(array_unique($close), 0, 8)) . '.' : ''));
    }

    /**
     * Document symbols as one flat list, children after their parent, each knowing its container.
     * Takes both answers the spec allows: hierarchical `DocumentSymbol[]` and flat
     * `SymbolInformation[]`.
     *
     * @param array<int, mixed> $result
     * @return list<array{name: string, kind: int, container: ?string, depth: int, range: array<string, mixed>, selection: array<string, mixed>, detail: ?string}>
     */
    public static function flattenSymbols(array $result, ?string $container = null, int $depth = 0): array
    {
        $flat = [];

        foreach ($result as $symbol) {
            if (!is_array($symbol) || !is_string($symbol['name'] ?? null)) {
                continue;
            }

            $range = $symbol['range'] ?? $symbol['location']['range'] ?? null;

            if (!is_array($range)) {
                continue;
            }

            $flat[] = [
                'name' => $symbol['name'],
                'kind' => (int) ($symbol['kind'] ?? 0),
                'container' => $container ?? (is_string($symbol['containerName'] ?? null) && $symbol['containerName'] !== '' ? $symbol['containerName'] : null),
                'depth' => $depth,
                'range' => $range,
                'selection' => is_array($symbol['selectionRange'] ?? null) ? $symbol['selectionRange'] : $range,
                'detail' => is_string($symbol['detail'] ?? null) && $symbol['detail'] !== '' ? $symbol['detail'] : null,
            ];

            if (is_array($symbol['children'] ?? null)) {
                array_push($flat, ...self::flattenSymbols($symbol['children'], $symbol['name'], $depth + 1));
            }
        }

        return $flat;
    }

    /**
     * The code-point column of $name as a whole word on $lineText, the occurrence nearest
     * $nearColumn (1-based) when given, else the first. Null when it is not there.
     */
    public static function findOnLine(string $lineText, string $name, ?int $nearColumn): ?int
    {
        if ($name === '') {
            return null;
        }

        // A word boundary that knows `$` and `\` belong to PHP names and that letters are not ASCII.
        $pattern = '/(?<![\p{L}\p{N}_$])' . preg_quote($name, '/') . '(?![\p{L}\p{N}_])/u';

        if (preg_match_all($pattern, $lineText, $matches, PREG_OFFSET_CAPTURE) < 1) {
            // `$amount` asked as `amount`, or the other way round.
            $alternative = str_starts_with($name, '$') ? substr($name, 1) : '$' . $name;
            $pattern = '/(?<![\p{L}\p{N}_$])' . preg_quote($alternative, '/') . '(?![\p{L}\p{N}_])/u';

            if (preg_match_all($pattern, $lineText, $matches, PREG_OFFSET_CAPTURE) < 1) {
                return null;
            }
        }

        $columns = array_map(static fn (array $match): int => Text::codePointsBefore($lineText, $match[1]), $matches[0]);

        if ($nearColumn === null) {
            return $columns[0];
        }

        usort($columns, static fn (int $a, int $b): int => abs($a - ($nearColumn - 1)) <=> abs($b - ($nearColumn - 1)));

        return $columns[0];
    }

    /** `Pay::charge`, `Pay.charge`, `$this->charge`, `charge()` → `charge`. */
    public static function lastSegment(string $query): string
    {
        $query = preg_replace('/\(.*$/s', '', trim($query)) ?? $query;
        $parts = preg_split('/::|->|\.|\\\\/', $query) ?: [$query];
        $last = trim((string) end($parts));

        return $last !== '' ? $last : $query;
    }

    /**
     * @param list<array{name: string, kind: int, container: ?string, depth: int, range: array<string, mixed>, selection: array<string, mixed>, detail: ?string}> $symbols
     * @return array{0: array<string, mixed>, 1: list<array<string, mixed>>}|null the match and the
     *         other equally good ones
     */
    private static function matchSymbol(array $symbols, string $query): ?array
    {
        if ($symbols === []) {
            return null;
        }

        $name = self::lastSegment($query);
        $parent = null;

        if (preg_match('/^(.+?)(?:::|->|\.)([^:.>]+)$/', preg_replace('/\(.*$/s', '', $query) ?? $query, $match) === 1) {
            $parent = self::lastSegment($match[1]);
        }

        $bare = static fn (string $value): string => ltrim($value, '$');
        $tests = [
            static fn (array $symbol): bool => $bare($symbol['name']) === $bare($name)
                && ($parent === null || ($symbol['container'] !== null && $bare(self::lastSegment($symbol['container'])) === $bare($parent))),
            static fn (array $symbol): bool => strcasecmp($bare($symbol['name']), $bare($name)) === 0
                && ($parent === null || ($symbol['container'] !== null && strcasecmp(self::lastSegment($symbol['container']), $parent) === 0)),
        ];

        // A name like `charge(int $amount)` from some servers' outline still matches `charge`.
        $tests[] = static fn (array $symbol): bool => strcasecmp($bare(self::lastSegment($symbol['name'])), $bare($name)) === 0 && $parent === null;

        foreach ($tests as $test) {
            $found = array_values(array_filter($symbols, $test));

            if ($found !== []) {
                return [$found[0], array_slice($found, 1)];
            }
        }

        return null;
    }

    /**
     * Where a symbol's name is: its selection range, or — for the flat answer, whose range is the
     * whole declaration — the name searched for on the declaration's first line.
     *
     * @param array<string, mixed> $symbol
     * @param list<string>         $lines
     * @return array{line: int, character: int}
     */
    private static function symbolPosition(array $symbol, array $lines, string $encoding): array
    {
        $start = $symbol['selection']['start'] ?? ['line' => 0, 'character' => 0];
        $line = (int) ($start['line'] ?? 0);
        $lineText = $lines[$line] ?? '';
        $character = Text::fromServerColumn($lineText, (int) ($start['character'] ?? 0), $encoding);

        if ($symbol['selection'] === $symbol['range']) {
            $found = self::findOnLine(mb_substr($lineText, $character, null, 'UTF-8'), self::lastSegment($symbol['name']), null);

            if ($found !== null) {
                $character += $found;
            }
        }

        return ['line' => $line, 'character' => $character];
    }

    /** @param array<string, mixed> $symbol */
    private static function describe(array $symbol, int $line): string
    {
        return ($symbol['container'] !== null ? $symbol['container'] . '::' : '') . $symbol['name'] . ' (line ' . ($line + 1) . ')';
    }
}
