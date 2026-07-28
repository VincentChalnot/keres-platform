<?php

declare(strict_types=1);

namespace App\Service\Matchmaking;

use App\Engine\GameEngine;
use App\Entity\Game;
use App\Entity\Seek;
use App\Message\CheckClockExpiryMessage;
use App\Model\Matchmaking\PairOutcome;
use App\Model\Matchmaking\SelfSeekParams;
use App\Model\TimeControlKind;
use App\Repository\SeekRepository;
use App\Service\Game\ClockManager;
use App\Service\Game\GameStatePayloadBuilder;
use App\Service\Game\GameUpdatePublisher;
use App\Service\GameFactory;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Uid\Uuid;

/**
 * 04-matchmaking.md sec 3. The single implementation behind all three entry
 * points (create, heartbeat, accept) - `readonly`, no mutable state, safe
 * under FrankenPHP worker mode (sec 8). `Connection` is used directly for
 * every lock and status write per sec 3.5: a deliberate re-verify failure is
 * a plain `null`, never an exception through `flush()`.
 */
final readonly class SeekMatcher
{
    public function __construct(
        private Connection $connection,
        private EntityManagerInterface $entityManager,
        private SeekRepository $seekRepository,
        private GameFactory $gameFactory,
        private GameEngine $gameEngine,
        private ClockManager $clockManager,
        private ClockInterface $clock,
        private MessageBusInterface $messageBus,
        private GameUpdatePublisher $publisher,
        private GameStatePayloadBuilder $payloadBuilder,
        private SeekPayloadBuilder $seekPayloadBuilder,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * 04-matchmaking.md sec 3.1/3.5. `$restrictTo` narrows the candidate
     * scan to exactly one seek (the lobby-accept path, sec 5.3); null scans
     * the whole compatible pool (create/heartbeat).
     */
    public function attemptPair(int $seekId, ?Uuid $restrictTo = null): ?Game
    {
        $outcome = $this->tryPair($seekId, $restrictTo);

        if ($outcome->skipped) {
            // The bounded retry (sec 3.1/3.5): our only candidate was mid-decision
            // in another transaction. By now it has committed either way.
            usleep(random_int(25_000, 75_000));
            $outcome = $this->tryPair($seekId, $restrictTo);
        }

        return $outcome->game;
    }

    private function tryPair(int $seekId, ?Uuid $restrictTo): PairOutcome
    {
        $this->connection->beginTransaction();

        try {
            // Step 1: lock self.
            $selfRow = $this->connection->fetchAssociative(
                'SELECT * FROM seek WHERE id = :id FOR UPDATE',
                ['id' => $seekId],
            );

            if (false === $selfRow) {
                $this->connection->commit();

                return new PairOutcome(null, false);
            }

            $selfStatus = (int) $selfRow['status_value'];

            if (1 === $selfStatus) { // MATCHED - someone paired us already
                $game = null !== $selfRow['matched_game_id']
                    ? $this->entityManager->find(Game::class, (int) $selfRow['matched_game_id'])
                    : null;
                $this->connection->commit();

                return new PairOutcome($game, false);
            }

            if (0 !== $selfStatus) { // CANCELED / EXPIRED
                $this->connection->commit();

                return new PairOutcome(null, false);
            }

            $self = SelfSeekParams::fromRow($selfRow);

            // Step 2: candidate query, SKIP LOCKED.
            $candidateRow = $this->seekRepository->lockNextCandidate($self, $restrictTo);

            if (null === $candidateRow) {
                $skipped = $this->seekRepository->hasLockableCandidate($self, $restrictTo);
                $this->connection->commit();

                return new PairOutcome(null, $skipped);
            }

            // Step 3: re-verify - PostgreSQL already re-qualified the locked row
            // under READ COMMITTED, so this is an assertion, not a filter.
            if (0 !== (int) $candidateRow['status_value']) {
                $this->connection->rollBack();

                return new PairOutcome(null, false);
            }

            // Step 4: build the game. GameFactory owns colour resolution.
            $selfSeek = $this->seekRepository->find((int) $selfRow['id']);
            $candidateSeek = $this->seekRepository->find((int) $candidateRow['id']);

            if (!$selfSeek instanceof Seek || !$candidateSeek instanceof Seek) {
                throw new \LogicException('Locked seek row has no corresponding entity.');
            }

            $game = $this->gameFactory->createFromSeeks($selfSeek, $candidateSeek);
            $this->entityManager->persist($game);
            $this->entityManager->flush();

            // Step 5: consume both seeks in one statement.
            $affected = $this->connection->executeStatement(
                'UPDATE seek SET status_value = 1, matched_game_id = :gameId WHERE id IN (:selfId, :candidateId) AND status_value = 0',
                ['gameId' => $game->getId(), 'selfId' => (int) $selfRow['id'], 'candidateId' => (int) $candidateRow['id']],
            );

            if (2 !== $affected) {
                $this->connection->rollBack();
                $this->logger->error('Seek pairing terminal UPDATE affected {count} rows, expected 2.', ['count' => $affected]);

                return new PairOutcome(null, false);
            }

            // Step 6: delayed clock-expiry message, same connection - a rollback un-schedules it.
            if (TimeControlKind::UNLIMITED !== $game->getTimeControl()->getKind() && null !== $game->getMoveDeadlineAt()) {
                $this->dispatchClockExpiryCheck($game);
            }

            $this->connection->commit();

            // Step 7: publish, strictly post-commit.
            $this->publishMatch($game, $selfSeek->getUuid(), $candidateSeek->getUuid());

            return new PairOutcome($game, false);
        } catch (\Throwable $e) {
            if ($this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }

            throw $e;
        }
    }

    private function dispatchClockExpiryCheck(Game $game): void
    {
        $deadline = $game->getMoveDeadlineAt();

        if (null === $deadline) {
            return;
        }

        $deadlineMicros = (int) $deadline->format('Uu');
        $delayMs = $this->clockManager->expiryCheckDelayMs($deadline);

        $this->messageBus->dispatch(
            new CheckClockExpiryMessage($game->getUuid()->toRfc4122(), $game->getGameMoves()->count(), $deadlineMicros),
            [new DelayStamp($delayMs)],
        );
    }

    private function publishMatch(Game $game, Uuid $selfSeekUuid, Uuid $candidateSeekUuid): void
    {
        $boardMovesData = $this->gameEngine->getBoardMovesData($game);
        $payload = $this->payloadBuilder->build($game, $boardMovesData);
        $this->publisher->publishGameState($game->getUuid()->toRfc4122(), $this->payloadBuilder->encode($payload));

        // 04-matchmaking.md sec 2/5.2: both consumed seeks leave the public
        // pool. The acting side already has the game from its own
        // create/heartbeat/accept response; this is for every other lobby
        // viewer's listing to reconcile, and for the *other* seek's owner -
        // who learns the gameUuid from their own next heartbeat response
        // (sec 4.2), not from this broadcast, which carries no gameUuid by
        // design (02-realtime.md sec 4.3). Publishing a richer
        // `user/{uuid}` SEEK_MATCHED event is deferred to Phase 6, when a
        // `Notification` row exists to back it and something client-side
        // subscribes to that topic.
        $poolSize = \count($this->seekRepository->findOpenForListing($this->clock->now()));
        $now = $this->clock->now();

        foreach ([$selfSeekUuid, $candidateSeekUuid] as $seekUuid) {
            $event = $this->seekPayloadBuilder->buildRemovedEvent($seekUuid, 'matched', $poolSize, $now);
            $this->publisher->publishSeekEvent($this->seekPayloadBuilder->encode($event));
        }
    }
}
