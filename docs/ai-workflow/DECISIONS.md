# Decisions and standing constraints

Newest first within each section. Add an entry when a choice should outlive
the current task. Keep each entry short: date, decision, reason.

## Production data and schema

- **2026-10-19** `chunk68.sql` (50 business categories; 8 old ones set
  `is_active=0`) was applied once. Re-running it would re-retire any category an admin
  re-activated.
- **2026-10-19** Legacy brand categories were mapped once with
  `php artisan platform:map-legacy-categories --apply`. One brand still sits in
  the retired "Services" category until its owner chooses a new one.
- **2026-10-18** `chunk67.sql` (`uq_brand_phone` unique key on `brands.phone`)
  was applied once. It is **not idempotent**, so never re-run it.
- The schema is raw SQL in `database/sql/` and is applied by hand. `deploy.sh` never
  runs migrations. The few Laravel migrations in `database/migrations/` (cache,
  jobs, Sanctum tokens, app versions) were also applied manually.

## Release and hosting

- Branches merge into `main` through PRs (`gh pr merge --merge`). Deploying =
  `deploy.sh`, which pulls `main`. Merges and deploys need explicit approval
  every time.
- `public/build` is committed. The host has no Node, so build locally with
  `npm run build:clean`.
- `local-features/` (Remote Support, Live Screen, Device Intelligence) is a
  private local-only overlay. It is gitignored, and `deploy.sh` refuses to deploy it.
- The GitHub repository is public. Never commit secrets, personal data or
  internal-only documents.

## Content and trust

- Never fabricate government approval, endorsements, awards, winners,
  statistics or testimonials.
- The DBID certificate (RJSC, Ministry of Commerce) is published only as the
  masked `public/images/home/certificate.png`. Never publish the unmasked original.
  The "Registered with the Ministry of Commerce" wording appears only while that
  certificate is published.
- There is no record of permission to use the Government of Bangladesh seal or
  emblem, so don't use it on the site.
