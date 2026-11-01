<?php

declare(strict_types=1);

namespace PigLsp\Test\Unit;

use PHPUnit\Framework\TestCase;
use PigLsp\Framing;

final class FramingTest extends TestCase
{
    public function testTheLengthCountsBytes(): void
    {
        $frame = Framing::encode(['jsonrpc' => '2.0', 'method' => 'x', 'params' => ['text' => '支付']]);
        [$header, $body] = explode("\r\n\r\n", $frame, 2);

        self::assertSame('Content-Length: ' . strlen($body), $header);
        self::assertStringContainsString('支付', $body);
    }

    public function testMessagesSplitAtEveryByteAreReassembled(): void
    {
        $stream = Framing::encode(['id' => 1, 'result' => ['a' => 'é']]) . Framing::encode(['id' => 2, 'result' => null]);

        for ($cut = 1; $cut < strlen($stream); $cut++) {
            $framing = new Framing();
            $messages = [...$framing->push(substr($stream, 0, $cut)), ...$framing->push(substr($stream, $cut))];

            self::assertSame([1, 2], array_column($messages, 'id'), "cut at {$cut}");
            self::assertSame(0, $framing->pending());
        }
    }

    public function testSeveralMessagesInOneChunk(): void
    {
        $framing = new Framing();
        $messages = $framing->push(Framing::encode(['id' => 1]) . Framing::encode(['id' => 2]) . Framing::encode(['id' => 3]));

        self::assertCount(3, $messages);
    }

    public function testExtraHeadersAreAllowed(): void
    {
        $body = '{"id":7}';
        $messages = (new Framing())->push("Content-Type: application/vscode-jsonrpc; charset=utf-8\r\ncontent-length: " . strlen($body) . "\r\n\r\n" . $body);

        self::assertSame([['id' => 7]], $messages);
    }

    public function testABadMessageCostsOnlyItself(): void
    {
        $framing = new Framing();
        $messages = $framing->push("X-Nothing: 1\r\n\r\n" . "Content-Length: 5\r\n\r\n{nope" . Framing::encode(['id' => 9]));

        self::assertSame([['id' => 9]], $messages);
        self::assertCount(2, $framing->takeErrors());
    }
}
