<?php

declare(strict_types=1);

namespace App\Twig;

use App\Service\Locale\FrontendCatalogue;
use App\Service\Locale\LocaleResolver;
use Symfony\Component\Translation\LocaleSwitcher;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `frontend_i18n()` - the JSON catalogue the TypeScript i18n module reads
 * (see FrontendCatalogue) - and `enabled_locales()` for the language switcher.
 */
final class LocaleExtension extends AbstractExtension
{
    public function __construct(
        private readonly FrontendCatalogue $frontendCatalogue,
        private readonly LocaleResolver $resolver,
        private readonly LocaleSwitcher $localeSwitcher,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('frontend_i18n', $this->frontendI18n(...), ['is_safe' => ['html']]),
            new TwigFunction('enabled_locales', $this->resolver->enabledLocales(...)),
        ];
    }

    public function frontendI18n(): string
    {
        return $this->frontendCatalogue->json($this->localeSwitcher->getLocale());
    }
}
