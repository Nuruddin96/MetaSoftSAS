# Implementation plan

Working state for the current objective (see
`.claude/rules/autonomous-workflow.md`). One active objective at a time. When it
is done, move a one-line summary to "Completed" and reset the active section.

## Active objective

**Objective:** Research 30 well-known brands operating in Bangladesh and add them as a
neutral discovery directory (homepage selection + Brand Directory), kept separate from
registered brands, with a safe replacement workflow when a genuine brand registers.
**Started:** 2026-10-20 · **Branch/worktree:** feat/brand-directory-listings (MetaSoftSAS-directory) · **Status:** done — awaiting review (not committed, not deployed)

### Acceptance criteria
- [x] Separate `brand_directory_listings` table (chunk70.sql); listings never enter `brands`, voting, awards or brand counts — DirectoryListingsTest
- [x] Import from `database/data/brand-directory.json` is idempotent, dry run by default, keeps admin decisions, skips names/domains already registered — DirectoryListingsTest
- [x] /brands shows listings in a separate, neutrally labelled section with search + category filter; `/directory/{slug}` info page; never a Verified badge — DirectoryListingsTest
- [x] Homepage shows 6–9 picked listings with a link to the directory — DirectoryListingsTest
- [x] Registration matching by normalized name / official domain records a claim; replacement only for an approved + Verified brand; archive, never delete; audit + restore — DirectoryListingsTest
- [x] Expired listings archived by `platform:directory-maintain --apply` — DirectoryListingsTest

### Milestones
- [x] 1. Research data file — database/data/brand-directory.json
- [x] 2. Schema + model + service + commands — chunk70.sql, BrandDirectoryListing, DirectoryListings, commands
- [x] 3. Public pages (directory section, listing page, homepage section)
- [x] 4. Registration claim + Super Admin directory page (replace / archive / restore)
- [x] 5. Tests, full suite, build

### Progress log
- 2026-10-20 — research done (29 verified from official sites, 1 needs_review); data file written.
- 2026-10-20 — chunk70.sql, model, DirectoryListings service, import/maintain commands, public pages, admin Directory page, registration claim hook.
- 2026-10-20 — DirectoryListingsTest 17/17; Platform+Central+SuperAdmin 248/248; full suite 1,899 tests, 12 failures = baseline (an earlier run had 13: one flaky extra); npm run build:clean OK; local SQLite visual check of /, /brands, /directory/aarong.
- 2026-10-20 — review pass: corrected 4 ownership notes to match their recorded sources (bKash, Singer, Bata, Banglalink); replaced-listing redirect now 302 (rollback-safe); marketplace seller URLs (2+ path segments) no longer count as an official-domain match; re-import with a newer check renews expiry-archived listings. DirectoryListingsTest 20/20; related suites 252/252; full suite 12 failures = baseline; build:clean unchanged CSS.

<!-- Template — copy into this section when a new objective starts:

**Objective:** <the user's request, verbatim or closely paraphrased>
**Started:** YYYY-MM-DD · **Branch/worktree:** <name> · **Status:** planning | in progress | blocked | done

### Acceptance criteria
- [ ] <observable behaviour> — proven by <test name / check>

### Milestones
- [ ] 1. <step> — files: <...>

### Blockers / decisions needed
- <only real blockers: missing info, external access, safety decision>

### Progress log
- YYYY-MM-DD HH:MM — <what was done, test result>

### Final report
Changed files · commands run + results · unresolved issues · deployment status
-->

## Completed

- _None yet._
