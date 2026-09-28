# Progress Log

One entry per completed/reverted/blocked task, appended in order. Updated
before starting the next task, not batched at the end.

---


## T1 — Async worker (mail off the request thread)
**Status**: done. **Commit**: `fcd86e6`.

**What changed**: Investigated first — most infra already existed: `php-worker`
Compose service (`restart: unless-stopped`, `frankenphp_worker_dev`/`_prod`
targets) runs Supervisor with 2 `messenger:consume async --time-limit=60
--memory-limit=128M` processes; `failure_transport: failed` was already
configured; `MailerFailureListener` already persists every `FailedMessageEvent`
(any transport) as an admin-visible `MailerError` row. The one real gap:
`Symfony\Component\Mailer\Messenger\SendEmailMessage` had no routing entry in
`config/packages/messenger.yaml`, so every `MailerInterface::send()` call
(`UserMailer`, used by `/login/lost-password` and registration) executed
synchronously in-process, blocking the HTTP response on SMTP/Scaleway. Added
the routing entry. Verified Sentry already auto-captures every
`WorkerMessageFailedEvent` (not just ones that exhaust retries) via its
bundled `MessengerListener`, prod-only, no code change needed — see
DECISIONS.md for how this was confirmed (a naive `debug:container`/
`debug:event-dispatcher` check was initially misleading). Also: enabled the
previously-dead `when@test:` override (`async: in-memory://`) since routing
mail to the real transport would otherwise make `LostPasswordActionTest`'s
success path attempt a live DB write it doesn't have; fixed a pre-existing
`KERNEL_CLASS` gap in `phpunit.dist.xml` that made `bin/phpunit` unusable for
any `WebTestCase`; updated the stale Messenger routing line in `AGENTS.md`;
added a "the worker starts automatically" note to `README.md`'s dev section.

**Verified live**: stopped `php-worker`, submitted `/login/lost-password` for
a dev-login user through Playwright — redirect to `/login` was immediate, and
a real `SendEmailMessage` row (subject "Reset your Keres password", correct
recipient) sat in `messenger_messages` (`queue_name = 'default'`) until the
worker was restarted, at which point it was consumed. The repo's local `.env`
points `MAILER_DSN` at a real Scaleway TEM project (not Mailpit) — that real
send failed with a genuine Scaleway 400 ("invalid argument(s)"), which
correctly produced three `MailerError` rows via the pre-existing
`MailerFailureListener` (one per retry), proving the failure-observability
chain survives the async move. To specifically prove Mailpit delivery (the
task's stated "Done when"), recreated `php`/`php-worker` once with a
temporary `MAILER_DSN=smtp://mailer:1025` override, repeated the flow for a
second address, and confirmed the email actually appeared in the Mailpit UI
at `https://mail.local.playkeres.com/` — then recreated both containers again
without the override to restore the original local `.env` configuration
exactly as found. Also ran `docker compose exec php bin/phpunit` (7 targeted
tests, then the full 45-test suite) — all green, no regressions from the
routing change. `composer cs:check` clean.

**Incidental environment fix**: found (and worked around, non-destructively)
an unrelated stale `keres-dev-mailer-1` container on the shared `proxy`
Docker network claiming the same Traefik router name (`keres-mail`) as this
project's Mailpit, causing `mail.local.playkeres.com` to 404. Stopped it only
for the verification window and restarted it immediately after — not part of
this repo, not modified.

**Open item flagged to Main, not blocking**: `UserMailer`'s redacted-HTTP-
response-body failure logging (added in the very recent, unrelated
PHP-SYMFONY-3 commit) is now unreachable for genuine async transport
failures — see DECISIONS.md. Judged non-blocking since `MailerError` +
Sentry still fully surface the failure; flagging in case Main wants the
diagnostic granularity restored via `MailerFailureListener` instead.

**Next action**: await Main's next task (T2).