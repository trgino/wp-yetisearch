<?php
declare(strict_types=1);

namespace WpYetiSearch\Features;

use WpYetiSearch\Core\Config;
use YetiSearch\Models\SearchQuery;

/** Scalar facets only: post type and the primary term per taxonomy (spec §3.6). */
final class FacetBridge {

	/** @var array<string, string> Range facet name => counted field. */
	private array $rangeFields = array();

	public function __construct( private Config $config ) {
	}

	public function apply( SearchQuery $query ): SearchQuery {
		if ( ! $this->config->bool( 'facets_enabled' ) ) {
			return $query;
		}
		$options = array( 'limit' => $this->config->int( 'facet_limit' ) );
		if ( $this->config->bool( 'facet_post_type' ) ) {
			$query->facet( 'post_type', $options );
		}
		foreach ( $this->config->strings( 'facet_taxonomies' ) as $taxonomy ) {
			$query->facet( 'facet_' . $taxonomy, $options );
		}
		if ( $this->config->bool( 'price_facet_enabled' ) ) {
			$thresholds = array();
			foreach ( (array) $this->config->get( 'price_ranges' ) as $threshold ) {
				if ( is_numeric( $threshold ) && (float) $threshold > 0 ) {
					$thresholds[] = (float) $threshold;
				}
			}
			sort( $thresholds );
			if ( $thresholds !== array() ) {
				$pairs = array( array( 'to' => $thresholds[0] ) );
				for ( $i = 1, $n = count( $thresholds ); $i < $n; $i++ ) {
					$pairs[] = array(
						'from' => $thresholds[ $i - 1 ],
						'to'   => $thresholds[ $i ],
					);
				}
				$pairs[] = array( 'from' => $thresholds[ count( $thresholds ) - 1 ] );
				$this->applyRange( $query, 'price_range', 'price', $pairs );
			}
		}
		return $query;
	}

	/**
	 * Numeric range facet via the library bucketing (yetisearch 2.5.3+).
	 * Each range is ['from' => float|null, 'to' => float|null] (`from`
	 * inclusive, `to` exclusive); the library counts, orders and returns
	 * empty buckets itself.
	 *
	 * @param array<int, array{from?: float|int|null, to?: float|int|null}> $ranges
	 */
	public function applyRange( SearchQuery $query, string $facet, string $field, array $ranges ): SearchQuery {
		$ranges = array_values( $ranges );
		if ( $ranges === array() ) {
			return $query;
		}
		$this->rangeFields[ $facet ] = $field;
		$query->facet(
			$facet,
			array(
				'field'  => $field,
				'ranges' => $ranges,
			)
		);
		return $query;
	}

	/**
	 * Adds clickable-filter specs to range buckets so themes can link them
	 * without knowing the field mapping: each bucket gains
	 * `filter[field] = {gte?, lte?}` (DSL shape). Scalar facets pass through.
	 *
	 * @param array<string, mixed> $facets
	 * @return array<string, mixed>
	 */
	public function enrichFacets( array $facets ): array {
		foreach ( $this->rangeFields as $name => $field ) {
			if ( ! isset( $facets[ $name ] ) || ! is_array( $facets[ $name ] ) ) {
				continue;
			}
			foreach ( $facets[ $name ] as $i => $bucket ) {
				if ( ! is_array( $bucket ) ) {
					continue;
				}
				$filter = array();
				if ( isset( $bucket['from'] ) && is_numeric( $bucket['from'] ) ) {
					$filter['gte'] = $bucket['from'] + 0;
				}
				if ( isset( $bucket['to'] ) && is_numeric( $bucket['to'] ) ) {
					$filter['lte'] = $bucket['to'] + 0;
				}
				if ( $filter !== array() ) {
					$facets[ $name ][ $i ]['filter'] = array( $field => $filter );
				}
			}
		}
		return $facets;
	}
}
