<?php

declare(strict_types=1);

namespace App\Service\Locale;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Translation\TranslatorBagInterface;

/**
 * The strings the TypeScript client needs, taken from the very translation
 * files PHP uses (no second copy): every domain named `frontend` or
 * `frontend_<area>`, flattened into one `key => ICU message` map. The `frontend`
 * domain keeps its keys as written; `frontend_<area>` keys are prefixed with
 * `<area>.` (`frontend_play` / `banner.won` is `play.banner.won` in TypeScript).
 *
 * Messages keep their ICU syntax; `assets/typescript/src/i18n` interprets it
 * client-side. Keys missing from the requested locale come from the fallback
 * locales, exactly as for the server-side translator.
 */
final readonly class FrontendCatalogue
{
    private const string DOMAIN = 'frontend';

    public function __construct(
        #[Autowire(service: 'translator')]
        private TranslatorBagInterface $translatorBag,
    ) {
    }

    /** @return array<string, string> */
    public function messages(string $locale): array
    {
        $chain = [];

        for ($catalogue = $this->translatorBag->getCatalogue($locale); null !== $catalogue; $catalogue = $catalogue->getFallbackCatalogue()) {
            $chain[] = $catalogue;
        }

        $messages = [];

        // Fallbacks first so the requested locale overrides them.
        foreach (array_reverse($chain) as $catalogue) {
            foreach ($catalogue->getDomains() as $domain) {
                if (self::DOMAIN !== $domain && !str_starts_with($domain, self::DOMAIN.'_')) {
                    continue;
                }

                $prefix = self::DOMAIN === $domain ? '' : substr($domain, \strlen(self::DOMAIN) + 1).'.';

                foreach ($catalogue->all($domain) as $key => $message) {
                    $messages[$prefix.$key] = $message;
                }
            }
        }

        ksort($messages);

        return $messages;
    }

    /** The JSON the page embeds (`<script type="application/json" id="app-i18n">`), safe inside HTML. */
    public function json(string $locale): string
    {
        return json_encode(
            ['locale' => $locale, 'messages' => (object) $this->messages($locale)],
            \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_HEX_TAG | \JSON_HEX_AMP | \JSON_HEX_APOS | \JSON_HEX_QUOT,
        );
    }
}
