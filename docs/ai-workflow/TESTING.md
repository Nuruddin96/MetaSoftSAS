# Testing and verification

Verified on Windows (PHP 8.2 CLI, Node 24) in October 2026. Tests run on SQLite
`:memory:` with the env in `phpunit.xml` (`TENANCY_MODE=path`,
`CENTRAL_DOMAIN=metasoftbd.test`, sync queue, array cache/mail/session); they
never touch a real database.

## Commands

| Purpose | Command |
|---|---|
| One test / filter | `php -d memory_limit=2G vendor/bin/phpunit --colors=never --filter=TestName` |
| One file or folder | `php -d memory_limit=2G vendor/bin/phpunit --colors=never tests/Feature/Platform` |
| Full suite (~1,870 tests, several minutes) | `php -d memory_limit=2G vendor/bin/phpunit --colors=never` |
| Failures only | append `2>&1 \| grep -E "^[0-9]+\) \|^Tests:\|^OK"` |
| Code style (changed PHP files) | `vendor/bin/pint --test <files>`, then `vendor/bin/pint <files>` to fix |
| Release asset build | `npm run build:clean` |

`php artisan test` / `composer test` work in principle but hit the default 128 MB
PHP memory limit on the full suite; call PHPUnit with `-d memory_limit=2G`.

There is no PHPStan/Larastan, no Pest, no Dusk/Playwright suite and no GitHub
Actions CI. Do not add them without the user's agreement.

## Known baseline failures

These fail on `main` without any change (checked 2026-10-19). Treat any failure
*outside* this list as caused by your change until proven otherwise, and
re-check the list if it starts to drift.

- `AiAgent/AiProductKnowledgeServiceTest::test_respects_the_configured_max_matched_products`
- `AiAgent/WhatsAppAiDispatchTest` (5 tests)
- `Tenant/AiChatControllerTest` (4 tests)
- `WordPress/WordPressConnectTest` (2 tests)
- Occasionally flaky: `AiAgent/ProcessWhatsAppAiAgentMessageJobTest`

Last full run (2026-10-19, `main` 95a5524): 1,866 tests, 12 failures, all from this list.

## Verification standard per change

1. Focused tests for the code you changed, written or updated in the same change
   (feature tests under the audience folder: `tests/Feature/Tenant`, `Platform`,
   `Central`, `Api`, …).
2. The affected folder(s), then the full suite before the final report.
3. Tenant-facing data: a cross-tenant test (see `.claude/rules/multi-tenancy.md`).
4. Blade/Tailwind changes: `npm run build:clean` and include `public/build` in the
   change. `tests/Feature/BuiltAssetsTest.php` guards a missing manifest entry,
   not a stale build. `deploy.sh` cannot build (the host has no Node).
5. UI changes: render the page locally and check it in a browser at phone and
   desktop widths (no horizontal overflow, no console errors). There is no
   committed browser-test tool. Use the Chrome integration or a headless Chrome
   script kept outside the repo, and say which one you used.
6. Schema changes: add a new `database/sql/chunkNN.sql`, make it idempotent where
   possible, and load the same table shape in the tests' SQLite setup (see
   `tests/Concerns/` and existing `setUp…Schema` helpers).

## Local environment gotchas

- Each git worktree needs its **own copied** `vendor/`. A junction or symlink
  to another worktree's `vendor` makes Laravel infer the wrong base path, so the
  tests render the other worktree's views.
- A fresh worktree has no `.env`, so about half the suite errors with
  `MissingAppKeyException`. Run with a throwaway key instead of copying a real
  `.env`: `APP_KEY="base64:$(node -e "process.stdout.write(require('crypto').randomBytes(32).toString('base64'))")" php -d memory_limit=2G vendor/bin/phpunit`.
- Rendering pages locally without MySQL: serve `public/` with
  `DB_CONNECTION=sqlite DB_DATABASE=<scratch .sqlite file> CACHE_STORE=array SESSION_DRIVER=file php -S 127.0.0.1:<port> ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php`.
  Use `localhost`, not `127.0.0.1`, for pages whose assets come from the Vite dev
  server.
- Vite dev sometimes misses classes in brand-new Blade files: touch
  `resources/css/app.css`.
- Rate-limited routes (e.g. `throttle:owner-register`) need a varying
  `REMOTE_ADDR` when a test posts many times.
