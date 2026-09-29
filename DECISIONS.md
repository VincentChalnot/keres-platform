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

### T4 — Trust page slug and placement of the registration-screen link
Decision: Slug `/trust` (EN, filename-based) / `/fr/engagements/` (FR `slug: engagements`), matching the URL the brief itself suggested. On `templates/security/register.html.twig`, placed the link as a small muted line ("Read our commitments to you") directly below the submit button, above the existing "Already have an account?" line.
Rationale: `/trust` was explicitly named in the brief. Placement is a minor, reversible styling call — fine print near the submit button, per the brief's own suggested options, not intrusive on the form itself.
Reversibility: trivial.

### T4 — Fifth commitment: hard stop honored, placeholder only
Decision: Did not draft any wording for the open-source-if-abandoned commitment. No licence name, no trigger condition, nothing resembling a rough attempt. Rendered a visually distinct box (dashed border, "COMING SOON"/"À VENIR" badge) under a heading that only restates the *topic* the orchestrator already named in the task brief itself ("open-sourcing the code if the project is ever abandoned") — not new legal content.
Rationale: Explicit hard stop in this task's brief and the run's §3 rules. Logged as an informational (non-blocking) entry in `BLOCKED.md` per instruction — ships alongside the other four real commitments in the same commit.
Reversibility: trivial (placeholder swap once wording is settled).

### T4 — "No advertising other than the author's own projects" claim verified before asserting it
Decision: Asserted the claim as true.
Rationale: Grepped both repos for any ad-network/analytics/tracking script (adsense, gtag, googletagmanager, doubleclick, facebook-pixel, hotjar, matomo, generic "analytics") before writing the page — zero matches (the one "analytics" hit in `AGENTS.md` refers to the internal `BoardPosition` ML-training tree, unrelated to third-party ad/analytics services). No ad network exists today, so the claim holds without qualification.
Reversibility: n/a (factual verification, not a design choice).

### T5 — Two dedicated single-purpose actions vs. one combined action for the GDPR forms
Decision: `SettingsPrivacyDataExportAction`/`SettingsPrivacyAccountDeletionAction`, each `POST`-only, each rendered by `SettingsPrivacyAction` via a shared `GdprRequestType` with an explicit `action` URL option — `SettingsPrivacyAction` itself never handles their submission.
Rationale: Matches the codebase's established "one invokable action per file" convention (`AGENTS.md`) rather than cramming three separate form-submission branches into one controller. The disabled/unmapped `email` field on `GdprRequestType` is purely a "you're submitting this as `<email>`" confirmation display — never read back on submit; the acting `$user` (from the security context) is always the authoritative source for who the request is from.
Reversibility: trivial.

### T5 — `ADMIN_NOTIFICATION_EMAIL` default: blank + no-op, not a derived address
Decision: Blank by default in both `.env.example` files and both `compose.yaml`s (same "commented out, feature stays off until configured" pattern as `SENTRY_DSN`/`OIDC_*`), and `AdminNotificationMailer::sendGdprRequestNotification()` explicitly no-ops (logs at info level, returns) when the address is empty, rather than attempting `->to('')` and throwing.
Rationale: Unlike `MAILER_FROM_ADDRESS`/`STATIC_SITE_URL` (legitimately derivable from `SERVER_NAME` — the domain the mail should look like it's from), there is no sensible *automatic* value for "the operator's personal inbox for GDPR alerts" that isn't either a fabricated-looking placeholder or an actual guess at a real address, and the brief explicitly said not to hardcode one. A silent no-op keeps the actual request-intake path (the part that matters — the `Feedback` row + confirmation flash) fully functional on a fresh install even before an admin address is configured, rather than 500ing on an unset var.
Reversibility: trivial.

### T5 — New `FeedbackCategory` cases wired through every existing category surface
Decision: Added `DATA_EXPORT_REQUEST`/`ACCOUNT_DELETION_REQUEST` not just to the enum but to every place the codebase already enumerates categories by hand: `FeedbackReviewType`'s (disabled) admin dropdown, the datagrid category-badge color map, and the datagrid's category filter choices.
Rationale: `FeedbackReviewType`'s category `ChoiceType` is `disabled => true` but still needs the current value present in its `choices` list to render the selected option correctly - leaving the two new cases out would have made the admin edit screen behave oddly (empty/blank category shown) for exactly the rows this task creates. No schema migration needed: `#[ORM\Column(type: Types::STRING, enumType: ...)]` is a plain-string column with a PHP-side cast, not a native Postgres enum type or CHECK constraint (confirmed via `doctrine:schema:update --dump-sql`, which showed a large pre-existing unrelated drift but nothing touching `feedback.category`).
Reversibility: trivial.

### T5 — Privacy policy wording check (asked explicitly in the brief)
Decision: Made a small tweak. `privacy_retention_text2`: "If you delete your account, your data is erased..." → "When your account is deleted at your request, your data is erased..." (FR: "Si vous supprimez votre compte" → "Si votre compte est supprimé à votre demande"). Also extended `privacy_rights_text2` to mention the new self-service request buttons in Settings → Privacy as an additional path alongside the contact form (this second change goes slightly beyond what was strictly asked, since T5 itself is what makes that sentence more accurate/useful — flagging it here rather than treating it as silently in-scope).
Rationale: The original phrasing read as if deletion were instant/self-service; T5 confirms it is always a *request* a human processes within 30 days (no `deletedAt`/anonymization code exists on `User` at all, confirmed by grep before starting). The "Vos droits"/"Your Rights" section already frames the 30-day process, so the retention section needed to match that framing rather than contradict it.
Reversibility: trivial (wording only).

### T6 — "Game abandoned" mapped to the existing `finaliseAbort()`/`ABORTED` path, not the unused `GameEndReason::ABANDONMENT` case
Decision: `GameLifecycleManager::finaliseAbort()` dispatches `AnalyticsEventType::GAME_ABANDONED`. `GameEndReason::ABANDONMENT` (a distinct enum case, value 4) is left untouched - no analytics dispatch added for it.
Rationale: Grepped the whole codebase for `GameEndReason::ABANDONMENT` before starting - it is used nowhere. There is no presence/disconnect tracker, no abandonment finaliser, nothing that ever constructs a game with that reason. The overview doc lists "opponent presence/disconnect indicator... drives abandonment adjudication" as in-scope but it hasn't been built yet (same category as the invite/challenge mechanism below - planned, not present). `finaliseAbort()` (games that vanish with no rating hit because nobody moved twice) is the only thing in the codebase a plain-English "game abandoned" can honestly refer to today. Wiring `ABANDONMENT` itself would mean inventing a call site that doesn't exist, which the brief's own guidance for the *other* two deferred event types (invite sent/accepted) explicitly says not to do.
Reversibility: trivial (a future presence-tracker task can dispatch `GAME_ABANDONED` from wherever it lands too - same event type, no schema change).

### T6 — `DevUserSwitchListener`'s user-creation path excluded from `ACCOUNT_CREATED`
Decision: Instrumented exactly the three paths the brief named (`RegisterAction`, `OidcUserProvider`, `DevLoginAuthenticator`). Found a fourth `new User(...)` site during research - `DevUserSwitchListener` (the `?_as=` two-tab-testing impersonation shim, dev-only) - and deliberately did not instrument it.
Rationale: It's a testing convenience that lazily creates a throwaway user as a side effect of impersonating an email that happens not to exist yet, not a moment any real visitor or the brief's three named flows produce. Including it would mean every multi-identity Playwright test run in this repo (including several already done in this session) inflates `account_created` counts with synthetic rows.
Reversibility: trivial (one more call site if ever wanted).

### T6 — Where "first game started" is computed, and which call sites get it
Decision: Added `GameRepository::countForUser()` (any status/type), called once per real game-creation call site - `NewLocalGameAction` (AI/hot-seat) and `SeekMatcher::tryPair()` (real matchmaking pairing, for *both* paired users) - immediately before the new game is persisted. `GameFactory` itself was not touched (it never persists/flushes - its callers do) and `CreateTestGameCommand` (the CLI dev-only test-fixture command, also calls `GameFactory` directly) was deliberately left uninstrumented, same reasoning as the dev-only account-creation path above.
Rationale: The brief explicitly allows a plain count here ("low-frequency, not hot-path"). Computing it right at the two real user-facing creation points, rather than inside `GameFactory`, matches the existing architecture (the factory is a pure builder; its callers own persistence) and correctly treats "first game" as a per-user milestone - a `SeekMatcher` pairing can be either player's first game independently, so both are checked and either, both, or neither `FIRST_GAME_STARTED` event fires.
Reversibility: trivial.

### T6 — Dispatch placement: inside the transaction in `GameLifecycleManager`/`GameEngine`, strictly post-commit in `SeekMatcher`
Decision: `GameLifecycleManager`'s four methods and `GameEngine::applyMove()` dispatch analytics *inside* the same transaction as the game write (same placement as the existing `RatingUpdater`/`NotificationCenter` calls they sit beside). `SeekMatcher::tryPair()` dispatches only after its manual `$this->connection->commit()`, alongside the existing "Step 7: publish, strictly post-commit" block.
Rationale: Not a functional inconsistency - both are safe, since `RecordAnalyticsEventMessage`'s doctrine transport shares the app's single `default` DBAL connection, so a dispatch made *inside* an open transaction is itself rolled back if that transaction aborts (a correct, transactional-outbox property, not a bug). The difference is purely "match the file's own existing convention": `GameLifecycleManager`/`GameEngine` had no pre-existing sync-vs-async distinction to preserve, so the simplest placement (right next to the sibling calls) was used; `SeekMatcher` already has an explicit, commented "post-commit" zone for exactly this class of "only after the real DB work is certain" concern (`tryPair()` can roll back and retry), so the analytics calls join it there for consistency with the file's own stated intent, not because the transactional placement would have been wrong.
Reversibility: trivial (placement only, no behavioral difference either way given the shared-connection property above).

### T6 — `INVITE_SENT`/`INVITE_ACCEPTED`: no comment anchor exists, noted here instead
Decision: Defined both enum cases now (so T11 doesn't touch `AnalyticsEventType`/the message/handler again) but added no `// TODO(T11): ...` code comment anywhere, since no invite/challenge file exists yet to anchor one in.
Rationale: Confirmed via repo-wide search (per the brief's own instruction to check `05-social.md` and the actual code first) that no `Challenge`/`Invite` entity, action, route, repository, or voter exists anywhere - only doc mentions and an unused `Game.rematchOfferedByColor` column. A comment dropped into an unrelated file on the vague theory that "invites will probably live near here" would likely just be wrong and stale by the time T11 actually lands. Recorded here and in `PROGRESS.md` instead: T11 should dispatch `AnalyticsRecorder`-style calls for `AnalyticsEventType::INVITE_SENT`/`INVITE_ACCEPTED` from wherever it ends up creating/accepting the invite/challenge row.
Reversibility: n/a (documentation-only choice).
