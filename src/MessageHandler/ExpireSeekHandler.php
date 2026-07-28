<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\ExpireSeekMessage;
use App\Model\SeekStatus;
use App\Repository\SeekRepository;
use App\Service\Game\GameUpdatePublisher;
use App\Service\Matchmaking\SeekPayloadBuilder;
use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

/**
 * The hard TTL (04-matchmaking.md sec 4.4). Guarded by `status = OPEN`, so
 * redelivery and a seek already consumed by pairing are both no-ops. Raw
 * DBAL for the same reason `SeekMatcher` is - a lock/status write that must
 * never throw through `flush()`.
 */
#[AsMessageHandler]
readonly class ExpireSeekHandler
{
    public function __construct(
        private Connection $connection,
        private SeekRepository $seekRepository,
        private GameUpdatePublisher $publisher,
        private SeekPayloadBuilder $seekPayloadBuilder,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(ExpireSeekMessage $message): void
    {
        $now = $this->clock->now();

        $this->connection->beginTransaction();

        try {
            $row = $this->connection->fetchAssociative(
                'SELECT id, status_value FROM seek WHERE uuid = :uuid FOR UPDATE',
                ['uuid' => $message->seekUuid],
            );

            if (false === $row || 0 !== (int) $row['status_value']) {
                $this->connection->commit(); // already matched/canceled/expired, or gone - no-op

                return;
            }

            $affected = $this->connection->executeStatement(
                'UPDATE seek SET status_value = :expired WHERE id = :id AND status_value = 0',
                ['expired' => SeekStatus::EXPIRED->value, 'id' => $row['id']],
            );

            $this->connection->commit();

            if (1 !== $affected) {
                return;
            }
        } catch (\Throwable $e) {
            if ($this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }

            throw $e;
        }

        $poolSize = \count($this->seekRepository->findOpenForListing($now));
        $event = $this->seekPayloadBuilder->buildRemovedEvent(Uuid::fromString($message->seekUuid), 'expired', $poolSize, $now);
        $this->publisher->publishSeekEvent($this->seekPayloadBuilder->encode($event));
    }
}
