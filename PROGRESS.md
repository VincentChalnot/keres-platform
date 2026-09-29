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

**Next action**: T2 (email templating) is next.

---

## T2 — Email templating
**Status**: done. **Commit**: `47685f0`.

**What changed**: Built a shared transactional-email layout —
`templates/email/_layout.html.twig` (table-based, fully inline-styled: dark
header banner with the "KERES" wordmark in the site's primary gold, cream
content card, muted footer) and `templates/email/_layout.txt.twig` (plain
text counterpart) — and ported both existing mails
(`reset_password.{html,txt}.twig`, `account_exists.{html,txt}.twig`) onto it
via `{% extends %}`, replacing the previous bare `<p>`-only markup with no
layout at all. Colors/fonts pulled from `../keres-website/tailwind.config.js`
(`keres.primary #e19e5b`, `keres.dark #55442d`, `keres.light #f8f0e6`,
`keres.surface #1a1a1a`) and this repo's own `assets/app.scss` (same values,
plus the `color:#1a1208` on-primary-button convention, reused for the CTA
buttons). Fonts fall back to `Georgia, 'Times New Roman', Times, serif`
web-safe stack — the site's Carolingia/RomanSerif webfonts have no
email-safe loading path. No `<style>` block, no external stylesheet, no
remote font — every rule is an inline `style="..."` attribute.

Sender address: changed the `no-reply@` default to `noreply@` (literal,
no hyphen, per the brief) in `compose.yaml`, `deploy/compose.yaml`, both
`.env.example` files' comments, `docs/multiplayer/07-notifications.md`'s
`VAPID_SUBJECT` cross-reference, and `UserMailerTest`'s test constant.
Confirmed (grep, no matches) that nothing anywhere sets a `Reply-To` header —
stays that way, matches the brief's "no Reply-To" requirement.

Footer: both mails' footer states the address is unmonitored and links to
`{{ static_site_url }}/contact` (the marketing site's real contact page, not
the platform's JSON `/api/contact` POST target). Unsubscribe: the layout
accepts an optional `unsubscribe_url` context variable and renders a
"manage email preferences" line only when it's set — neither of the two
existing mails (password reset, account-exists security notice) passes it,
since both are critical/transactional and there's nothing to unsubscribe
from; wired to point at `/settings/notifications`
(`settings_notifications` route) whenever a future non-critical mail needs
it.

**Verified live**: `bin/console lint:twig templates/email/` (6 files, all
valid). `bin/phpunit` (45 tests, all green — no template-shape assumptions
broken). `composer cs:check` clean. Via Playwright against
`https://app.local.playkeres.com/`: dev-logged-in as a throwaway user,
drove `/login/lost-password` (reset-password mail) and `/register` with
that same already-registered email (account-exists mail). Both landed in
Mailpit (temporary `MAILER_DSN=smtp://mailer:1025` override for the
verification window only, restored immediately after — the local `.env`
points at a real Scaleway project, never touched). Confirmed via the
Mailpit API (`GET /api/v1/message/{id}`) and a rendered screenshot: correct
`noreply@local.playkeres.com` sender, dark header banner with gold "KERES"
wordmark, cream card body, gold pill CTA button matching the site's
`is-primary is-rounded` buttons, correct subject/body copy, footer with the
unmonitored notice + working contact link, and — correctly — no unsubscribe
line on either mail. Plain-text parts confirmed clean (no stray blank lines
from the unrendered conditional).

**Next action**: T3 (legal pages) was next; see below.

---

## T3 — Legal pages (mentions légales, CGU, politique de confidentialité)
**Status**: done. **Commits**: `keres-platform` `3a8e1b5`, `keres-website` `f4981bc`.

**What changed**: This content belongs on the marketing site
(`../keres-website`, Hugo), not this repo — created `v1-integration` there
too (first time touching it this run) and did the substantive work there.

- **Mentions légales**: filled in the real editor identity (SIREN 889 048 229,
  SIRET 889 048 229 00039, APE 6202A, 7 rue de Bruxelles 69100 Villeurbanne)
  from the identity table verbatim, replacing the old bracket placeholders.
  Replaced the single-provider hosting placeholder with all 4 real
  providers — IONOS SE (app hosting), Cloudflare, Inc. (static site/CDN),
  Google Cloud France (AI compute), Scaleway SAS (transactional email) —
  each with a legal name + registered address fetched from that provider's
  own published legal/imprint page (not memory). GDPR section now points to
  the new privacy policy instead of duplicating it; cookies section states
  plainly that only a strictly-necessary auth cookie is used, no banner
  needed.
- **CGV → CGU**: the existing `/terms-of-sale` page was a full French
  Conditions Générales de *Vente* for a physical "Collector's Edition"
  board game (pre-orders, 45€, 14-day withdrawal) — inapplicable boilerplate
  for a platform with no store/payment/stock. Deleted it and replaced with
  real Conditions Générales d'*Utilisation* at a new slug
  (`conditions-generales-d-utilisation` FR / `terms-of-use` EN): account
  creation, user conduct, IP, service availability, account deletion
  (cross-linking the privacy policy for retention detail), liability,
  French law/mediation. i18n key prefix renamed `terms_` → `cgu_` too, not
  just the content.
- **New Politique de confidentialité / Privacy Policy page**: data
  collected, purposes/legal basis, cookies (auth-only, explicitly no
  consent banner required), data recipients (cross-linking the hosting
  list), retention — including the required clause that games from
  deleted accounts are kept in irreversibly anonymized form (user↔game
  link severed, no recoverable mapping) and usable for model training —
  rights with a stated 30-day response window pointing at the existing
  contact form (no dedicated privacy inbox or self-service export/delete
  flow exists yet, and the page doesn't imply one does). English is a full
  translation (logged as a minor decision, not a French-law stub).
- Wired into both footers: `keres-website`'s `layouts/partials/footer.html`
  and the homepage's own duplicated footer block in `layouts/index.html`
  (pre-existing duplication, not introduced here) now link
  legal-notice/terms-of-use/privacy-policy/contact. This repo's
  `templates/base.html.twig` footer updated from `/terms-of-sale` to
  `/terms-of-use`, plus a new `/privacy-policy` link.
- No date of birth or any other non-public operator detail appears
  anywhere, per the brief. No cookie-consent banner was added — grepped
  both repos for any non-essential cookie/tracker first; found none, so
  none was needed per the brief's own reasoning.

**Verified live**: `keres-website` has no PHP CS Fixer; ran its actual CI
build command (`hugo --environment production --minify --gc`) clean, 10 EN
/ 8 FR pages, zero errors. Brought up `keres-website`'s own dev stack for
the first time this session (`docker compose up`, plus a one-off `npm ci`
inside the Hugo image — `node_modules` wasn't present) and drove it via
Playwright against `https://local.playkeres.com/`: screenshotted
`/legal-notice/`, `/terms-of-use/`, `/privacy-policy/` and their French
equivalents, confirmed every hosting provider/address renders correctly,
confirmed the old `/terms-of-sale/`/`/fr/conditions-generales-de-vente/`
URLs now 404 (had to clear a stale gitignored `public/`/`resources/_gen`
build-output directory left over from before this session — Hugo doesn't
clean orphaned output by default — to get an accurate result), and
followed every cross-link (legal notice ↔ privacy policy ↔ contact form ↔
terms of use) to confirm each resolves to the correctly localized page in
both languages. In `keres-platform`: `composer cs:check` clean,
`bin/console lint:twig` clean, `bin/phpunit` 45/45 green (unrelated to this
change, run for regression safety), and confirmed via Playwright
(dev-login → `/login` page footer) that all three links render with the
correct hrefs and that clicking through to Terms of Use actually lands on
the real, newly-written page.

**Decisions logged**: CGU rename rationale, EN-full-translation-vs-stub
call, the IONOS address verification path (two-source first-party
corroboration, not a single clean page load — see DECISIONS.md), and an
observation (not acted on) that pre-existing "keres.fr" domain mentions
elsewhere on the legal notice page are stale but out of scope for this task.

**Next action**: T4 (trust pledge page) was next; see below.

---

## T4 — Trust pledge page
**Status**: done. **Commits**: `keres-platform` `e50e177`, `keres-website`
`efab281`.

**What changed**: Public trust-signal content, built on `../keres-website`
again (already branched from T3). New `/trust` page (FR slug
`engagements`) presenting four real, verified commitments: no imposed
third-party cookies, personal data never sold/transferred, no advertising
beyond the author's own projects, and advance notice + opt-out/
account-deletion before any of those three change. No donation ask
anywhere on the page.

**Fifth commitment (hard stop honored)**: the orchestrator's brief named
this a hard stop on this specific point — the open-source-if-abandoned
commitment's wording, licence, and trigger condition were **not** drafted,
not even roughly. Rendered instead as a visually distinct placeholder
(dashed border, "COMING SOON"/"À VENIR" badge) under a heading that only
restates the topic already named in the task brief. Logged in `BLOCKED.md`
as informational/non-blocking — ships in the same commit as the other four
commitments, doesn't block anything downstream.

**Claims verified before asserting them**: re-confirmed (grep, both repos)
that no third-party cookie/tracker/ad-network exists anywhere, so "no
imposed third-party cookies" and "no advertising beyond the author's own
projects" are both true today, not aspirational.

**Wiring**: `keres-website`'s `layouts/partials/footer.html` and the
homepage's duplicated footer block in `layouts/index.html` both link the
new page (`footer_trust` key, same pattern as T3's three links). This
repo's `templates/security/register.html.twig` (the actual template
`RegisterAction` renders, per `config/packages/templating.yaml`) gained a
small fine-print line below the submit button linking to
`{{ static_site_url }}/trust`.

**Verified live**: `hugo --environment production --minify --gc` (this
repo's own CI build command) clean, 11 EN / 9 FR pages, zero errors.
Playwright against `https://local.playkeres.com/`: screenshotted `/trust/`
and `/fr/engagements/`, confirmed all four commitments render with the
intended copy and the fifth renders as a clearly-marked, content-free
placeholder in both languages. In `keres-platform`: `composer cs:check`
clean, `bin/console lint:twig` clean, `bin/phpunit` 45/45 green (regression
safety), and confirmed via Playwright that `/register`'s new link actually
navigates to the real trust page.

**Next action**: T5 (GDPR request actions) was next; see below.

---

## T5 — GDPR request actions
**Status**: done. **Commits**: `keres-platform` `8f3dc78`, `keres-website`
`96a1e87`.

**Confirmed before starting** (per the task's own instruction): `User` has
no `deletedAt`/anonymization field or logic anywhere — grepped
`src/Entity/User.php` and the whole codebase for `deletedAt`/`anonymiz`;
only `Game.deletedAt` exists (unrelated per-game soft-delete/archiving).
Confirms this is a request-intake mechanism only, exactly as scoped.

**What changed**: Two new `FeedbackCategory` cases
(`DATA_EXPORT_REQUEST`/`ACCOUNT_DELETION_REQUEST`), wired through every
existing place the codebase enumerates categories by hand
(`FeedbackReviewType`'s admin dropdown, the datagrid category-badge colors,
the datagrid category filter) — no migration needed, the column is a plain
string with a PHP-side enum cast, not a native DB enum/constraint
(confirmed via `doctrine:schema:update --dump-sql`, which showed only
large pre-existing unrelated drift).

`/settings/privacy` (`SettingsPrivacyAction` + `privacy.html.twig`) gained
a new "Your data" box with two small forms (shared `GdprRequestType`: a
disabled/unmapped email field pre-filled for display, an optional
free-text "anything else we should know?" field), each posting to its own
dedicated action (`SettingsPrivacyDataExportAction`,
`SettingsPrivacyAccountDeletionAction` — matches the "one invokable action
per file" convention). Each creates a `Feedback` row attributed to
`$user` with a fixed, readable message body, flashes the confirmation
copy ("request received... 30 days"), and calls a new
`AdminNotificationMailer` service.

`AdminNotificationMailer::sendGdprRequestNotification()` sends through
the now-async `MailerInterface` (T1) to a new `ADMIN_NOTIFICATION_EMAIL`
env var (blank by default in both `.env.example`s — the method explicitly
no-ops rather than sending to an empty address, so a fresh install stays
fully functional before an admin address is configured), using T2's shared
email layout (new `templates/email/admin_gdpr_request.{html,txt}.twig`) —
deliberately **without** ever passing `unsubscribe_url` (this is an
operational alert, not a user notification). The email links directly to
the specific `Feedback` row in the admin panel
(`sidus_admin.Feedback.edit`).

**Privacy policy wording check** (explicitly asked): tweaked
`privacy_retention_text2` on `../keres-website`'s privacy policy from "If
you delete your account..." to "When your account is deleted at your
request..." (FR equivalent), since T5 confirms deletion is always a
human-processed *request*, not self-service/instant — the original
phrasing read as overpromising next to the "Your Rights" section's own
30-day-request framing. Also extended `privacy_rights_text2` to mention
the new in-app request buttons alongside the contact form (flagged as
slightly beyond the strict ask, logged in DECISIONS.md).

**Verified live**: `composer cs:check` clean, `bin/console lint:twig`
clean, `bin/phpunit` 45/45 green. Via Playwright against
`https://app.local.playkeres.com/`: dev-logged-in, submitted both request
types from the real `/settings/privacy` UI, confirmed both flash
confirmations render the exact "received... 30 days" copy. Confirmed via
`psql` that both `Feedback` rows exist with the correct category, message,
and `user_id`. Temporarily granted the test user `ROLE_ADMIN` (reverted
immediately after) to confirm the admin datagrid renders both new
categories with distinct badge colors and that the (disabled) category
dropdown on the edit screen correctly shows "Data export request" as
selected. Used the established temporary-DSN-and-admin-email-override
pattern (restored immediately after) to confirm the admin notification
email actually lands in Mailpit: correct subject ("New account deletion
request"), T2 layout rendering correctly, a working deep link to the exact
admin edit page, and — confirmed by reading the raw HTML — no unsubscribe
line. On `../keres-website`: `hugo --environment production --minify --gc`
clean (11 EN / 9 FR pages, unchanged), Playwright-confirmed the reworded
privacy policy section renders correctly in English.

**Next action**: T6 (instrumentation) was next; see below.

---

## T6 — Instrumentation (append-only event log)
**Status**: done. **Commit**: `ba21924`.

**Confirmed before starting** (per the task's own instructions): grepped for
`GameEndReason::ABANDONMENT` (unused anywhere - no presence/disconnect
tracker exists) and for any `Challenge`/`Invite` entity/action/route
(none exist - `05-social.md` only mentions it as planned). Both findings
directly shaped the design below.

**What changed**: New `analytics_event` table
(`migrations/Version20260928224500.php`, `src/Entity/AnalyticsEvent.php`) -
`BIGSERIAL` id, `type` (new `AnalyticsEventType` string enum, 8 cases),
`occurred_at`, a nullable managed `user` relation (set via
`EntityManagerInterface::getReference()` in the handler - no SELECT), a
nullable raw-value `game` UUID column (deliberately *not* a managed
relation - never a join/lock on the hot move-submission path), and a
denormalised `payload` JSON column (`Notification::payload`'s precedent,
cited in the docblock).

Write path: a new `RecordAnalyticsEventMessage` routed to `async`
(messenger.yaml - T1's worker delivers it) + `RecordAnalyticsEventHandler`.
A new `AnalyticsRecorder` facade service (same "one place, consistent
shape" reasoning as `NotificationCenter`) gives every call site a single
cheap typed method call - no query, no join, no wait for a flush, even
from the hottest call site.

**Six real call sites instrumented**:
- `ACCOUNT_CREATED`: `RegisterAction`, `OidcUserProvider`,
  `DevLoginAuthenticator` (the three paths the brief named). A fourth
  `new User(...)` site found during research, `DevUserSwitchListener`
  (dev-only `?_as=` impersonation shim), was deliberately **not**
  instrumented (see DECISIONS.md).
- `FIRST_GAME_STARTED` / `GAME_STARTED`: `NewLocalGameAction` (AI/hot-seat)
  and `SeekMatcher::tryPair()` (real matchmaking pairing - checked for
  *both* paired users independently). New `GameRepository::countForUser()`
  for the first-game check (a plain count, fine per the brief - low
  frequency). `GameFactory` itself untouched (never persists); the CLI-only
  `CreateTestGameCommand` deliberately left uninstrumented.
- `MOVE_PLAYED`: `GameEngine::applyMove()`, after its transaction commits,
  only on a real (non-flagged) move - the one true funnel point for both
  human and AI moves (`aiMove()` calls into the same method). The hot path
  this whole design is built around.
- `GAME_FINISHED` / `GAME_ABANDONED`: all four `GameLifecycleManager`
  methods (`finaliseEngineResult`/`resign`/`finaliseTimeout` →
  `GAME_FINISHED` with `reason`/`whiteWins`/`draw` read straight off `Game`
  post-`finish()`; `finaliseAbort` → `GAME_ABANDONED`, mapped there rather
  than to the unused `GameEndReason::ABANDONMENT` - see DECISIONS.md).

**Two deferred, no fabricated call site**: `INVITE_SENT`/`INVITE_ACCEPTED`
enum cases exist now so T11 doesn't touch this infrastructure again, but
nothing dispatches them - there is no invite/challenge mechanism anywhere
in the codebase to hook into yet. No file existed to anchor a `// TODO`
comment in truthfully; noted here and in DECISIONS.md instead: **T11 should
dispatch `AnalyticsEventType::INVITE_SENT`/`INVITE_ACCEPTED` via
`AnalyticsRecorder` from wherever it creates/accepts the invite/challenge
row.**

**Verified**: `composer cs:check` clean, `bin/console cache:clear` (DI
wiring), `bin/phpunit` 45/45 green, `doctrine:schema:update --dump-sql`
showed nothing touching `analytics_event` (only large pre-existing
unrelated drift) - confirms the entity mapping matches the migration
exactly. No dashboard/report UI was built - there is deliberately nothing
to click for this task, so verification was entirely write-path: played a
real game end-to-end through the browser as a freshly `/register`-ed user
(dev-login re-auth, then `/play/new` → AI game → two real moves each side
→ resign) and read `analytics_event` via `psql` after each step. Got
exactly the expected sequence, in order: `account_created` →
`first_game_started` + `game_started` (`{"opponentType":"AI","aiLevel":null}`)
→ four `move_played` rows alternating `WHITE`/`BLACK` (one per ply, human
and AI moves both captured) → `game_finished`
(`{"reason":"RESIGNATION","whiteWins":false,"draw":false}` - correct, the
human/White side resigned). The multiplayer `SeekMatcher` path is
implemented and code-reviewed (identical pattern to the AI path, plus the
strictly-post-commit placement described in DECISIONS.md) but **not**
separately Playwright-verified - stated explicitly rather than
improvising a two-tab seek-matching test for a collection-only task with
no UI to confirm against.

**Next action**: T7 complete; awaiting next task assignment.

---

## T7 — Waitlist form (physical edition)
**Status**: done. **Commits**: `keres-platform` `831bbad`,
`keres-website` `19d39d7`.

**What changed (keres-platform)**: Double opt-in signup flow, entirely
separate from `Feedback` until confirmed (see DECISIONS.md). New
`waitlist_signup` table (`migrations/Version20260929080000.php`,
`src/Entity/WaitlistSignup.php`) - email, optional name/note, `tokenHash`/
`expiresAt`/`confirmedAt`, same token scheme as `LostPasswordAction`/
`ResetPasswordAction` (`bin2hex(random_bytes(32))` in the URL,
`hash('sha256', ...)` stored). New `POST /api/waitlist`
(`WaitlistSignupAction`, rate-limited via a new `waitlist_signup` limiter,
honeypot + required-email check, matches `ContactAction`'s shape) creates a
pending `WaitlistSignup` and sends a confirmation mail via a new
`WaitlistMailer` (T2's shared email layout, no unsubscribe link, 7-day
expiry stated in the copy). New `GET /waitlist/confirm`
(`WaitlistConfirmAction`) validates the token (`confirmedAt IS NULL` +
not-expired), marks the signup confirmed, and only then creates the
`Feedback(WAITLIST, "<email> (<name>)\n\n<note>")` row the admin panel
actually reviews - a replayed/shared confirmation link finds no matching
row the second time and shows the same "invalid or expired" state, with no
duplicate `Feedback` row. `FeedbackCategory::WAITLIST` wired through the
admin review dropdown, datagrid badge color (`success`/green), and datagrid
category filter, same as T5's two new categories.

**What changed (keres-website)**: New `/physical-edition/`
(`/edition-physique/` in French) page reusing `real_board_full.webp`,
with the waitlist form (email required, name/note optional) posting
cross-origin to `{platform-url}/api/waitlist`, mirroring
`layouts/contact/single.html`'s exact JS pattern (honeypot, status div,
i18n-driven copy/messages). The pre-existing, previously dead-linked
homepage "Collector's Edition" block (`content/{en,fr}/blocks/collector.md`)
was reframed from "Pre-order..." (button `url: "#"`) to "Join the
waitlist..." pointing at the new page, and the hardcoded `45€` price line
in `layouts/index.html` was removed - both were leftover
purchase/pre-order framing that contradicted this task's explicit
no-payment/no-store scope (see DECISIONS.md, same class of fix as T3's
CGV→CGU rename).

**Incidental fix**: found and fixed a pre-existing `keres-website` dev-only
bug while live-testing the new cross-origin form - Hugo's `server`
subcommand appends its own `--port` to `.Site.BaseURL` by default, which
broke every `platform-url.html`-derived link (Play/Login/Contact, and now
Waitlist) in the local dev stack specifically (not production, which never
runs `hugo server` - see DECISIONS.md). Added `--appendPort=false` to
`compose.yaml`.

**Verified live**: `composer cs:check` clean, `bin/phpunit` 45/45 green,
`doctrine:schema:update --dump-sql` clean for `waitlist_signup` (confirmed
the entity's `#[ORM\Index]` matches the migration exactly), `lint:twig`
clean, `debug:router` shows both new routes. Hugo `--environment
production --minify --gc` builds clean (12 EN / 10 FR pages, +1 each).
Full double opt-in flow via Playwright + temporary
`MAILER_DSN=smtp://mailer:1025` override (restored after): submitted the
real cross-origin form from `/physical-edition/` → confirmed via `psql` a
`waitlist_signup` row with `confirmed_at` still null → confirmation mail
arrived in Mailpit with a working `/waitlist/confirm?token=...` link →
clicked it → landing page showed "You're on the list!" → `psql` confirmed
`confirmed_at` set and exactly one `Feedback(category='waitlist')` row with
the composed message → clicked the same link again → landing page correctly
showed "invalid or expired" and `psql` confirmed still exactly one
`Feedback` row (no duplicate). Also checked `/admin/feedback/` as a
temporary admin test account: the new row renders with a green "waitlist"
badge and the "Waitlist" filter option works. All test rows (signup,
feedback, admin test user) deleted after verification.
