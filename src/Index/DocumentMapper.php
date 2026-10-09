<?php
declare(strict_types=1);

namespace WpYetiSearch\Index;

use WpYetiSearch\Core\Config;

/** WP_Post → library document (spec §3.2). */
final class DocumentMapper {

	private LanguageResolver $languages;

	public function __construct( private Config $config, ?LanguageResolver $languages = null ) {
		$this->languages = $languages ?? new LanguageResolver();
	}

	public function indexFor( \WP_Post $post ): string {
		return $this->languages->indexForPost( $post );
	}

	/** Content language code an index holds (suffix, else the default language). */
	public function languageForIndex( string $index ): string {
		return $this->languages->languageForIndex( $index );
	}

	/** Creation settings for an index: stemming on, in its content language. */
	public function creationSettings( string $index ): array {
		return array(
			'stemming' => true,
			'language' => $this->languageForIndex( $index ),
		);
	}

	/**
	 * Make sure an index exists with stemming in its language, healing
	 * pre-2.6 indexes via rebuildFts (no re-crawl needed). Throws on
	 * engine failure; callers already convert that to logged errors.
	 */
	public function ensureIndex( ?\YetiSearch\YetiSearch $yeti, string $index, bool $heal = true ): void {
		if ( $yeti === null || ! LanguageResolver::isPluginIndex( $index ) ) {
			return;
		}
		$settings = $this->creationSettings( $index );
		try {
			$names = array();
			foreach ( $yeti->listIndices() as $info ) {
				$name = is_array( $info ) ? ( $info['name'] ?? null ) : null;
				if ( is_string( $name ) ) {
					$names[] = $name;
				}
			}
		} catch ( \Throwable ) {
			$yeti->createIndex( $index, $settings );
			return;
		}
		if ( ! in_array( $index, $names, true ) ) {
			$yeti->createIndex( $index, $settings );
			$this->recordStemmed( $index, $settings['language'] );
			return;
		}
		if ( ! $heal ) {
			return;
		}
		$recorded = get_option( Config::STEMMED_INDEXES_OPTION, array() );
		$recorded = is_array( $recorded ) ? $recorded : array();
		if ( ( $recorded[ $index ] ?? null ) !== $settings['language'] ) {
			$yeti->rebuildFts( $index, $settings );
			$this->recordStemmed( $index, $settings['language'] );
		}
	}

	private function recordStemmed( string $index, string $language ): void {
		$recorded           = get_option( Config::STEMMED_INDEXES_OPTION, array() );
		$recorded           = is_array( $recorded ) ? $recorded : array();
		$recorded[ $index ] = $language;
		update_option( Config::STEMMED_INDEXES_OPTION, $recorded, false );
	}

	public function isIndexable( \WP_Post $post ): bool {
		return $post->post_status === 'publish'
			&& $post->post_password === ''
			&& in_array( $post->post_type, $this->config->strings( 'indexed_post_types' ), true );
	}

	/** @return array<string, mixed>|null */
	public function map( \WP_Post $post ): ?array {
		if ( ! $this->isIndexable( $post ) ) {
			return null;
		}

		$indexedTaxonomies = $this->config->strings( 'indexed_taxonomies' );
		$facetTaxonomies   = $this->config->bool( 'facets_enabled' ) ? $this->config->strings( 'facet_taxonomies' ) : array();
		$terms             = array();
		$facets            = array();
		foreach ( array_unique( array( ...$indexedTaxonomies, ...$facetTaxonomies ) ) as $taxonomy ) {
			$list = get_the_terms( $post->ID, $taxonomy );
			if ( ! is_array( $list ) || $list === array() ) {
				continue;
			}
			$names = array_values( array_map( static fn ( $term ): string => (string) $term->name, $list ) );
			if ( in_array( $taxonomy, $indexedTaxonomies, true ) ) {
				$terms = array( ...$terms, ...$names );
			}
			if ( in_array( $taxonomy, $facetTaxonomies, true ) ) {
				$facets[ 'facet_' . $taxonomy ] = $names[0];
			}
		}

		$meta = array();
		foreach ( $this->config->strings( 'indexed_meta_keys' ) as $key ) {
			foreach ( (array) get_post_meta( $post->ID, $key, false ) as $value ) {
				$meta[] = self::flatten( $value );
			}
		}
		if ( $post->post_type === 'product' ) {
			$meta[] = self::flatten( get_post_meta( $post->ID, '_sku', true ) );
		}

		$url = get_permalink( $post->ID );

		return $this->build( $post, $terms, $meta, $facets, $this->geo( $post ), is_string( $url ) ? $url : '', $this->price( $post ) );
	}

	/**
	 * @param list<string>                       $terms
	 * @param list<string>                       $meta
	 * @param array<string, string>              $facets
	 * @param array{lat: float, lng: float}|null $geo
	 * @return array<string, mixed>
	 */
	public function build( \WP_Post $post, array $terms, array $meta, array $facets, ?array $geo, string $url, ?float $price = null ): array {
		$parsedDate = strtotime( $post->post_date );
		$document   = array(
			'id'        => (string) $post->ID,
			'content'   => array(
				'title'      => self::clean( $post->post_title ),
				'content'    => self::clean( strip_shortcodes( $post->post_content ) ),
				'excerpt'    => self::clean( $post->post_excerpt ),
				'taxonomies' => implode( ' ', array_unique( array_map( array( self::class, 'clean' ), $terms ) ) ),
				'meta'       => implode( ' ', array_filter( array_unique( array_map( array( self::class, 'clean' ), $meta ) ), static fn ( string $v ): bool => $v !== '' ) ),
				'url'        => $url,
				'route'      => self::route( $post->ID ),
			),
			'metadata'  => array(
				'post_id'   => $post->ID,
				'post_type' => $post->post_type,
				'post_date' => $post->post_date,
				'author_id' => (int) $post->post_author,
			) + $facets,
			'type'      => $post->post_type,
			'timestamp' => $parsedDate !== false ? $parsedDate : time(),
		);

		$language = $this->languages->postLanguage( $post ) ?? $this->config->stemmerLanguage();
		if ( $language !== null && $language !== '' ) {
			$document['language'] = $language;
		}
		if ( $geo !== null ) {
			$document['geo'] = $geo;
		}
		if ( $price !== null ) {
			$document['metadata']['price'] = $price;
		}

		return $document;
	}

	public static function route( int $postId ): string {
		return '/?p=' . $postId;
	}

	public static function postIdFromDocId( string $id ): int {
		return (int) explode( '#', $id, 2 )[0];
	}

	/** Numeric price for range faceting; null when missing or non-numeric. */
	private function price( \WP_Post $post ): ?float {
		$key = $this->config->string( 'price_meta_key' );
		if ( $key === '' ) {
			return null;
		}
		$raw = get_post_meta( $post->ID, $key, true );
		return is_numeric( $raw ) ? (float) $raw : null;
	}

	/** @return array{lat: float, lng: float}|null */
	private function geo( \WP_Post $post ): ?array {
		if ( ! $this->config->bool( 'geo_enabled' ) ) {
			return null;
		}
		$lat = get_post_meta( $post->ID, $this->config->string( 'geo_lat_meta_key' ), true );
		$lng = get_post_meta( $post->ID, $this->config->string( 'geo_lng_meta_key' ), true );
		if ( ! is_numeric( $lat ) || ! is_numeric( $lng ) || abs( (float) $lat ) > 90 || abs( (float) $lng ) > 180 ) {
			return null;
		}
		return array(
			'lat' => (float) $lat,
			'lng' => (float) $lng,
		);
	}

	private static function clean( string $text ): string {
		$text = html_entity_decode( wp_strip_all_tags( $text ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		return trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
	}

	private static function flatten( mixed $value ): string {
		if ( is_scalar( $value ) ) {
			return (string) $value;
		}
		if ( is_array( $value ) ) {
			return implode( ' ', array_map( array( self::class, 'flatten' ), $value ) );
		}
		return '';
	}
}
