<?php

declare(strict_types=1);

namespace App\Action\Api;

use App\Entity\WaitlistSignup;
use App\Service\WaitlistMailer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * T7: physical-edition waitlist, double opt-in (no payment/store/stock -
 * this is a pure interest-registration form). Same public-JSON shape as
 * `ContactAction` (honeypot, rate limiter, required-field check) - kept as
 * its own action rather than folded into `/api/contact` because the field
 * set and the double-opt-in email step genuinely differ.
 *
 * No `Feedback(WAITLIST, ...)` row is created here - only a pending
 * `WaitlistSignup`. The row only exists once `WaitlistConfirmAction` sees
 * the recipient actually click the link.
 */
#[AsController]
readonly class WaitlistSignupAction
{
    private const string TOKEN_TTL = '+7 days';

    public function __construct(
        private EntityManagerInterface $entityManager,
        private RateLimiterFactory $waitlistSignupLimiter,
        private WaitlistMailer $waitlistMailer,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    #[Route(
        path: '/api/waitlist',
        name: 'api_waitlist_signup',
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

        if (empty($payload['email'])) {
            return new JsonResponse(['error' => 'missing field: email'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $limiter = $this->waitlistSignupLimiter->create($request->getClientIp());

        if (false === $limiter->consume(1)->isAccepted()) {
            return new JsonResponse(['error' => 'rate limit exceeded'], Response::HTTP_TOO_MANY_REQUESTS);
        }

        $plainToken = bin2hex(random_bytes(32));

        $signup = new WaitlistSignup(
            $payload['email'],
            empty($payload['name']) ? null : $payload['name'],
            empty($payload['note']) ? null : $payload['note'],
            hash('sha256', $plainToken),
            new \DateTimeImmutable(self::TOKEN_TTL),
        );

        $this->entityManager->persist($signup);
        $this->entityManager->flush();

        $confirmUrl = $this->urlGenerator->generate('waitlist_confirm', ['token' => $plainToken], UrlGeneratorInterface::ABSOLUTE_URL);
        $this->waitlistMailer->sendConfirmationMail($signup, $confirmUrl);

        return new JsonResponse(['success' => true], Response::HTTP_OK);
    }
}
