<?php

declare(strict_types=1);

namespace PigLsp\Test\Unit;

use PHPUnit\Framework\TestCase;
use PigLsp\LspError;
use PigLsp\PositionResolver;

final class PositionResolverTest extends TestCase
{
    private const string CODE = <<<'PHP'
        <?php
        interface Pay { public function charge(int $amount): string; }
        final class Checkout {
            public function run(Pay $pay, int $金额): string {
                $payment = $pay->charge($金额);
                return $pay->charge(1) . $payment;
            }
        }
        PHP;

    public function testLineAndQueryFindTheColumn(): void
    {
        $at = PositionResolver::resolve(self::CODE, ['line' => 5, 'query' => 'charge']);

        self::assertSame(4, $at['line']);
        self::assertSame(mb_strpos('        $payment = $pay->charge($金额);', 'charge'), $at['character']);
        self::assertNull($at['note']);
    }

    public function testAQualifiedQueryUsesItsLastPart(): void
    {
        self::assertSame(
            PositionResolver::resolve(self::CODE, ['line' => 5, 'query' => 'charge'])['character'],
            PositionResolver::resolve(self::CODE, ['line' => 5, 'query' => 'Pay::charge()'])['character'],
        );
        self::assertSame('charge', PositionResolver::lastSegment('$this->pay->charge'));
        self::assertSame('Pay', PositionResolver::lastSegment('App\\Billing\\Pay'));
    }

    public function testAWholeWordIsRequired(): void
    {
        // `pay` must not match inside `$payment` or `Pay`: on line 5 it is the `$pay` variable.
        $at = PositionResolver::resolve(self::CODE, ['line' => 5, 'query' => '$pay']);

        self::assertSame(mb_strpos('        $payment = $pay->charge($金额);', '$pay->'), $at['character']);
    }

    public function testCharacterPicksTheNearestOccurrence(): void
    {
        $text = "<?php\n\$a = max(1, 2) + max(3, 4);";
        $second = strrpos('$a = max(1, 2) + max(3, 4);', 'max');

        self::assertSame(5, PositionResolver::resolve($text, ['line' => 2, 'query' => 'max'])['character']);
        self::assertSame($second, PositionResolver::resolve($text, ['line' => 2, 'query' => 'max', 'character' => 20])['character']);
    }

    public function testAVariableIsFoundWithOrWithoutItsDollar(): void
    {
        $line = '        return $pay->charge(1) . $payment;';

        self::assertSame(mb_strpos($line, '$pay'), PositionResolver::resolve(self::CODE, ['line' => 6, 'query' => 'pay'])['character']);
        self::assertSame(mb_strpos($line, '$payment'), PositionResolver::resolve(self::CODE, ['line' => 6, 'query' => '$payment'])['character']);
    }

    public function testASymbolOnANearbyLineIsUsedAndSaid(): void
    {
        $at = PositionResolver::resolve(self::CODE, ['line' => 4, 'query' => 'payment']);

        self::assertSame(4, $at['line']);
        self::assertStringContainsString('not on line 4; used line 5', (string) $at['note']);
    }

    public function testASymbolNotThereIsAnErrorWithTheLine(): void
    {
        $this->expectException(LspError::class);
        $this->expectExceptionMessage('Line 2 is: interface Pay');

        PositionResolver::resolve(self::CODE, ['line' => 2, 'query' => 'refund']);
    }

    public function testQueryAloneFallsBackToTheFirstOccurrence(): void
    {
        $at = PositionResolver::resolve(self::CODE, ['query' => 'charge']);

        self::assertSame(1, $at['line']);
        self::assertStringContainsString('first occurrence, line 2', (string) $at['note']);
    }

    public function testNoSubstringGuessing(): void
    {
        $this->expectException(LspError::class);
        $this->expectExceptionMessage('`char` was not found');

        PositionResolver::resolve(self::CODE, ['query' => 'char']);
    }

    public function testLineAndCharacterAreTakenAsGiven(): void
    {
        self::assertSame(['line' => 1, 'character' => 9, 'note' => null], PositionResolver::resolve(self::CODE, ['line' => 2, 'character' => 10]));
    }

    public function testALineOutsideTheFileIsAnError(): void
    {
        $this->expectExceptionMessage('outside the file');

        PositionResolver::resolve(self::CODE, ['line' => 99, 'query' => 'x']);
    }

    public function testFlattenSymbolsTakesBothShapes(): void
    {
        $range = ['start' => ['line' => 1, 'character' => 0], 'end' => ['line' => 1, 'character' => 5]];
        $hierarchical = PositionResolver::flattenSymbols([
            ['name' => 'Pay', 'kind' => 11, 'range' => $range, 'selectionRange' => $range, 'children' => [
                ['name' => 'charge', 'kind' => 6, 'range' => $range, 'selectionRange' => $range],
            ]],
        ]);
        $flat = PositionResolver::flattenSymbols([
            ['name' => 'charge', 'kind' => 6, 'containerName' => 'Pay', 'location' => ['uri' => 'file:///x', 'range' => $range]],
        ]);

        self::assertSame(['Pay', 'charge'], array_column($hierarchical, 'name'));
        self::assertSame('Pay', $hierarchical[1]['container']);
        self::assertSame(1, $hierarchical[1]['depth']);
        self::assertSame('Pay', $flat[0]['container']);
    }
}
