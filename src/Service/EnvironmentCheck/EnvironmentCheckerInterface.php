<?php

declare(strict_types=1);

namespace App\Service\EnvironmentCheck;

/**
 * Implement + tag `app.environment_checker` (autoconfigured via the
 * `_instanceof` rule in config/services.yaml) to plug a new startup
 * sanity check into `app:check-environment`, run by
 * frankenphp/docker-entrypoint.sh before the app server starts.
 */
interface EnvironmentCheckerInterface
{
    /** Short label shown in the command's output, e.g. "Mailer DSN". */
    public function getName(): string;

    /** @throws EnvironmentCheckFailedException if the environment is misconfigured */
    public function check(): void;
}
