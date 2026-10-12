<?php

declare(strict_types=1);

namespace App\Tests\Functional\Repository;

use App\Entity\Seek;
use App\Entity\User;
use App\Model\ColorPreference;
use App\Model\MultiplayerLimits;
use App\Model\TimeControl;
use App\Repository\SeekRepository;
use App\Tests\Functional\DatabaseTestCase;

/**
 * A correspondence seek is listed while its author is away (no heartbeat), a real-time one is not;
 * an expired one never is.
 */
final class SeekRepositoryListingTest extends DatabaseTestCase
{
    public function testCorrespondenceSeekIsListedWithoutHeartbeatButRealtimeIsNot(): void
    {
        $now = new \DateTimeImmutable();
        $hourAgo = $now->modify('-1 hour');
        $correspondence = $this->persistSeek($this->createUser('corr@example.com'), TimeControl::correspondence(24), $hourAgo, MultiplayerLimits::CHALLENGE_TTL_SECONDS);
        $realtime = $this->persistSeek($this->createUser('rt@example.com'), TimeControl::realtime(300, 0), $hourAgo, 86400);
        $expired = $this->persistSeek($this->createUser('old@example.com'), TimeControl::correspondence(24), $hourAgo, 60);

        $listed = static::getContainer()->get(SeekRepository::class)->findOpenForListing($now);

        $uuids = array_map(static fn (Seek $s): string => $s->getUuid()->toRfc4122(), $listed);
        self::assertContains($correspondence->getUuid()->toRfc4122(), $uuids);
        self::assertNotContains($realtime->getUuid()->toRfc4122(), $uuids);
        self::assertNotContains($expired->getUuid()->toRfc4122(), $uuids);
    }

    private function persistSeek(User $user, TimeControl $timeControl, \DateTimeImmutable $at, int $ttlSeconds): Seek
    {
        $seek = new Seek($user, $timeControl, false, ColorPreference::RANDOM, false, 1500, $at, $ttlSeconds);
        $this->em()->persist($seek);
        $this->em()->flush();

        return $seek;
    }
}
