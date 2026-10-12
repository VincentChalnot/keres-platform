# TODO

Only open work. Done items are deleted; the reasoning behind choices is in
[`DECISIONS.md`](DECISIONS.md), unanswered product questions in
[`QUESTIONS.md`](QUESTIONS.md).

## Privacy: make the policy true

The privacy policy (marketing site, `i18n/*.toml`) says account deletion erases personal
data, keeps played games in permanently anonymised form (account link severed, no
recoverable mapping) and answers requests within 30 days. Today requests only land in the
admin queue (`SettingsPrivacyAccountDeletionAction`, `SettingsPrivacyDataExportAction`:
"no automated export/deletion"; `User` has no `deletedAt` or anonymisation).

- [ ] Account deletion with anonymisation: erase the `User` and its `UserAuth`,
      preferences, friendships, notifications, ratings; sever the `GamePlayer`/`Game` link
      to the user without any recoverable mapping, keep the games in the shared tree.
- [ ] Data export: produce the user's data (profile, preferences, games, friends) for the
      admin to send, or as a download.

## Production logging cleanup

Goal: `docker compose logs` in production is near-empty in steady state (user-traffic access
lines and real warnings/errors); the rest is dropped, sent to Sentry or kept as breadcrumbs.
Decisions already taken: D14 in `DECISIONS.md`. Already done: Docker log rotation
(`x-logging`), workers started with `--quiet`, supervisord `loglevel=warn`, the sweep command
silent when idle.

Facts behind the plan (from a 2.9 MB production dump covering 6 h 20):

- Caddy's access log was 72 % of the volume: SentryUptimeBot probes every minute (`/`,
  `/lobby`), the Mercure SSE endpoint, `/notifications/unread-count` polling, ~1.1 KB per
  line with full request headers; Mercure also logs every subscriber connect/disconnect.
- `monolog/monolog` is not installed: `LoggerInterface` is Symfony's fallback logger writing
  to stderr, which only emits `error` and above without `SHELL_VERBOSITY`.
- `logger->error()` calls do not reach Sentry (`sentry-symfony` only captures exceptions):
  `UserMailer`, `WaitlistMailer`, `CorrespondenceMailer`, `NotificationMailer`,
  `LostPasswordAction`, `GameUpdatePublisher`, `SeekMatcher` log errors to stderr only.

### Phase 1 - Caddy (`frankenphp/Caddyfile`, shared by dev and prod)

Untested; validate each step (`docker compose exec php frankenphp validate --config
/etc/frankenphp/Caddyfile`), then count `docker compose logs php` lines over a few minutes
of mixed traffic (browse, SSE, uptime probe) in dev too.

- [ ] `log_skip` in the site block for the `SentryUptimeBot` user agent,
      `/.well-known/mercure*` and `/notifications/unread-count` (expected: ~1.2 MB of
      2.0 MB). The Sentry uptime monitor must keep working (it hits the site, not the logs).
- [ ] Global option `log { level WARN }` for the default logger (Mercure subscriber
      chatter, "serving initial configuration", "autosaved config", TLS maintenance); check
      that the access log (separate `http.log.access.*` logger) is unaffected.
- [ ] Trim the existing `format filter` (it only redacts Mercure's `authorization` query
      parameter): drop `request>tls` and `resp_headers`; drop `request>headers` except
      `User-Agent`/`Referer` once IP retention is decided (`QUESTIONS.md`).
- [ ] Optional: keep only 4xx/5xx if Caddy can vary the access-log level by status.

### Phase 2 - Monolog and Sentry routing

- [ ] `composer require symfony/monolog-bundle`; `when@prod` handlers in `monolog.yaml`:
      `main` = `fingers_crossed` (`action_level: error`, `excluded_http_codes: [403, 404, 405]`)
      → `nested` (`stream` to `php://stderr`, `monolog.formatter.json`); `warnings` = plain
      `stream` at `warning`, channels `!event`, `!doctrine`; `deprecation` → `null`; dev/test keep
      the bundle defaults.
- [ ] `config/packages/sentry.yaml` (follow the commented template there): keep
      `register_error_listener` enabled (uncaught exceptions, honours `ignore_exceptions`); add
      `Sentry\Monolog\Handler` at `error` with `channels: ["!request"]` (confirm that
      `ErrorListener`'s `request`-channel log really causes the duplicate first) and
      `Sentry\Monolog\BreadcrumbHandler` at `info`; do **not** enable
      `Sentry\SentryBundle\Monolog\LogsHandler` (Sentry Logs product).
- [ ] PII: `MultiProviderOidcAuthenticator` logs the OIDC `sub` and the user's `email` at
      `info`; drop them from the context (or move to `debug`) before Monolog routes `info`
      anywhere durable. Skim the other `logger->info/warning` call sites (`ClockManager`,
      `SeekMatcher`, the mailers).

### Phase 3 - Verification

- [ ] In prod mode, trigger a handled `logger->error()` and an uncaught exception: one JSON
      line on stderr each, **one** Sentry event each, breadcrumbs attached, no duplicate.
- [ ] A 404 produces neither a Sentry event nor a Docker log line.
- [ ] `docker compose logs -f` in prod for ~10 min: user-traffic access lines and real
      warnings only.
- [ ] `composer cs:check` and the PHPUnit suite.
- [ ] Document the log routing table (what goes where) in `deploy/README.md`.

## Smaller items

- [ ] `DevLoginAuthenticator::onAuthenticationSuccess()` never calls `removeTargetPath()`, so a
      stale target (an earlier unauthenticated hit on a protected route) hijacks every later
      `/dev/login` redirect until the session is cleared. Dev only.
- [ ] Optional: move the Scaleway response-body redaction of `UserMailer::send()` (and the
      sibling mailers) into `MailerFailureListener`, so that real async transport failures log
      the diagnostic detail again (D01).
- [ ] Opponent presence / disconnect indicator and abandonment adjudication are specified
      (`docs/multiplayer/00-overview.md` §2.1) but not built: `GameEndReason::ABANDONMENT` has no
      producer. Confirm they are still wanted (`QUESTIONS.md`), else drop them from the spec.
