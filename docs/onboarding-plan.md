# Onboarding & UI organisation plan

Working plan agreed with the operator on 2026-10-16. Execute phase by phase, one or more atomic conventional
commits per phase. Delete this file when the last phase is merged (fold the lasting decisions into `DECISIONS.md`).

Read `AGENTS.md` first (design system, i18n en+fr, board.css rules, binary wire format, no game rules in PHP).
Every user-facing string goes to both `en` and `fr`. Every phase ends with: `composer cs:check`,
`npm run type-check`, `app:translations:check`, `vendor/bin/phpunit`, and a Playwright smoke run of the changed
surface (skill `keres-multiplayer-testing`; screenshots in `/tmp`, never in the repo).

## Decisions (do not reopen)

| # | Decision |
|---|---|
| D1 | The marketing site's "Play for free" lands on the app home `/`. The app never links back to the marketing site except in the footer (legal pages, "About"). The marketing site is otherwise left alone (no shared nav, no cookie trick). |
| D2 | No sign-in wall before the first game: anonymous visitors play as guests, the sign-up prompt comes after their first game. Guests play **bot level 1 only**. |
| D3 | AI costs money (GCP Cloud Run behind `AI_BACKEND_API_URL`): every AI-backend call is rate-limited, per IP for guests, per user when signed in. Hints share that bucket. |
| D4 | Tutorial = hybrid (option C): one question "Do you play chess?", a short chess-player track or 4-6 interactive beginner lessons, then a real game vs bot level 1. Everything skippable; `/tutorial` stays as a replayable reference. |
| D5 | One bot game at a time (casual AI and rated bot games together; hot-seat and multiplayer not concerned). Leaving the page leaves the game running. The player can resume it or resign it from the home page, or "resign and start a new one" in one click. Leaving a *timed* game page asks for confirmation. |
| D6 | Existing backlogs: a one-off console command **aborts** every running bot game except the most recently played one per user (no result, no rating, no ladder effect). |
| D7 | Piece movement diagrams are static SVGs authored once with the admin board editor (exported with `exportStaticSvg()`), committed to the repo. |
| D8 | Coach mode (beginners, opt-in for everyone in casual bot games): hint, threat warnings, blunder take-back. Hints unlimited but rate-limited (D3). |
| D9 | Level-1 bot games put the human on White when the colour preference is "random" (the one-click bot card). An explicit Black choice is respected. |
| D10 | Guest games keep feeding the shared tree through `/api/guest-moves` (already implemented, `BoardTreeManager::recordLastMove()`); no `Game` row, no import at sign-up. Only verify it in prod. |

## Known facts from the audit (2026-10-16)

- `/` redirects to `/dashboard` (signed in) or `/lobby` (anonymous, renders `play_welcome.html.twig`). Login lands on
  `/dashboard` (`security.yaml` `default_target_path`). `/play` redirects to `/lobby`.
- The navbar logo and "Rules" link point to `static_site_url`. No "Home"/"Lobby" entry; the orange "Play" goes to `/lobby`.
- The tutorial banner works; it disappears for whoever has the `keres_onboarded` cookie (checked before the account in
  `OnboardingExtension::isPending()`). Resetting `User::onboardedAt` alone does not bring it back.
- Signed-in bot games get a random colour (`GameFactory::createAiOrHotseatGame` / `createBotGame`), so a first game can
  start as Black with the AI's move already played. Guest games are always White.
- The bot card PLAY always creates a new game. Bot games are `Game`s with a non-null `aiLevel`: `OpponentType::AI`
  (casual, unlimited) or `OpponentType::MULTIPLAYER` against a bot `User` (rated, timed, `GameFactory::createBotGame`).
- Piece info is text only; a ballista blocked by its soldier shows no move at all when clicked.
- New user dashboard says "Welcome back, <raw email>".
- AI-backend calls: `EngineApi::engineMoveGame()` (AI replies) and `EngineApi::evaluateGame()` (evaluation jobs), both
  with a fallback to the local backend. `/api/moves`, `/api/replay-moves`, `/api/game-over-reason` hit the local backend.
- Rate limiters today: `guest_engine_move` (IP, token bucket 20 / 30 per min), `guest_move_record` (IP). Nothing per user
  on AI moves.

## Phase 1 — Cost guards and bot-game rules (backend first)

1. **Guests: level 1 only.** `LocalGameType::GUEST_MAX_AI_LEVEL = 1`. Everything already keys off it
   (`NewLocalGameAction`, `GuestEngineMoveAction` 403, `GuestAiProgress`, `NextBot`, the disabled form choices). Check
   the copy around locked levels ("Create an account to face level 2").
2. **Rate limits** (`config/packages/rate_limiter.yaml`, named like the existing ones, comment each):
   - `guest_ai` (IP): replaces `guest_engine_move`; consumed by `GuestEngineMoveAction` and the guest hint relay
     (Phase 6). Start with token bucket limit 15, 10 per minute (casual unlimited games only).
   - `user_ai` (user id): consumed wherever a signed-in user triggers an AI-backend call: `SubmitMoveAction` **before**
     applying a move in a bot game (refusal → 429, code `ai_rate_limited`, move not applied), `PlayAction` before it
     dispatches the AI's first move (refused → do not dispatch; the page shows the error, a reload retries), and the
     hint endpoint. Start with token bucket limit 40, 30 per minute (rated bullet vs a bot must stay playable).
   - Client: `api_error.ai_rate_limited` in `frontend` (en+fr), shown like other move errors (the server uses the same
     key for the JSON `error` of `SubmitMoveAction`, the guest client for a 429 of the relay).
   - Note for the operator (not in scope): every played move of a signed-in game also queues a level-10
     `EvaluateMoveMessage` on the AI backend; that is likely the larger bill.
3. **One bot game at a time (D5).**
   - `GameRepository::findInProgressBotGameForUser(User): ?Game` (in progress, `aiLevel IS NOT NULL`, user is a player).
   - `NewLocalGameAction` (AI) and the rated-bot path refuse to create a second one: re-render with the conflict
     (see Phase 2 modal) instead of creating it. Server-side guard is the source of truth; the UI only mirrors it.
   - "Resign and start a new one": the new-game form carries `replaceCurrent=1`; the action resigns the current bot
     game through `GameLifecycleManager::resign()` (or `finaliseAbort()` when it is still abortable, `Game::isAbortable()`)
     then creates the new one, in one request.
   - Guests: their game lives in localStorage, already one at a time; the home page offers "Resume" when one is stored.
4. **Backlog cleanup command (D6).** `app:bot-games:abort-extra [--dry-run]`: for each user with more than one running
   bot game, keep the one with the most recent move (fallback: creation date) and finalise the others with
   `GameLifecycleManager::finaliseAbort()`. Prints counts. Functional test on `DatabaseTestCase`.
5. **White at level 1 (D9).** In `GameFactory` (both creation paths), level 1 + `ColorPreference::RANDOM` → human White.
6. **Tests**: guest level cap (form + relay), `user_ai` refusal leaves the game untouched, second bot game refused,
   replace-current resigns/aborts then creates, cleanup command keeps the right game, level-1 colour.

## Phase 2 — Information architecture: home, navbar, lobby

1. **Home `/`** (`IndexAction` becomes the page; route name `index` kept). Delete `DashboardAction`,
   `actions/dashboard.html.twig`, and the anonymous branch of `LobbyAction` (`play_welcome.html.twig`).
   `/dashboard` → 301 to `/` (bookmarks). `security.yaml` `default_target_path: index`. `/play` → `/`.
   - **Anonymous**: one hero: title, one-line pitch, primary "Play now, no account needed", small "Sign in" row
     (Google, Discord, email), "Learn the game in 2 minutes" link. Nothing else above the fold; no announcements, no
     public games list, hot-seat as a discreet "Play with a friend on this device" link. A guest with a stored game sees
     "Resume your game" instead of "Play now".
   - **Signed in**, top to bottom:
     1. *Continue*: games where it is your move (multiplayer), then the running bot game as one card "Resume vs Bot N"
        + "Resign" (confirm modal). Hidden when empty.
     2. *Play*: three large choices: "Play the bot" (next level, portrait, = current bot card; becomes the resume card
        when a bot game runs), "Play online" (→ `/lobby`), "Play a friend" (invite flow). "More ways to play" link:
        hot-seat, custom game (`/play/new`).
     3. *Your games*: in-progress multiplayer games, compact, "View all" → `game_list`.
     4. Sidebar: compact stats (hidden until the first finished game) and "What's new" (latest 3 announcements).
     5. First visit (no `chessExperience`, see Phase 3): the onboarding card replaces the stats.
   - Greeting: "Welcome" until the first finished game, then "Welcome back"; name = display name, else username,
     never the email.
2. **Conflict modal** (bot card, `/play/new`, tutorial end): "You already have a game against Bot N" → Resume /
   Resign and start the new one / Cancel. Same modal everywhere, fed by the server's conflict answer.
3. **Navbar** (`base.html.twig`): logo → `path('index')`. Links: "Play online" (`/lobby`), "Learn" (`/tutorial`),
   "Friends" (signed in). Primary button "Play" → `/`. Bell, then an avatar menu (Profile, Settings, Log out); language
   switcher stays. Remove "Dashboard" and the external "Rules" (rules live in-app, Phase 4; the marketing rules page is
   linked from the footer). Check 390 px French.
4. **Lobby = online play only** (`/lobby`, `ROLE_USER`; anonymous → login with target path). Remove the bot card,
   "Your games" and the hot-seat card. Drop the `<h1>Lobby</h1>` + "Post a seek" subtitle for a plain heading
   "Play online". Wording: "Open seeks" → "Players looking for a game", "New game" → "Create a game", explain the
   empty state. Add a quick-pairing row of the existing `PRESETS` as large buttons (one click posts the seek). Fix the
   mobile table (Colour column and PLAY clipped).
5. **Leaving a timed game** (D5): on a game page where the viewer is a player of a timed game in progress, in-app link
   clicks open a confirm modal ("Your clock keeps running; you may lose on time"); `beforeunload` covers tab close
   and reload (native prompt). Untimed games: no prompt.
6. **Marketing link**: check where keres-website's "Play for free" points; if not `/`, that one-line change goes to the
   other repo (report it, don't touch that repo from here).
7. Remove the `_onboarding_banner` include where its page disappeared; the banner itself is replaced in Phase 3.

## Phase 3 — "Do you play chess?" and the onboarding state

1. `User::$chessExperience` (nullable enum `ChessExperience`: `player`, `beginner`, `skipped`) + migration. Guests:
   cookie `keres_chess_xp`, copied to the account at sign-up in `NewUserBrowserProgressListener` (same pattern as
   `keres_onboarded` and `keres_ai_beaten`).
2. `POST /onboarding/experience` stores the answer (account and/or cookie). The existing `/onboarding/complete` +
   `keres_onboarded` keep meaning "tutorial finished or dismissed".
3. The question is asked once, as a modal, when the visitor first clicks "Play now" / "Play the bot" or opens
   `/tutorial` without an answer: "Yes, I play chess" / "No, I'm new to this" / "Skip, just let me play".
   - player → chess track of the tutorial (Phase 5), then the game.
   - beginner → beginner lessons, then the game with Coach on.
   - skipped → straight to the game, Coach off, piece cards still shown.
   Each screen has "Skip to the game". Settings → Board & gameplay lets the user change the answer.
4. Replace the banner with the home page's onboarding card (signed in) / the hero's "Learn" link (anonymous).
   `OnboardingExtension::isPending()` is kept for that card; fix the precedence so the account wins over the cookie
   for a signed-in user (the cookie only matters for anonymous visitors and at sign-up).

## Phase 4 — Piece diagrams and in-game help

1. **Author the diagrams (D7)** with `/admin/board-editor` (dev, `app:user:promote` an account):
   - One per piece type: soldier, bishop, rook, knight, paladin, guard, ballista, king, in two orientations
     (White piece seen from White, Black piece seen from Black) because soldier and ballista move "forward".
   - Get the exact squares from the engine, never from memory: build the position (the piece on the centre square,
     both kings parked far away if the engine needs them; drop them from the final picture if it does not), call
     `/api/moves` on its board code (the editor's `GameAPI`), paint those squares "Potential", and draw one arrow per
     direction to the farthest reachable square (knight: the editor's elbow arrows). Export with "Export SVG".
   - Extra diagrams: a blocked ballista (why it shows no move), a stack and its combined moves, an unstack.
   - Files: `public/help/pieces/<type>-<white|black>.svg`, `public/help/<topic>.svg`. Size-check them (the export
     prunes unused sprites; keep each small).
2. **Piece info panel**: add the diagram of the selected/hovered piece (in the player's orientation) next to the
   existing text; a stack shows the top piece's and the bottom piece's. A selected piece with no legal move says
   "Blocked right now" above its diagram. Click enlarges in a modal.
3. **Help drawer**: a "?" button in the Moves panel header (next to flip / fullscreen / gear), opens a side drawer on
   desktop, a full-screen sheet on mobile, without leaving the game:
   - "Pieces": the 8 pieces as a grid; one opens its diagram + description.
   - "Keres vs chess": pieces move and capture the same way; stacking/unstacking; promotions; no castling, no en
     passant, no stalemate. Take the exact wording from the engine repo's `docs/PROTOCOL.md` and
     https://playkeres.com/rules, not from memory.
   - "Full tutorial" → `/tutorial`.
   The same content is a section of `/tutorial` (the navbar "Learn"), so there is one source (a Twig partial + the
   `frontend_help` domain for anything rendered by TS).

## Phase 5 — Tutorial (hybrid, D4)

1. **Chess-player track** (~60 s, one or two screens): "Everything you know, except…": the three new pieces
   (ballista, paladin, guard) with diagrams, soldier vs pawn, stacking/unstacking, the list of chess rules that do not
   exist. Ends with "Play bot level 1" (White, D9).
2. **Beginner track**: interactive lessons on a live `SVGBoardView`, each skippable, progress dots, "Skip to the game":
   1. The board and the goal (static: capture… checkmate the king; wording from the rules).
   2. Soldier, then knight/bishop/rook, then paladin, guard, ballista, king: "reach the stars" — the piece alone (plus
      parked kings if needed), star squares to visit; after each move the client resets the side-to-move to the
      player so they keep moving (board flags edited through `boardUtils.ts` codecs only). Legal moves come from
      `/api/moves`, so no rule is reimplemented.
   3. Capture: take the marked enemy pieces.
   4. Stack and unstack (scripted steps, like the current `puzzle.json`, the unstack modal included).
   5. A mate-in-one (scripted: the expected move is authored).
3. **Lesson format**: generalise `puzzle.json` into `public/tutorial/lessons.json`:
   `{id, track, board, flipped, goal: {type: reach|capture|script, stars?: [], steps?: []}, text}` (texts are keys of
   the `tutorial` domain). Author positions with the board editor. Validate the file in a unit test (every board code
   parses with `parseBoardCode`, every text key exists in en and fr). `TutorialContent::isAvailable()` checks the new
   file list; remove the slides that no longer serve.
4. `/tutorial` page structure: the experience question (if unknown) → the track → the end ("Play bot level 1",
   "Create an account" for guests). Finishing or skipping calls `/onboarding/complete`. The "Pieces & rules" reference
   (Phase 4) sits below the tracks, always visible.

## Phase 6 — Coach mode (D8)

Scope: casual bot games only (`OpponentType::AI`, signed in) and guest games. Never rated, never multiplayer,
never a spectator. All game knowledge stays in TypeScript; the engine is the only rule source.

1. **Switch**: a board preference `coach` (bool) through the documented preference pipeline (`UserPreferences` +
   migration, `BoardSettingsType`, `SettingsBoardDefaultsAction`, `PlayAction`, `_play_board.html.twig`,
   `BoardDefaults` in `app.ts`, localStorage for guests). Default: on for `beginner`, off otherwise. Gear menu toggle.
2. **Coach card** in the side panel (above Piece info; collapsible on mobile), one message at a time, with actions.
3. **Threat warning** (start of the player's turn, local backend only): opponent moves via the existing
   `GameAPI.getOpponentThreats()`; for each capture of one of the player's pieces, check whether the player could
   recapture on that square (`/api/moves` on the board after that capture). Warn about the most valuable piece that is
   attacked and undefended, or attacked by a cheaper piece (values from `materialDiff.ts`): highlight the square
   (`threat` overlay) and say "Your <piece> on <square> can be captured".
4. **Blunder take-back**: after the player's move, run the same test from the opponent's side on the new position. If
   it wins material, the card says "That move leaves your <piece> unprotected" with "Take it back" (the existing undo,
   which also removes the AI's reply) and "Keep it".
5. **Hint**: button "Hint" → the engine's move drawn as a green arrow (`view.setArrows`, cleared on the next render as
   usual). Endpoints: `POST /api/games/{uuid}/hint` (participant, casual AI game in progress, player's turn; 403
   otherwise) and `POST /api/hint` for guests (move list in the body). Both consume `user_ai` / `guest_ai`.
   - **Engine call (operator note, 2026-10-16)**: the hint calls `EngineApi::evaluateMoves()` (`POST /evaluate-moves`,
     added in Phase 1: one level-10 search, every legal move with its exact White-POV score, best first for the side to
     move) and picks from that best-first list; the hint is the first entry. The `HINT_LEVEL` constant is dropped: the
     route is always level 10, deterministic, no cheaper than `/evaluate-game`. Consequence for the guest: the scores
     never leave the server, only the move.
   - **Rate limit**: the `user_ai` / `guest_ai` bucket is consumed **once per such call** (one search, whatever the
     number of moves returned).
   - **Persistence in the shared tree**: every returned move is stored through `BoardTreeManager` as a `Move` edge
     (`from` = the position before, `to` = the position the move leads to, found by replaying history + move on the
     local backend, no AI cost) with `Move.evaluation` set, including moves nobody played, because those evaluations
     will be mined later to find interesting positions for puzzles.
   - **Semantics of `Move.evaluation` for never-played siblings (decision)**: AGENTS.md defines `Move.evaluation` as the
     verdict of the position *after* the edge, in the line that led there (the edge is not the board: repetition
     history and the no-capture counter matter), `Move` being otherwise immutable and `evaluation` the one column filled
     after the fact, which also serves as the cache. An `/evaluate-moves` score is exactly that quantity for the line
     "history + this move" (White POV, same units as `/evaluate-game`), so a never-played sibling edge stores it under
     the same definition: no new column, no separate table. Rules: (1) **first write wins**, a non-NULL evaluation is
     never overwritten (same as the cache rule of `MoveEvaluator`), so an evaluation computed for another transposition
     of the same edge may stay; accepted, since the mining wants a strong verdict, not a per-line one; (2) such edges
     have **no `GameMove`**, like the guest edges of `/api/guest-moves`; (3) they are **never shipped to a client**:
     `evaluations[]`, the eval bar and the graph read edges through `GameMove`, so siblings stay invisible, and
     `Game::canExposeEvaluation()` is unaffected (the hint returns the move, not scores); (4) the depth differs by one ply
     from what `/evaluate-game` gives for the child position (the root search of the parent), so an edge evaluated both
     ways keeps whichever came first; (5) the engine version/depth is not recorded, the protocol advises tagging scores
     with it: out of scope until puzzle mining needs it, flagged here so it is not forgotten.
   - **Later migration**: the existing `EvaluateMoveHandler` (one `/evaluate-game` per played move) should be migrated to
     `evaluateMoves()` so that the played move's evaluation and its siblings' come from the same single search. Not part
     of Phase 6; the other call sites (`MoveEvaluator`, `EvaluationScheduler`) stay on `evaluateGame()` until then.
6. **Assisted games**: rename `Game::$undoUsed` to `$assisted` (migration renames the column) and set it on undo and on
   hint. The ladder unlock rule becomes "win without help (no undo, no hint)"; update the bot card copy and the
   guest cookie logic (`utils/cookies.ts`) the same way. Clean cutover, no alias.
7. **Phase tips**: during the first moves, one short tip at a time (beginners: "advance your soldiers to open lines",
   "bring your knights and paladins out"; chess players, first game only: "same principles: centre, development,
   king safety — there is no castling"). Dismissable, never repeated once dismissed.
8. **Piece cards**: the first time a piece type is selected (per user/browser), the card shows its diagram and one
   sentence. Chess players get them for the new pieces only.

## Phase 7 — Guest to account, after the first game

1. Guest game-over modal: "Create an account to save your progress and face Bot level 2" + Google / Discord / email
   buttons (target path back to `/`), and "Play again". The in-game guest banner stays.
2. At sign-up the existing listener copies ladder progress, `keres_onboarded` and `keres_chess_xp`.
3. Verify in prod that `/api/guest-moves` rows appear (`Move` edges without `GameMove`) — D10.

## Phase 8 — Docs and announcement

- `AGENTS.md`: replace the Tutorial section, add Home/Coach/Help drawer, update the board preferences list and the
  Messenger/rate-limit notes. `docs/features.md`: onboarding, one bot game at a time, cleanup command, rate limits,
  lesson file schema. `DECISIONS.md`: D1-D10 as entries.
- One announcement (`AnnouncementProvider::ENTRIES` + en/fr) for the new home, tutorial and coach.
- Run `app:bot-games:abort-extra` in prod after deploy (dry run first); note it in the deploy checklist.
- Delete this file.
