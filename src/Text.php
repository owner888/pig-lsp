<?php

declare(strict_types=1);

namespace PigLsp;

/**
 * Paths, URIs and columns — the three places an LSP client gets quietly wrong.
 *
 * **Columns are not bytes.** LSP counts a position's `character` in UTF-16 code units unless the
 * server agrees to something else at `initialize` (`positionEncoding`, LSP 3.17). Upstream never
 * had to think about it: a JavaScript string index *is* a UTF-16 index. In PHP a string offset is
 * a byte, so a line with one Chinese character before the symbol sent the server two columns too
 * far. Every column crosses this class: into the server in its encoding, out of it as the column
 * a person counts (code points, 1-based), which is what the tools take and print.
 */
final class Text
{
    /** The server's column unit, as `initialize` settled it. */
    public const string UTF16 = 'utf-16';

    public const string UTF8 = 'utf-8';

    public const string UTF32 = 'utf-32';

    /** @return list<string> the file's lines, without their line endings */
    public static function lines(string $text): array
    {
        return explode("\n", str_replace("\r\n", "\n", $text));
    }

    /** One line of $text, 0-based; '' past the end. */
    public static function line(string $text, int $line): string
    {
        return self::lines($text)[$line] ?? '';
    }

    /**
     * A person's column — code points before it, 0-based — as the server counts it.
     */
    public static function toServerColumn(string $lineText, int $codePoints, string $encoding): int
    {
        $prefix = mb_substr($lineText, 0, max(0, $codePoints), 'UTF-8');

        return match ($encoding) {
            self::UTF8 => strlen($prefix),
            self::UTF32 => mb_strlen($prefix, 'UTF-8'),
            default => self::utf16Length($prefix),
        };
    }

    /**
     * The server's column, back as code points before it (0-based). A column past the end of the
     * line is the end of the line: a server that answered a stale version of the file must not
     * make the output lie about where on the line it is.
     */
    public static function fromServerColumn(string $lineText, int $column, string $encoding): int
    {
        if ($column <= 0) {
            return 0;
        }

        if ($encoding === self::UTF8) {
            return mb_strlen(substr($lineText, 0, $column), 'UTF-8');
        }

        if ($encoding === self::UTF32) {
            return min($column, mb_strlen($lineText, 'UTF-8'));
        }

        $units = 0;
        $points = 0;

        foreach (mb_str_split($lineText, 1, 'UTF-8') as $char) {
            if ($units >= $column) {
                break;
            }

            $units += mb_ord($char, 'UTF-8') > 0xFFFF ? 2 : 1;
            $points++;
        }

        return $points;
    }

    /** UTF-16 code units in $text: one per character, two for one outside the BMP (most emoji). */
    public static function utf16Length(string $text): int
    {
        return intdiv(strlen((string) mb_convert_encoding($text, 'UTF-16LE', 'UTF-8')), 2);
    }

    /** A byte offset into a line, as the code points before it. */
    public static function codePointsBefore(string $lineText, int $byteOffset): int
    {
        return mb_strlen(substr($lineText, 0, $byteOffset), 'UTF-8');
    }

    public static function pathToUri(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        // A Windows drive letter: `file:///C:/…`, its colon kept as it is.
        $prefix = preg_match('#^[A-Za-z]:/#', $path) === 1 ? '/' : '';
        $segments = array_map(
            static fn (string $segment): string => preg_match('#^[A-Za-z]:$#', $segment) === 1 ? $segment : rawurlencode($segment),
            explode('/', $path),
        );

        return 'file://' . $prefix . implode('/', $segments);
    }

    public static function uriToPath(string $uri): string
    {
        if (!str_starts_with($uri, 'file://')) {
            return $uri;
        }

        $path = rawurldecode(substr($uri, 7));
        // `file://host/…` is not a local path anywhere pig runs; only the empty host is.
        if (preg_match('#^/[A-Za-z]:/#', $path) === 1) {
            $path = substr($path, 1);
        }

        return $path;
    }

    /** $path relative to $root when it is under it, otherwise as it is. */
    public static function relative(string $path, string $root): string
    {
        $root = rtrim($root, '/');

        if ($root !== '' && str_starts_with($path, $root . '/')) {
            return substr($path, strlen($root) + 1);
        }

        return $path;
    }

    /** $path made absolute against $cwd; `~` is the home directory; `@` is a model's mention. */
    public static function absolute(string $path, string $cwd): string
    {
        $path = ltrim($path, '@');

        if (str_starts_with($path, '~/')) {
            $home = getenv('HOME');
            $path = (is_string($home) ? $home : '') . substr($path, 1);
        }

        if (!str_starts_with($path, '/') && preg_match('#^[A-Za-z]:[\\\\/]#', $path) !== 1) {
            $path = rtrim($cwd, '/') . '/' . $path;
        }

        // `..` and `.` folded, without realpath(): a file being created does not exist yet.
        $parts = [];

        foreach (explode('/', str_replace('\\', '/', $path)) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }

            if ($part === '..') {
                array_pop($parts);

                continue;
            }

            $parts[] = $part;
        }

        return (preg_match('#^[A-Za-z]:$#', $parts[0] ?? '') === 1 ? '' : '/') . implode('/', $parts);
    }
}
