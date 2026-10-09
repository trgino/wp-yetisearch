<?php
/** YetiSearch settings page layout.
 *
 * @var string $tab
 * @var \WpYetiSearch\Core\Config $config
 * @var array{ready: bool, checks: array<string, array{ok: bool, value: string}>}|null $health
 * @var bool $ready
 * @var string $dbPath
 * @var string|null $dirUrl
 * @var string $server
 * @var string $snippet
 * @var int $docCount
 * @var int $lastReindex
 */
declare(strict_types=1);

use WpYetiSearch\Admin\FieldLabels;
use WpYetiSearch\Admin\SettingsPage;
use WpYetiSearch\Core\SettingsSchema;

$navTabs    = FieldLabels::tabs();
$formAction = admin_url( 'admin.php?page=' . SettingsPage::MENU_SLUG . '&tab=' . $tab );
?>
<div class="wrap yetisearch-admin">
	<h1><?php echo esc_html__( 'WP YetiSearch', 'wp-yetisearch' ); ?></h1>
	<div class="yetisearch-strip" id="yetisearch-strip" role="status">
		<span class="yetisearch-strip-status">
			<span class="yetisearch-dot <?php echo $ready ? 'yetisearch-dot--ok' : 'yetisearch-dot--bad'; ?>" aria-hidden="true"></span>
			<?php echo $ready ? esc_html__( 'Engine ready', 'wp-yetisearch' ) : esc_html__( 'Engine not ready', 'wp-yetisearch' ); ?>
		</span>
		<span class="yetisearch-strip-docs"><?php echo esc_html( sprintf( /* translators: %d: number of indexed documents */ __( '%d documents', 'wp-yetisearch' ), $docCount ) ); ?></span>
		<span class="yetisearch-strip-reindexed">
			<?php
			echo $lastReindex > 0
				? esc_html( sprintf( /* translators: %s: human-readable time difference, e.g. "2 hours" */ __( 'Reindexed %s ago', 'wp-yetisearch' ), human_time_diff( $lastReindex, time() ) ) )
				: esc_html__( 'Never reindexed', 'wp-yetisearch' );
			?>
		</span>
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . SettingsPage::MENU_SLUG . '&tab=maintenance' ) ); ?>"><?php echo esc_html__( 'Maintenance', 'wp-yetisearch' ); ?></a>
	</div>
	<?php if ( (bool) get_option( \WpYetiSearch\Core\Config::NEEDS_REINDEX_OPTION, false ) ) : ?>
		<div class="notice notice-warning inline"><p><?php echo esc_html__( 'Index schema changed — please re-index on the Maintenance tab.', 'wp-yetisearch' ); ?></p></div>
	<?php endif; ?>
	<h2 class="nav-tab-wrapper">
		<?php foreach ( $navTabs as $key => $label ) : ?>
			<a class="nav-tab<?php echo $key === $tab ? ' nav-tab-active' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=' . SettingsPage::MENU_SLUG . '&tab=' . $key ) ); ?>"><?php echo esc_html( $label ); ?></a>
		<?php endforeach; ?>
	</h2>
	<form method="post" action="<?php echo esc_url( $formAction ); ?>">
		<?php wp_nonce_field( SettingsPage::NONCE ); ?>
		<?php
		if ( $tab === 'general' ) {
			require __DIR__ . '/tab-general.php';
		} elseif ( $tab === 'maintenance' ) {
			require __DIR__ . '/tab-maintenance.php';
		} else {
			?>
			<div class="card yetisearch-card">
			<?php
			foreach ( SettingsSchema::fieldsForTab( $tab ) as $key => $field ) {
				require __DIR__ . '/field.php';
			}
			?>
			</div>
			<?php
		}
		?>
		<?php if ( $tab !== 'maintenance' ) : ?>
			<?php submit_button(); ?>
		<?php endif; ?>
	</form>
</div>
