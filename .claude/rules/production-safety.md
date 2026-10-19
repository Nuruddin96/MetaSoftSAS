# Production safety

Production = the live metasoftbd.com app on Hostinger shared hosting, reached
over SSH (see `docs/ai-workflow/DEPLOYMENT.md`). Treat every remote command as
production unless it is clearly read-only.

- **Read-only is fine without asking:** HTTP checks, `git rev-parse` /
  `git status` in the deploy checkout, reading logs (report only error class and
  message), `SELECT`-only queries that return no personal data.
- **Ask the user, immediately before running it, for anything that changes
  production:** `deploy.sh`, merging to `main` (deploy pulls `main`), any
  `database/sql/*.sql` or other DDL/DML, `artisan` commands that write (cache
  rebuilds after `.env` edits, data-fixing commands, `migrate`), file edits on
  the server, rollbacks. Show ACTION / TARGET / CONTENT (commit, SQL, backup dir)
  first. One approval covers one operation, not the rest of the session.
- Local tests passing never authorizes a deploy.
- Never run destructive SQL (`DROP`, `TRUNCATE`, `DELETE`/`UPDATE` without a
  reviewed `WHERE`) or `migrate:fresh/refresh/reset` against production.
  Non-idempotent SQL chunks run once; record that in `DECISIONS.md`.
- Never print or commit `.env` values, DB credentials, API keys, tokens, OTPs or
  customer personal data. Report only presence ("set" / "missing").
  This repository is **public** on GitHub: anything committed is published.
- Never deploy `local-features/` (private overlay; `deploy.sh` refuses it).
- Never claim a deploy or rollback succeeded without the post-checks in
  `DEPLOYMENT.md`.
