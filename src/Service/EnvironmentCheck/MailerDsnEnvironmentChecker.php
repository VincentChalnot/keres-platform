<?php

declare(strict_types=1);

namespace App\Service\EnvironmentCheck;

use Symfony\Component\Mailer\Transport\Dsn;

/**
 * Only enforced in prod (bound $environment, see config/services.yaml):
 * dev/test keep using whatever MAILER_DSN they already have (e.g. the
 * bundled Mailpit SMTP transport) without needing Scaleway credentials at
 * all. In prod, a real Scaleway HTTP API transport is mandatory — running
 * without the ability to send email is treated as a fatal misconfiguration,
 * not a degraded mode.
 */
final readonly class MailerDsnEnvironmentChecker implements EnvironmentCheckerInterface
{
    private const SCALEWAY_SCHEMES = ['scaleway', 'scaleway+api'];

    public function __construct(
        private string $mailerDsn,
        private string $environment,
    ) {
    }

    public function getName(): string
    {
        return 'Mailer DSN (Scaleway)';
    }

    public function check(): void
    {
        if ('prod' !== $this->environment) {
            return;
        }

        try {
            $dsn = Dsn::fromString($this->mailerDsn);
        } catch (\InvalidArgumentException $exception) {
            throw new EnvironmentCheckFailedException(\sprintf('MAILER_DSN is not a valid DSN: %s', $exception->getMessage()), previous: $exception);
        }

        if (!\in_array($dsn->getScheme(), self::SCALEWAY_SCHEMES, true)) {
            throw new EnvironmentCheckFailedException(\sprintf('MAILER_DSN must use the "scaleway+api" transport in production, got scheme "%s". Set it to scaleway+api://PROJECT_ID:API_KEY@default (see deploy/compose.yaml).', $dsn->getScheme()));
        }

        if (null === $dsn->getUser() || '' === $dsn->getUser()) {
            throw new EnvironmentCheckFailedException('MAILER_DSN is missing the Scaleway project ID (DSN user part): scaleway+api://PROJECT_ID:API_KEY@default.');
        }

        if (null === $dsn->getPassword() || '' === $dsn->getPassword()) {
            throw new EnvironmentCheckFailedException('MAILER_DSN is missing the Scaleway API key (DSN password part): scaleway+api://PROJECT_ID:API_KEY@default.');
        }
    }
}
