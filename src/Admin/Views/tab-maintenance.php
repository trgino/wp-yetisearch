<?php
/** Maintenance tab: re-index, embeddings, calibration, cache, stats. No settings form. */
declare(strict_types=1);
?>
<div class="card yetisearch-card">
<h2><?php echo esc_html__( 'Index', 'wp-yetisearch' ); ?></h2><p class="description"><?php echo esc_html__( 'Result totals reflect the index snapshot; private or deleted posts are always excluded from the listed items.', 'wp-yetisearch' ); ?></p>
<p>
	<button type="button" class="button button-primary" id="yetisearch-reindex"><?php echo esc_html__( 'Re-index all posts', 'wp-yetisearch' ); ?></button>
	<label><input type="checkbox" id="yetisearch-reindex-force" value="1" /> <?php echo esc_html__( 'Force (drop and rebuild)', 'wp-yetisearch' ); ?></label>
</p>
<p><progress id="yetisearch-reindex-progress" max="100" value="0" hidden></progress> <span id="yetisearch-reindex-status" role="status"></span></p>

<h2><?php echo esc_html__( 'Try the search', 'wp-yetisearch' ); ?></h2>
<p>
	<input type="search" id="yetisearch-preview-input" class="regular-text" placeholder="<?php echo esc_attr__( 'Type to test the index…', 'wp-yetisearch' ); ?>" autocomplete="off" />
</p>
<ul id="yetisearch-preview-results" class="yetisearch-listbox" aria-live="polite"></ul>

<h2><?php echo esc_html__( 'Embeddings', 'wp-yetisearch' ); ?></h2>
<p>
	<button type="button" class="button" id="yetisearch-embed"><?php echo esc_html__( 'Embed pending documents', 'wp-yetisearch' ); ?></button>
	<button type="button" class="button" id="yetisearch-calibrate"><?php echo esc_html__( 'Calibrate noise gate', 'wp-yetisearch' ); ?></button>
	<span id="yetisearch-embed-status" role="status"></span>
</p>

<h2><?php echo esc_html__( 'Cache', 'wp-yetisearch' ); ?></h2>
<p>
	<button type="button" class="button" id="yetisearch-cache-clear"><?php echo esc_html__( 'Clear cache', 'wp-yetisearch' ); ?></button>
	<button type="button" class="button" id="yetisearch-cache-warmup"><?php echo esc_html__( 'Warm up cache', 'wp-yetisearch' ); ?></button>
	<span id="yetisearch-cache-status" role="status"></span>
</p>

<h2><?php echo esc_html__( 'Error log', 'wp-yetisearch' ); ?></h2><p class="description"><?php echo esc_html__( 'Warning and error records from the last 14 days. Newest last.', 'wp-yetisearch' ); ?></p>
<p>
	<button type="button" class="button" id="yetisearch-logs-refresh"><?php echo esc_html__( 'Refresh log', 'wp-yetisearch' ); ?></button>
	<button type="button" class="button" id="yetisearch-logs-clear"><?php echo esc_html__( 'Clear log', 'wp-yetisearch' ); ?></button>
</p>
<pre id="yetisearch-log-output" class="yetisearch-log" aria-live="polite"></pre>
</div>
