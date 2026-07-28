<?php

declare(strict_types=1);

namespace App\Action\Social;

use App\Entity\User;
use App\Service\Social\FriendListPayloadBuilder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * `GET /friends` (05-social.md sec 3.6/9.2, 09-api-reference.md sec 3.3).
 * Server-renders the same `{friends,incoming,outgoing,blocked}` shape
 * `GET /friends/list` returns, as a bootstrap script tag - first paint
 * needs no round trip, the same discipline `LobbyAction`/`lobby.html.twig`
 * already apply.
 */
#[AsController]
class FriendsPageAction extends AbstractController
{
    public function __construct(
        private readonly FriendListPayloadBuilder $payloadBuilder,
        private readonly ClockInterface $clock,
    ) {
    }

    #[IsGranted('ROLE_USER')]
    #[Route(path: '/friends', name: 'friends', methods: ['GET'])]
    public function __invoke(): array
    {
        $user = $this->getUser();

        if (!$user instanceof User) {
            throw $this->createAccessDeniedException('User is required to view friends.');
        }

        $payload = $this->payloadBuilder->build($user, $this->clock->now());

        return [
            'userUuid' => $user->getId()->toRfc4122(),
            'friendsBootstrap' => json_encode($payload, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE),
        ];
    }
}
