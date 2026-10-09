# WP YetiSearch — Usage Guide

## First run

1. Activate the plugin, open the **WP YetiSearch** menu (bottom of admin sidebar).
2. The **Get started** panel walks you through: enable the engine, choose content, run the first index.
3. If the toggle is locked with "health checks are not passing", press **Re-check**. All checks must pass (SQLite with FTS5 is the usual missing piece — ask your host to enable `pdo_sqlite`).
4. Open **Maintenance** and press **Re-index all posts** (or `wp yetisearch reindex`).

The status strip on every tab shows engine state, indexed document count, and last reindex time.

## Settings tour

- **General** — master toggle, custom database directory (with a pre-save **Check directory** button), storage protection probe.
- **Content** — post types, taxonomies, and meta keys to index.
- **Relevance** — field weights, fuzzy matching, suggestions.
- **Language** — stemmer (`auto` detects English/French/German/Spanish), stop words, synonyms.
- **Display** — typeahead behavior (debounce 100–2000ms, 2–10 chars to trigger, max results) and highlight tag.
- **Facets & Geo** — post-type/taxonomy facets, price ranges (`_price` by default, WooCommerce-ready), geo radius.
- **Semantic** — optional embedding endpoint for vector search, with cron queue.
- **Performance** — result cache, REST cache lifetime, Fast/Verified search mode.
- **Maintenance** — reindex, embeddings, cache, live search preview, error log.

Changing the database directory moves the index files safely (copy → verify → delete sources). Changing indexed content marks the **reindex notice**.

## Theme integration

Nothing is required: the normal search results page is taken over automatically. Optional helpers:

```php
<?php yetisearch_the_suggestion(); ?>           // "Did you mean: …" link
<?php yetisearch_the_facet_links('price_range'); ?> // clickable price buckets
```

Or drop in the **WP YetiSearch** widget or the `yetisearch/search-box` block — the typeahead suggestions attach to any `input[name="s"]` automatically.

## REST API

```
GET /wp-json/yetisearch/v1/search?q=boots&facets=1
GET /wp-json/yetisearch/v1/search?q=boots&filter[price][gte]=100&filter[price][lte]=500
GET /wp-json/yetisearch/v1/search?q=vapur&lang=tr
```

Arguments: `q` (required), `limit`, `page`, `post_type`, `facets`, `lat`/`lng`/`radius`, `context` (`search`|`typeahead`), `lang`, plus `filter[field][gte|lte]` range filters. Price buckets in the response carry ready-to-use `filter` specs. Responses are public-cached per the **REST cache** setting.

## WP-CLI

```
wp yetisearch reindex [--batch=100] [--post-type=post,product] [--force]
wp yetisearch check        # environment self-test
wp yetisearch health       # cached health record
wp yetisearch stats        # index statistics
wp yetisearch query <term> [--limit=10] [--no-fuzzy]
wp yetisearch embed [--batch=50]
wp yetisearch calibrate
wp yetisearch clear --yes
wp yetisearch cache <clear|warmup>
```

## Multilingual

One index per language via Polylang, Polylang Pro/Woo, or WPML. Search with `?lang=tr` (or rely on the current language on theme pages and in the typeahead). After changing a post's language, run `reindex --force` once.

## Troubleshooting

| Symptom | Fix |
|---|---|
| Toggle locked | Press **Re-check**; enable `pdo_sqlite` on the host if flagged |
| No results | Run **Re-index**; confirm the post type is selected under Content |
| Stale results after edits | Reindex (edits sync live; deletes/moves may lag one cycle) |
| Slow first search | Normal — caches are cold; repeats use the query cache |
| Uninstall | Deletes options, cron events, and index files; posts untouched |
| Multisite | Not supported in 1.0.0 (single site only) |
