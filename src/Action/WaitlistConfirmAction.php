<?php

declare(strict_types=1);

namespace App\Action;

use App\Entity\Feedback;
use App\Model\FeedbackCategory;
use App\Repository\WaitlistSignupRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * T7: the double-opt-in landing page. Creates the `Feedback(WAITLIST, ...)`
 * row - the actual "Message" the brief asks for - only now, on first valid
 * click; a second click on the same (already-confirmed) link finds no
 * matching row via `findByValidTokenHash()`'s `confirmedAt IS NULL` guard
 * and shows the same "invalid or expired" state rather than double-creating
 * a `Feedback` row.
 */
#[AsController]
class WaitlistConfirmAction extends AbstractController
{
    public function __construct(
        private readonly WaitlistSignupRepository $waitlistSignupRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route(path: '/waitlist/confirm', name: 'waitlist_confirm', methods: ['GET'])]
    public function __invoke(Request $request): array
    {
        $token = $request->query->get('token');

        if (!\is_string($token) || '' === $token) {
            return ['confirmed' => false];
        }

        $signup = $this->waitlistSignupRepository->findByValidTokenHash(hash('sha256', $token));

        if (null === $signup) {
            return ['confirmed' => false];
        }

        $signup->confirm();

        $message = \sprintf('Waitlist signup: %s', $signup->getEmail());

        if (null !== $signup->getName()) {
            $message .= \sprintf(' (%s)', $signup->getName());
        }

        if (null !== $signup->getNote()) {
            $message .= "\n\n".$signup->getNote();
        }

        $this->entityManager->persist(new Feedback(FeedbackCategory::WAITLIST, $message));
        $this->entityManager->flush();

        return ['confirmed' => true];
    }
}
