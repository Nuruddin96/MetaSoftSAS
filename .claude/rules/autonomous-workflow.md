# Autonomous feature workflow

Applies to every feature, fix or change request in this repo. The user gives one
high-level objective; you carry it to a verified result without asking for each
small step.

1. **Resume first.** Read `docs/ai-workflow/IMPLEMENTATION_PLAN.md`. If it holds an
   unfinished objective, continue from its first unchecked milestone instead of
   redoing finished work. Re-verify any "done" item whose files changed since.
2. **Plan.** Rewrite the objective in `IMPLEMENTATION_PLAN.md` as concrete,
   testable milestones (acceptance criteria + the test that proves each). Note the
   affected guards, tenants, SQL chunks and views. Ask the user only if the
   objective is genuinely ambiguous about *what* to build, or needs a safety
   decision (see `production-safety.md`).
3. **Inspect before editing.** Read the existing controllers, models, views and
   tests you will touch; reuse their patterns (audience-grouped namespaces,
   `BelongsToTenant`, raw-SQL schema in `database/sql/`). No unrelated refactors,
   no new dependencies unless the objective requires one and the user agrees.
4. **Implement end to end** across every file the objective needs (route,
   controller, request validation, view, SQL chunk, tests, built assets), not
   just the first one.
5. **Test as you go** (commands in `docs/ai-workflow/TESTING.md`): focused tests
   after each milestone, then the affected suite(s), then the full suite before
   reporting. Fix failures your change caused. Compare failures against the known
   baseline; a new failure is yours until proven otherwise.
6. **Check** tenant isolation, authorization (right guard), validation, data
   integrity, N+1/performance on lists, backwards compatibility of existing
   routes/APIs (the Flutter app uses `/api/mobile/v1/*`), and secrets in output.
7. **Track progress.** Tick milestones and append a dated, one-line entry to the
   plan's progress log after each one, so an interrupted session can resume.
   Record lasting design choices in `docs/ai-workflow/DECISIONS.md`.
8. **Be honest.** Never report a test, build, browser check or deploy you did not
   run, or a skipped check as passing. Say "not run" and why.
9. **Stop only for real blockers:** missing information, missing external access
   (credentials, logins, 2FA, Meta/Google consoles), or a safety decision.
   Otherwise keep going through independent milestones.
10. **Finish with a report:** changed files, exact commands run and their real
    results, unresolved issues, and deployment status ("not deployed" unless an
    approved deploy was verified). Then clear the active objective in the plan
    (move it to the "Completed" list).

Commits, pushes, PRs and merges happen only when the user asks. Deployment is a
separate gated stage: `docs/ai-workflow/DEPLOYMENT.md`.
