<?php

declare(strict_types=1);

namespace App\Tests\Unit\Engine;

use App\Engine\EngineApi;
use App\Model\MoveData;
use App\Model\MovesData;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class EngineApiEvaluateMovesTest extends TestCase
{
    public function testDecodesMovesAndSignedScoresInTheEnginesOrder(): void
    {
        $body = pack('v', 0x0123).pack('V', 250)
            .pack('v', 0x4456).pack('V', 0xFFFFFFFF - 36) // -37
            .pack('v', 0x0001).pack('V', 0x80000000); // i32 minimum

        $moves = $this->api([new MockResponse($body)])->evaluateMoves($this->history());

        self::assertCount(3, $moves);
        self::assertSame(pack('v', 0x0123), $moves[0]->move->data);
        self::assertSame(250, $moves[0]->score);
        self::assertSame(pack('v', 0x4456), $moves[1]->move->data);
        self::assertSame(-37, $moves[1]->score);
        self::assertSame(-2147483648, $moves[2]->score);
    }

    public function testFinishedGameIsAnEmptyList(): void
    {
        self::assertSame([], $this->api([new MockResponse('')])->evaluateMoves($this->history()));
    }

    public function testTruncatedResponseIsRejected(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->api([new MockResponse(str_repeat("\0", 7))])->evaluateMoves($this->history());
    }

    public function testFallsBackToTheLocalBackendWhenTheAiBackendFails(): void
    {
        $urls = [];
        $responses = [
            new MockResponse('', ['http_code' => 503]),
            new MockResponse(pack('v', 0x0123).pack('V', 5)),
        ];
        $client = new MockHttpClient(static function (string $method, string $url) use (&$urls, &$responses): MockResponse {
            $urls[] = $method.' '.$url;

            return array_shift($responses);
        });

        $moves = (new EngineApi($client, 'http://local', 'http://ai'))->evaluateMoves($this->history());

        self::assertSame(['POST http://ai/evaluate-moves', 'POST http://local/evaluate-moves'], $urls);
        self::assertSame(5, $moves[0]->score);
    }

    /**
     * @param list<MockResponse> $responses
     */
    private function api(array $responses): EngineApi
    {
        return new EngineApi(new MockHttpClient($responses), 'http://local', 'http://ai');
    }

    private function history(): MovesData
    {
        return (new MovesData())->addMove(new MoveData(pack('v', 0x0A0B)));
    }
}
