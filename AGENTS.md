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
| `src/i18n/`                         | `t()` + ICU-subset formatter over the server-rendered catalogue (see Internationalisation) |
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

Guest games have no `Game` row but still feed the shared tree: after each ply the browser posts
the whole move list to `POST /api/guest-moves` (rate-limited per IP), and
`BoardTreeManager::recordLastMove()` replays it through the engine (an illegal list records
nothing) and stores the last move as a `Move` edge between deduped `BoardPosition`s - no
`GameMove`, no evaluation job. Guests are limited to AI levels 1-4
(`LocalGameType::GUEST_MAX_AI_LEVEL`; `/api/engine-move-game?level=N` answers 403 above it unless
signed in) and never get the live evaluation bar (form field disabled, `/api/games/**` is
`ROLE_USER`, anonymous spectators get no evaluations in the page bootstrap and no eval bar).

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

## Internationalisation (en / fr)

The whole UI is bilingual: `en` (default and fallback) and `fr`
(`framework.enabled_locales` in `config/packages/translation.yaml`). Symfony
Translation, **ICU MessageFormat in YAML**, one file per domain and locale:
`translations/<domain>+intl-icu.<locale>.yaml` (nested keys, 4-space indent).

| Domain                         | Used by                                                                 |
|--------------------------------|-------------------------------------------------------------------------|
| `navigation`                   | navbar, footer, notification bell, confirm modal, language switcher     |
| `game`                         | lobby, play pages, game lists, dashboard, game header/badges            |
| `auth`, `settings`, `pages`    | login/register/reset, settings pages, feedback/waitlist/error pages     |
| `social`, `notifications`      | friends, profile, inbox page and the notification texts                 |
| `forms`, `validators`, `flashes` | form labels/help/choices, our constraint messages, flash messages     |
| `emails`                       | e-mail subjects and bodies                                              |
| `frontend`, `frontend_<area>`  | the TypeScript client (see below)                                       |

Admin screens (`templates/admin/**`, `config/admin`, `config/datagrid`,
`src/Action/Admin/**`, the admin TS entry), the admin-facing e-mail
(`admin_gdpr_request*`), console output and developer-facing exception/log/API
`message` texts are **deliberately English-only**. Links to the marketing site
(`STATIC_SITE_URL`) are unchanged (no `/fr` assumptions). Known untranslated
art: the piece names printed on the piece sprites (`assets/pieces/texts/*.svg`,
outlined vector artwork) are English; accessible names/tooltips are translated.
Legacy game JSON endpoints (`/play/{uuid}/move|undo|resign|…`) return a
translated `error` plus a machine-readable `code` (e.g. `concurrent_move`); the
`/lobby`, `/friends`, `/notifications` endpoints use the `ApiResponse` envelope
(English `message`, `code` is the contract).

### Which language a request is served in

`App\Service\Locale\LocaleResolver` (applied by `App\EventListener\LocaleListener`
right after the firewall): the signed-in user's saved `User::$locale` → the
`keres_locale` cookie → `Accept-Language` → `en`. The language switcher
(`templates/_language_switcher.html.twig`, `GET /locale/{locale}?redirect=/path`)
sets the cookie (this is how anonymous visitors are remembered, no session is
started) and, when signed in, saves `User::$locale`; Settings → Profile has the
same choice, with "Automatic" (= NULL, follow the browser). New accounts start
in the language of their first request (`NewUserLocaleListener`). `<html lang>`
follows the request locale. Anything rendered outside a request (queued
e-mails, Mercure/notification texts built for another user) must pass the
recipient's locale explicitly (`$translator->trans($id, $params, $domain, $locale)`,
`TemplatedEmail::locale()`); never rely on the ambient locale there.

### Adding or changing a string

1. Add the key to **both** `<domain>+intl-icu.en.yaml` and `.fr.yaml` (same key,
   same ICU arguments). English is the source; write real French, not a
   word-for-word translation (formal "vous", `’` apostrophes, a no-break space
   before `: ; ? !` and inside « »). Domain glossary: game = partie, move =
   coup, seek = proposition, time control = cadence, rated = classée…
2. Use it. **Twig**: `{% trans_default_domain 'game' %}` at the top, then
   `{{ 'lobby.title'|trans }}` / `{{ 'key'|trans({count: n}) }}`; keys must be
   literals. Dates and numbers: `format_date`/`format_datetime`/`format_number`.
   **PHP**: inject `TranslatorInterface`, `->trans('flash.saved', [], 'flashes')`;
   forms use `translation_domain` + keys, constraints use keys in `validators`;
   `src/Model` stays translator-free (codes in, text out at display time).
   **TypeScript**: `import {t} from '../i18n'`, `t('lobby.seek.accept')`,
   `t('play.timer.minutes', {count: 3})`.
3. ICU subset only (it is what the TS formatter implements): `{name}`,
   `{n, number}`, `{count, plural, =0 {…} one {…} other {…}}` (`#` = the
   number), `{x, select, a {…} other {…}}`. Never put `'` directly before `{`
   or `}`. No date/ordinal types.
4. Keep API contracts language-neutral: the JSON envelope's `error.code` is the
   contract (`api_error.<code>` in `frontend` is what the client shows); stored
   rows/Mercure payloads carry codes + params, not rendered text.

### TypeScript catalogue (single source of truth)

No second copy of any string: every domain called `frontend` or
`frontend_<area>` is exported by `App\Service\Locale\FrontendCatalogue` as JSON
into `<script type="application/json" id="app-i18n">` (see `base.html.twig`).
`frontend` keys keep their name (`common.cancel`); `frontend_play` key
`banner.won` becomes `play.banner.won`. `assets/typescript/src/i18n/` holds
`t()`, `locale()`, `formatDate/DateTime/Number/RelativeTime` and the ICU
interpreter. A missing key renders as the key and warns in the console.

### Adding a language

1. `framework.enabled_locales` (+ `fallbacks` stays `en`) in `config/packages/translation.yaml`.
2. One `<domain>+intl-icu.<locale>.yaml` per existing domain, plus the endonym
   `language.<locale>` in **every** `navigation` file (the switcher and the
   settings select list `enabled_locales`).
3. `bin/console app:translations:check` must pass; check the layout with the
   longest strings (navbar, buttons, badges, 390px mobile).

### Checks (CI)

```bash
docker compose exec -T php bin/console app:translations:check            # same files/keys/ICU arguments in every locale
docker compose exec -T php bin/console lint:translations                 # Symfony: ICU validity
docker compose exec -T php bin/console debug:translation fr --only-missing   # keys used in code but absent from fr (also: en)
docker compose exec -T php vendor/bin/phpunit                            # TranslationCatalogueTest + FrontendCatalogueTest (every TS t('…') key exists)
```

## Announcements

The news list ("Announcements") is shown at the bottom of the "Your stats" card
on the dashboard and on the anonymous `/lobby` page (latest 3, "Show more"
beyond that; the dashboard shows 5). It is the typed source
`App\Service\Announcement\AnnouncementProvider::ENTRIES` (`id => 'Y-m-d'`),
rendered by `templates/actions/_announcements.html.twig`, dates per locale.
To add one, user-visible changes only (no refactors/tests/docs/CI), related
commits grouped, dated by the latest relevant commit:

1. Add `'my_id' => '2026-10-12'` to `ENTRIES` (any position; sorted newest first).
2. Add `items.my_id.title` and `items.my_id.body` to **both**
   `translations/announcements+intl-icu.en.yaml` and `.fr.yaml`.

`AnnouncementProviderTest` fails if a key is missing in a locale.

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
