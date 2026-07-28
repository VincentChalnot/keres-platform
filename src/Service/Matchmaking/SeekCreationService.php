<?php

declare(strict_types=1);

namespace App\Service\Matchmaking;

use App\Entity\Seek;
use App\Entity\User;
use App\Message\ExpireSeekMessage;
use App\Model\ColorPreference;
use App\Model\Matchmaking\SeekCreateOutcome;
use App\Model\Matchmaking\SeekCreationResult;
use App\Model\MultiplayerLimits;
use App\Model\TimeControl;
use App\Model\TimeControlKind;
use App\Repository\SeekRepository;
use App\Service\Game\GameUpdatePublisher;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Uid\Uuid;

/**
 * The write side of a seek's create/dedupe/replace lifecycle
 * (04-matchmaking.md sec 2/6.2), shared by `CreateSeekAction` and
 * `QuickPairAction` so the two front doors (sec 1) really do write one row
 * through one path. Immediate pairing (sec 3.1) always follows a genuine
 * insert; a dedupe never re-attempts pairing (it already ran once, at the
 * original create).
 */
final readonly class SeekCreationService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private SeekRepository $seekRepository,
        private SeekMatcher $seekMatcher,
        private SeekPayloadBuilder $seekPayloadBuilder,
        private GameUpdatePublisher $publisher,
        private ClockInterface $clock,
        private MessageBusInterface $messageBus,
    ) {
    }

    public function create(
        User $user,
        TimeControl $timeControl,
        bool $rated,
        ColorPreference $colorPreference,
        bool $autoWiden,
        ?int $ratingMin,
        ?int $ratingMax,
    ): SeekCreateOutcome {
        $result = $this->insertOrReplaceSeek($user, $timeControl, $rated, $colorPreference, $autoWiden, $ratingMin, $ratingMax);

        if ($result->deduped) {
            return new SeekCreateOutcome($result->seek, null, true);
        }

        $now = $this->clock->now();
        $matchedGame = $this->seekMatcher->attemptPair((int) $result->seek->getId());

        if (null === $matchedGame) {
            $poolSize = \count($this->seekRepository->findOpenForListing($now));
            $event = $this->seekPayloadBuilder->buildAddedEvent($result->seek, $poolSize, $now);
            $this->publisher->publishSeekEvent($this->seekPayloadBuilder->encode($event));
        }

        return new SeekCreateOutcome($result->seek, $matchedGame, false);
    }

    /**
     * Insert-or-dedupe-or-replace only, no pairing attempt - the shared
     * write step behind both the front-door create flow above and
     * `AcceptSeekAction`'s mirror seek (sec 5.3, sec 6.2). Publishes
     * `seek.removed{replaced}` when it cancels an existing open seek;
     * never publishes `seek.added` - callers that want the seek visible in
     * the pool decide that for themselves once they know whether it paired.
     */
    public function insertOrReplaceSeek(
        User $user,
        TimeControl $timeControl,
        bool $rated,
        ColorPreference $colorPreference,
        bool $autoWiden,
        ?int $ratingMin,
        ?int $ratingMax,
    ): SeekCreationResult {
        $now = $this->clock->now();
        $ttl = TimeControlKind::CORRESPONDENCE === $timeControl->getKind()
            ? MultiplayerLimits::CHALLENGE_TTL_SECONDS
            : MultiplayerLimits::SEEK_TTL_SECONDS;

        try {
            $result = $this->insertOrReplace($user, $timeControl, $rated, $colorPreference, $autoWiden, $ratingMin, $ratingMax, $now, $ttl);
        } catch (UniqueConstraintViolationException) {
            // sec 6.2: two concurrent first-ever creates both saw zero rows and locked
            // nothing; the loser's INSERT hit `seek_one_open_per_user`. One retry now
            // finds the winner's row and takes the dedupe/replace branch.
            $result = $this->insertOrReplace($user, $timeControl, $rated, $colorPreference, $autoWiden, $ratingMin, $ratingMax, $now, $ttl);
        }

        if (null !== $result->replacedSeekUuid) {
            $this->publishRemoved($result->replacedSeekUuid, 'replaced', $now);
        }

        return $result;
    }

    /** Cancels a seek and publishes its removal - `AcceptSeekAction`'s narrowed-pairing-failure cleanup (sec 5.3 docblock). */
    public function cancelSeek(Seek $seek, string $reason): void
    {
        $this->entityManager->getConnection()->executeStatement(
            'UPDATE seek SET status_value = 2 WHERE id = :id AND status_value = 0',
            ['id' => $seek->getId()],
        );

        $this->publishRemoved($seek->getUuid(), $reason, $this->clock->now());
    }

    private function insertOrReplace(
        User $user,
        TimeControl $timeControl,
        bool $rated,
        ColorPreference $colorPreference,
        bool $autoWiden,
        ?int $ratingMin,
        ?int $ratingMax,
        \DateTimeImmutable $now,
        int $ttl,
    ): SeekCreationResult {
        return $this->entityManager->wrapInTransaction(
            function (EntityManagerInterface $em) use ($user, $timeControl, $rated, $colorPreference, $autoWiden, $ratingMin, $ratingMax, $now, $ttl): SeekCreationResult {
                $existing = $this->seekRepository->findOpenForUserForUpdate($user);

                $candidate = new Seek(
                    $user,
                    $timeControl,
                    $rated,
                    $colorPreference,
                    $autoWiden,
                    MultiplayerLimits::GLICKO_DEFAULT_RATING,
                    $now,
                    $ttl,
                    $ratingMin,
                    $ratingMax,
                );

                if (null !== $existing && $existing->hasSameParameters($candidate)) {
                    return new SeekCreationResult($existing, true);
                }

                $replacedUuid = null;

                if (null !== $existing) {
                    $replacedUuid = $existing->getUuid();
                    $existing->cancel();
                    // Own flush, before the insert: Doctrine's UnitOfWork
                    // processes insertions before updates within one flush,
                    // so cancelling and inserting together would race the
                    // new row's INSERT against `uniq_seek_open_per_user`
                    // while the old row was still `status = 0`.
                    $em->flush();
                }

                $em->persist($candidate);
                $em->flush();

                $this->messageBus->dispatch(
                    new ExpireSeekMessage($candidate->getUuid()->toRfc4122()),
                    [new DelayStamp($ttl * 1000)],
                );

                return new SeekCreationResult($candidate, false, $replacedUuid);
            },
        );
    }

    private function publishRemoved(Uuid $seekUuid, string $reason, \DateTimeImmutable $now): void
    {
        $poolSize = \count($this->seekRepository->findOpenForListing($now));
        $event = $this->seekPayloadBuilder->buildRemovedEvent($seekUuid, $reason, $poolSize, $now);
        $this->publisher->publishSeekEvent($this->seekPayloadBuilder->encode($event));
    }
}
