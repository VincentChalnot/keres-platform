# AGENTS.md — Keres Platform (Symfony + TypeScript)

## Scope

This repository is the whole scope for this agent. It used to be the
`platform/` folder of a monorepo shared with the Rust engine and the Hugo
marketing site; those now live in separate repositories
([keres](https://github.com/VincentChalnot/keres),
[keres-website](https://github.com/VincentChalnot/keres-website)). Nothing
here should assume those repos are checked out alongside this one:

| Dependency          | How this repo reaches it                                                        |
|----------------------|-----------------------------------------------------------------------------------|
| Rust engine           | `BACKEND_API_URL`/`AI_BACKEND_API_URL` env vars — the published `ghcr.io/vincentchalnot/keres/backend` image in dev/prod, never a source checkout |
| Marketing site         | `STATIC_SITE_URL` env var — only used to build absolute links back to it, no other coupling |
| Game rules doc          | [`docs/PROTOCOL.md`](https://github.com/VincentChalnot/keres/blob/main/docs/PROTOCOL.md) in the engine repo, and https://playkeres.com/rules for the player-facing rules |

## Project Overview

This is a **gameplay-agnostic** web platform for abstract board games.
It does **not** implement game rules. All game logic lives in the Rust
engine, communicated via a binary HTTP API (`BACKEND_API_URL`).

Platform responsibilities:

- User authentication and game session management (Symfony/PHP)
- Relaying moves to/from the Rust engine and persisting the resulting board tree
- Rendering the board state as SVG (TypeScript)
- Building analytics data structures (`BoardPosition` tree) for future ML use

When in doubt about game rules or piece behaviour, consult the engine
repo's `docs/PROTOCOL.md` or https://playkeres.com/rules — but **never let
that knowledge leak into PHP business logic**. The platform must remain
playable with any abstract game whose engine respects the binary API
contract. Only the TypeScript renderer is allowed to hold game-specific
knowledge (piece names, SVG representations, movement descriptions).

## Stack

- **PHP 8.4** / **Symfony 7.4** — FrankenPHP as application server
- **Doctrine ORM 3** — PostgreSQL, migrations in `migrations/`
- **Symfony Messenger** — async jobs via `async` transport
- **Symfony Mercure** — server-sent events pushed to the frontend
- **Vite + TypeScript** — vanilla TS, no framework, no Stimulus/Symfony UX
- **Docker Compose** — the sole dev environment, nothing runs on the host

## Architecture

### PHP / Symfony (`src/`)

| Directory                              | Role                                                    |
|----------------------------------------|---------------------------------------------------------|
| `src/Action/`                          | Symfony controllers (HTTP actions)                      |
| `src/Entity/`                          | Doctrine ORM entities                                   |
| `src/Model/`                           | Value objects and binary DTOs (no Doctrine)             |
| `src/Engine/`                          | Rust API bridge — stable, low-churn code                |
| `src/Service/`                         | Business logic services                                 |
| `src/Message/` + `src/MessageHandler/` | Symfony Messenger async jobs                            |
| `src/Event/`                           | Domain events (autoconfigured via `#[AsEventListener]`) |
| `src/Form/`                            | Symfony forms                                           |
| `src/Security/`                        | Authentication / authorization                          |
| `src/Repository/`                      | Doctrine repositories                                   |
| `src/Command/`                         | Symfony console commands                                |

### TypeScript / Vite (`assets/`)

| File / Directory                    | Role                                                                            |
|-------------------------------------|---------------------------------------------------------------------------------|
| `assets/typescript/src/app.ts`      | Main entry point — wires all components                                         |
| `src/models/types.ts`               | Core domain types: `Board`, `Piece`, `Move`, `PotentialMove`, `TileState`       |
| `src/utils/boardUtils.ts`           | **All** binary encode/decode functions — single source of truth for wire format |
| `src/controllers/GameController.ts` | Central mediator: clicks, drag, API calls, Mercure events                       |
| `src/models/GameState.ts`           | In-memory reactive state                                                        |
| `src/network/GameAPI.ts`            | HTTP client (`/api`, `application/octet-stream`)                                |
| `src/network/MercureClient.ts`      | Mercure SSE subscription                                                        |
| `src/views/IBoardView.ts`           | Renderer interface                                                              |
| `src/views/SVGBoardView.ts`         | **Active renderer** — SVG, inline sprite sheet                                  |
| `src/views/ThreeJSBoardView.ts`     | Inactive renderer — do not modify unless explicitly asked                       |

## Domain Model

### Key Entities

```
BoardPosition   — unique board state (81-byte binary key, globally deduped across all games)
    ↑ fromBoardPosition / toBoardPosition
Move            — directed edge in the board tree (2-byte moveData)
    ↑ move
GameMove        — one move within a specific Game, pointing into the shared Move tree
    ↑ gameMoves
Game            — a game session (owner, opponent type, game-over state, optimistic lock)
User            — authenticated player
UserAuth        — OAuth provider binding (provider + providerId, unique pair)
```

`BoardPosition` + `Move` form a **shared, append-only tree across all games**
used for analytics and future neural network training. `Game` + `GameMove`
reference into that tree — never duplicate board or move data.

### Binary Wire Format (PHP ↔ Rust engine)

All engine communication uses raw binary over HTTP (`Content-Type: application/octet-stream`).
Do **not** introduce JSON serialization on this path.

**Board state — 83 bytes** (`BoardData` PHP / `Board` TS):

| Bytes | Content                                                                                |
|-------|----------------------------------------------------------------------------------------|
| 0–80  | 81 squares of the 9×9 board (one byte per cell, piece encoding owned by the engine)    |
| 81    | Flags (big-endian): `0x80` whiteToMove, `0x40` gameOver, `0x20` whiteWins, `0x10` draw |
| 82    | `movesWithoutCapture` counter (uint8, 50-move rule)                                    |

**Move — 2 bytes** (`MoveData`): opaque blob, no client-side parsing beyond storage.

**PHP serialization** lives in `src/Model/` — `BoardData`, `MoveData`, `MovesData`, `BoardMovesData`.  
**TypeScript codecs** live exclusively in `assets/typescript/src/utils/boardUtils.ts` — all
`encode*` / `decode*` functions must stay there and nowhere else.

> ⚠️ **Known technical debt**: `src/Model/` mixes DTO concerns with
> serialization/deserialization logic. Do not worsen it. If you touch this area,
> prefer moving serialization into dedicated classes.

## Engine API Bridge (`src/Engine/`)

Four endpoints, all `POST`, binary in/out, base URL injected via `$backendApiUrl`
(env var `BACKEND_API_URL`):

| Endpoint            | Request                       | Response               |
|---------------------|-------------------------------|------------------------|
| `/replay-moves`     | `MovesData` binary (2N bytes) | 83 bytes → `BoardData` |
| `/engine-move-game` | `MovesData` binary (2N bytes) | 2 bytes → `MoveData`   |
| `/evaluate-game`    | `MovesData` binary (2N bytes) | 4 bytes → int32 LE, level-10 score, White's point of view (`EngineApi::evaluateGame()`) |
| `/game-over-reason` | `MovesData` binary (2N bytes) | 1 byte → the engine's code for why the game is over, 0 = in progress (`EngineApi::gameOverReason()`) |

The game-over code is stored once, when the engine ends a game (`GameEngine::applyMove()`
→ `Game::finish()`), in `Game.engineEndCode`, and shipped as `engineEndCode` in the game-state
payload (page bootstrap, Mercure, API). It is **opaque to PHP**: only the TypeScript client
(`utils/gameOverText.ts`) knows what each value means, so the banner can say "Draw by the 40-move
rule" without game rules leaking into the platform. NULL for non-engine endings (resignation,
timeout...) and for engine endings that predate the column until
`bin/console app:games:backfill-engine-end-code` has run; the banner then shows no reason.
Guest (browser-only) games ask the engine through the `/api/game-over-reason` relay.

Evaluations are stored on `Move.evaluation` (the move edge, **not** `BoardPosition`:
a board is shared by many lines but the verdict depends on the line - repetition
history, no-capture counter) and double as the cache. `Move` is otherwise
immutable; `evaluation` is the one column filled in after the fact.

The engine is **never** called on a request path. Every played move dispatches an
`EvaluateMoveMessage` to the dedicated `evaluation` transport (consumed by the
`evaluation-consume` supervisor program of `php-worker`, separate from `async` so
level-10 searches cannot delay AI replies or clock checks); `EvaluateMoveHandler`
calls `MoveEvaluator` (only for an edge with a NULL evaluation), stores the result
and publishes it on the `game/{uuid}` Mercure topic as a named SSE event
`evaluation` (`{ply, evaluation}`) when `Game::canExposeEvaluation()` allows it.
`POST /api/games/{uuid}/evaluation` returns `202` at once with the stored values
(index = ply, `null` = queued) after queueing the missing plies via
`EvaluationScheduler`; it treats a request on a rated game in progress as a
cheating attempt (403 + Sentry `fatal`). The frontend (`app.ts`) renders from
what it has and updates when `evaluation` events arrive - it never polls.
`bin/console app:moves:evaluate` backfills history (synchronously, in the console).
`Game::canExposeEvaluation()` gates every place that ships evaluations to a client.

- `EngineApi` — makes raw HTTP calls
- `GameEngine` — consumes results, updates `Game` entity, handles game-over detection
- `BoardTreeManager` — deduplicates `BoardPosition` rows using the 81-byte position key

`src/Engine/` is in scope but is stable and low-churn. Be conservative here.

## Dev Environment

**Everything runs in Docker. Never run PHP or npm commands on the host.**

### Start the environment

```bash
docker compose up --build -d --remove-orphans --force-recreate
```

### PHP commands (inside the PHP container)

```bash
docker compose exec php bin/console <command>

# Common examples:
docker compose exec php bin/console doctrine:migrations:migrate
docker compose exec php bin/console doctrine:migrations:diff
docker compose exec php bin/console debug:router
docker compose exec php bin/console messenger:consume async
docker compose exec php bin/console cache:clear
```

### TypeScript / Node commands (inside the Node container)

```bash
docker compose exec node npm run dev         # Vite HMR dev server (https://vite.app.local.playkeres.com)
docker compose exec node npm run build       # Production build → public/build/
docker compose exec node npm run type-check  # TypeScript strict check (must pass, no emit)
```

### Code style — PHP (run locally, no container needed)

```bash
composer cs:check   # Dry-run — shows violations without modifying files
composer cs:fix     # Applies PHP CS Fixer fixes in place
```

Run `composer cs:check` before considering any PHP task complete.
Run `composer cs:fix` to auto-correct style issues.

## Testing via the Integrated Browser

Real auth is Google/Discord OIDC only — no credentials are available to an
agent. Use the **dev-only login bypass** instead:

```
GET /dev/login?email=<anything>@example.com
```

Navigate a browser tab straight to that URL (e.g.
`https://app.local.playkeres.com/dev/login?email=agent-test@example.com`). It
authenticates the session as that user, creating the `User` row on first
hit — no password, no OIDC round trip. Use different emails to test as
different users (e.g. two players in the same game).

- Implementation: `App\Security\DevLoginAuthenticator` (`src/Security/`),
  route in `config/routes/dev/dev_login.yaml`.
- Only reachable when `kernel.environment == dev`: the route is loaded
  exclusively in dev, and the authenticator independently refuses outside
  dev — it does not exist in prod/test.
- The `/login` page also renders a plain email-input form for this when
  `app.environment == 'dev'`, for manual use alongside the OIDC buttons.
- This bypasses `UserAuth`/OIDC provider linkage entirely — it only creates
  a bare `User` by email. Don't use it to test the OIDC callback flow
  itself; that still requires real Google/Discord credentials.

### If the browser tool runs in its own container

DNS for `*.local.playkeres.com` is public and points at **loopback**
(`127.0.0.1` / `::1`), which inside a container means *that container*, not
the host — so every navigation dies with `ERR_CONNECTION_CLOSED` even
though the same URL works from the host. Fix it on the browser container by
remapping the dev hostnames to the host gateway, e.g. in its compose file:

```yaml
extra_hosts:
  - "app.local.playkeres.com:host-gateway"       # Symfony app
  - "vite.app.local.playkeres.com:host-gateway"  # Vite dev server / HMR
  - "mail.local.playkeres.com:host-gateway"      # Mailpit UI
  - "local.playkeres.com:host-gateway"           # marketing site (logout target)
```

Traefik publishes 80/443 on the host, and the dev certificate is a real
Let's Encrypt one, so HTTPS validates normally — no need to disable TLS
verification. `network_mode: host` works too and needs no hostname list,
at the cost of network isolation.

Do **not** work around this by pointing the browser at the `php` container
directly: that bypasses Traefik and TLS, so cookie `Secure`/domain
behaviour no longer matches what a real browser sees — precisely the class
of bug this setup is used to reproduce.

## Sessions

Sessions are stored in the Postgres `sessions` table (Symfony
`PdoSessionHandler`, `framework.session.handler_id`; service in
`config/services.yaml`), **not** on the php container's filesystem, so logins
survive container restarts and redeployments. Rules:

- The table comes from a migration (`Version20261009190000`), never
  auto-created, and is hidden from the ORM by `doctrine.dbal.schema_filter`
  (`schema:validate` / `migrations:diff` ignore it). It reuses the default DBAL
  connection's PDO handle (no second connection) with `LOCK_ADVISORY`
  (`pg_advisory_lock`, no wrapping transaction — `LOCK_TRANSACTIONAL` would
  clash with DBAL/ORM transactions on the shared connection). Concurrent
  requests of the *same* session serialise, as with file sessions; different
  sessions never block each other.
- Lifetime is 30 days, sliding: `gc_maxlifetime` is the row TTL, pushed forward
  on each request that starts the session, and `SessionCookieRefreshListener`
  re-issues the cookie with a fresh expiry (Symfony only re-sends it when the
  id changes). Both are `framework.session.cookie_lifetime`/`gc_maxlifetime`.
- Cleanup is PHP's probabilistic gc (`gc_probability: 1` / `gc_divisor: 100`:
  ~1% of session starts run `DELETE ... WHERE sess_lifetime < now()` on an
  indexed column). No cron/command needed.
- Sessions survive deploys only if `APP_SECRET` is stable (it is required env
  in prod, no generated fallback) and the `User` entity's serialised fields
  stay compatible — changing the password hash or the fields the entity
  exposes to the session token logs affected users out. There is no
  remember-me; the session is the login. Security's CSRF tokens are stateless
  (`config/packages/csrf.yaml`), so they don't depend on server state.
- Dev uses the distinct cookie name `KERESDEVSESSID` (see `compose.yaml`) to
  avoid colliding with prod's `KERESSESSID`; keep that.

## Conventions

### PHP

- **PSR-12** coding standard enforced by PHP CS Fixer
- **PHP 8 attributes** for all Doctrine mappings, Symfony routing, security,
  and Messenger configuration — no annotations, no YAML mappings for these
- Autowiring everywhere — no explicit service declarations in `services.yaml`
- Constructor argument named `$backendApiUrl` receives `BACKEND_API_URL`
  automatically via the global `bind` in `services.yaml`
- `Game` uses Doctrine optimistic locking (`@Version`) — be aware when updating
  `Game` outside of `GameEngine` (which handles the manual version increment)
- Messenger: `ProcessAiMoveMessage`, `CheckClockExpiryMessage`, `ExpireSeekMessage`,
  `RecordAnalyticsEventMessage`, and `Symfony\Component\Mailer\Messenger\SendEmailMessage`
  route to the `async` transport (`config/packages/messenger.yaml`), consumed
  by the `php-worker` Compose service; `EvaluateMoveMessage` routes to its own
  `evaluation` transport, consumed by `evaluation-consume` in the same service;
  everything else routes to `sync` (handled in-process)

### TypeScript

- **Strict mode** — `tsc --noEmit` must return zero errors before any task is done
- Vanilla TypeScript with Vite — no Stimulus, no Symfony UX, no frontend framework
- `Board` (in `src/models/types.ts`) is the canonical board state type
- All binary codec lives exclusively in `src/utils/boardUtils.ts` — do not add
  `encode*` / `decode*` logic anywhere else

## Async & Real-time Flow

```
User action → GameController → GameAPI (HTTP /api, binary)
                          ↓
    Symfony Action → GameEngine → EngineApi → Rust
                          ↓
                  Mercure hub (SSE)
                          ↓
MercureClient → GameController → GameState → SVGBoardView
```

AI moves are dispatched as `ProcessAiMoveMessage` to the `async` transport and
processed by a Messenger consumer, then pushed to the frontend via Mercure.
