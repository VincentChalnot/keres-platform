<?php

declare(strict_types=1);

namespace App\Action\Api;

use App\Entity\Feedback;
use App\Model\FeedbackCategory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

#[AsController]
readonly class ContactAction
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private RateLimiterFactory $contactLimiterFactory,
    ) {
    }

    #[Route(
        path: '/api/contact',
        name: 'api_contact',
        methods: ['POST', 'OPTIONS'],
    )]
    public function __invoke(Request $request): Response
    {
        if ('OPTIONS' === $request->getMethod()) {
            return new JsonResponse(null, Response::HTTP_NO_CONTENT);
        }

        // Honeypot: hidden "website" field must be empty
        $payload = json_decode($request->getContent(), true) ?? [];

        if (!empty($payload['website'] ?? '')) {
            // Pretend success — do not leak to bots that we detected them
            return new JsonResponse(['success' => true], Response::HTTP_OK);
        }

        foreach (['name', 'email', 'subject', 'message'] as $required) {
            if (empty($payload[$required])) {
                return new JsonResponse(['error' => "missing field: $required"], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
        }

        $limiter = $this->contactLimiterFactory->create($request->getClientIp());

        if (false === $limiter->consume(1)->isAccepted()) {
            return new JsonResponse(['error' => 'rate limit exceeded'], Response::HTTP_TOO_MANY_REQUESTS);
        }

        // The marketing site's contact form has no Keres account behind it,
        // so submissions land as unattributed Feedback rows (category
        // CONTACT, no user). Reviewers reply directly from the embedded
        // name/e-mail, same as when this handed the payload to a mailer.
        $feedback = new Feedback(
            FeedbackCategory::CONTACT,
            \sprintf(
                "Nom : %s\nE-mail : %s\nSujet : %s\n\n%s",
                $payload['name'],
                $payload['email'],
                $payload['subject'],
                $payload['message'],
            ),
        );

        $this->entityManager->persist($feedback);
        $this->entityManager->flush();

        return new JsonResponse(['success' => true], Response::HTTP_OK);
    }
}
