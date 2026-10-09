# Changelog

All notable changes to this project are documented here.
Format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

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
