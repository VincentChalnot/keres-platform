# TODO — Production logging cleanup

Source: a 2.9 MB / 13,680-line `docker compose logs` dump from production (6h20).
Goal: `docker compose logs` in prod is near-empty in steady state (user-traffic
access lines + real warnings/errors); everything else is dropped, routed to
Sentry, or kept as Sentry breadcrumbs. Sentry is already in production.

## What the dump showed

| Source | Volume | Cause |
|---|---|---|
| `php` (Caddy) | 2.08 MB (72%) | 1,494 JSON access-log lines, ~1.1 KB each (full request headers, IPs). 762 are `SentryUptimeBot` probes (`/` + `/lobby` every minute, 0.83 MB); ~0.4 MB is the Mercure SSE endpoint + `/notifications/unread-count` polling; only ~360 are real user traffic. Mercure also logs 250 "New subscriber" / "Subscriber disconnected" info lines. |
| `php-worker` | 0.8 MB (86% of lines) | `messenger:consume --time-limit=60` restarts every minute x2 processes (762 restarts) -> Symfony banner + blank lines + 3 supervisord INFO lines per restart; the sweep loop printed `Forfeited 0 game(s), warned 0 game(s).` every minute. |
| `database` | 45 lines | Negligible. One real error, see "Open items". |
| `backend` | 2 lines | Negligible. |

Facts that shape the design:

- **`monolog/monolog` is not installed.** `LoggerInterface` is Symfony's
  fallback `HttpKernel\Log\Logger`, which writes to stderr and, with
  `SHELL_VERBOSITY` unset, only emits `error` and above. That is why no app
  log line appears in the dump, and why `--quiet` on the worker (Phase 0) does
  not lose anything today.
- **`logger->error(...)` calls never reach Sentry.** `sentry-symfony` only
  captures exceptions (its error listener). `UserMailer`/`WaitlistMailer`/
  `GameUpdatePublisher`/`SeekMatcher` log errors that go to stderr only.
- `MultiProviderOidcAuthenticator` logs `email` and `sub` at `info`. Invisible
  today; becomes PII in a durable sink as soon as Monolog routes info anywhere.

## Decisions (agreed)

- No rotating file / extra volume for an info-level audit trail. The analytics
  events in the DB (`RecordAnalyticsEventMessage`) and Sentry breadcrumbs cover it.
- Caddy access logs: keep client IPs and headers **for now** (revisit in Phase 1,
  item "Trim fields", when the GDPR position on IP retention is settled).
- Keep `--time-limit=60` on the messenger workers. It is a documented reliability
  choice (`docs/multiplayer/03-time-control.md` section 10.1: leaked memory, stale
  entity manager, dropped connection), so the noise is silenced instead of
  lowering the restart frequency.

## Phase 0 — Quick wins — DONE

- [x] `deploy/compose.yaml`: `x-logging` anchor (`json-file`, `max-size: 10m`,
      `max-file: 5`) applied to `php`, `php-worker`, `backend`, `database`.
      Previously unbounded. Verified with `docker compose config`.
- [x] `messenger-worker.conf`: `--quiet` (drops the 10-line banner per restart).
- [x] `supervisord.conf`: `loglevel=warn` (drops spawned / RUNNING / exited lines).
- [x] `SweepGameDeadlinesCommand` (ex-`SweepCorrespondenceDeadlinesCommand`): print the summary only when
      something was forfeited or warned.
- Not done on purpose: the `CRIT Server 'unix_http_server' running without any
  HTTP authentication` line (once per container start). The socket is `chmod 0700`
  inside the container; silencing it needs credentials in both `[unix_http_server]`
  and `[supervisorctl]`. Cosmetic — skip.

## Phase 1 — Caddy (`frankenphp/Caddyfile`)

Everything here is untested; validate each step before moving on.

- [ ] **Skip noise** in the site block with `log_skip`: User-Agent
      `*SentryUptimeBot*`, path `/.well-known/mercure*`, path
      `/notifications/unread-count`. Expected: ~1.2 MB of the 2.0 MB access log.
      Check that the Sentry uptime monitor still works (it hits the site, not the logs).
- [ ] **Silence non-access Caddy/Mercure chatter**: global option
      `log { level WARN }` for the default logger (Mercure "New/Disconnected
      subscriber", `serving initial configuration`, `autosaved config`,
      TLS maintenance). Confirm the access log (separate `http.log.access.*`
      logger configured by the site-level `log {}`) is unaffected.
- [ ] **Trim fields** in the existing `format filter` (currently only redacts the
      Mercure `authorization` query param): at minimum delete `request>tls` and
      `resp_headers`; delete `request>headers` except `User-Agent`/`Referer`
      once IP retention is decided (see Decisions).
- [ ] **Optional — errors only**: find out whether Caddy's access-log level varies
      with status (to keep 4xx/5xx only). If not, leave all user traffic logged.
- [ ] Validate: `docker compose exec php frankenphp validate --config /etc/frankenphp/Caddyfile`,
      then count `docker compose logs php` lines over a few minutes of mixed traffic
      (browse + SSE + uptime probe). Dev and prod share this Caddyfile — check dev too.

## Phase 2 — Monolog + Sentry routing

- [ ] `composer require symfony/monolog-bundle` (recipe creates `config/packages/monolog.yaml`).
- [ ] `when@prod` handlers in `monolog.yaml`:
  - `main`: `fingers_crossed`, `action_level: error`,
    `excluded_http_codes: [403, 404, 405]`, buffer limit, `handler: nested`.
  - `nested`: `stream` to `php://stderr`, `formatter: monolog.formatter.json`
    (one JSON line per record -> `docker logs`).
  - `warnings`: plain `stream` to `php://stderr` at `warning`, channels
    `!event`, `!doctrine` (what stays visible in steady state).
  - `deprecation` channel -> `null`.
  - dev/test keep the bundle defaults.
- [ ] Sentry (`config/packages/sentry.yaml`, follow the commented template there):
  - Keep `register_error_listener` **enabled** (reports uncaught exceptions and
    honours `ignore_exceptions`).
  - Add `Sentry\Monolog\Handler` at `error` with `channels: ["!request"]`, so
    logged errors reach Sentry without duplicating the exception the listener
    already sends. Confirm that `ErrorListener`'s own `request`-channel log of an
    exception really is what causes the duplicate before relying on this.
  - Add `Sentry\Monolog\BreadcrumbHandler` at `info` (free context on events,
    nothing in Docker).
  - Do **not** enable `Sentry\SentryBundle\Monolog\LogsHandler` (Sentry Logs product).
- [ ] PII: in `MultiProviderOidcAuthenticator`, drop `email` / `sub` from the log
      context (or move to `debug`). Skim other `logger->info/warning` call sites
      (`ClockManager`, `SeekMatcher`, mailers) for PII.
- [ ] The sweep summary (Phase 0) could become `logger->info` so it lands in
      breadcrumbs instead of stdout; optional.

## Phase 3 — Verification

- [ ] Trigger a handled `logger->error(...)` and an uncaught exception in prod mode
      (`APP_ENV=prod` container or `docker compose exec`): expect one JSON line on
      stderr each, **one** Sentry event each, breadcrumbs attached, no duplicate.
- [ ] A 404 produces neither a Sentry event nor a Docker log line.
- [ ] Watch `docker compose logs -f` in prod for ~10 min: user-traffic access lines
      and real warnings only.
- [ ] `composer cs:check` and the PHPUnit suite.
- [ ] Update `README.md` / ops docs with the log routing table (what goes where).

## Open items (outside this effort)

- `database-1`: `ERROR: relation "games" does not exist` at 14:41 — a one-off at
  container start, likely a query running before migrations. Worth checking the
  deploy order (migrate before the worker/sweep start, or `depends_on`).
