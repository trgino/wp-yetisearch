<?php
/**
 * Theme-facing template tags. Plain functions: no container access.
 */

declare(strict_types=1);

if ( ! function_exists( 'yetisearch_get_suggestion' ) ) {
	/** "Did you mean" suggestion of a search query, or '' (spec §6.4). */
	function yetisearch_get_suggestion( ?WP_Query $query = null ): string {
		$query ??= $GLOBALS['wp_query'] ?? null;
		if ( ! $query instanceof WP_Query ) {
			return '';
		}
		$suggestion = $query->get( 'yetisearch_suggestion' );

		return is_string( $suggestion ) ? $suggestion : '';
	}
}

if ( ! function_exists( 'yetisearch_the_suggestion' ) ) {
	/** Prints "Did you mean: <a>term</a>" when the engine has a suggestion. */
	function yetisearch_the_suggestion(): void {
		$suggestion = yetisearch_get_suggestion();
		if ( $suggestion === '' ) {
			return;
		}
		printf(
			'<p class="yetisearch-suggestion">%s <a href="%s">%s</a></p>',
			esc_html__( 'Did you mean:', 'wp-yetisearch' ),
			esc_url( get_search_link( $suggestion ) ),
			esc_html( $suggestion )
		);
	}
}

if ( ! function_exists( 'yetisearch_get_facet_links' ) ) {
	/**
	 * Clickable range-bucket links for a facet ("Under 100 (1)" ...).
	 * Buckets come from the `yetisearch_facets` query var (with `filter`
	 * specs added by FacetBridge::enrichFacets); links keep the current
	 * search term and apply only that bucket's filter.
	 */
	function yetisearch_get_facet_links( string $facet, ?WP_Query $query = null ): string {
		$query ??= $GLOBALS['wp_query'] ?? null;
		if ( ! $query instanceof WP_Query ) {
			return '';
		}
		$facets = $query->get( 'yetisearch_facets' );
		if ( ! is_array( $facets ) || ! isset( $facets[ $facet ] ) || ! is_array( $facets[ $facet ] ) ) {
			return '';
		}
		$term  = $query->get( 's' );
		$term  = is_string( $term ) ? $term : '';
		$items = '';
		foreach ( $facets[ $facet ] as $bucket ) {
			if ( ! is_array( $bucket ) || ! isset( $bucket['filter'] ) || ! is_array( $bucket['filter'] ) ) {
				continue;
			}
			$label  = yetisearch_facet_bucket_label( $bucket );
			$url    = esc_url(
				add_query_arg(
					array(
						's'      => $term,
						'filter' => $bucket['filter'],
					),
					home_url( '/' )
				)
			);
			$items .= sprintf(
				'<li class="yetisearch-facet-option"><a href="%s">%s (%d)</a></li>',
				$url,
				esc_html( $label ),
				isset( $bucket['count'] ) ? (int) $bucket['count'] : 0
			);
		}
		if ( $items === '' ) {
			return '';
		}
		return '<ul class="yetisearch-facet-links yetisearch-facet-' . esc_attr( $facet ) . '">' . $items . '</ul>';
	}
}

if ( ! function_exists( 'yetisearch_the_facet_links' ) ) {
	/** Prints clickable range-bucket links for a facet, if any. */
	function yetisearch_the_facet_links( string $facet ): void {
		$html = yetisearch_get_facet_links( $facet );
		if ( $html !== '' ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- yetisearch_get_facet_links escapes every part.
			echo $html;
		}
	}
}

if ( ! function_exists( 'yetisearch_facet_bucket_label' ) ) {
	/** Human label for a range bucket: "Under 100", "100 – 500", "500+". */
	function yetisearch_facet_bucket_label( array $bucket ): string {
		$from = isset( $bucket['from'] ) && is_numeric( $bucket['from'] ) ? $bucket['from'] + 0 : null;
		$to   = isset( $bucket['to'] ) && is_numeric( $bucket['to'] ) ? $bucket['to'] + 0 : null;
		if ( $from === null && $to === null ) {
			return isset( $bucket['value'] ) && is_string( $bucket['value'] ) ? $bucket['value'] : '';
		}
		if ( $from === null ) {
			/* translators: %s: upper bound */
			return sprintf( __( 'Under %s', 'wp-yetisearch' ), yetisearch_facet_format_number( $to ) );
		}
		if ( $to === null ) {
			/* translators: %s: lower bound */
			return sprintf( __( '%s+', 'wp-yetisearch' ), yetisearch_facet_format_number( $from ) );
		}
		return yetisearch_facet_format_number( $from ) . ' – ' . yetisearch_facet_format_number( $to );
	}
}

if ( ! function_exists( 'yetisearch_facet_format_number' ) ) {
	/** 100.0 displays as "100", 99.95 as "99.95". */
	function yetisearch_facet_format_number( mixed $value ): string {
		$number = (float) $value;
		if ( $number === floor( $number ) ) {
			return (string) (int) $number;
		}
		return rtrim( rtrim( sprintf( '%.4F', $number ), '0' ), '.' );
	}
}
