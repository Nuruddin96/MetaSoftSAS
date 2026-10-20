# Deployment (gated)

Production is Hostinger shared hosting behind Cloudflare. Local SSH alias:
`metasoft-production` (key-based, defined in the user's `~/.ssh/config`, not in
this repo). Rules: `.claude/rules/production-safety.md`.

| | Path on server |
|---|---|
| Deploy checkout (git, branch `main`) | `~/domains/metasoftbd.com/apps/shopsaas-git` |
| Live app (rsync target, serves https://metasoftbd.com) | `~/domains/metasoftbd.com/apps/shopsaas` |
| Backups | `~/backups/<label>-<UTC timestamp>/` |

Host limits: PHP `exec()`, `symlink()` and `shell_exec()` are disabled for PHP;
no Node/npm; config is cached (`bootstrap/cache/config.php`), so any `.env`
edit needs `php artisan config:cache`, which is itself a production change.

## What `deploy.sh` actually does (read 2026-10-19)

Run on the server from the deploy checkout:
1. `git pull origin main`. **It deploys whatever is on `main`**, so merging to `main` is
   the real release decision.
2. Aborts if `local-features/` exists; aborts if `public/build/manifest.json` is
   missing or references a missing file.
3. `composer install --no-dev --optimize-autoloader`.
4. `rsync -av --delete` checkout → live, excluding `.git/`, `.env`, `.env.bak*`,
   `.env.backup*`, `storage/`, `node_modules/`, `public/storage`, `/public/puzzle`,
   `/local-features`.
5. Storage link (via shell `ln` when PHP `symlink()` is disabled),
   `php artisan optimize:clear`, `php artisan optimize`.

It does **not**: back up, run `artisan migrate`, apply `database/sql` chunks,
enable maintenance mode, build assets, or health-check. Those are manual gates
below. The repo's `backup.sh` only tars the code (no database), so use
`docs/ai-workflow/scripts/predeploy-backup.sh` instead.

## Release checklist

Each ⛔ step needs the user's explicit "yes" in chat, given right before that step.

1. **Pre-flight (local, no approval needed).** Branch rebased on / merged with
   `origin/main`; clean working tree; full suite run, with only baseline failures
   (`TESTING.md`); `npm run build:clean` committed if views or CSS changed; diff
   reviewed for secrets and for `local-features/`. Read the live commit:
   `ssh metasoft-production 'git -C ~/domains/metasoftbd.com/apps/shopsaas-git rev-parse HEAD'`.
2. **Migration impact.** List every new `database/sql/chunk*.sql` and any Laravel
   migration. For each: idempotent? locks or rewrites a large table? already applied?
   (check `DECISIONS.md`). Prepare the exact SQL and a verification query.
3. ⛔ **Present the release:** commit/PR, files, SQL to run, backup label,
   rollback plan. Wait for approval.
4. **Backup** (approved as part of step 3):
   `scp docs/ai-workflow/scripts/predeploy-backup.sh metasoft-production:predeploy-backup.sh && ssh metasoft-production 'bash ~/predeploy-backup.sh <label>'`.
   The last line must be `BACKUP OK: <dir>` (it checks the gzip, the tar, the dump
   trailer and equal live/dump table counts). Anything else means stop. The folder
   holds the live `.env` inside `code-live.tar.gz`: never copy it off the server.
5. ⛔ **Merge to `main`** (`gh pr merge <n> --merge`). Branch protection is not
   enabled on GitHub, so nothing else stops a bad merge.
6. ⛔ **SQL**, only if the release has any. Apply each chunk once, run its
   verification query, and record it in `DECISIONS.md`.
7. **Deploy:** `ssh metasoft-production 'cd ~/domains/metasoftbd.com/apps/shopsaas-git && bash deploy.sh'`.
   Expect `===== Deployment Complete =====`; any earlier exit is a failure.
8. **Post-deploy verification.** All of these must pass before reporting success:
   - deploy checkout `rev-parse HEAD` equals the merged commit;
   - `curl -s -o /dev/null -w "%{http_code}" https://metasoftbd.com/` → 200, plus
     every page or endpoint the release touched (and its main flow, read-only);
   - the live `public/build/manifest.json` CSS/JS files return 200;
   - no new errors at the tail of `storage/logs/laravel.log` (show only the error
     class and message, never request data or personal data);
   - for SQL changes, the verification query shows the expected result.
9. **Report** the commit, backup dir, checks and their output, and anything not
   verified.

## Rollback

⛔ Only with approval, and only after reporting the failed check.

- **Code only:** `git revert -m 1 <merge>` → PR → merge → `deploy.sh` → step 8
  checks. Alternative for an emergency: restore `code-live.tar.gz` from the
  backup into the live folder (never over `.env` or `storage/`).
- **Database:** prefer a targeted reverse SQL statement (e.g. drop the added
  index). A full restore of `db-full.sql.gz` overwrites everything written since
  the backup, so it needs separate, explicit approval.
- Verify the rollback with step 8 before calling it done.
