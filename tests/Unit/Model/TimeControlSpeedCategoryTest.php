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
        // estimated = initial + 40*increment (sec 5.1), Keres-tuned boundaries 300/900/3000
        yield '2+1 = 160 -> bullet' => [120, 1, SpeedCategory::BULLET];
        yield '0+2 = 80 -> bullet' => [0, 2, SpeedCategory::BULLET];
        yield '3+2 = 260 -> bullet (lobby default)' => [180, 2, SpeedCategory::BULLET];
        yield '5+0 = 300 -> first blitz' => [300, 0, SpeedCategory::BLITZ];
        yield '7+5 = 620 -> blitz (lobby default)' => [420, 5, SpeedCategory::BLITZ];
        yield '15+0 = 900 -> first rapid' => [900, 0, SpeedCategory::RAPID];
        yield '20+10 = 1600 -> rapid (lobby default)' => [1200, 10, SpeedCategory::RAPID];
        yield '30+15 = 2400 -> rapid, not classical' => [1800, 15, SpeedCategory::RAPID];
        yield '50+0 = 3000 -> first classical' => [3000, 0, SpeedCategory::CLASSICAL];
        yield '100+0 = 6000 -> classical (lobby default)' => [6000, 0, SpeedCategory::CLASSICAL];
    }
}
