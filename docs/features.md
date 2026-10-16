# Platform features reference

What the platform does beyond the base game loop (`AGENTS.md`) and the multiplayer
specification (`docs/multiplayer/`, a design document written *before* the code:
where it disagrees with the source, the source wins - e.g. it still names the
correspondence column `days_per_move`, now `hours_per_move`). Each section says
where the code lives and which invariant must not be broken. The *why* behind
the choices is in [`DECISIONS.md`](../DECISIONS.md); open work is in
[`TODO.md`](../TODO.md).

## Outgoing email

- **Always asynchronous.** `Symfony\Component\Mailer\Messenger\SendEmailMessage` is routed to
  `async` (`config/packages/messenger.yaml`): `->send()` only persists a
  `messenger_messages` row, the `php-worker` service delivers it. In the `test`
  environment `async` and `evaluation` are `in-memory://`, so functional tests
  need no database for mail.
- **Failures are recorded in the worker**, not in the request: `MailerFailureListener`
  stores every `FailedMessageEvent` as an admin-visible `MailerError` row and the
  Sentry Messenger listener (prod) reports it. The `try/catch` + response-body
  redaction in the `*Mailer::send()` helpers therefore only fires for errors
  thrown at enqueue time (e.g. a malformed DSN).
- **One mailer service per feature area**: `UserMailer` (reset password, account
  exists - the two auth mails), `WaitlistMailer`, `AdminNotificationMailer`,
  `CorrespondenceMailer` (deadline warning), `NotificationMailer` (your turn,
  game finished, seek matched). Do not grow `UserMailer` into a catch-all.
- **Layout**: `templates/email/_layout.{html,txt}.twig` - table-based, every style
  inline (no `<style>`, no remote font, Georgia/serif stack), dark header with the
  gold wordmark, light content card. Mail templates extend it. Pass
  `unsubscribe_url` (→ `/settings/notifications`) for any non-critical mail; the
  footer line only renders when it is set (security mails never set it).
- **Sender**: `MAILER_FROM_ADDRESS`, default `noreply@<SERVER_NAME>`. No `Reply-To`
  anywhere; the footer links to the marketing site's contact page.
- **Locale**: mails are written in the *recipient's* locale (`EmailLocale::forUser()`,
  `TemplatedEmail::locale()`), never the request's (see AGENTS.md, Internationalisation).

### Notification emails

Email is a second, independently toggleable channel next to the in-app inbox,
driven by the same `NotificationType` enum (Settings → Notifications shows one
row per type with an in-app and an email toggle; only deltas from the default are
stored; every type is on by default). `NotificationCenter` calls
`NotificationMailer` after its own in-app write.

- `YOUR_TURN` email: at most **one per game per hour**, whichever player is the
  recipient (`Game::lastYourTurnEmailAt`, checked and written under a pessimistic
  row lock).
- `GAME_FINISHED` email is queued from `NotificationCenter::gameFinished()`, inside
  the finalising transaction: the Messenger Doctrine transport shares the DBAL
  connection, so a rollback removes the queued mail with it (transactional outbox).
  The same property is relied on for `RecordAnalyticsEventMessage` and
  `CheckClockExpiryMessage`.

## Correspondence games and the deadline sweep

Correspondence time control is stored in **hours** (`hours_per_move` on `game`
and `seek`; `moveDeadlineAt` is the indexed derived deadline). The Supervisor
program `deadline-sweep` (`frankenphp/supervisor/deadline-sweep.conf`) runs
`app:games:sweep-deadlines` every 60 s, two independent row-locked passes:

1. forfeit games whose clock expired, through `ClockAdjudicator::adjudicate()`
   (`GameEndReason::TIMEOUT`);
2. send the one-time deadline warning mail
   `MultiplayerLimits::CORRESPONDENCE_DEADLINE_WARNING_HOURS` (6 h) before expiry
   (`Game::deadlineWarningSentAt`, re-armed by comparing with `clockTurnStartedAt`).

Overlapping sweeps are safe: the loser of the row lock re-checks and finds nothing to
do. The command prints only when it forfeited or warned something (it runs every
minute; silence keeps `docker logs` readable).

## Open seeks: lifetime, listing, admin alert

- A **real-time or unlimited** seek lives `SEEK_TTL_SECONDS` (10 min) and is listed only while its author's page keeps
  heartbeating (`SEEK_STALE_AFTER_SECONDS`); the lobby cancels it on `pagehide`.
- A **correspondence** seek lives `CHALLENGE_TTL_SECONDS` (7 days) and is listed (and paired) whether or not its author
  is on the page: `SeekRepository::findOpenForListing()` and `candidateWhereClause()` both exempt it from the heartbeat
  filter and `LobbyController.cancelMySeekOnUnload()` does not cancel it. Invitations and rematches keep 24 h.
- Every public seek that stays in the pool (not one that paired at once) alerts the administrators
  (`AdminSeekAlert`, called from `SeekCreationService::create()`): one in-app `NotificationType::SEEK_POSTED` row per
  `ROLE_ADMIN` user except the author (no settings toggle, see `NotificationType::userConfigurable()`) and one e-mail to
  `ADMIN_NOTIFICATION_EMAIL` (`AdminNotificationMailer::sendSeekPosted()`, skipped when the author is an admin or the
  address is blank). Invitations and rematches never alert.

## Terms of use

The terms live on the marketing site (`/terms-of-use`); the platform stores which version of them an account accepted:
`User::$termsAcceptedVersion` / `$termsAcceptedAt`, compared with `TermsOfUse::CURRENT_VERSION` (the "Last updated" date of
the page - **change it whenever the site publishes new terms**).

- E-mail registration requires the checkbox (`TermsCheckboxType`) and records the version.
- Every other signed-in account that has not accepted the current version (OIDC sign-ups, accounts that predate the
  terms, a new version) is redirected to `/terms/accept?redirect=<page>` by `TermsAcceptanceListener` and returns to the
  page after accepting. The listener only touches page navigations (GET, `Accept: text/html`, not XHR) of requests that
  carry a session; the acceptance page, logout, the language switch and the sign-in routes are exempt.
- `/dev/login` accepts the terms on every login (scripted browser flows would stop on the page otherwise).

## Onboarding and the tutorial

`/tutorial` (`TutorialAction`) shows seven slides, then a guided problem on the game's board, then the ending (create an
account / play as a guest, or face the bot / lobby when signed in). It is authored by the operator: drop these files in
`public/tutorial/` (committed; until all are there the route answers 404 and no banner or link is shown):

- `board.svg`, `like-chess.svg`, `chess-pieces.svg`, `new-pieces.svg`, `chess-only.svg`, `stacking.svg`,
  `stack-moves.svg`: the slides, in that order. Export them from `/admin/board-editor` ("Export SVG"); captions are the
  `slides.*` keys of the `tutorial` translation domain.
- `puzzle.json`:
  ```json
  {"start": "<board code>", "flipped": false,
   "steps": [{"by": "player|opponent", "from": "E2", "to": "E6", "unstack": false, "board": "<board code after the move>",
              "text": "carry|unstack_fork|legal_blunder|capture_king"}]}
  ```
  `unstack` is optional (default `false`): `true` marks a move of the top piece of a stack only, as opposed to the whole stack.
  `prompt` is optional: when the player makes the step's move, the real game's dialog (`#unstack-modal`, shared with the
  game page through `utils/unstackModal.ts` and `_unstack_modal.html.twig`) opens first, and only the answer matching
  `unstack` plays the step (anything else answers "try again", Escape/backdrop just backs out). Values are the buttons the
  engine would offer: `choose` (full stack / top piece), `select` (+ "select that piece instead", for a move onto one of
  the player's own pieces), `select_top` (top piece / select), `select_stack` (stack here / select); a prompt must offer the
  expected answer. Without `prompt` the move plays at once, as when the engine forces the interpretation.
  Board codes are the base64 of the editor. A `player` step shows its sentence (no arrow: the player has to find the move) and
  accepts exactly that click pair or drag; anything else answers "try again". An `opponent` step plays itself 900 ms
  after its sentence. `tutorial.ts` validates the whole file on load (`parseBoardCode`, squares, `text`) and falls back
  to the ending with an error line when it is wrong.

"Has been through the tutorial" is the `keres_onboarded=1` cookie (set by `POST /onboarding/complete`, called at the end
of the problem and by the banner's close button; also stored on `User::$onboardedAt` when signed in).
`NewUserBrowserProgressListener` copies the cookie onto any account created from that browser, so the invitation banner
(`_onboarding_banner.html.twig`, on the lobby, the anonymous welcome page and the dashboard) disappears for good. The
banner is an invitation only: nobody is redirected.

## Analytics event log

`analytics_event` is an append-only table (`AnalyticsEvent`), written
asynchronously: `AnalyticsRecorder` (facade) dispatches
`RecordAnalyticsEventMessage` (`async` transport), `RecordAnalyticsEventHandler`
inserts. There is no dashboard; it is collection only. Types
(`App\Model\AnalyticsEventType`) and where they fire:

| Type | Fired by |
|---|---|
| `account_created` | `RegisterAction`, `OidcUserProvider`, `DevLoginAuthenticator` (not `DevUserSwitchListener`: its throwaway `?_as=` users would pollute the counts) |
| `game_started` / `first_game_started` | `NewLocalGameAction` (AI, hot-seat) and `SeekMatcher::tryPair()` (both players, after its manual commit); `GameFactory` and `CreateTestGameCommand` deliberately do not |
| `move_played` | `GameEngine::applyMove()`, the single funnel for human and AI moves |
| `game_finished` / `game_abandoned` | `GameLifecycleManager` (`game_abandoned` = `finaliseAbort()`, i.e. `ABORTED`; `GameEndReason::ABANDONMENT` has no producer yet) |
| `invite_sent` / `invite_accepted` | `CreateInviteAction`, `InviteAcceptAction` (only after a successful pairing) |

Rule: dispatch next to the sibling `RatingUpdater`/`NotificationCenter` calls, inside
the game transaction; only in `SeekMatcher`, which has an explicit post-commit
block, dispatch after the commit.

## Invite a friend

`POST /lobby/invites` (`CreateInviteAction`) writes an **invite-only `Seek`**
(`Seek::$inviteOnly`) through `SeekCreationService::insertOrReplaceSeek()` - never
`create()`, which would try to pair the inviter with the public pool - and returns
the link `/invite/{seek uuid}`. `GET /invite/{uuid}` (`InviteAcceptAction`,
`ROLE_USER` through `access_control`, so the login redirect brings the visitor back
to the link) rejects self-invites, blocked pairs, expired or already-used links
(`not_found`/`self_invite`/`blocked`/`expired`/`rate_limited` states, same page) and
otherwise pairs through `SeekMatcher::attemptPair()` with the mirrored colour.
Single use falls out of the Seek's OPEN → MATCHED transition. The link's token *is*
the Seek uuid. Invite-only seeks are excluded from the public listing, from the
anonymous pairing pool and from the dedupe/equality check (`matchesTuple`); keep all
three guards when touching matchmaking.

## Rematch

The game-over modal (`#gameover-modal` of `_play_board.html.twig`, opened by `app.ts` when a game ends in the page and
again by a click on the dimmed board) posts to `POST /play/{uuid}/rematch` (`RematchGameAction` → `RematchService`).
A rematch is a game with `Game::$parentGame` set (unique, `uniq_game_parent`: a game has at most one rematch),
the same parameters and the colours swapped; whoever asks first wins, the other click lands on the same game.

- **AI / hot-seat**: created at once, under a lock on the parent row.
- **Multiplayer**: needs both players. The first click inserts a rematch `Seek` (`Seek::$rematchOfGame`, invite-only
  by construction, TTL `REMATCH_TTL_SECONDS`), notifies the opponent (`NotificationType::REMATCH_OFFERED`, link = the
  finished game) and redirects to the lobby, which lists it to its owner only (`findOpenForListing($now, $viewer)`;
  `rematch: true` in the summary, not counted in `poolSize`, not cancelled when the lobby page closes). The opponent's
  click on the "Accept the rematch" button (state from `PlayAction::rematchState()`) pairs the two seeks through
  `SeekMatcher::attemptPair($mine, restrictTo: $theirs)`, which creates the game with the parent and re-checks, under a
  lock on the parent row, that none exists yet. Both players end up on the game: the acting one by redirect, the
  waiting one by the lobby's usual "seek matched" path.
- Guards to keep when touching matchmaking: a rematch seek is never offered by the lobby "accept" endpoint nor the
  invite link, never paired by its heartbeat (only with the opponent's own request), and exempt from the stale-heartbeat
  filter (the opponent may answer much later).
- Guest games have no server game: the modal's link restarts `/play/guest` with the same kind/level and the other colour.

## AI difficulty and guests

`Game::$aiLevel` (nullable, 1-10, form default 1 = weakest). `EngineApi::aiMove()`
always calls the leveled endpoint `/engine-move-game/{level}` (`GameEngine` passes
`$game->getAiLevel() ?? 1`); the engine image must be recent enough to serve it
(see "Stale engine image" in AGENTS.md). Guests (no account, no `Game` row) are
limited to levels 1-4 (`LocalGameType::GUEST_MAX_AI_LEVEL`, enforced in the form, in
`NewLocalGameAction` and in `GuestEngineMoveAction`), and get no live evaluation.

### Rated games against a bot

`/play/new` can also start a **rated** game (`rated` + `cadence`, a real-time lobby preset) against the bot of the chosen
level. It is a *multiplayer* game (`GameFactory::createBotGame()`): the human against the account `bot-level-N@playkeres.com`
(`BotAccounts`), with `Game::$aiLevel` set (`Game::isBotGame()`), a clock, a Glicko rating on both sides, no undo, no
evaluation bar and no rematch button. The bot's side is answered by the existing AI path: `SubmitMoveAction` and
`PlayAction` (participants only) dispatch `ProcessAiMoveMessage` for AI games *and* bot games, `GameEngine::aiMove()` reads
`getAiLevel()`. Casual AI games (no account, `OpponentType::AI`) are unchanged and coexist. The bots exist for levels 1-10;
a missing account answers "this bot is not available" (create them with `app:bot-tournament`).

The bots' ratings are the one shared Glicko pool: they move with every game, bots are left out of the leaderboards
(`BotAccounts::EMAIL_LIKE`) and there is no anti-farming (level 10 is deterministic, a winning line can be replayed -
revisit if it happens). `bin/console app:bot-tournament --reset` deletes (soft) every game played between bots and every bot
rating before replaying the round-robin of levels 1-10 (`--dry-run` prints the counters); run it once in production after a
database backup, when the bots start meeting humans. Portraits: `public/images/bots/level-N.webp` (square, 256 px, N = 1-10),
looked up when the accounts are (re)created; without the file the lobby shows a robot icon.

### The AI ladder and the lobby's next bot

`User::$highestAiLevelBeaten` is the highest level the player beat: any win against the AI (casual or against a bot account in
a rated game) counts, unless `Game::$undoUsed` (an undo in an AI game); draws, aborted games and resignations of the player
count for nothing, and nothing lowers it (`AiLadder`, called from `GameLifecycleManager` as the game is finalised). The
migration backfilled it from the finished AI games. Guests keep it in the `keres_ai_beaten` cookie (written by
`utils/cookies.ts` from `LocalGameAPI`, capped at the guest limit 4 when read, copied to the account at sign-up by
`NewUserBrowserProgressListener`). `NextBot` turns it into the bot the lobby, the anonymous welcome page and the tutorial's
ending propose: one click posts a ready-made game to `/play/new` (`_quick_game_form`, hidden inputs mirroring
`LocalGameType`). The hot-seat game is its own card at the bottom of the lobby (same mini-form); `/play/new` is the custom
game against the AI only.

## Game lists

Every list of games (dashboard, `/games`, a profile's history, the signed-in lobby,
the anonymous "recently finished" feed) renders one `GameListRow`, built by
`GameListPresenter::present($game, $subject, $isSelf, $viewer)` and displayed by the
`game_list_row()` Twig function / `_game_row.html.twig`. The row is told from the
**subject's** point of view (a profile is framed from the profile owner's side
whoever looks; a spectator sees "Next to play: White" instead of "Your turn"). It also
carries the time remaining (correspondence: `moveDeadlineAt`; real time: clock minus
elapsed since the anchor, read-only - only `ClockManager` writes clock columns).
Labels are translated when the row is built; never cache one.

The anonymous `/lobby` shows a "Recently finished games" feed
(`GameRepository::findRecentPubliclyFinishedGames()`): the *query* selects finished
games only. `GameVoter::VIEW` is unchanged and lets anyone with the link watch **any**
multiplayer game, in progress or not (see the open question in `DECISIONS.md`).

## Privacy requests, waitlist, contact

- **GDPR requests are intake only.** Settings → Privacy has two POST-only forms,
  `SettingsPrivacyDataExportAction` (`/settings/privacy/data-export`) and
  `SettingsPrivacyAccountDeletionAction` (`/settings/privacy/delete-account`),
  rendered by `SettingsPrivacyAction` with a shared `GdprRequestType`. Each creates a
  `Feedback` row (`FeedbackCategory::DATA_EXPORT_REQUEST` / `ACCOUNT_DELETION_REQUEST`)
  for an admin to process, and `AdminNotificationMailer` emails
  `ADMIN_NOTIFICATION_EMAIL` with a link to the review queue (blank = log and skip,
  the request is still stored). Nothing exports or deletes automatically, and `User`
  has no `deletedAt` or anonymisation. A new `FeedbackCategory` case must be added by
  hand to `FeedbackReviewType`, the datagrid badge colours and the datagrid filter.
- **Waitlist** (physical edition, form on the marketing site): `POST /api/waitlist`
  (`WaitlistSignupAction`, `waitlist_signup` rate limiter, honeypot field `website`)
  stores a `WaitlistSignup` (token hash, 7-day expiry) and sends the confirmation mail;
  `GET /waitlist/confirm` (`WaitlistConfirmAction`) creates the canonical
  `Feedback(WAITLIST)` row exactly once. Token scheme as the password reset:
  `bin2hex(random_bytes(32))` in the URL, only its `sha256` stored.
- **Contact form**: `POST /api/contact` (`ContactAction`, `contact_limiter`) stores a
  `Feedback` row (nullable user).

## Legal and trust pages

They live on the marketing site ([keres-website](https://github.com/VincentChalnot/keres-website)),
not here: legal notice, terms of use, privacy policy, `/trust` (commitments). The
platform only links to them from the footer (`templates/base.html.twig`) and from the
registration page (`register.trust_notice`), through `STATIC_SITE_URL`. The platform's
`LICENSE` holds the conditional AGPL-3.0 grant the trust page cites; keep the two
consistent.
