=== WP YetiSearch ===
Contributors: trgino
Tags: search, sqlite, relevance, woocommerce, multilingual
Requires at least: 6.6
Tested up to: 7.0
Requires PHP: 8.2
Stable tag: 1.1.1
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Fast, typo-tolerant site search powered by SQLite FTS5. No external service required.

== Description ==

WP YetiSearch replaces the default WordPress search with an SQLite FTS5 index that lives on your own server: typo tolerance, relevance weighting, highlights, suggestions, facets (post type, taxonomies, price ranges, geo distance), an optional semantic layer, a public REST endpoint, an accessible typeahead, and WP-CLI commands.

* Local-first: the index is an SQLite file under your uploads directory, protected against direct web access with an automatic leak probe.
* Multilingual: one index per language via Polylang or WPML (default language reuses the legacy tables, no migration).
* WooCommerce-ready: index the `product` type and facet/filter by the `_price` meta out of the box.
* Transparent trade-offs: a Fast/Verified search mode switch, health checks with an admin log viewer, and a full WP-CLI surface (`yetisearch reindex/status/...`).

No visitor data leaves your server in the default configuration. The optional semantic layer calls the embedding endpoint you configure, only when you enable it.

== Installation ==

1. Upload the plugin files to `/wp-content/plugins/wp-yetisearch`, or install the zip via Plugins → Add New → Upload.
2. Activate the plugin through the Plugins screen.
3. Go to Settings → YetiSearch, enable the search engine, choose the content to index, and run the first index from the Maintenance tab (or `wp yetisearch reindex`).
4. Single-site only; multisite is not supported in 1.0.0.

== Frequently Asked Questions ==

= Does search work without JavaScript? =
Yes. The theme's normal search results page is taken over server-side; the typeahead and REST endpoint are progressive enhancements.

= Which languages are supported? =
Content in any language is indexed. The stemmer auto-detects English, French, German and Spanish; per-language indexes work with Polylang or WPML.

= What happens on uninstall? =
Uninstall deletes all plugin options, clears scheduled cron events, and removes the index files. Your posts are untouched.

== Changelog ==

= 1.0.0 =
Initial release.
