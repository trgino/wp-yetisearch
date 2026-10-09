# WP YetiSearch

<p align="center">
  <img src="wpyetisearch-logotype.jpg" alt="WP YetiSearch" width="600">
</p>

Fast, typo-tolerant site search powered by SQLite FTS5. No external service required.

A WordPress integration of [YetiSearch](https://github.com/yetidevworks/yetisearch)
(`yetidevworks/yetisearch`): the index is an SQLite file on your own server, with
relevance weighting, highlights, suggestions, facets (post type, taxonomies, price
ranges, geo distance), an optional semantic layer, a public REST endpoint, an
accessible typeahead, and a full WP-CLI surface. One index per language via Polylang or WPML.

## Requirements

- WordPress 6.6+
- PHP 8.2+
- SQLite with `pdo_sqlite` and FTS5 (checked on the settings page with a self-test)

## Install

1. Upload the release zip via Plugins → Add New → Upload, or copy the files to
   `/wp-content/plugins/wp-yetisearch`.
2. Activate the plugin.
3. Go to Settings → YetiSearch, enable the search engine, choose the content to
   index, and run the first index from the Maintenance tab
   (or `wp yetisearch reindex`).
4. Single-site only; multisite is not supported in 1.0.0.

## Search from a theme

Nothing to change: the normal search results page is taken over server-side.
Optional helpers:

- REST: `GET /wp-json/yetisearch/v1/search?q=...&facets=1&filter[price][gte]=100`
- Template tags and a typeahead script (see `src/Search/template-tags.php`).
- WP-CLI: `wp yetisearch reindex`, `wp yetisearch check`, and 7 more commands.

Full guide: [USAGE.md](./USAGE.md) (settings tour, theme/REST/CLI reference, multilingual, troubleshooting).

## Development

```sh
composer install
composer test        # PHPUnit (unit + integration)
composer analyse     # PHPStan level 8
composer cs          # PHPCS with WordPress Coding Standards
composer verify      # all three gates in one command
```

End-to-end tests run against wp-env + Playwright (`npx playwright test`).
Extra environment legs (WooCommerce, Polylang, WPML, CPT, load) run via
`bin/matrix-leg.ps1 -Leg woo|i18n|cpt|load|wpml|woopll`.
Release zips are built in Docker: `bin/release-in-docker.ps1`.

See [CHANGELOG.md](./CHANGELOG.md) for release history.

## Credits

Search engine by [YetiSearch](https://github.com/yetidevworks/yetisearch)
(Yeti DevWorks), bundled dependency-prefixed in releases.

## License

GPL-2.0-or-later. See [License URI](https://www.gnu.org/licenses/gpl-2.0.html).
