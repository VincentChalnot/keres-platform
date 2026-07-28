<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Seek;
use App\Entity\User;
use App\Model\Matchmaking\SelfSeekParams;
use App\Model\MultiplayerLimits;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\DBAL\ParameterType;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<Seek>
 */
class SeekRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Seek::class);
    }

    public function findByUuid(Uuid $uuid): ?Seek
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.uuid = :uuid')
            ->setParameter('uuid', $uuid, 'uuid')
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** Serialises this user's concurrent creates (04-matchmaking.md sec 6.2 step 1). */
    public function findOpenForUserForUpdate(User $user): ?Seek
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.user = :user')
            ->andWhere('s.statusValue = 0')
            ->setParameter('user', $user)
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->getOneOrNullResult();
    }

    /**
     * The listing predicate is the pairing predicate minus viewer-specific
     * clauses (sec 5.1): a seek that cannot be paired must not be shown.
     *
     * @return Seek[]
     */
    public function findOpenForListing(\DateTimeImmutable $now): array
    {
        return $this->createQueryBuilder('s')
            ->addSelect('u')
            ->join('s.user', 'u')
            ->andWhere('s.statusValue = 0')
            ->andWhere('s.expiresAt > :now')
            ->andWhere('s.lastHeartbeatAt > :staleThreshold')
            ->setParameter('now', $now)
            ->setParameter('staleThreshold', $now->modify(\sprintf('-%d seconds', MultiplayerLimits::SEEK_STALE_AFTER_SECONDS)))
            ->orderBy('s.createdAt', 'ASC')
            ->addOrderBy('s.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * The literal candidate query of 04-matchmaking.md sec 3.4, `FOR UPDATE
     * OF c SKIP LOCKED`. Raw DBAL, not the ORM (sec 3.5): a deliberate
     * re-verify failure must be a plain `return null`, never an exception
     * through `flush()`. Returns the raw row (or null) - the caller resolves
     * a `Seek`/`User` from `id`/`user_id` only once a match is certain.
     *
     * Every placeholder name is used **exactly once**, even where the same
     * value is needed in several places (e.g. `:selfRatingA`/`:selfRatingB`):
     * DBAL's named->positional rewriter does not reliably deduplicate a
     * name reused across a `CASE`/cast-heavy query like this one - it can
     * misassign a later occurrence's value to an earlier positional slot,
     * observed here as spurious "invalid input syntax for type boolean"
     * errors on values that were never boolean. Repeating the bound value
     * under a fresh name per occurrence sidesteps the rewriter entirely.
     *
     * @return array<string, mixed>|null
     */
    public function lockNextCandidate(SelfSeekParams $self, ?Uuid $restrictTo): ?array
    {
        $sql = 'SELECT c.* FROM seek c WHERE '.$this->candidateWhereClause()."\n"
            .'ORDER BY c.created_at ASC, c.id ASC LIMIT 1 FOR UPDATE OF c SKIP LOCKED';

        $row = $this->getEntityManager()->getConnection()->fetchAssociative(
            $sql,
            $this->candidateParams($self, $restrictTo),
            $this->candidateParamTypes(),
        );

        return false !== $row ? $row : null;
    }

    /** Bare existence check (no lock) - distinguishes "nobody qualifies" from "my only candidate is contended" (sec 3.4/3.5/7 race 1). */
    public function hasLockableCandidate(SelfSeekParams $self, ?Uuid $restrictTo): bool
    {
        $sql = 'SELECT c.id FROM seek c WHERE '.$this->candidateWhereClause().' LIMIT 1';

        $row = $this->getEntityManager()->getConnection()->fetchAssociative(
            $sql,
            $this->candidateParams($self, $restrictTo),
            $this->candidateParamTypes(),
        );

        return false !== $row;
    }

    /** 05-social.md sec 4.4: the block anti-join rides in here, not as a per-candidate check. */
    private function candidateWhereClause(): string
    {
        return <<<'SQL'
                c.status_value = 0
            AND c.speed_category_value IS NOT DISTINCT FROM :selfSpeedCategory
            AND c.id      <> :selfId
            AND c.user_id <> :selfUserId
            AND c.time_control_kind = :selfKindA
            AND c.initial_seconds   IS NOT DISTINCT FROM :selfInitialSeconds
            AND c.increment_seconds IS NOT DISTINCT FROM :selfIncrementSeconds
            AND c.days_per_move     IS NOT DISTINCT FROM :selfDaysPerMove
            AND c.rated = :selfRated
            AND (:selfColorPreferenceA = 2 OR c.color_preference_value <> :selfColorPreferenceB)
            AND c.expires_at > now()
            AND (:selfKindB = 2 OR c.last_heartbeat_at > now() - make_interval(secs => :staleAfter))
            AND (
                  CASE WHEN :selfAutoWiden
                       THEN abs(c.rating_snapshot - :selfRatingA) <= LEAST(
                              :windowMaxA, :windowBaseA + :widenPerSecondA
                                          * EXTRACT(EPOCH FROM (now() - :selfCreatedAt)))
                       ELSE c.rating_snapshot >= COALESCE(:selfRatingMinA, c.rating_snapshot)
                        AND c.rating_snapshot <= COALESCE(:selfRatingMaxA, c.rating_snapshot)
                  END)
            AND (
                  CASE WHEN c.auto_widen
                       THEN abs(:selfRatingB - c.rating_snapshot) <= LEAST(
                              :windowMaxB, :windowBaseB + :widenPerSecondB
                                          * EXTRACT(EPOCH FROM (now() - c.created_at)))
                       ELSE (c.rating_min IS NULL OR :selfRatingC >= c.rating_min)
                        AND (c.rating_max IS NULL OR :selfRatingD <= c.rating_max)
                  END)
            AND NOT EXISTS (
                  SELECT 1
                    FROM friendship b
                   WHERE b.status_value = 3
                     AND (   (b.requester_id = :selfUserId AND b.addressee_id = c.user_id)
                          OR (b.requester_id = c.user_id   AND b.addressee_id = :selfUserId) )
                )
            AND (:restrictToUuid = '' OR c.uuid::text = :restrictToUuid)
            SQL;
    }

    /** @return array<string, mixed> */
    private function candidateParams(SelfSeekParams $self, ?Uuid $restrictTo): array
    {
        return [
            'selfId' => $self->id,
            'selfUserId' => $self->userId,
            'selfKindA' => $self->kind->value,
            'selfKindB' => $self->kind->value,
            'selfSpeedCategory' => $self->speedCategory,
            'selfInitialSeconds' => $self->initialSeconds,
            'selfIncrementSeconds' => $self->incrementSeconds,
            'selfDaysPerMove' => $self->daysPerMove,
            'selfRated' => $self->rated,
            'selfColorPreferenceA' => $self->colorPreference->value,
            'selfColorPreferenceB' => $self->colorPreference->value,
            'staleAfter' => MultiplayerLimits::SEEK_STALE_AFTER_SECONDS,
            'selfAutoWiden' => $self->autoWiden,
            'selfRatingA' => $self->ratingSnapshot,
            'selfRatingB' => $self->ratingSnapshot,
            'selfRatingC' => $self->ratingSnapshot,
            'selfRatingD' => $self->ratingSnapshot,
            'windowMaxA' => MultiplayerLimits::QUICK_PAIR_WINDOW_MAX,
            'windowMaxB' => MultiplayerLimits::QUICK_PAIR_WINDOW_MAX,
            'windowBaseA' => MultiplayerLimits::QUICK_PAIR_WINDOW_BASE,
            'windowBaseB' => MultiplayerLimits::QUICK_PAIR_WINDOW_BASE,
            'widenPerSecondA' => MultiplayerLimits::QUICK_PAIR_WIDEN_PER_SECOND,
            'widenPerSecondB' => MultiplayerLimits::QUICK_PAIR_WIDEN_PER_SECOND,
            'selfCreatedAt' => $self->createdAt->format('Y-m-d H:i:s.uP'),
            'selfRatingMinA' => $self->ratingMin,
            'selfRatingMaxA' => $self->ratingMax,
            'restrictToUuid' => $restrictTo?->toRfc4122() ?? '',
        ];
    }

    /**
     * PDO's pgsql driver stringifies an untyped `bindValue()` bool - `(string)
     * false === ''`, which Postgres then rejects as an invalid boolean
     * literal. `true` silently survives as `'1'`, which is why this only
     * ever surfaced on a `false` value. The only two boolean params here.
     *
     * @return array<string, ParameterType>
     */
    private function candidateParamTypes(): array
    {
        return [
            'selfRated' => ParameterType::BOOLEAN,
            'selfAutoWiden' => ParameterType::BOOLEAN,
        ];
    }
}
