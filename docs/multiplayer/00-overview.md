# Multiplayer — Overview & Decision Register

> **Status**: specification, not yet implemented.
> **Scope**: turning Keres from a single-player platform (human vs. engine, or
> hot-seat on one device) into a real multiplayer platform: matchmaking, time
> control, friends, invites, browser notifications, and ratings.
>
> This document is the **contract**. Every other file in `docs/multiplayer/`
> elaborates one slice of it and must not contradict it. If an implementation
> detail disagrees with this file, this file is wrong and must be amended
> first — not worked around.

## Table of contents

| File | Concern |
|---|---|
| `00-overview.md` | This file — scope, decisions, contract, invariants |
| `01-domain-model.md` | Entities, columns, indexes, migrations, backfill |
| `02-realtime.md` | Mercure topics, subscriber authorization, payload contracts |
| `03-time-control.md` | Clock model, flag adjudication, lag compensation, abort |
| `04-matchmaking.md` | Seeks, quick pair, pairing algorithm, lobby |
| `05-social.md` | Friendships, challenges, blocking |
| `06-rating.md` | Glicko-2, rating pools, when a game is rated |
| `07-notifications.md` | Web Push, service worker, preferences, in-tab fallback |
| `08-frontend.md` | TypeScript architecture, clock rendering, new views |
| `09-api-reference.md` | Route table, request/response schemas, error envelope |
| `10-delivery-plan.md` | Phases, migration order, risks, acceptance criteria |

---

## 1. The one rule that governs everything

**The platform stays gameplay-agnostic.**

The Rust engine owns all game rules. Multiplayer introduces no exception:

- The **83-byte board format and the 2-byte move format do not change.**
- **No clock, rating, player identity, or time-control data ever enters the
  engine wire protocol.** Clocks are platform state layered *around* the
  engine's move sequence, never inside it.
- `/replay-moves` and `/engine-move-game` keep their exact current contracts.
- The platform still never asks "is this move legal?" — it forwards the move
  sequence and stores whatever the engine returns.

Every design below is checked against this rule. Where a shortcut would have
leaked rules knowledge into PHP, the spec takes the longer path.

## 2. Decision register

These were decided explicitly. They are settled; revisit only by amending this
table.

| # | Decision | Choice | Rationale |
|---|---|---|---|
| D1 | Rating algorithm | **Glicko-2**, surfaced in the UI as a plain integer plus a `?` provisional marker | Correct handling of provisional players and inactivity; RD is an internal detail players never need to see |
| D2 | Rating pools | **Per speed category** — Bullet / Blitz / Rapid / Classical / Correspondence | A bullet specialist and a classical specialist must not share one number, or pairing quality collapses |
| D3 | Time-control modes | **Real-time (Fischer increment)**, **Correspondence (days per move)**, **Untimed casual**. No Bronstein/delay. | Covers competitive play, asynchronous play, and friendly play with three code paths instead of four |
| D4 | Flag adjudication | **Persisted server clock + one delayed Messenger message per move**, with a lazy check on read and an explicit claim endpoint as safety nets | Exact, live "flag falls" moment with no polling and no new infrastructure — the Doctrine transport already supports `available_at` |
| D5 | Browser notifications | **Full Web Push** (service worker + VAPID), with an in-tab `Notification` fallback | Required for correspondence and "your turn while away"; in-tab-only would not deliver the feature asked for |
| D6 | Matchmaking | **Quick-pair buttons over a shared seek pool**, plus a lobby listing custom seeks | Closest to current lichess; one mechanism (seeks) serves both UX affordances |
| D7 | Infrastructure | **Postgres only.** `SELECT … FOR UPDATE SKIP LOCKED` for pairing. Redis documented as an escape hatch with explicit trigger thresholds. | Zero new containers, matches current ops posture. See §7 for the exact conditions that would force Redis. |
| D8 | Takeback | **Never in rated play.** The existing unconditional Undo button is removed from every multiplayer game and survives only in AI and hot-seat games. No consent flow is built. | User decision. Also removes a whole class of clock/rating abuse |
| D9 | Deliverable | `docs/multiplayer/` split by concern | This document set |

### 2.1 Derived scope decisions

The following were not asked about explicitly; they are ruled in or out here by
direct consequence of D1–D9. Flagged so they can be vetoed cheaply.

| Feature | In scope? | Why |
|---|---|---|
| Draw offer / accept / decline | **In** | Not optional for timed competitive play; a drawn position with two stubborn players otherwise ends only on the 50-move counter or a flag |
| Abort (before either side has moved twice) | **In** | Required by D4: a game where nobody ever moves must vanish without a rating hit. Shares the delayed-message mechanism with the clock |
| Rematch offer | **In** | Trivial once challenges exist — a rematch is a pre-accepted challenge with colours swapped |
| Opponent presence / disconnect indicator | **In** | Required by D3: with a clock running, "my opponent's screen is closed" is information the other player is entitled to. Also drives abandonment adjudication |
| Public profile page (rating + game history) | **In** | D1/D2 produce five rating numbers per user with nowhere to live. There is currently no self-service profile page at all |
| Leaderboard (top N per pool) | **In**, last phase | One query and one template once D1/D2 land |
| Takeback | **Out** | D8 |
| In-game chat | **Out** | Not requested; adds a moderation, reporting and abuse surface disproportionate to the rest of this work. Revisit as its own spec |
| Tournaments / arenas | **Out** | Explicit non-goal for this iteration |
| Spectator-specific features (chat, move list sync, board flip memory) | **Out** | Games are publicly *viewable* (§4.3) but no spectator UX is built |
| Anti-cheat / engine-assistance detection | **Out** | Named as a known gap in `10-delivery-plan.md`, not solved here |

## 3. Where the platform is today

Established by direct source inspection, not assumption. These are the facts the
spec has to move.

### 3.1 There is no second player, anywhere

- `Game` has exactly one `User` relation: `owner` (`src/Entity/Game.php:54-56`),
  plus a boolean `isWhite` recording which colour that one owner plays.
- `GameVoter::voteOnAttribute()` is a single line:
  `$user instanceof User && $user === $subject->getOwner()`
  (`src/Security/Voter/GameVoter.php:36`). Its own docblock states:
  *"There is no second human player to grant access to."*
- `OpponentType` has two cases: `AI = 0`, `HOTSEAT = 1`
  (`src/Model/OpponentType.php`). `HOTSEAT` means *the same user plays both
  colours on one device* — it is **not** a placeholder for networked play and
  must not be reused as one.
- `GameRepository` scopes everything by `owner`.

### 3.2 Turn enforcement and live updates are gated behind AI mode

- `SubmitMoveAction.php:68` — the "is it your turn?" check is
  `(OpponentType::AI === $game->getOpponentType()) && …`. **Any non-AI game has
  zero server-side turn validation.**
- `SubmitMoveAction` **never calls `GameUpdatePublisher`.** A human move is
  returned only in that request's own synchronous JSON response. The only code
  that publishes to Mercure is the async AI-move handler
  (`ProcessAiMoveHandler.php:35,41`) and the `game:play-ai` CLI command.
  **Today, if a second human moved, the opponent's browser would learn nothing.**
- `assets/typescript/src/app.ts:104-109` gates `initializeMercure()` on
  `OPPONENT_TYPE_AI`, so the client would not be listening anyway.
- `GameController.handleMercureUpdate()` unconditionally calls
  `setBoardLocked(false)` — written for "the AI finished thinking". With two
  humans this would unlock the board for the player who does *not* have the move.

### 3.3 Two payload builders already drift

`SubmitMoveAction` (HTTP response) and `GameUpdatePublisher` (SSE) independently
construct near-identical payloads — same `board`/`moves`/`gameOver`/`whiteWins`/
`draw` fields, except the SSE one also carries `timestamp`. Adding clocks,
ratings and offers to both by hand guarantees they diverge. **Unifying them is a
prerequisite, not a cleanup task** (see §5, P0).

### 3.4 Mercure is entirely public

`new Update("game/{$uuid}", $json)` uses two of six constructor arguments, so
`$private` stays `false` (`src/Service/GameUpdatePublisher.php:36-39`). Caddy
runs the hub with `anonymous`, and `config/packages/mercure.yaml` declares no
`jwt.subscribe` claim. **Any client that knows a game UUID can subscribe with no
authentication.** That is tolerable while the payload is only "the board" — both
players can see it anyway — and it becomes unacceptable the moment user-scoped
events (challenges, friend requests, notifications) ride the same transport.

### 3.5 The optimistic-lock scheme is a trap for clocks

`GameEngine::applyMove()` has **two mutually exclusive** locking paths
(`src/Engine/GameEngine.php:41-82`):

1. When the move ends the game, `Game` becomes dirty, so Doctrine's native
   `#[ORM\Version]` UPDATE fires and provides the check.
2. When it does not, `Game` is clean and Doctrine issues no UPDATE at all — so
   the code hand-writes
   `UPDATE game SET version = version + 1 WHERE id = :id AND version = :version`
   and throws `OptimisticLockException` on zero affected rows.

A clock writes to the `game` row on **every** move. That makes path 2 dead code
overnight, silently. This must be resolved deliberately, not discovered in
production — see `03-time-control.md` §6.

### 3.6 Nothing else exists

Confirmed absent by repo-wide search across `src/`, `config/`, `migrations/`:
no rating, no matchmaking, no invite, no friend, no clock, no presence, no
push-subscription, no username, no service worker, no `Notification`/`Push` API
usage, no toast system, no scheduler, no Redis, no pagination convention outside
the vendor admin datagrid, and no self-service profile page.

The only per-move timestamp is `game_move.created_at`, and it is stamped at
**DB-write time**, i.e. *after* the untimed HTTP round trip to the Rust engine
has already completed. A clock cannot use it as-is (see `03-time-control.md` §3).

## 4. Core model decisions

### 4.1 `GamePlayer` replaces `owner` + `isWhite`

A `Game` gains a collection of exactly two `GamePlayer` rows, one per colour,
each optionally pointing at a `User` (`null` = the engine).

The rejected alternative was adding `opponent_id` beside `owner_id` and keeping
`isWhite`. That forces every query into `owner = :u OR opponent = :u`, makes
colour resolution indirect, and provides no home for the eight per-side fields
that clocks and ratings need — which would otherwise become
`white_clock_ms`/`black_clock_ms`/`white_rating_before`/… column pairs on `game`.

`GamePlayer` is the natural home for everything that is *per side*: remaining
clock, rating snapshot before and after, in-game presence, and per-player
archiving. It also reduces `GameVoter` to a membership query. Hot-seat is
expressible (two rows, same user, different colour) because the unique
constraint is on `(game_id, color)`, not `(game_id, user_id)`.

`OpponentType` gains `HUMAN = 2`. It stays even though it is derivable from
`GamePlayer`, because filtering game lists by mode should not require a join.

### 4.2 Colour is a first-class enum

`App\Model\PieceColor { WHITE = 0, BLACK = 1 }`. `Game::isWhiteTurn()` stays as
it is — `0 === $gameMoves->count() % 2` — because "white moves first" is already
baked into the engine's board format and is not new rules knowledge.

### 4.3 Games are publicly viewable; participation is not

`GameVoter` is rewritten with three attributes:

- `GAME_VIEW` — multiplayer games: **anyone**, including anonymous visitors.
  AI and hot-seat games: participants only.
- `GAME_PARTICIPATE` — the acting user holds a `GamePlayer` row on this game.
  Required for every mutating endpoint.
- `GAME_MANAGE` — archiving/hiding; the acting participant, for their own side.

Making multiplayer games publicly viewable is not a new exposure: the Mercure
topic `game/{uuid}` is already world-readable (§3.4). The spec makes that
honest rather than pretending otherwise, and keeps single-player games private.

### 4.4 Usernames are a prerequisite

Identity today is **email-only**: `User::getUserIdentifier()` returns the email,
`app_user_provider` uses `property: email`, and every authenticator resolves
through `UserRepository::findByEmail`.

Multiplayer needs a public handle. Friend requests, profile URLs, challenge
pages and leaderboards cannot key on email without leaking it — and
"is this email registered?" must not be an oracle.

`User.username` is therefore added in **Phase 0**, before any social feature:
unique, `^[a-zA-Z0-9_-]{3,32}$`, case-preserving with a unique index on
`LOWER(username)`, auto-derived from `displayName`/email local-part on first
login with a numeric suffix on collision, and changeable by the user at most
once — enforced by a nullable `username_changed_at` column written exactly once
on the change. The Security identifier stays the email — this is a display and
lookup handle, not an auth credential.

## 5. Phase-zero prerequisites

These are not features. They are the load-bearing changes that everything else
sits on, and they must land first, in this order:

- **P0.1** `User.username` + backfill (§4.4).
- **P0.2** `GamePlayer`; `Game.owner`/`Game.isWhite` removed and backfilled;
  `GameFactory` becomes the single place that constructs a game (§4.1).
- **P0.3** `GameVoter` rewritten to the three attributes in §4.3.
- **P0.4** `GameStatePayloadBuilder` — one builder, used by both the HTTP
  response and the Mercure publisher, killing the drift in §3.3.
- **P0.5** Human moves publish to Mercure; the AI gate is removed from
  `SubmitMoveAction`, `PlayAction` and `app.ts`; turn enforcement is
  generalised from "is it the owner's colour" to "is it the acting
  participant's colour".
- **P0.6** Private Mercure topics + subscriber-JWT cookie (§3.4,
  `02-realtime.md` §2).
- **P0.7** The `GameEngine::applyMove()` locking scheme is collapsed to a single
  path before clocks are added (§3.5, `03-time-control.md` §6).

`10-delivery-plan.md` expands this into the full phase list.

## 6. Cross-cutting conventions

New code follows what the codebase already does. Where the codebase does two
things, the spec picks one and says so.

| Concern | Convention |
|---|---|
| Controllers | One invokable action per file, `#[AsController]`, attribute routing. **Use `__invoke`** — the codebase is split between `__` and `__invoke`; `__invoke` is the majority and the PHP-native spelling |
| HTML actions | `extends AbstractController`, return `array` (resolved to Twig by `sidus/template-bundle`) or `RedirectResponse` |
| JSON actions | **Never** `AbstractController`. Bare `readonly class`, constructor-inject `Symfony\Bundle\SecurityBundle\Security`, check manually, return an explicit `JsonResponse` per branch — otherwise a denial renders an HTML error page into a JSON client |
| JSON envelope | The codebase has none and already ships two shapes (`{error}` and `{success,error}`). This spec defines **one** — see `09-api-reference.md` §2 — and new endpoints use only that |
| Entity IDs | `Uuid` for externally addressable entities (Game, Challenge, Seek, User), `BIGSERIAL` for internal rows (GamePlayer, GameMove, UserRating, Notification) |
| Soft delete | Nullable timestamp column; **no Doctrine SQLFilter is registered**, so every query must opt in to the `IS NULL` predicate explicitly. `GameRepository::findByUuid()` currently does not, and that bug is fixed in P0.2 |
| Validation | Inline per-field `'constraints' => [new NotBlank(), …]` in the `FormType`. No `#[Assert\*]` entity attributes, no `validation.yaml` |
| Pagination | None exists outside the vendor admin datagrid. `pagerfanta/pagerfanta` is **already a direct dependency** — use it, and use it consistently for game history, friends and leaderboards |
| Worker-mode safety | The app runs under persistent FrankenPHP worker mode. **No service may hold mutable state across requests** unless it is decorated and tagged `kernel.reset`, per the existing `ResettableDataGridRegistry` pattern. This forbids an in-memory matchmaking queue outright |
| Rate limiting | Only `contact_limiter` exists. Every new mutating public endpoint declares a limiter — see `09-api-reference.md` §5 |
| CSRF | Symfony forms get it automatically; **the JSON endpoints do not have it today**. New JSON mutating endpoints are same-origin and session-authenticated, so they declare `SameSite=Lax` reliance explicitly and are excluded from the CORS `^/api/` block by living under `/play/*`, `/lobby/*`, `/challenge/*` |

## 7. Redis escape hatch

D7 fixes Postgres as the store. That is correct at the current scale and wrong
at some larger one. The trigger conditions are named here so the decision can be
revisited on evidence rather than on vibes:

| Signal | Threshold | What moves to Redis |
|---|---|---|
| Seek-pool contention | Pairing transactions retry (40001/lock timeout) on >1% of attempts, or p99 pairing latency >250 ms | The seek pool becomes a Redis sorted set per `(speedCategory, rated)` |
| Seek heartbeat write load | Sustained >50 heartbeat `UPDATE`s/second | Heartbeats move to a Redis key with TTL; Postgres keeps only the durable seek row |
| Presence write load | `user.last_seen_at` updates exceed ~100/second | Presence becomes a Redis set with TTL, never touching Postgres |
| Delayed-message backlog | `messenger_messages` sustains >100k undelivered rows (correspondence at scale) | Correspondence deadlines move to a scheduled sweep over an indexed `move_deadline_at` instead of one row per move |

Until one of these fires, adding Redis is unjustified complexity: a new
container in dev and prod, a new failure mode, and a new thing to back up.

## 8. Invariants

Statements that must hold after every operation. `10-delivery-plan.md` turns
these into acceptance tests.

1. A `Game` has exactly **two** `GamePlayer` rows, one per `PieceColor`.
2. A `GamePlayer.user` is `NULL` **iff** that side is the engine.
3. A game is rated **only if** both sides are distinct human users, its time
   control is not `UNLIMITED`, it was created from a seek or challenge flagged
   rated, and both sides completed at least `RATED_MIN_PLIES` plies.
4. Ratings change **exactly once** per rated game, at the transition to
   `gameOverAt IS NOT NULL`, and `GamePlayer.ratingBefore`/`ratingAfter` record
   the transition immutably.
5. A finished game is never reopened. `gameOverAt`, `endReason`, `whiteWins`
   and `draw` are write-once.
6. Clock arithmetic is **server-authoritative**. The client renders an
   interpolation and never submits a time value that the server trusts.
7. Clock adjudication is **idempotent**: the delayed message, the lazy read
   check and the explicit claim endpoint all funnel into one method, and two
   concurrent invocations produce one result.
8. Every state-changing game event publishes exactly one `GameStatePayload` to
   `game/{uuid}` with a strictly increasing `seq`.
9. `seq` equals `Game.version`, so ordering is derived from the same value the
   optimistic lock already maintains.
10. No engine call ever carries, or is influenced by, clock or rating state.
11. Both `GamePlayer.clockMsRemaining` values are `NULL` **iff** the time control
    is `UNLIMITED`.
12. A `Seek` and a `Challenge` are each consumed at most once; the transition to
    `MATCHED`/`ACCEPTED` and the creation of the resulting `Game` occur in one
    transaction.

## 9. Glossary

| Term | Meaning here |
|---|---|
| **Seek** | A standing, anonymous offer to play under given conditions, visible in the lobby pool and matched automatically |
| **Challenge** | A directed invitation to a specific user, or an open shareable link |
| **Quick pair** | A preset button that creates a seek with an auto-widening rating window |
| **Flag / flag-fall** | A player's clock reaching zero |
| **Abort** | Ending a game before it counts — no result, no rating change |
| **Abandonment** | A disconnected player losing after the disconnect grace period |
| **Rating period** | Glicko-2's unit of time for RD inflation; here 7 days, applied lazily |
| **Provisional** | A rating whose deviation still exceeds `PROVISIONAL_RD_THRESHOLD` (110); rendered with a `?` |
| **Speed category** | Bullet / Blitz / Rapid / Classical / Correspondence — the rating pool selector |
| **Ply** | A single move by one side. Two plies make a full move |
