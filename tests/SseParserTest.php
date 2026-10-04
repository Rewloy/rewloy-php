<?php

declare(strict_types=1);

namespace Rewloy\Tests;

use PHPUnit\Framework\TestCase;
use Rewloy\ServerSentEvent;
use Rewloy\SseParser;

final class SseParserTest extends TestCase
{
    /**
     * Parses text fed in the given pieces.
     *
     * @param list<string> $pieces
     * @return array{list<array{string, string, string}>, SseParser}
     */
    private static function parse(array $pieces, string $lastEventId = ''): array
    {
        $parser = new SseParser($lastEventId);
        $events = [];
        foreach ($pieces as $piece) {
            foreach ($parser->push($piece) as $event) {
                $events[] = self::plain($event);
            }
        }
        return [$events, $parser];
    }

    /** @return array{string, string, string} */
    private static function plain(ServerSentEvent $e): array
    {
        return [$e->event, $e->data, $e->id];
    }

    /**
     * Every way of cutting the text in two, one piece per byte, and the whole.
     *
     * @return list<list<string>>
     */
    private static function cuts(string $text): array
    {
        $out = [[$text], str_split($text)];
        for ($i = 1; $i < strlen($text); $i++) {
            $out[] = [substr($text, 0, $i), substr($text, $i)];
        }
        return $out;
    }

    public function testParsesTheApisOwnStreamCutAnywhere(): void
    {
        $text = "retry: 5000\n\n: hb\n\nevent: event\ndata: {\"kind\":\"earn\",\"delta\":2}\n\nevent: changed\ndata: 1\n\n";
        foreach (self::cuts($text) as $pieces) {
            [$events, $parser] = self::parse($pieces);
            self::assertSame([['event', '{"kind":"earn","delta":2}', ''], ['changed', '1', '']], $events, (string) json_encode($pieces));
            self::assertSame(5000, $parser->retry);
        }
    }

    public function testTakesLfCrAndCrlfLineEndingsWhereverAPieceEnds(): void
    {
        $expected = [['message', "a\nb", ''], ['x', 'c', '']];
        foreach (["data: a\ndata: b\n\nevent: x\ndata: c\n\n", "data: a\rdata: b\r\revent: x\rdata: c\r\r", "data: a\r\ndata: b\r\n\r\nevent: x\r\ndata: c\r\n\r\n"] as $text) {
            foreach (self::cuts($text) as $pieces) {
                self::assertSame($expected, self::parse($pieces)[0], (string) json_encode($pieces));
            }
        }
    }

    public function testKeepsMultiByteCharactersSplitAcrossPieces(): void
    {
        $text = "event: event\r\ndata: {\"name\":\"Ayşe\",\"location\":\"Moda Şubesi\"}\r\n\r\n: hb\r\n\r\n";
        foreach (self::cuts($text) as $pieces) {
            self::assertSame([['event', '{"name":"Ayşe","location":"Moda Şubesi"}', '']], self::parse($pieces)[0]);
        }
    }

    public function testReadsFieldsAsTheStandardSays(): void
    {
        [$events, $parser] = self::parse([
            "\xEF\xBB\xBFdata:no space\n",      // BOM dropped; no space after the colon
            "data:  two spaces\n",              // only one space is removed
            "data\n",                           // a field name alone: empty value
            "ignored: field\n",
            "id: 7\n\n",
            "data: next\n\n",                   // the last event ID carries over
            "id: bad\0id\ndata: x\n\n",         // an id with NULL is ignored
            "retry: 12a\nretry: 250\n",         // only digits count
            "event: lonely\n\n",                // no data: no event, and the type resets
            "data: after\n\n",
            "id\ndata: cleared\n\n",            // an empty id clears it
            'data: unfinished',                 // no blank line: dropped at the end
        ]);
        self::assertSame([
            ['message', "no space\n two spaces\n", '7'],
            ['message', 'next', '7'],
            ['message', 'x', '7'],
            ['message', 'after', '7'],
            ['message', 'cleared', ''],
        ], $events);
        self::assertSame(250, $parser->retry);
        $parser->end();
        self::assertSame([], $parser->push("\n"));
    }

    public function testDropsAByteOrderMarkSplitAcrossPieces(): void
    {
        foreach ([["\xEF", "\xBB\xBFdata: x\n\n"], ["\xEF\xBB", "\xBF", "data: x\n\n"], ["\xEF", "\xBB", "\xBF", 'data: x', "\n\n"]] as $pieces) {
            self::assertSame([['message', 'x', '']], self::parse($pieces)[0], implode(' ', array_map(bin2hex(...), $pieces)));
        }
        // A short first piece that cannot begin a mark starts the stream at once.
        self::assertSame([['message', 'y', '']], self::parse(['da', "ta: y\n\n"])[0]);
        // A byte that only begins like a mark stays: here it spoils the field name, so no event.
        self::assertSame([], self::parse(["\xEF", "data: x\n\n"])[0]);
        // A mark is dropped only at the very start of the stream.
        self::assertSame([['message', 'x', '']], self::parse(["data: x\n\n", "\xEF\xBB\xBFdata: z\n\n"])[0]);
    }

    public function testDispatchesAnEventWhoseDataIsEmpty(): void
    {
        self::assertSame([['message', '', ''], ['message', '', '']], self::parse(["data\n\ndata:\n\n"])[0]);
    }

    public function testStartsFromALastEventIdItIsGiven(): void
    {
        self::assertSame([['message', 'x', '41']], self::parse(["data: x\n\n"], '41')[0]);
    }

    public function testTakesTheLastEventIdAtABlankLineEvenWithoutData(): void
    {
        [$events, $parser] = self::parse(["id: 5\n\n", "id: 6\n"]);
        self::assertSame([], $events);
        self::assertSame('5', $parser->lastEventId);
        $parser->push("\n");
        self::assertSame('6', $parser->lastEventId);
    }
}
