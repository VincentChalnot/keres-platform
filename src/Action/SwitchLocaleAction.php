<?php

declare(strict_types=1);

namespace App\Action;

use App\Entity\User;
use App\EventListener\LocaleListener;
use App\Service\Locale\LocaleResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * `GET /locale/{locale}?redirect=/some/path` - the language switcher.
 *
 * Remembers the choice in the language cookie (what keeps it for anonymous
 * visitors) and, for a signed-in user, on the account, then goes back to
 * `redirect` (a path of this site only, never another host).
 */
#[AsController]
readonly class SwitchLocaleAction
{
    public function __construct(
        private LocaleResolver $resolver,
        private Security $security,
        private EntityManagerInterface $entityManager,
    ) {
    }

    #[Route(path: '/locale/{locale}', name: 'locale_switch', requirements: ['locale' => '[a-z]{2,3}'], methods: ['GET'])]
    public function __invoke(Request $request, string $locale): RedirectResponse
    {
        if (!$this->resolver->isSupported($locale)) {
            throw new NotFoundHttpException('Unsupported locale.');
        }

        $user = $this->security->getUser();

        if ($user instanceof User && $user->getLocale() !== $locale) {
            $user->setLocale($locale);
            $this->entityManager->flush();
        }

        $response = new RedirectResponse(self::localPath($request->query->get('redirect')));
        $response->headers->setCookie(LocaleListener::cookie($locale, $request->isSecure()));

        return $response;
    }

    /** Anything that is not a plain absolute path of this site falls back to `/`. */
    private static function localPath(mixed $target): string
    {
        if (!\is_string($target) || 1 !== preg_match('#^/(?![/\\\\])[^\x00-\x1f\\\\]*$#', $target)) {
            return '/';
        }

        return $target;
    }
}
