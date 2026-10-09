<?php
declare(strict_types=1);

namespace WpYetiSearch\Features;

use WpYetiSearch\Core\Config;
use YetiSearch\Geo\GeoPoint;
use YetiSearch\Models\SearchQuery;

/** Radius filter, distance sort and distance facet.
 * Works without R-Tree too: since yetisearch 2.5.4 the library runs the same
 * queries on the plain spatial table — exact results, just slower. */
final class GeoBridge {

	public function __construct( private Config $config, private bool $rtree ) {
	}

	public function isEnabled(): bool {
		return $this->config->bool( 'geo_enabled' );
	}

	/** True when geo runs without R-Tree: exact results, slower queries. */
	public function isUnindexed(): bool {
		return $this->isEnabled() && ! $this->rtree;
	}

	public function apply( SearchQuery $query, float $lat, float $lng, ?float $radius = null ): SearchQuery {
		if ( $lat < -90.0 || $lat > 90.0 || $lng < -180.0 || $lng > 180.0 ) {
			throw new \InvalidArgumentException( 'Invalid coordinates.' );
		}
		$unit   = $this->config->string( 'geo_unit' );
		$radius = $radius !== null && $radius > 0 ? $radius : $this->config->float( 'geo_default_radius' );
		$point  = new GeoPoint( $lat, $lng );

		$query->near( $point, $radius * ( $unit === 'mi' ? 1609.344 : 1000.0 ) );
		$query->sortByDistance( $point, 'asc' );

		if ( $this->config->bool( 'facets_enabled' ) ) {
			$ranges = array_values( array_map( 'floatval', (array) $this->config->get( 'geo_distance_ranges' ) ) );
			if ( $ranges !== array() ) {
				$query->facet(
					'distance',
					array(
						'from'   => $point,
						'ranges' => $ranges,
						'units'  => $unit,
					)
				);
			}
		}
		return $query;
	}
}
