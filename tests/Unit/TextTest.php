<?php

declare(strict_types=1);

namespace PigLsp\Test\Unit;

use PHPUnit\Framework\TestCase;
use PigLsp\Text;

final class TextTest extends TestCase
{
    public function testColumnsCountCharactersNotBytes(): void
    {
        $line = '$金额 = $pay->charge(1);';
        $at = mb_strpos($line, 'charge');

        self::assertSame(12, $at);
        // UTF-16: every character here is one unit.
        self::assertSame(12, Text::toServerColumn($line, $at, Text::UTF16));
        // UTF-8: the two Chinese characters are three bytes each.
        self::assertSame(16, Text::toServerColumn($line, $at, Text::UTF8));
        self::assertSame($at, Text::fromServerColumn($line, 12, Text::UTF16));
        self::assertSame($at, Text::fromServerColumn($line, 16, Text::UTF8));
    }

    public function testAnEmojiIsTwoUtf16Units(): void
    {
        $line = '// 😀 then pay()';
        $at = mb_strpos($line, 'pay');

        self::assertSame($at + 1, Text::toServerColumn($line, $at, Text::UTF16));
        self::assertSame($at, Text::fromServerColumn($line, $at + 1, Text::UTF16));
        self::assertSame($at, Text::toServerColumn($line, $at, Text::UTF32));
    }

    public function testAColumnPastTheEndIsTheEnd(): void
    {
        self::assertSame(3, Text::fromServerColumn('abc', 99, Text::UTF16));
        self::assertSame(0, Text::fromServerColumn('abc', -1, Text::UTF16));
    }

    public function testUrisRoundTrip(): void
    {
        foreach (['/tmp/a b/支付.php', '/srv/x#y/%z.php', '/plain/file.dart'] as $path) {
            $uri = Text::pathToUri($path);

            self::assertStringStartsWith('file:///', $uri);
            self::assertStringNotContainsString(' ', $uri);
            self::assertSame($path, Text::uriToPath($uri));
        }

        self::assertSame('file:///C:/work/a.php', Text::pathToUri('C:\\work\\a.php'));
        self::assertSame('C:/work/a.php', Text::uriToPath('file:///C:/work/a.php'));
    }

    public function testPathsAreMadeAbsoluteWithoutTouchingTheDisk(): void
    {
        self::assertSame('/p/src/A.php', Text::absolute('src/A.php', '/p'));
        self::assertSame('/p/A.php', Text::absolute('./src/../A.php', '/p/'));
        self::assertSame('/q/B.php', Text::absolute('/q/B.php', '/p'));
        self::assertSame('/p/src/C.php', Text::absolute('@src/C.php', '/p'));
        self::assertSame('src/A.php', Text::relative('/p/src/A.php', '/p'));
        self::assertSame('/elsewhere/A.php', Text::relative('/elsewhere/A.php', '/p'));
    }

    public function testLinesHandleCrlf(): void
    {
        self::assertSame(['a', 'b', ''], Text::lines("a\r\nb\n"));
        self::assertSame('b', Text::line("a\nb", 1));
        self::assertSame('', Text::line("a\nb", 7));
    }
}
