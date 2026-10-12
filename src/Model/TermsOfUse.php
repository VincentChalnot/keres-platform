<?php

declare(strict_types=1);

namespace App\Model;

/**
 * The version of the terms of use users have to accept (the page lives on the marketing site, `/terms-of-use`).
 * It is the "Last updated" date of that page. Change it whenever the site publishes new terms: every account that has
 * not accepted this version is then asked again (`TermsAcceptanceListener`).
 */
final class TermsOfUse
{
    public const string CURRENT_VERSION = '2026-09-28';
}
