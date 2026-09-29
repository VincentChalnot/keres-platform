<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\AnalyticsEvent;
use App\Entity\User;
use App\Message\RecordAnalyticsEventMessage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

/**
 * T6. `getReference()` (not `find()`) for the user: sets the FK column
 * without a SELECT, since all this handler ever does with it is attach it -
 * a full load would be wasted work for every single event, including the
 * hot move-played one.
 */
#[AsMessageHandler]
readonly class RecordAnalyticsEventHandler
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(RecordAnalyticsEventMessage $message): void
    {
        $event = new AnalyticsEvent(
            $message->type,
            new \DateTimeImmutable($message->occurredAt),
            null !== $message->userId ? $this->entityManager->getReference(User::class, Uuid::fromString($message->userId)) : null,
            null !== $message->gameUuid ? Uuid::fromString($message->gameUuid) : null,
            $message->payload,
        );

        $this->entityManager->persist($event);
        $this->entityManager->flush();
    }
}
