# Decisions

Design, domain and technical decisions that are not obvious from the code, in
ADR-lite form. **Oldest first; append new entries at the bottom.** Status is
`Accepted`, `Superseded by …` or `Open` (needs an operator decision). How the
features work is in [`docs/features.md`](docs/features.md), the conventions
in [`AGENTS.md`](AGENTS.md), open work in [`TODO.md`](TODO.md), unanswered
questions in [`QUESTIONS.md`](QUESTIONS.md).

Entry template:

```
## DNN - Title
Date - Status
Context: the problem and the constraints.
Decision: what was chosen (and what was rejected).
Consequences: what it commits us to, what to watch.
```

Older code comments cite the entries by their original run numbers (T1-T14 of the
v1.0 batch); they are given as "formerly Tn" below.

---

## D01 - Outgoing mail goes through the `async` Messenger transport
2026-09-28 - Accepted (formerly T1)

Context: `SendEmailMessage` had no routing entry, so every `MailerInterface::send()`
ran in-process and blocked the HTTP response on SMTP/Scaleway.

Decision: route `Symfony\Component\Mailer\Messenger\SendEmailMessage` to `async`
(`config/packages/messenger.yaml`), delivered by `php-worker`. In `when@test`, `async`
(and `evaluation`) are `in-memory://`, so mail-sending functional tests keep needing no
database. No Sentry change: the bundle's Messenger listener already reports every
failed message (checked on the compiled prod container; `debug:container` on an
uncompiled one is misleading, and a prod cache built without any `SENTRY_DSN` key
looks as if the listener were missing).

Consequences: a real transport failure now happens in the worker, outside the
`try/catch` of `UserMailer::send()` & co., so their redacted-response-body log line only
fires for enqueue-time errors. Failures are still visible as `MailerError` rows
(`MailerFailureListener`) and in Sentry. Restoring the response-body detail would mean
moving the redaction helper into `MailerFailureListener` (see `TODO.md`).

## D02 - Transactional email: one inline-styled layout, one mailer per feature
2026-09-28 - Accepted (formerly T2, T7, T9)

Decision: a single table-based, fully inline-styled layout (`templates/email/_layout.*`)
with a web-safe serif stack and a light content card (email clients often strip
`background-color` but keep text colour, so a dark body risks unreadable text).
`unsubscribe_url` is an optional context variable rendering the footer line, so the
layout works for critical mail (no unsubscribe) and notification mail alike.
Each feature has its own mailer (`UserMailer` is reserved for the two auth mails);
`WaitlistMailer` duplicates the ~10-line try/catch of `UserMailer` instead of
generalising it, because a waitlist recipient has no `User`. Sender is
`noreply@<SERVER_NAME>`, no `Reply-To`.

Consequences: a new mail type gets a new or existing feature mailer and extends the
layout; do not fold new mailers into `UserMailer`. Extract a shared base only when a
third mailer needs it.

## D03 - GDPR requests are an intake queue, not self-service
2026-09-28 - Accepted; implementation of the actual export/deletion is open (see `TODO.md`) (formerly T5)

Context: `User` has no deletion or anonymisation logic, and the privacy policy (on the
marketing site) promises a 30-day human-processed response.

Decision: Settings → Privacy only files a request: a `Feedback` row
(`DATA_EXPORT_REQUEST` / `ACCOUNT_DELETION_REQUEST`) plus an admin email to
`ADMIN_NOTIFICATION_EMAIL`. Two single-purpose POST actions (the codebase convention
"one invokable action per file") share a `GdprRequestType`; the acting user comes from
the security context, the displayed email field is never read back. The admin email
address is blank by default and the notification then no-ops (logs at info), so intake
works on a fresh install without that variable. No schema change: the category is a
plain string column.

Consequences: someone must read the admin queue (or set `ADMIN_NOTIFICATION_EMAIL`).
The policy also states that games of deleted accounts are kept in irreversibly
anonymised form; nothing implements that yet.

## D04 - The waitlist has its own entity until the address is confirmed
2026-09-29 - Accepted (formerly T7)

Decision: double opt-in state (unconfirmed email, token hash, expiry) lives in a new
`waitlist_signup` table; the canonical `Feedback(WAITLIST)` row is created only when a
valid token is clicked. Same token scheme as the password reset (random 32 bytes in the
URL, only the sha256 stored). The link is valid 7 days (a waitlist confirmation has no
security impact, unlike the 1-hour reset). `POST /api/waitlist` is separate from
`/api/contact`: different required fields, and the confirmation step has no contact
equivalent.

Consequences: a replayed confirmation link cannot create a second `Feedback` row
(`confirmedAt IS NULL` guard in `WaitlistSignupRepository::findByValidTokenHash()`).

## D05 - Analytics are an append-only event log, recorded asynchronously
2026-09-29 - Accepted (formerly T6, T11)

Decision: `analytics_event` table written through `AnalyticsRecorder` →
`RecordAnalyticsEventMessage` (`async`). "Game abandoned" is mapped to the existing
`finaliseAbort()` / `ABORTED` path, not to `GameEndReason::ABANDONMENT`, which has no
producer (there is no presence tracker). Dev-only throwaway users (`?_as=`) and the
`CreateTestGameCommand` fixtures are not recorded. "First game" is computed with
`GameRepository::countForUser()` at the two creation points (`NewLocalGameAction`,
`SeekMatcher::tryPair()`), not in `GameFactory`, which never persists. Dispatch sits
inside the game transaction (next to the rating and notification calls), except in
`SeekMatcher`, whose explicit post-commit block it joins.

Consequences: dispatching inside an open transaction is safe because the Messenger
Doctrine transport shares the DBAL connection: a rollback also drops the queued message
(transactional outbox). The same property is relied on for `CheckClockExpiryMessage`
and the `GAME_FINISHED` email (D07). Wire a new event next to the sibling calls.

## D06 - Correspondence time control is counted in hours and swept every minute
2026-09-29 - Accepted (formerly T8)

Decision: `days_per_move` became `hours_per_move` on `game` and `seek` (migration
`Version20260929120000`, existing values multiplied by 24). `app:games:sweep-deadlines`
(Supervisor loop, every 60 s) forfeits expired games through `ClockAdjudicator` and
sends a one-time warning 6 h before expiry, in two independent row-locked passes.

Consequences: the one migration that transforms existing data rather than adding
columns; `docs/multiplayer/` still describes `days_per_move`.

## D07 - Notification email is a second channel; `YOUR_TURN` is limited per game
2026-09-29 - Accepted (formerly T9)

Decision: email is toggled independently of the in-app channel, per `NotificationType`
(all on by default). `YOUR_TURN` emails are limited to one per game per hour
(`Game::lastYourTurnEmailAt`, one column for both players: a per-recipient limit would
need two columns or a side table). The `GAME_FINISHED` email is queued inside the
finalising transaction rather than after each of the four callers' flush, on the
transactional-outbox argument of D05. A new `NotificationMailer` rather than extending
`UserMailer` (D02).

Consequences: with both players moving fast, at most one of them is emailed per hour for
that game.

## D08 - AI difficulty is a game property; guests are capped
2026-09-29 (guest cap 2026-10-10) - Accepted (formerly T10)

Decision: nullable `game.ai_level` (1-10, form default 1 = weakest);
`EngineApi::aiMove()` always calls `/engine-move-game/{level}`, never the bare endpoint,
so no code path remains without a level. The difficulty select is always visible with a
help text ("only against the AI") rather than JS-hidden for hot-seat (the form has no JS
entry). Initially guest games ignored the level; since 2026-10-10 guests are limited to
levels 1-4 without live evaluation (`LocalGameType::GUEST_MAX_AI_LEVEL`, enforced in the
form, `NewLocalGameAction` and `GuestEngineMoveAction`).

Consequences: the engine image must serve the leveled route; a stale cached
`backend:latest` answers 404 (AGENTS.md).

## D09 - An invite is an invite-only `Seek` whose token is its UUID
2026-10-01 - Accepted (formerly T11)

Decision: invites reuse the seek/matchmaking machinery: `Seek::$inviteOnly`, link
`/invite/{seek uuid}`. No second hashed token: the v4 UUID is already unguessable and is
what every seek endpoint uses (unlike D04, nothing requires the plaintext to be
unrecoverable). Invite-only seeks are excluded from the public listing, from the
anonymous pairing pool and from the mutual dedupe, each independently (a guard in a single
layer yields either a dangling button or a wrong-actor pairing). Single use is the seek's
OPEN → MATCHED transition. Analytics: `invite_sent` / `invite_accepted` (D05).

Consequences: switching to a separate token later needs a column and a backfill.

## D10 - One game-row presenter, told from a subject
2026-10-01 - Accepted (formerly T13)

Decision: `GameListPresenter::present($game, $subject, $isSelf, $viewer)` serves every
games list (dashboard, `/games`, profile, lobby), including the profile page, whose rows
are framed from the profile owner's point of view whoever looks. Time remaining is shown
for correspondence and real-time games (read-only; `ClockManager` remains the only writer
of clock columns).

## D11 - The public lobby feed filters by query; `GameVoter::VIEW` is unchanged
2026-10-01 - **Open** (formerly T14)

Context: the v1.0 brief said finished games are publicly viewable and games in progress
visible only to their participants. `GameVoter::VIEW` grants any multiplayer game, in
any status, to anyone with the link, a contract the profile pages rely on
(`docs/multiplayer/00-overview.md` §4.3).

Decision: the anonymous `/lobby` feed only *selects* finished games
(`GameRepository::findRecentPubliclyFinishedGames()`); the voter is not tightened.
There is no hero panel on the signed-in lobby (account creation is meaningless there).
*Update (onboarding plan, Phase 2): the anonymous lobby and its feed are gone (the home page `/` has a hero instead, `findRecentPubliclyFinishedGames()` was deleted); the voter question stays open.*

Consequences: anyone holding the link can watch a live multiplayer game. If the brief's
sentence was meant as a product rule, the voter must change (it would also 404 the
"Watch" links on profiles). Needs an operator go/no-go: `QUESTIONS.md`.

## D12 - Conditional AGPL-3.0 grant in `LICENSE`
2026-09-28 (committed with the trust page; wording approved by the operator after the v1.0 run) - Accepted (formerly T4)

Decision: the platform stays proprietary, but `LICENSE` grants every person an
AGPL-3.0 licence, automatically and irrevocably, if (a) the shutdown of playkeres.com is
announced (effective no later than the shutdown date) or (b) the service is unreachable
for 90 consecutive days without an announced return. Code only: no trademark rights, no
user data. The engine is already GPL-3.0 (GPL-3.0 §13 allows combining with AGPL-3.0, and
AGPL closes the network-service gap a revived hosted fork would exploit). "No commits for
N months" was rejected as a trigger because a finished product would trip it. The
marketing site's `/trust` page cites this commitment.

Consequences: practically irreversible once published; the clause should have a lawyer's
review (`QUESTIONS.md`). Keep `LICENSE` and the trust page consistent.

## D13 - Legal and trust pages live on the marketing site
2026-09-28 - Accepted (formerly T3, T4)

Decision: the legal notice, terms of use, privacy policy and `/trust` are content of
[keres-website](https://github.com/VincentChalnot/keres-website); the platform only links
to them (`STATIC_SITE_URL`, footer and registration page). The former terms *of sale*
(a physical-edition store the platform does not have) were replaced by terms *of use*
(`terms_*` i18n keys renamed `cgu_*`; old URLs now 404). No cookie banner: the only cookie
is the strictly necessary session one, and no third-party tracker or ad network exists
(re-check this before asserting the "no tracking, no advertising" commitments again).

## D14 - Production logging: quiet by default, no extra audit volume
2026-10-02 - Accepted; part of the plan still open (see `TODO.md`)

Decision: `docker compose logs` in production should only hold user-traffic access lines
and real warnings/errors; everything else is dropped, routed to Sentry or kept as
breadcrumbs. No rotating file or additional volume for an info-level audit trail (the
analytics events in the database and Sentry breadcrumbs cover it). Docker logs are
size-capped (`x-logging` in `deploy/compose.yaml`). The messenger workers keep
`--time-limit=60` (a documented reliability choice, `docs/multiplayer/03-time-control.md`
§10.1) and are silenced with `--quiet` instead. Caddy keeps client IPs in the access log
until the GDPR position on IP retention is settled.

## D15 - The UI is styled by the Keres design system, not Bulma
2026-10-11 - Accepted

Context: Bulma 1.0 plus a 2,500-line `assets/app.scss`, with the palette duplicated from
the marketing site.

Decision: `@vchalnot/keres-design-system` (own repo, Tailwind 3 preset + CSS entry) consumed as a git
dependency pinned to a tag (the lockfile pins the commit) and compiled by the platform's
own Tailwind through Vite/PostCSS, so utilities come from the platform's templates. A git
dependency (rather than a sibling checkout, a tarball URL or a prebuilt `dist`) works
unchanged in the dev container, the production `node_builder` stage and CI; it only needs
`git` in the Alpine images. Tailwind scans templates, TS, PHP and the DS CSS, so DS
component variants are generated even for dynamically built class names. Font Awesome
stays for icons; `assets/board.css` is untouched; the Symfony form theme is
`form/ds_layout.html.twig`. `bulma` and `sass` are removed. Stylesheet went from ≈ 930 kB
to ≈ 220 kB (44 kB gzipped).

Consequences: hard to reverse (every template changed vocabulary). Gotchas are in
[`docs/design-system.md`](docs/design-system.md) ("Things that bite").

### Front-end — design system now consumed from npm
Decision: `@vchalnot/keres-design-system` is installed from the npm registry (`^0.4.1`, published by the DS repo's CI through Trusted Publishing) instead of a git dependency; `git` was removed from the `node` service and the `node_builder` stage. Supersedes the "git dependency" part of the entry above.
Rationale: no git in the images, normal semver ranges and integrity-checked lockfile.
Reversibility: easy (point the dependency back at `github:VincentChalnot/keres-design-system#vX.Y.Z`).

### Multiplayer rematch rides on the seek machinery
Decision: a multiplayer rematch is a *rematch seek* (`Seek::$rematchOfGame`, invite-only, addressed to the one opponent) paired with the opponent's own rematch seek by `SeekMatcher`, which creates the game with `Game::$parentGame` (unique). The first click redirects to the lobby; the opponent is notified (`rematch_offered`) and accepts from the finished game. Rejected: creating the game on the first click and showing a "waiting for player" overlay on it (a game, clocks and ratings would exist before the opponent agreed).
Rationale: reuses pairing, expiry, cancel and lobby plumbing instead of a second offer/accept state machine; the unique parent makes "who creates it" a database fact. Supersedes the unused `Game::$rematchOfferedByColor` of docs/multiplayer (still in the schema, still unused).
Reversibility: easy (drop the two columns and `RematchService`).

### Rated games against bots are multiplayer games against bot accounts
Decision: a rated game against the engine is a `MULTIPLAYER` game between the human and `bot-level-N` (a real `User`), with `Game::$aiLevel` marking it; the bot's replies reuse `ProcessAiMoveMessage`. Bot ratings move in the same Glicko pool as everybody's (like Lichess) and bots never appear in leaderboards. Casual games against the AI keep `OpponentType::AI` (no account needed, no row for the engine side). Rejected: rating the human against a fixed per-level number (needs a second rating model and would let the human's rating drift from the pool), and rated `OpponentType::AI` games (`Game::isRatedOutcome()` clause 3a excludes the engine on purpose).
Rationale: clock, rating, history, notifications and the end-of-game paths already exist for multiplayer; the only new code is "who answers for the bot". Reversibility: easy for the UI (hide the checkbox); the games already played stay valid multiplayer games.

### Correspondence seeks live seven days and are not tied to the author's page
Decision: `MultiplayerLimits::CHALLENGE_TTL_SECONDS` is 7 days, correspondence seeks are exempt from the 25 s heartbeat in the listing (as `candidateWhereClause()` already did for pairing) and the lobby does not cancel them on `pagehide`. Invitations and rematches keep 24 h. Rationale: the point of correspondence is not being there; the old behaviour hid the seek as soon as its author left.

### Terms of use are accepted per version, by every account
Decision: `User::$termsAcceptedVersion/At` against `TermsOfUse::CURRENT_VERSION` (the "Last updated" date of the site's page); registration has a checkbox, any other signed-in account without the current version is sent to `/terms/accept` (page navigations only), `/dev/login` accepts automatically. Rationale: OIDC sign-ups never see a form, and a new version must be accepted again by everybody.

---

## D16 - Onboarding, cost guards and the coach (ten decisions)
2026-10-16 - Accepted

Context: new visitors landed on a dashboard with no explanation, the AI backend (GCP Cloud Run, `AI_BACKEND_API_URL`) is billed per call and unguarded, and players piled up unfinished bot games. The operator settled these ten points before the work started (code comments cite them as "D16 point N").

1. **Marketing site.** Its "Play for free" lands on the app home `/`. The app links back to the marketing site only from the footer (legal pages, "About"). Otherwise the site is left alone: no shared nav, no cookie trick.
2. **No sign-in wall.** Anonymous visitors play as guests; the sign-up prompt comes after their first game (the guest game-over modal). Guests play **bot level 1 only** (`LocalGameType::GUEST_MAX_AI_LEVEL`).
3. **AI calls are rate-limited**, per IP for guests (`guest_ai`) and per user when signed in (`user_ai`); hints draw from the same bucket, one token per call.
4. **Tutorial: hybrid.** One question, "Do you play chess?" (`ChessExperience`: player / beginner / skipped, cookie `keres_chess_xp` for visitors), then a short chess-player track or interactive beginner lessons (`public/tutorial/lessons.json`), then a real game against bot level 1. Everything is skippable; `/tutorial` stays as a replayable reference. (Delivered: 12 beginner lessons instead of the 4-6 first planned.)
5. **One bot game at a time**, casual and rated against bots together; hot-seat and multiplayer are not concerned. Leaving the page leaves the game running; the player resumes or resigns it from the home page, or "resigns and starts a new one" in one click. Leaving a *timed* game page asks for confirmation.
6. **Existing backlogs** are cleaned once by `app:bot-games:abort-extra` (aborts all but the most recently played bot game of each user: no result, no rating, no ladder effect).
7. **Piece diagrams** are static SVGs, engine-derived and committed (`public/help/`), not drawn at runtime.
8. **Coach mode.** Hint, capture warnings and a take-back offer in casual bot games (opt-in for everyone, on by default for beginners). Hints are unlimited but rate-limited (point 3). A hint or a take-back marks the game **assisted** (`Game::$assisted`, formerly `undoUsed`): such a win no longer unlocks the next ladder bot.
9. **Level-1 bot games put the human on White** when the colour preference is "random" (the one-click bot card); an explicit Black choice is respected.
10. **Guest games keep feeding the shared tree** through `/api/guest-moves` (`BoardTreeManager::recordLastMove()`): no `Game` row and no import at sign-up. (To verify in prod: `Move` edges without `GameMove` keep appearing.)

Consequences: the home page, a navbar without the dashboard, an online-only `/lobby`, the help drawer and the coach are built on these; `docs/features.md` describes each. Changing a point means touching those places on purpose.

## D17 - A hint is one `/evaluate-moves` call, and every move it returns goes on the shared tree
2026-10-16 - Accepted

Context: the coach's hint needs the engine's best move; the engine's `/evaluate-moves` (`EngineApi::evaluateMoves()`) returns every legal move with its exact level-10 White-POV score, best first for the side to move, at the cost of one `/evaluate-game`.

Decision: the hint is the first entry of that list (`Service\Coach\HintProvider`; there is no separate hint level); the limiter token is consumed once per call whatever the number of moves returned. Every returned move is stored through `BoardTreeManager::recordEvaluatedMoves()` as a `Move` edge (the target position found by replaying history + move on the local backend, no AI cost) with `Move.evaluation` set, **including moves nobody played**, because those evaluations will be mined to find interesting positions for puzzles. `Move.evaluation` keeps its definition (the verdict of the position after the edge, in the line that led there), which an `/evaluate-moves` score is for the line "history + this move". Rules: **first write wins** (a non-NULL evaluation is never overwritten, as for `MoveEvaluator`'s cache; an evaluation computed for another transposition of the edge may stay); such edges have **no `GameMove`**, like guest edges; they are **never shipped to a client** (`evaluations[]`, the eval bar and the graph read through `GameMove`, `Game::canExposeEvaluation()` is unaffected: the hint returns a move, not scores); the depth differs by one ply from what `/evaluate-game` gives for the child position, so an edge evaluated both ways keeps whichever came first. Rejected: a new column or table for sibling scores.

Consequences: the engine version/depth is not recorded on these scores (the protocol advises tagging them): to do when puzzle mining needs it. The existing `EvaluateMoveHandler` (one `/evaluate-game` per played move) could be migrated to `evaluateMoves()` so the played move and its siblings come from one search; until then `MoveEvaluator` and `EvaluationScheduler` stay on `evaluateGame()`.
