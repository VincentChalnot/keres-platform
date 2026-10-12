# Questions for the operator

Things the code and git history cannot answer. Each can be answered in one line; once
answered, fold the answer into [`DECISIONS.md`](DECISIONS.md) or [`TODO.md`](TODO.md) and
delete the question.

1. **Spectating in-progress multiplayer games.** `GameVoter::VIEW` lets anyone with the link
   watch any multiplayer game, finished or not (`src/Security/Voter/GameVoter.php:31-32`); the
   v1.0 brief said games in progress should be visible only to participants (D11 in
   `DECISIONS.md`, commits `83b63ed`, `318da7a`). Keep it public, or restrict in-progress games
   to participants (which also breaks the "Watch" links on profiles)?
2. **Lawyer review of the AGPL grant.** `LICENSE` contains the conditional AGPL-3.0 grant
   (commit `083ddd8`); the old run notes said it deserved a lawyer's review before launch. Was
   that done, or is it still pending?
3. **`ADMIN_NOTIFICATION_EMAIL` in production.** It is blank by default
   (`deploy/compose.yaml:112`, `deploy/.env.example:51`) and GDPR requests are then only
   visible in the admin queue. Is it set on the production host?
4. **Account deletion and export.** Requests are intake-only (`SettingsPrivacyAccountDeletionAction`,
   `SettingsPrivacyDataExportAction`) while the privacy policy promises erasure plus anonymised
   game retention within 30 days. Are these handled by hand for now, and do you want the
   automated anonymisation (new `TODO.md` section "Privacy") scheduled before launch?
5. **IP retention in Caddy logs.** Phase 1 of the logging plan (`TODO.md`) waits for the GDPR
   decision on client IPs and request headers in the access log (`frankenphp/Caddyfile`, `log`
   block). Keep full IPs and headers, or trim to `User-Agent`/`Referer` and which retention?
6. **Presence / abandonment.** `docs/multiplayer/00-overview.md` §2.1 lists an opponent
   presence/disconnect indicator driving abandonment adjudication as in scope;
   `GameEndReason::ABANDONMENT` (`src/Model/GameEndReason.php:18`) has no producer. Still wanted,
   or drop it from the spec?
7. **Multiplayer spec vs. code.** `docs/multiplayer/` (12k lines, written before the code, e.g.
   `01-domain-model.md` still says `days_per_move`) has no status header saying which parts
   were built as written. Do you want it reduced to a short "as built" reference, kept as the
   historical design record, or deleted (the code and `docs/features.md` being the truth)?
8. **Engine image tag in production.** Local dev hit a 404 on `/engine-move-game/:level` because
   the cached `backend:latest` predated the route (commit `e8fa44c`). Is production pinned to a
   `BACKEND_IMAGE_TAG` that has the leveled route (`deploy/compose.yaml:210`, default `latest`)?
9. **Database error at start-up.** The old TODO listed one production
   `ERROR: relation "games" does not exist` at container start. The compose files now make
   `php-worker` wait for a healthy `php`, which migrates before starting
   (`frankenphp/docker-entrypoint.sh:84`, `deploy/compose.yaml:205-207`), so I dropped it as
   probably fixed. Was it seen again?
10. **Hosting providers in the legal notice.** The marketing site's legal notice lists IONOS,
    Cloudflare, Google Cloud France (AI compute) and Scaleway; the old notes flagged the IONOS
    address as corroborated from a secondary first-party page only. Still accurate (including the
    "Google Cloud" AI compute hosting) as of the current deployment?
