<?php
declare(strict_types=1);

namespace WpYetiSearch\Core;

use WpYetiSearch\Features\SemanticBridge;

/** Activation / deactivation (spec §6.1). */
final class Lifecycle {

	public function __construct( private Container $container ) {
	}

	public function activate(): void {
		$storage = $this->container->get( 'storage' );
		$storage->ensureProtected();
		$storage->dbHash(); // Stable database identity from the first activation on.
		( new FileLog( $storage->storageDir() ) )->prune();

		$config = $this->container->get( 'config' );
		$health = $this->container->get( 'health' );
		$health->refresh( $storage->storageDir() );

		if ( $config->bool( 'semantic_enabled' ) && $config->bool( 'semantic_cron' ) ) {
			SemanticBridge::syncSchedule( $config );
		}
		HealthChecker::syncSchedule();
	}

	public function deactivate(): void {
		wp_clear_scheduled_hook( SemanticBridge::CRON_HOOK );
		wp_clear_scheduled_hook( HealthChecker::CRON_HOOK );
	}
}
