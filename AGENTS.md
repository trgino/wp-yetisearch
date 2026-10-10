# AGENTS.md — WP YetiSearch project rules

## Git: agent commits, never pushes

- The agent MAY commit (`git add -A && git commit`) when a unit of work is
  complete and verified.
- The agent MUST NEVER `git push`, force-push, tag (`git tag`), or delete
  remote refs. Push and release tagging are the maintainer's job.
- Exception: push/tag only when the user explicitly asks in that turn
  ("pushla", "tag bas"). The permission does not carry over.
- Never rewrite public history (`rebase`, `reset --hard`, `commit --amend`
  on pushed commits) without an explicit order.

## Versioning and releases

- Semver (`x.y.z`), tag format `v1.2.3`. A tag push (`v*`) triggers the
  GitHub Release workflow (Docker build → zip → Release assets).
- Bump with `php bin/bump-version.php <x.y.z>` (or `composer bump -- <x.y.z>`):
  it syncs the plugin header, `WPYETISEARCH_VERSION`, `readme.txt` Stable tag,
  and opens the CHANGELOG section. Fill the CHANGELOG section by hand.
- CI enforces header = Stable tag = constant. Keep the three together.
- Every user-facing change needs a CHANGELOG entry under the current
  Unreleased/next section.

## Definition of done (every change)

1. Failing test first (TDD); no implementation without a red run.
2. `vendor/bin/phpunit` green (unit + integration, `failOnWarning`/`failOnRisky` on).
3. `vendor/bin/phpstan analyse` clean (level 8, no ignores/baselines).
4. `composer cs` clean (WPCS).
5. E2E (`npx playwright test`) for user-facing search/admin behavior; full
   suite before releases.
6. `composer coverage` must not drop; new logic needs unit tests.
7. `composer validate --strict` must pass after composer.json edits.

## Stack constraints

- PHP >= 8.2, WordPress >= 6.6, `yetidevworks/yetisearch` `^2.6`.
- SQLite (pdo_sqlite + FTS5) is the engine; multisite is out of scope.
- `/vendor/` is git-ignored; `composer.lock` is tracked. Release zip is
  built by Strauss in Docker, never by hand.

## i18n

- Text domain `wp-yetisearch`. New/changed source strings must be synced to
  `languages/wp-yetisearch.pot` and the five `.po` files (tr, de_DE, es_ES,
  fr_FR, it_IT); recompile the `.mo` files. Untranslated strings fall back
  to English — never ship a wrong translation instead of leaving it empty.

## Docs

- User-facing behavior changes update `USAGE.md` (and `README.md` if the
  overview is affected). Keep both compact and factual.
