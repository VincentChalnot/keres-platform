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

### T2 — Visual design for the email layout (colors, layout shape, font fallback)
Decision: Table-based layout, dark header banner (`#1a1a1a`) with the "KERES" wordmark in `#e19e5b` (keres-primary), cream content card (`#f8f0e6`), gold pill CTA buttons (`background:#e19e5b; color:#1a1208`, matching `assets/app.scss`'s existing on-primary-button convention), muted footer (`#8a7a63`). Font fallback: `Georgia, 'Times New Roman', Times, serif`.
Rationale: No design spec was given beyond "match approximately" + the palette/font names; picked the closest web-safe serif to the site's two custom serif webfonts (Carolingia/RomanSerif have no email-safe loading path per the brief), and reused color roles (dark surface for chrome, primary for accent/CTA, light for readable body) already established both on the marketing site and in this repo's own `assets/app.scss`, rather than inventing a new pairing. A light body (not the site's near-black `#010101` page background) was chosen deliberately: email clients frequently strip `background-color` while keeping inline text `color`, so a dark-background design risks unreadable near-invisible text in clients that drop the background; this is a standard transactional-email pattern (dark brand header, light content) precisely to avoid that failure mode.
Reversibility: trivial (CSS values only, no structural/schema lock-in).

### T2 — Where should the "unsubscribe" hook live if neither current mail uses it?
Decision: `_layout.html.twig`/`_layout.txt.twig` accept an optional `unsubscribe_url` context variable and only render the footer line when it's truthy; neither `reset_password` nor `account_exists` passes it. Documented in both layout files' leading comment that it must point at `/settings/notifications` (`settings_notifications` route) for a future non-critical mail, since all recipients are logged-in users (no anonymous unsubscribe-token system needed).
Reversibility: trivial.

### T3 — CGV → CGU repurpose: slug, i18n key prefix, and shortcode rename
Decision: Deleted `content/{fr,en}/terms-of-sale.md` and `layouts/shortcodes/i18n_terms.html` (all `terms_*` i18n keys) in `keres-website`; replaced with `content/{fr,en}/terms-of-use.md` (FR slug `conditions-generales-d-utilisation`) and `layouts/shortcodes/i18n_cgu.html` (all `cgu_*` keys), entirely new terms-of-use content (no store/sale language — account creation, conduct, IP, availability, account deletion cross-linking the new privacy policy, liability, French law/mediation).
Rationale: Directed explicitly by the orchestrator (the platform has no store/payment/stock; the old CGV — pre-order, 45€ Collector's Edition, 14-day withdrawal right, delivery — described a product that doesn't exist in scope). Renaming the i18n key prefix (not just the page content) avoids a stale "terms_" prefix implying "terms of sale" for a future maintainer.
Reversibility: moderate (content + slug + i18n key rename; URLs change, so any external link to the old `/terms-of-sale`/`/conditions-generales-de-vente` slugs now 404s — acceptable since this is a pre-launch content fix, not a live page with inbound links to preserve).

### T3 — New Privacy Policy page: English translated in full vs. FR-only + note
Decision: Wrote the English `privacy-policy.md`/`i18n_privacy.html` content as a full translation, not a "governed by French law, see the French version" stub.
Rationale: The brief left this open ("your call, log it either way"). Full translation keeps the `en.toml`/`fr.toml` key-count lockstep convention (`AGENTS.md`: "keep en.toml and fr.toml in lockstep") intact and gives English-reading users (the platform's own UI is English-first) the actual policy rather than a language-gated stub.
Reversibility: trivial (translation text only).

### T3 — IONOS legal-entity/address verification path
Decision: Cited "IONOS SE, Elgendorfer Straße 57, 56410 Montabaur, Germany" in the legal notice.
Rationale: `ionos.de/impressum` (the exact retail Impressum) is a client-side-rendered Next.js page — my fetch tool returned the page shell/title ("Impressum | IONOS SE", confirming the entity name) but not the JS-hydrated address block. I directly read a second IONOS-owned first-party page, `ionos-group.com/imprint.html`, which explicitly states the identical street address for the sibling/parent entity at the same registered office. Combined with the web-search tool's own citation extraction (sourced from ionos.de/impressum directly, quoting the same address), this is corroborated first-party confirmation, not a guess — not marked `{{TODO}}`. Flagging the caveat here per the brief's "don't rely on memory" instruction, in case Main wants a stricter single-page confirmation.
Reversibility: trivial (one address line).

### T3 — Pre-existing "keres.fr" domain mentions left untouched
Observation (not a decision requiring action): `legal_editor_text`/`legal_ip_text1`/`legal_ip_text2`/`legal_links_text` still say "Le site keres.fr" / "keres.fr", while the actual production domain is `playkeres.com` (per this repo's own `AGENTS.md`). This predates T3 and wasn't part of the identity-table/hosting/privacy scope given — left as-is rather than drive-by-fixing unrelated stale content. New content written for T3 (CGU, privacy policy) correctly uses `playkeres.com`/`app.playkeres.com`.
