# Decisions Log

Judgment calls made during the v1-integration run. Hard/consequential calls
are asked of the orchestrator directly and recorded here once answered;
minor/reversible style choices are made unilaterally and logged here too.

---

### T1 — Does `SendEmailMessage` need routing to `async`, or is Sentry's Messenger failure integration a real gap?
Decision: Routed `Symfony\Component\Mailer\Messenger\SendEmailMessage` to `async` in `config/packages/messenger.yaml`. Verified (by inspecting the compiled `--env=prod` container's actual `addListener` calls, not just `debug:container`'s uncompiled service-tag view, which is misleading) that `Sentry\SentryBundle\EventListener\MessengerListener` is already auto-registered on `WorkerMessageFailedEvent` at priority 50 whenever the bundle loads with any `SENTRY_DSN` value including empty string (which `Dockerfile`'s prod build-time placeholder block always provides) — `capture_soft_fails` defaults to `true`, so it already reports every Messenger failure, not just ones that exhaust retries. No Sentry code change was needed; this was not a real gap, contrary to the initial task brief's inference.
Rationale: Avoid duplicating an already-correct, bundle-provided integration; a stray, ad-hoc `--env=prod` cache built without `SENTRY_DSN` set at all (unlike the real Docker build) initially looked like the listener was missing, until a cache rebuild with the DSN key present (even empty) proved it wires up correctly, matching the real build process.
Reversibility: trivial (routing.yaml entry, no schema/behavior lock-in).

### T1 — `SendEmailMessage` routed to `async` breaks the `test` env's implicit no-DB assumption
Decision: Enabled the previously-commented-out `when@test:` override in `messenger.yaml`, pointing `async` at `in-memory://` for the `test` environment only.
Rationale: `LostPasswordActionTest`'s success-path cases exercise the real `MailerInterface` (not a mock) and its docblock explicitly assumes no live DB connection is available; without this override, routing mail to the real `async` (Doctrine) transport in `test` would attempt a DB write during a test written to expect none. `in-memory://` reproduces the previous (pre-T1) effectively-synchronous, no-I/O behavior for tests.
Reversibility: trivial.

### T1 — `phpunit.dist.xml` was missing `KERNEL_CLASS`, blocking any functional test run
Decision: Added `<server name="KERNEL_CLASS" value="App\Kernel" force="true" />` to `phpunit.dist.xml`.
Rationale: Pre-existing gap (unrelated to T1) that made `bin/phpunit` unusable for `WebTestCase`-based tests (`LostPasswordActionTest`, added by an earlier commit). Needed to actually prove T1 didn't regress that suite; a one-line, safe, standard Symfony config addition.
Reversibility: trivial.

### T1 — `UserMailer`'s redacted-HTTP-body failure logging becomes unreachable for real transport failures once mail is async
Decision: Left `UserMailer::send()`'s try/catch (added very recently for a real Sentry issue, PHP-SYMFONY-3) unchanged rather than moving its response-body redaction into `MailerFailureListener`/`FailedMessageEvent`.
Rationale: Once `SendEmailMessage` is queued instead of sent in-process, a real transport failure happens later, inside the worker's `MessageHandler`, entirely outside `UserMailer::send()`'s try/catch — so that specific redacted-body log line will no longer fire for genuine Scaleway failures (only for exceptions thrown at *enqueue* time, e.g. a malformed DSN). This is not a silent gap: `MailerFailureListener` (unrelated pre-existing code, fires on the transport-agnostic `FailedMessageEvent` regardless of sync/async) still persists every real failure as an admin-visible `MailerError` row (class, message, subject, recipients), and Sentry's Messenger integration (see above) still reports it — verified live in this session: a real Scaleway 400 during testing produced three `MailerError` rows exactly as expected. The only loss is the extra redacted-HTTP-response-body detail UserMailer specifically added; class+message+subject+recipient is judged sufficient for admin triage. Flagged to Main in the T1 report rather than treated as silently resolved, since it touches another very recent, deliberate piece of work.
Reversibility: moderate (would require moving the redaction helper into `MailerFailureListener` and updating `MailerError`/its 3 existing unit tests + `UserMailerTest` if reconsidered).
