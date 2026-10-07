<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Game;
use App\Model\MultiplayerLimits;
use App\Model\PieceColor;
use App\Repository\GameRepository;
use App\Service\CorrespondenceMailer;
use App\Service\Game\ClockAdjudicator;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The durable backstop for every game clock. Run once a minute by supervisor
 * (`frankenphp/supervisor/deadline-sweep.conf`). The delayed
 * `CheckClockExpiryMessage` (precise) and the lazy/claim-timeout paths (on
 * demand) normally resolve a flag first; this command resolves whatever
 * they miss - a crashed worker or bot process, a lost/failed message, a game
 * nobody is watching - so no game can sit "in progress" past its deadline.
 * It is also the only mechanism for CORRESPONDENCE, whose 6h-72h deadlines
 * are too far out for a per-move DelayStamp.
 *
 * Two independent, idempotent passes:
 * 1. Adjudicate any game, of any time-control kind, whose deadline has
 *    passed, via the same `ClockAdjudicator::adjudicate()` every other
 *    adjudication path already uses (idempotent, row-locked - safe if this
 *    command overlaps itself or a lazy adjudicate() elsewhere). A flag past
 *    ply 1 is a timeout; an expired first-two-plies clamp is an abort.
 * 2. Warn the side to move in a correspondence game once, 6 hours before a
 *    deadline they have not yet been warned about *for the current move* -
 *    re-armed after every move by comparing against `clockTurnStartedAt`, no
 *    write needed on the move path itself. Guarded by its own row lock so
 *    two overlapping sweeps can never send the warning twice.
 */
#[AsCommand(name: 'app:games:sweep-deadlines', description: 'Adjudicate games whose clock has expired and send the correspondence 6-hour deadline warning')]
class SweepGameDeadlinesCommand extends Command
{
    public function __construct(
        private readonly GameRepository $gameRepository,
        private readonly ClockAdjudicator $clockAdjudicator,
        private readonly EntityManagerInterface $entityManager,
        private readonly CorrespondenceMailer $correspondenceMailer,
        private readonly ClockInterface $clock,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $now = $this->clock->now();

        $forfeited = 0;

        foreach ($this->gameRepository->findExpiredClockGames($now) as $game) {
            try {
                if ($this->clockAdjudicator->adjudicate($game)) {
                    ++$forfeited;
                }
            } catch (\Throwable $exception) {
                $io->error(\sprintf('Failed to adjudicate game %s: %s', $game->getUuid()->toRfc4122(), $exception->getMessage()));
            }
        }

        $warned = 0;
        $warningHorizon = $now->modify(\sprintf('+%d hours', MultiplayerLimits::CORRESPONDENCE_DEADLINE_WARNING_HOURS));

        foreach ($this->gameRepository->findCorrespondenceGamesNeedingDeadlineWarning($now, $warningHorizon) as $game) {
            try {
                if ($this->warnIfStillDue($game, $now)) {
                    ++$warned;
                }
            } catch (\Throwable $exception) {
                $io->error(\sprintf('Failed to warn for game %s: %s', $game->getUuid()->toRfc4122(), $exception->getMessage()));
            }
        }

        // Silent when idle: this runs every 60 s from the worker's shell loop,
        // and a "0 and 0" line per minute buries everything else in `docker logs`.
        if ($forfeited > 0 || $warned > 0) {
            $io->writeln(\sprintf('Forfeited %d game(s), warned %d game(s).', $forfeited, $warned));
        }

        return Command::SUCCESS;
    }

    /**
     * Marks the warning sent and flushes inside one transaction, row-locked
     * the same way `ClockAdjudicator::adjudicate()` locks `game` - two
     * overlapping sweeps race for the lock, the loser re-checks and finds
     * the condition already false. The mail send rides the same connection
     * (Messenger's doctrine transport shares it, `04-matchmaking.md` sec
     * 3.5's "delayed messages ride the same connection" property), so a
     * send failure rolls the "warned" flag back too - the next sweep simply
     * retries rather than silently losing the warning.
     */
    private function warnIfStillDue(Game $game, \DateTimeImmutable $now): bool
    {
        return $this->entityManager->wrapInTransaction(function (EntityManagerInterface $em) use ($game, $now): bool {
            $em->getConnection()->executeStatement("SET LOCAL lock_timeout = '3s'");

            // SELECT ... FOR UPDATE + re-hydrate (same mutex ClockAdjudicator uses).
            $em->find(Game::class, $game->getId(), LockMode::PESSIMISTIC_WRITE);

            $deadline = $game->getMoveDeadlineAt();
            $clockTurnStartedAt = $game->getClockTurnStartedAt();
            $warningHorizon = $now->modify(\sprintf('+%d hours', MultiplayerLimits::CORRESPONDENCE_DEADLINE_WARNING_HOURS));
            $alreadyWarned = null !== $game->getDeadlineWarningSentAt()
                && null !== $clockTurnStartedAt
                && $game->getDeadlineWarningSentAt() >= $clockTurnStartedAt;

            if (null !== $game->getGameOverAt()
                || null === $deadline
                || null === $clockTurnStartedAt
                || $alreadyWarned
                || $deadline <= $now
                || $deadline > $warningHorizon
            ) {
                // Someone moved, resolved, or already warned this since the read above.
                return false;
            }

            $sideToMove = $game->isWhiteTurn() ? PieceColor::WHITE : PieceColor::BLACK;
            $user = $game->getPlayer($sideToMove)->getUser();

            $game->markDeadlineWarningSent($now);

            if (null !== $user) {
                $this->correspondenceMailer->sendDeadlineWarning($user, $game);
            }

            $em->flush();

            return true;
        });
    }
}
