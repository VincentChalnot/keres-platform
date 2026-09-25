<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\User;
use PHPUnit\Framework\TestCase;

/** 00-overview.md R2 / 05-social.md sec 1.6: one username change every 12 months. */
final class UserUsernameChangeTest extends TestCase
{
    public function testNeverChangedCanChangeNow(): void
    {
        $user = new User('a@example.com');

        self::assertTrue($user->canChangeUsername(new \DateTimeImmutable('2026-09-25')));
        self::assertNull($user->getNextUsernameChangeAt());
    }

    public function testCooldownRunsTwelveMonths(): void
    {
        $user = new User('a@example.com');
        $user->setUsernameChangedAt(new \DateTimeImmutable('2026-09-25 10:00:00+00:00'));

        self::assertEquals(new \DateTimeImmutable('2027-09-25 10:00:00+00:00'), $user->getNextUsernameChangeAt());
        self::assertFalse($user->canChangeUsername(new \DateTimeImmutable('2027-09-25 09:59:59+00:00')));
        self::assertTrue($user->canChangeUsername(new \DateTimeImmutable('2027-09-25 10:00:00+00:00')));
    }
}
