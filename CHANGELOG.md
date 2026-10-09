# Changelog

All notable changes to this project are documented here.
Format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [1.1.0] - 2026-10-09

### Added

- Stemming via yetisearch 2.6: every index stems in its content language
  (English/French/German/Spanish built in, Turkish/Italian via bundled
  custom stemmers). `stemmer_language` gains `turkish`/`italian`; documents
  carry their post language and queries their request language.
  Pre-2.6 indexes self-heal through `rebuildFts` on the next indexing run
  (no full re-crawl needed).
- Italian stemmer, faithful to the Snowball algorithm (verified 1:1 over
  its 35,494-word reference vocabulary).

### Changed

- yetisearch `^2.6` (stemming API, stem-weight blending, per-language stop
  words, unregistered languages no longer stemmed as English).

## [1.0.0] - 2026-10-08

Initial release.

### Added

- SQLite FTS5 search index with typo tolerance, relevance weighting,
  highlights, and suggestions.
- Facets: post type, taxonomies, numeric price ranges (WooCommerce `_price`
  ready), and geo distance.
- Optional semantic layer (configurable embedding endpoint) with WP-Cron
  embedding queue.
- Public REST endpoint (`yetisearch/v1/search`) with `post_type`, `facets`,
  `filter[price][gte/lte]`, geo, `lang`, and pagination arguments.
- Accessible typeahead script and theme template tags.
- Settings page (9 tabs), health checks with hourly cron, admin log viewer,
  and AJAX maintenance actions.
- WP-CLI surface: 9 `yetisearch` commands (index, status, search, facets,
  geo, semantic, cache, maintenance).
- Per-language indexes via Polylang or WPML; default language reuses the
  legacy tables with no migration.
- Fast/Verified search-mode switch with documented total-count trade-off.
- WordPress.org `readme.txt`, translation template (`languages/`), and
  Docker-based release packaging with dependency prefixing.

### Verified

- PHPUnit: 195 tests; PHPStan level 8; PHPCS WordPress standard clean.
- Playwright E2E: 39 scenarios on wp-env, plus Docker matrix legs for
  WooCommerce, Polylang (EN/TR), custom post types, and a 300-post load run.
