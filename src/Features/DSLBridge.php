<?php
declare(strict_types=1);

namespace WpYetiSearch\Features;

use YetiSearch\DSL\URLQueryParser;
use YetiSearch\Models\SearchQuery;

/** JSON:API-style filter[...] and sort through the library parser, restricted to metadata.* fields. */
final class DSLBridge {

	public const MAX_LIMIT = 20;

	private const ALIASES = array(
		'post_type' => 'metadata.post_type',
		'author'    => 'metadata.author_id',
		'date'      => 'metadata.post_date',
		'price'     => 'metadata.price',
	);

	/** @param array<string, mixed> $params */
	public function applyFilters( array $params, SearchQuery $query ): SearchQuery {
		$input = array();
		if ( isset( $params['filter'] ) && is_array( $params['filter'] ) ) {
			$input['filter'] = $params['filter'];
		}
		if ( isset( $params['sort'] ) && is_string( $params['sort'] ) ) {
			$input['sort'] = $params['sort'];
		}
		if ( $input === array() ) {
			return $query;
		}

		$parsed = ( new URLQueryParser( self::ALIASES ) )->parse( $input + array( 'q' => '' ) );

		foreach ( $parsed->getFilters() as $filter ) {
			$field = (string) ( $filter['field'] ?? '' );
			if ( $field === 'metadata.post_type' ) {
				continue; // Owned by SearchService::frontQuery(); AND-ing a second one empties results.
			}
			if ( str_starts_with( $field, 'metadata.' ) ) {
				$query->filter( $field, $filter['value'], (string) $filter['operator'] );
			}
		}
		foreach ( $parsed->getSort() as $field => $direction ) {
			$field = self::ALIASES[ (string) $field ] ?? (string) $field;
			if ( str_starts_with( $field, 'metadata.' ) ) {
				$query->sortBy( $field, $direction === 'desc' ? 'desc' : 'asc' );
			}
		}
		return $query;
	}
}
