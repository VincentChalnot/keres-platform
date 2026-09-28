# Blocked Tasks

Hard stops only (§3 of the run's ground rules: real credentials/DNS/VPS/NAS/GCP
access, deleting/migrating real user data, piece logo visual identity, Rust
engine rules/evaluation changes, exact trust-pledge wording). A blocked task
never blocks later tasks.

---

### T4 — Fifth trust commitment (open-source-if-abandoned wording)
**Not blocking** — the other four commitments and a clearly marked
placeholder shipped together in the same commit.

What's needed to unblock: the operator needs to settle the exact wording
of "open-source the code if the project is abandoned" — specifically the
licence name and the trigger condition (what counts as "abandoned": a time
period of inactivity? an explicit operator statement? something else). Per
the run's hard-stop rules, this was left as a visually distinct "coming
soon" placeholder on `/trust` (`../keres-website/content/{fr,en}/trust.md`
→ `layouts/shortcodes/i18n_trust.html`, `trust_opensource_*` i18n keys) —
no licence named, no trigger condition invented, no rough draft attempted.
Swap the placeholder block for real `trust_opensource_text` (or however
it's phrased) once the operator has decided.
