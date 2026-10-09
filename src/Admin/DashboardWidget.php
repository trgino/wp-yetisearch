<?php
declare(strict_types=1);

namespace WpYetiSearch\Admin;

use WpYetiSearch\Core\HealthChecker;
use WpYetiSearch\Search\SearchService;

/** Dashboard status widget (read-only; no writes, capability-checked). */
final class DashboardWidget {

	public const WIDGET_ID = 'yetisearch_status';

	public function __construct(
		private HealthChecker $health,
		private SearchService $search
	) {
	}

	public function register(): void {
		add_action( 'wp_dashboard_setup', array( $this, 'addWidget' ) );
	}

	public function addWidget(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		wp_add_dashboard_widget(
			self::WIDGET_ID,
			__( 'WP YetiSearch', 'wp-yetisearch' ),
			array( $this, 'render' )
		);
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$ready = $this->health->isReady();
		$stats = $this->search->indexStats();
		$docs  = isset( $stats['documents'] ) ? (int) $stats['documents'] : 0;
		$last  = (int) get_option( 'yetisearch_last_reindex', 0 );
		?>
		<p>
			<span class="yetisearch-dot <?php echo $ready ? 'yetisearch-dot--ok' : 'yetisearch-dot--bad'; ?>" aria-hidden="true"></span>
			<?php echo $ready ? esc_html__( 'Engine ready', 'wp-yetisearch' ) : esc_html__( 'Engine not ready', 'wp-yetisearch' ); ?>
		</p>
		<p><?php echo esc_html( sprintf( /* translators: %d: number of indexed documents */ __( '%d documents indexed', 'wp-yetisearch' ), $docs ) ); ?></p>
		<p>
			<?php
			echo $last > 0
				? esc_html( sprintf( /* translators: %s: human-readable time difference, e.g. "2 hours" */ __( 'Last reindexed %s ago', 'wp-yetisearch' ), human_time_diff( $last, time() ) ) )
				: esc_html__( 'Never reindexed', 'wp-yetisearch' );
			?>
		</p>
		<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=' . SettingsPage::MENU_SLUG ) ); ?>"><?php echo esc_html__( 'Open settings', 'wp-yetisearch' ); ?></a></p>
		<?php
	}
}
