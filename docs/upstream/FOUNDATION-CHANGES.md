# Foundation changes (to apply to Displanary at merge-back)

Every edit this repo makes to **shared foundation code** inherited from Displanary (`2d85ddb`) is listed here, so the same change can be applied to Displanary when attendance merges back (ADR 0013). Removals made by spec 500 are not listed — Displanary keeps that code.

| Date | Spec | File | Change | Why |
|---|---|---|---|---|
| 2026-10-21 | 500 | `phpunit.xml` | Test DB `testing` → `testing_attendance` | Both suites run on the same WSL MySQL; sharing `testing` would let one wipe the other. **Do not apply** to Displanary. |
| 2026-10-21 | 500 | `AGENTS.md`, `README.md` | Docker/Sail instructions replaced by native WSL commands | Displanary left Docker on 2026-10-16 but these files still describe Sail. **Port** (keeping Displanary's own paths and `testing` DB). |
