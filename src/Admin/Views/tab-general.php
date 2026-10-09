<?php
/** General tab: health table, master toggle guard, storage info, probe + server rules.
 *
 * @var \WpYetiSearch\Core\Config $config
 * @var array{ready: bool, checks: array<string, array{ok: bool, value: string}>, checked_at: int}|null $health
 * @var bool $ready
 * @var string $dbPath
 * @var string|null $dirUrl
 * @var string $server
 * @var string $snippet
 * @var int $docCount
 * @var bool $checklistHidden
 */
declare(strict_types=1);

use WpYetiSearch\Admin\SettingsPage;

$master = (bool) $config->get( 'master_enabled' );
$checks = is_array( $health ) ? $health['checks'] : array();
?>
<div class="card yetisearch-card">
<?php if ( ! $master && ! $checklistHidden ) : ?>
	<div class="yetisearch-checklist" id="yetisearch-checklist">
		<h2><?php echo esc_html__( 'Get started', 'wp-yetisearch' ); ?></h2>
		<ol>
			<li data-step="enable">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . SettingsPage::MENU_SLUG ) ); ?>"><?php echo esc_html__( 'Enable the search engine', 'wp-yetisearch' ); ?></a>
			</li>
			<li data-step="content">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . SettingsPage::MENU_SLUG . '&tab=content' ) ); ?>"><?php echo esc_html__( 'Choose the content to index', 'wp-yetisearch' ); ?></a>
			</li>
			<li data-step="index" data-done="<?php echo $docCount > 0 ? '1' : '0'; ?>">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . SettingsPage::MENU_SLUG . '&tab=maintenance' ) ); ?>"><?php echo esc_html__( 'Run the first index', 'wp-yetisearch' ); ?></a>
			</li>
		</ol>
		<button type="button" class="button-link" id="yetisearch-checklist-dismiss"><?php echo esc_html__( 'Dismiss', 'wp-yetisearch' ); ?></button>
	</div>
<?php endif; ?>
<h2><?php echo esc_html__( 'System health', 'wp-yetisearch' ); ?></h2>
<table class="widefat striped yetisearch-health">
	<tbody>
		<?php foreach ( $checks as $name => $check ) : ?>
			<tr>
				<td><?php echo esc_html( (string) $name ); ?></td>
				<td>
					<?php if ( (bool) $check['ok'] ) : ?>
						<span class="dashicons dashicons-yes-alt yetisearch-health-ok" aria-hidden="true"></span>
						<span class="screen-reader-text"><?php echo esc_html__( 'OK', 'wp-yetisearch' ); ?></span>
					<?php else : ?>
						<span class="dashicons dashicons-dismiss yetisearch-health-fail" aria-hidden="true"></span>
						<span class="screen-reader-text"><?php echo esc_html__( 'FAIL', 'wp-yetisearch' ); ?></span>
					<?php endif; ?>
				</td>
				<td><?php echo esc_html( (string) $check['value'] ); ?></td>
			</tr>
		<?php endforeach; ?>
	</tbody>
</table>
<p>
	<button type="button" class="button" id="yetisearch-health-refresh"><?php echo esc_html__( 'Re-check', 'wp-yetisearch' ); ?></button>
	<span id="yetisearch-health-result" role="status"></span>
</p>
<?php if ( is_array( $health ) ) : ?>
	<p class="description">
	<?php
	echo esc_html(
		sprintf(
		/* translators: %s: human-readable time difference, e.g. "2 hours" */
			__( 'Last checked %s ago. Automatic checks run hourly.', 'wp-yetisearch' ),
			human_time_diff( (int) $health['checked_at'], time() )
		)
	);
	?>
	</p>
<?php endif; ?>
<?php if ( ( $checks['sqlite_recency']['ok'] ?? true ) === false ) : ?>
	<p class="description"><?php echo esc_html__( 'Notice: SQLite 3.35 or newer is recommended for best performance. Search works on your version.', 'wp-yetisearch' ); ?></p>
<?php endif; ?>

<h2><?php echo esc_html__( 'Search', 'wp-yetisearch' ); ?></h2>
<table class="form-table" role="presentation">
	<tr>
			<th scope="row"><label for="yetisearch-master_enabled"><?php echo esc_html__( 'Enable WP YetiSearch', 'wp-yetisearch' ); ?></label></th>
		<td>
			<input type="checkbox" id="yetisearch-master_enabled" name="yetisearch[master_enabled]" value="1" <?php checked( $master ); ?> <?php disabled( ! $ready && ! $master ); ?> />
			<?php if ( ! $ready ) : ?>
				<p class="description"><?php echo esc_html__( 'Locked: health checks are not passing.', 'wp-yetisearch' ); ?></p>
			<?php endif; ?>
		</td>
	</tr>
	<tr>
		<th scope="row"><label for="yetisearch-db_custom_dir"><?php echo esc_html__( 'Custom database directory', 'wp-yetisearch' ); ?></label></th>
		<td>
			<input type="text" id="yetisearch-db_custom_dir" name="yetisearch[db_custom_dir]" value="<?php echo esc_attr( $config->string( 'db_custom_dir' ) ); ?>" class="regular-text" />
			<button type="button" class="button" id="yetisearch-dircheck"><?php echo esc_html__( 'Check directory', 'wp-yetisearch' ); ?></button>
			<span id="yetisearch-dircheck-result" role="status"></span>
			<p class="description"><?php echo esc_html__( 'Absolute path; outside the web root is safest. Empty means uploads/yetisearch/.', 'wp-yetisearch' ); ?></p>
			<p class="description"><?php echo esc_html__( 'Current database:', 'wp-yetisearch' ); ?> <code><?php echo esc_html( $dbPath ); ?></code></p>
		</td>
	</tr>
</table>

<h2><?php echo esc_html__( 'Storage protection', 'wp-yetisearch' ); ?></h2>
<p><?php echo esc_html__( 'Server detected:', 'wp-yetisearch' ); ?> <code><?php echo esc_html( $server ); ?></code></p>
<p>
	<button type="button" class="button" id="yetisearch-probe"><?php echo esc_html__( 'Probe directory protection', 'wp-yetisearch' ); ?></button>
	<span id="yetisearch-probe-result" role="status"></span>
</p>
<?php if ( $snippet !== '' ) : ?>
	<p><?php echo esc_html__( 'Add this rule to your server configuration:', 'wp-yetisearch' ); ?></p>
	<pre><?php echo esc_html( $snippet ); ?></pre>
<?php endif; ?>
</div>
