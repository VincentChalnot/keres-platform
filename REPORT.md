# Keres v1.0 Release Batch — Final Report

Branch: `v1-integration` in both `keres-platform` and `keres-website` (the
Rust engine, `../keres`, was read-only reference throughout — never
branched, never changed). `main` was never touched in either repo. All
fourteen tasks (T1–T14) are done, committed, and individually verified
against the running dev stack. One atomic commit per task; full narrative
and verification detail for each is in `PROGRESS.md`; every judgment call
is in `DECISIONS.md`; the one hard stop (since resolved) is in `BLOCKED.md`. This
report summarizes all three plus the cross-cutting inventories the brief
asked for (migrations, env vars, TODOs, risks).

The branch history was rebased after the run: post-run fixes were folded
into the task commits they belong to, and every commit is GPG-signed. The
SHAs below are the current ones; `git log --oneline main..v1-integration`
in each repo reproduces them. Hashes quoted inside `PROGRESS.md` predate
that rebase and are stale; match commits by their `T<n>:` subject instead.

---

## Per-task status

### T1 — Async worker (mail off the request thread)
**Commit**: `keres-platform` `44be8af`.

Most of the async infrastructure already existed (worker service, failure
transport, admin-visible `MailerError` logging, Sentry's bundled Messenger
failure listener — confirmed by inspecting the compiled prod container,
not just `debug:container`). The one real gap: `SendEmailMessage` had no
routing entry, so every mail send blocked the HTTP response. Fixed with a
one-line `messenger.yaml` routing entry, plus a `test`-env override so the
existing `LostPasswordActionTest` (which assumes no live DB) keeps
passing, and a pre-existing `phpunit.dist.xml` gap (`KERNEL_CLASS` missing)
that made `bin/phpunit` unusable for `WebTestCase`s at all.

**Exercised**: stopped the worker, submitted a real password-reset form,
confirmed the request returned immediately while the message sat queued;
confirmed real Scaleway send failures still produce admin-visible
`MailerError` rows; confirmed Mailpit delivery with a temporary DSN
override. Full suite green.

**Flagged, not fixed** (non-blocking): `UserMailer`'s redacted-HTTP-body
failure logging is now unreachable for genuine async failures (the
exception happens later, inside the worker, outside its try/catch) —
`MailerError` + Sentry still fully cover it, just with less granularity.
See `DECISIONS.md` T1.

### T2 — Email templating
**Commit**: `keres-platform` `d8f9506`.

Built a shared, table-based, fully inline-styled transactional email
layout (dark header banner, "KERES" gold wordmark, cream content card,
muted footer, web-safe serif fallback) and ported both existing mails
(reset-password, account-exists) onto it. Changed the sender default from
`no-reply@` to `noreply@` everywhere it's referenced. Added an optional
`unsubscribe_url` hook, unused by either of these two critical/
transactional mails, documented for later use.

**Exercised**: both mails driven live through Mailpit, visually confirmed
(screenshot) correct colors/wordmark/CTA/footer/no-unsubscribe-line;
plain-text parts checked for stray blank lines.

### T3 — Legal pages
**Commits**: `keres-platform` `6738112`, `keres-website` `e79a0f2`.

Real content lives on the marketing site. Filled in the real editor
identity and all four real hosting providers (with addresses corroborated
from each provider's own first-party pages — flagged the one page that
needed two-source corroboration because a client-rendered Impressum page
didn't serve its address to a plain fetch) in Mentions légales. Replaced
the old physical-product CGV (pre-order, 45€, 14-day withdrawal — totally
inapplicable to a platform with no store) with a real CGU covering
accounts, conduct, IP, availability, deletion, liability. Wrote a new
Privacy Policy, including the required "games from deleted accounts are
anonymized, not erased, and may be used for training" clause. Wired all
three into both footers and this platform's own footer/login page.

**Exercised**: real Hugo production build, screenshotted every new/changed
page in both languages, confirmed old URLs now 404, followed every
cross-link.

### T4 — Trust pledge page
**Commits**: `keres-platform` `083ddd8`, `keres-website` `ddb9616`.

New `/trust` page with four real, verified commitments (no imposed
third-party cookies, data never sold, no advertising beyond the author's
own projects, advance notice before any of those three change — each
checked against the actual codebase before being asserted, not assumed).
**Fifth commitment** (open-source-if-abandoned): the licence and trigger
were a named hard stop, so the run shipped a marked "coming soon"
placeholder. Resolved after the run with the operator's approval: the
platform code is released under AGPL-3.0 on an announced shutdown or
after 90 consecutive days of unreachability, via a self-executing
conditional grant in the platform's `LICENSE`. The engine is already
GPL-3.0, and the page says so. Linked from the registration screen.

**Exercised**: production Hugo build, both languages render all five
commitments with no placeholder left; registration-page link navigates
there.

### T5 — GDPR request actions
**Commits**: `keres-platform` `2e61041`, `keres-website` `0e77779`.

Confirmed first (grep) that `User` has no deletion/anonymization logic at
all — this is a request-intake mechanism, not a self-service flow, by
design. Added two `FeedbackCategory` cases, two dedicated one-action-per-
file controllers under `/settings/privacy`, and a new
`AdminNotificationMailer` that emails a configurable admin address (new
`ADMIN_NOTIFICATION_EMAIL` env var, blank-by-default, no-ops rather than
erroring when unset) with a deep link to the review queue. Tightened one
sentence of the privacy policy that read as if deletion were instant.

**Exercised**: both request types submitted through the real UI, confirmed
`Feedback` rows with correct category/body/user, confirmed the admin
datagrid renders the new categories, confirmed the admin notification
email actually lands in Mailpit with a working deep link.

### T6 — Instrumentation (append-only analytics event log)
**Commit**: `keres-platform` `af76cb4`.

New `analytics_event` table + `AnalyticsRecorder` facade, dispatched async
via a new Messenger message (rides T1's worker). Six real call sites
instrumented: account creation (three real flows, deliberately excluding
the dev-only `?_as=` impersonation shim), first-game/game-started (both
AI/hot-seat and real matchmaking pairing), move played (the single funnel
both human and AI moves pass through), game finished/abandoned (mapped to
the actual existing finalization paths, not an unused enum case that has
no call site yet). Two event types (`INVITE_SENT`/`INVITE_ACCEPTED`)
were defined ahead of the invite feature; T11 wires them (see below).

**Exercised**: no UI was built (there's deliberately nothing to click for
a pure collection task) — played a real game end-to-end through the
browser and read `analytics_event` via `psql` after each step, confirming
the exact expected sequence and payloads in order.

### T7 — Waitlist form (physical edition)
**Commits**: `keres-platform` `2d89478`, `keres-website` `6440353`.

Double opt-in signup, explicitly separate from the generic `Feedback`
model until confirmed (new `waitlist_signup` table), reusing the existing
password-reset token scheme exactly. New `/physical-edition` page on the
marketing site cross-posts to a new platform endpoint; the confirmation
mail uses T2's shared layout. Fixed the previously dead-linked, purchase-
implying homepage "Collector's Edition" block to point here instead and
removed a hardcoded, now-misleading `45€` price line. Also fixed an
unrelated pre-existing dev-server bug (`hugo server --appendPort`) that
was silently breaking every cross-origin link in local dev, discovered
while testing this feature's own form.

**Exercised**: full double-opt-in round trip through Mailpit — signup,
confirm link click, `Feedback` row created exactly once; replayed the same
link and confirmed no duplicate row and the correct "invalid or expired"
state; confirmed the admin panel renders the new category.

### T8 — Correspondence time control (per-move deadline sweeper)
**Commit**: `keres-platform` `5ed1972`.

Correspondence switched from whole-day to hour granularity
(`days_per_move` → `hours_per_move`, ×24 on existing rows — pre-launch, no
real user data at risk). New Supervisor-run sweep command does two
independent, row-locked passes every 60s: forfeit expired games through
the existing adjudicator, and send a one-time deadline-warning email 6h
before expiry. The move path no longer races the sweeper for
correspondence games.

**Exercised**: warning-then-no-duplicate-warning verified via Mailpit;
forfeit path verified with the correct `GameEndReason::TIMEOUT`; **ran two
simultaneous sweep processes against the same expired game** to prove the
row lock prevents a double-finalize — exactly one of the two runs reported
the forfeit, the game ended exactly once.

### T9 — Notification emails
**Commit**: `keres-platform` `9115025`.

Email became a second, independently toggleable channel alongside the
existing in-app notifications, same enum-driven settings UI pattern (a new
notification type gets both toggles for free). `YOUR_TURN` is rate-limited
to one email per game per hour via a row-locked column, proven by calling
the mailer twice in immediate succession and confirming Mailpit's count
moved by exactly one. `GAME_FINISHED` email dispatch rides the same
already-established transactional-outbox property T6 relies on (same-
connection Messenger transport inside an open transaction) rather than
threading post-commit plumbing through four call sites — reasoned and
logged, not just asserted.

**Exercised**: two real users, a real multiplayer game — move produced
both the in-app row and the correctly-toggleable email; rate-limit
suppression proven with a throwaway script; settings page proven to
persist only the deltas from default and to make each (type, channel)
toggle genuinely independent.

**Found, not fixed** (non-blocking, logged): `DevLoginAuthenticator` never
clears a stale `target_path`, so a leftover one from earlier testing can
hijack a later dev-login's redirect. Dev-only convenience code, unrelated
to this task; flagged for whoever next touches it.

### T10 — AI difficulty level piping
**Commit**: `keres-platform` `e8fa44c`.

New nullable `game.ai_level` column; the AI move path always calls the
leveled Rust endpoint now (`/engine-move-game/:level`), confirmed against
the engine's actual route table in `../keres` (read-only reference, not
modified). New game form gained an always-visible, help-texted difficulty
selector (1–10, defaulting to 1, the weakest, per the brief); the level
flows through to persistence, the board UI ("vs AI (level N)"), and T6's
analytics payload for free (T6 had already reserved the parameter months
earlier anticipating this task by name). Guest (no-account) AI games are
explicitly out of scope — there's no `Game` entity to persist a level on
for that path.

**Exercised**: two real AI games (level 5 and the default level 1) through
Playwright; confirmed the persisted column, the rendered label, the
bootstrap JSON, the analytics payload, and the literal outbound HTTP URL
all agree. Hit and resolved a real environment issue along the way (a
stale cached backend image 404'd on the leveled route) — not a platform
bug, logged for future sessions.

### T11 — Invite a friend
**Commit**: `keres-platform` `aa68045`.

Reused the existing `Seek`/matchmaking system wholesale rather than
building a parallel concept: an invite is a `Seek` with a new
`inviteOnly` flag, excluded from the public pool and from mutual dedupe
at both the listing-query and pairing-scan layers independently (a lesson
this codebase's own testing conventions already document: a guard in only
one layer produces a wrong-actor pairing). The shareable link's token is
the `Seek`'s own UUID — already unguessable, already the identifier every
other seek endpoint uses, no new hashed-token column needed (a deliberate
difference from T7's waitlist token, reasoned in `DECISIONS.md`).
Single-use falls out of the existing OPEN→MATCHED state transition.
Auth-flow preservation through login needed zero new code — the firewall
already saves/restores the intended URL.

**Exercised live via direct HTTP calls** (no browser tool available for
this phase): self-invite rejection, anonymous→login→original-URL
preservation end-to-end, a real pairing creating a real `Game` row,
correct "no longer available" on replay by both the original acceptor and
an unrelated third party, and a correspondence invite's independent
TTL-expiry branch (live-checked at accept time, not dependent on a sweep).

Invite analytics (`INVITE_SENT` on creation, `INVITE_ACCEPTED` only after
a successful pairing) were missing from the original T11 commit and folded
in after the run; verified live: one invite created and accepted produced
exactly one `invite_sent` (inviter) and one `invite_accepted` (acceptor,
with the new game) row, alongside both players' `game_started` /
`first_game_started` rows from `SeekMatcher`.

### T12 — Nav/styling unification (descoped mid-run)
**Commit**: `keres-platform` `8142d9c`.

Original ask (a stylesheet shared between this Bulma app and the Tailwind
marketing site) was explicitly descoped by the orchestrator mid-run to
"bring the platform's visual style closer via `assets/app.scss` alone,
keep Bulma." Investigation found the two were already a near-exact match
from earlier tasks' incidental palette work — the one real, visually
confirmed gap was Bulma's unthemed `$link` variable, which also drives
input focus-ring color, producing a clashing blue focus ring on every form
field in the app. One-line SASS variable fix.

**Exercised**: before/after screenshots of a focused form field (blue →
gold), full-page comparisons against the marketing site, confirming no
other visual regression.

### T13 — Unified games-list template
**Commit**: `keres-platform` `318da7a`.

Found three templates independently reimplementing "render a game row"
(dashboard widget, `/games`, profile page), each slightly different — only
the profile page correctly handled spectating someone else's game. Built
one `GameListPresenter`/`GameListRow`/`game_list_row()` Twig function/
`_game_row.html.twig` partial, taking an explicit `$subject`/`$isSelf`
pair (not always "the logged-in viewer") specifically because the profile
page's list is framed from the *profile owner's* perspective regardless of
who's looking — this is what let all three templates unify without
regressing the profile page's correct spectator wording. Added "time
remaining" (not shown anywhere before this task) for both correspondence
and realtime games, read-only, reusing `ClockManager`'s own timestamp
technique.

**Exercised**: a real AI game and a real matched multiplayer game,
confirmed byte-for-byte in curl responses that the participant view, the
spectator view (different wording, different button, correctly omits a
non-multiplayer game entirely), and both empty states all render
correctly across all three call sites.

### T14 — Lobby redesign
**Commit**: `keres-platform` `83b63ed`.

Rebuilt `/lobby`'s anonymous branch as the intended entry point: hero
panel (illustration, OIDC + "Create an account" as primary CTAs, guest
play kept deliberately secondary below a divider), a static announcements
list, and a new "Recently finished games" public feed using T13's
component. The brief's "games in progress stay visible only to
participants" is satisfied by scoping the new feed's own query to
finished games only — deliberately **not** by tightening
`GameVoter::VIEW`, which already has an existing, documented, in-use
contract that multiplayer games are publicly viewable at any point in
their lifetime (T13 just finished proving the spectator "Watch" path on
profile pages works; retroactively 404ing it would have been a much
bigger, unrelated, unreviewed change than "redesign the lobby" calls for).
The signed-in branch's "Your games" link became an inline T13 list; no
hero panel was added there since the signed-in page's primary action
already is the seek-posting UI.

**Exercised**: anonymous `/lobby` rendered 3 real finished games from the
dev database correctly, with a linked finished game loading for a fully
anonymous request; signed-in `/lobby` showed the correct empty state, then
a freshly created AI game inline immediately after creation.

---

## Decisions log — flagged for review

Every decision is in `DECISIONS.md`. The ones most worth a
human second look, roughly in order of how hard they'd be to walk back:

1. **T4 — Conditional AGPL-3.0 grant in the platform `LICENSE`** (hard to
   reverse: an irrevocable conditional licence on public code). Approved by
   the operator after the run; the clause's wording deserves a lawyer's
   review before launch.
2. **T3 — CGV→CGU slug/content replacement** (moderate reversibility: old
   `/terms-of-sale` URLs now 404). Directed explicitly by the orchestrator
   mid-run, not a unilateral call, but it's a real content/URL change on
   the public site worth a final look before launch.
3. **T14 / T13 — `GameVoter::VIEW` left unchanged**, meaning in-progress
   multiplayer games remain spectatable by anyone with the link, not just
   participants. This is the one place a literal brief sentence ("games
   in progress stay visible only to participants") and the actual shipped
   behavior diverge — deliberately, because the broader rule predates this
   run and changing it would be a materially bigger, separate decision.
   **Worth an explicit go/no-go from the operator before v1.0 ships**,
   since it's a real privacy-posture call, not just styling.
4. **T7 — New `WaitlistSignup` table + its own mailer**, rather than
   extending `Feedback`/`UserMailer`. Moderate reversibility (real schema),
   fully additive, no existing table touched.
5. **T8 — `days_per_move` → `hours_per_move` column rename**, with
   existing rows multiplied ×24 in the migration. Pre-launch, no real
   user data, but it's the one migration that transforms existing values
   rather than purely adding columns — worth a glance at the migration
   file directly (`Version20260929120000.php`) before running it anywhere
   with real data.
6. **T10 — guest (no-account) AI games excluded from difficulty
   selection** — easy to extend later, flagged only because it's a visible
   feature gap a tester might notice and report as a bug if not told it's
   intentional.
7. **T12 — scope reduction accepted without pushback**: the original
   "shared stylesheet across both repos" ask became "one SCSS variable in
   this repo alone." Correct per the orchestrator's own mid-run
   instruction, but flagging so whoever reads this doesn't mistake a
   one-line diff for an oversight.

All entries, including the fully-trivial ones (wording tweaks,
placement choices, test-methodology notes), are in `DECISIONS.md` for
completeness; the seven above are the ones that change user-facing behavior
or data shape in a way worth a deliberate look rather than a rubber stamp.

---

## Blocked

Nothing open. The run hit exactly one hard stop, which never blocked
anything downstream and is now resolved:

**T4 — Fifth trust-pledge commitment** (open-source-if-abandoned wording).
The licence and the "abandoned" trigger were reserved for the operator, so
the run shipped a marked placeholder. After the run the operator approved
AGPL-3.0 with two objective triggers (announced shutdown; 90 consecutive
days unreachable) as a self-executing conditional grant in `LICENSE`. The
conditional-grant wording should get a lawyer's review before launch.

No other task hit a hard stop: real credentials, DNS, deleting user data,
piece-logo identity and Rust engine rules were never touched by any task.

---

## `{{TODO}}` / placeholder inventory

A full-diff search (`git diff main..v1-integration`, both repos) for
`TODO`, `FIXME`, and placeholder markers finds **no code-level TODOs and
no content placeholders**. The one placeholder the run shipped (T4's fifth
commitment) has been replaced with the real commitment.

---

## New environment variables requiring real values before production

- **`ADMIN_NOTIFICATION_EMAIL`** (new, T5) — recipient for operational
  GDPR-request alerts. Blank by default; the notification silently no-ops
  (not an error) until this is set, so the actual request-intake flow
  (the part that matters) works on a fresh install either way. **Needs a
  real inbox address before launch**, or GDPR export/deletion requests
  will only be visible by someone actively checking the admin panel.
- **`MAILER_FROM_ADDRESS`** default comment changed from `no-reply@` to
  `noreply@` (T2) — not a new variable, just confirm the deployed value
  (if explicitly set rather than left to the default) matches the new
  convention.

No other new required configuration was introduced. `SENTRY_DSN`,
`OIDC_*`, `MAILER_DSN`, `BACKEND_API_URL`, `STATIC_SITE_URL` are all
pre-existing and untouched by this batch.

---

## Migrations

Six new migrations, all additive or value-transforming on pre-launch data
only — **no destructive schema change, no real user data involved**:

| Migration | Task | What it does |
|---|---|---|
| `Version20260928224500.php` | T6 | New `analytics_event` table (append-only). |
| `Version20260929080000.php` | T7 | New `waitlist_signup` table. |
| `Version20260929120000.php` | T8 | **Renames** `days_per_move`→`hours_per_move` on `game` and `seek`, multiplying existing values ×24; adds `game.deadline_warning_sent_at`. The one migration in this batch that transforms existing data rather than purely adding — safe pre-launch (no real rows), worth a direct look before running against any environment with real games. |
| `Version20260929140000.php` | T9 | Adds `game.last_your_turn_email_at`. |
| `Version20260929160000.php` | T10 | Adds nullable `game.ai_level`. |
| `Version20260929180000.php` | T11 | Adds `seek.invite_only`. |

Every migration was checked against `doctrine:schema:update --dump-sql`
after writing it to confirm the entity mapping matches exactly (several
tasks' `PROGRESS.md` entries note this showed only large, pre-existing,
unrelated drift — not investigated further, out of scope for this batch,
and not newly introduced by any of these six migrations).

---

## Risks and honest caveats

- **Confidence is highest on backend/state-machine correctness** (T1, T5,
  T6, T8, T9, T10, T11) — every one of these was exercised against the
  real running stack with real database reads confirming the actual
  persisted state, not just "the page loaded." Lowest-confidence area:
  anything that was curl-verified rather than browser-verified (T11, T13,
  T14 — see below) only proves the HTTP/data contract, not that the
  actual rendered page looks right in a real browser; I read the response
  HTML directly for visual-shaped checks (button labels, tag text) but
  never rendered it.
- **No browser tool was available for T11 onward** (an earlier phase of
  this run had Playwright access; it was disabled partway through).
  T1–T10 and T12 have real screenshot verification; T11, T13, T14 are
  verified via direct HTTP calls with real sessions and cookie jars,
  which exercises the actual backend logic completely but does not prove
  CSS layout, responsive behavior, or JS-driven interactions (clipboard
  copy on the invite button, the lobby's seek-form JS) beyond a type-check
  pass and direct diff review. **If a browser tool becomes available
  again, a visual pass over `/lobby` (both branches), the invite flow, and
  the unified game-row component across all four of its now five call
  sites would be the highest-value follow-up check.**
- **T12's scope reduction was accepted as directed**, not independently
  re-verified against the *original* "shared stylesheet" ask — if that
  original, broader ask still matters to the operator, it was not done
  and would need a new task.
- **The `GameVoter::VIEW` divergence (see Decisions #3 above)** is a real
  behavioral fact about the shipped system, not a bug: anyone with a link
  to an in-progress multiplayer game can watch it live today, and nothing
  in this batch changed that. If the brief's literal sentence was meant
  as a product requirement rather than a description of the new lobby
  feed's own scope, this needs a follow-up task, not a doc fix.
- **T6's multiplayer analytics path (`SeekMatcher`)** was only code-
  reviewed when T6 shipped; the post-run invite smoke test exercised a
  real two-player pairing and confirmed both players' `game_started` /
  `first_game_started` rows.
- **Two pre-existing, unrelated bugs were found and documented but left
  unfixed** (both logged above and in `DECISIONS.md`): `UserMailer`'s
  failure-log redaction losing granularity under async (T1), and
  `DevLoginAuthenticator` never clearing a stale `target_path` (T9, dev-
  only convenience code). Neither blocks anything; both are one-paragraph
  fixes for whoever picks them up next.

---

## Summary

14/14 tasks shipped, committed, and verified against the real running dev
stack; 1 hard stop, resolved after the run; 0 destructive migrations;
0 TODOs or placeholders; 1 new required-before-launch
env var (`ADMIN_NOTIFICATION_EMAIL`); 1 decision worth an explicit
operator go/no-go before launch (the `GameVoter` scope boundary, above).
`PROGRESS.md` and `DECISIONS.md` have full detail behind every claim in
this report.
