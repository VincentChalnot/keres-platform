<?php

declare(strict_types=1);

namespace App\Tests\Unit\Model;

use App\Model\SpeedCategory;
use App\Model\TimeControl;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * F10 (06-rating.md sec 9.4): `estimated = initial + 40*increment` pool
 * boundaries (sec 5.1/5.2). The chapter names `SpeedCategory::fromTimeControl()`
 * but that logic actually lives on `TimeControl::speedCategory()`
 * (no separate static exists) - same predicate, tested where it lives.
 */
final class TimeControlSpeedCategoryTest extends TestCase
{
    public function testUnlimitedHasNoCategory(): void
    {
        self::assertNull(TimeControl::unlimited()->speedCategory());
    }

    public function testCorrespondenceIsAlwaysItsOwnCategoryRegardlessOfDaysPerMove(): void
    {
        self::assertSame(SpeedCategory::CORRESPONDENCE, TimeControl::correspondence(1)->speedCategory());
        self::assertSame(SpeedCategory::CORRESPONDENCE, TimeControl::correspondence(7)->speedCategory());
    }

    #[DataProvider('realtimeBoundaryProvider')]
    public function testRealtimeBoundaries(int $initialSeconds, int $incrementSeconds, SpeedCategory $expected): void
    {
        self::assertSame($expected, TimeControl::realtime($initialSeconds, $incrementSeconds)->speedCategory());
    }

    public static function realtimeBoundaryProvider(): iterable
    {
        // estimated = initial + 40*increment (sec 5.1)
        yield '2+1 = 160 -> bullet' => [2, 1, SpeedCategory::BULLET];
        yield '0+2 = 80 -> bullet' => [0, 2, SpeedCategory::BULLET];
        yield '3+0 = 180 -> first blitz' => [180, 0, SpeedCategory::BLITZ];
        yield '5+3 = 420 -> blitz' => [300, 3, SpeedCategory::BLITZ];
        yield '8+0 = 480 -> first rapid' => [480, 0, SpeedCategory::RAPID];
        yield '10+5 = 800 -> rapid' => [600, 5, SpeedCategory::RAPID];
        yield '15+10 = 1300 -> rapid, not classical' => [900, 10, SpeedCategory::RAPID];
        yield '25+0 = 1500 -> first classical' => [1500, 0, SpeedCategory::CLASSICAL];
        yield '15+15 = 1500 -> classical, not rapid' => [900, 15, SpeedCategory::CLASSICAL];
    }
}
