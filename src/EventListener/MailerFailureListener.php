<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\MailerError;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Mailer\Event\FailedMessageEvent;
use Symfony\Component\Mime\Email;

/**
 * Persists every failed outgoing email (any transport, including Scaleway)
 * as a MailerError row, surfaced read-only in the admin panel. Symfony
 * Mailer dispatches FailedMessageEvent regardless of transport, so this
 * listener requires no Scaleway-specific knowledge.
 */
#[AsEventListener(event: FailedMessageEvent::class)]
final readonly class MailerFailureListener
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(FailedMessageEvent $event): void
    {
        $message = $event->getMessage();
        $subject = null;
        $recipients = null;

        if ($message instanceof Email) {
            $subject = $message->getSubject();
            $recipients = implode(', ', array_map(
                static fn ($address): string => $address->toString(),
                $message->getTo(),
            )) ?: null;
        }

        $error = new MailerError(
            $event->getError()::class,
            $event->getError()->getMessage(),
            $subject,
            $recipients,
        );

        $this->entityManager->persist($error);
        $this->entityManager->flush();
    }
}
