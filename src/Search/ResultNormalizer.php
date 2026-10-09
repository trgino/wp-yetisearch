<?php
declare(strict_types=1);

namespace WpYetiSearch\Search;

use WpYetiSearch\Core\Config;
use WpYetiSearch\Index\DocumentMapper;

/** Library results → safe, minimal items shared by QueryBridge, REST and CLI (spec §3.4). */
final class ResultNormalizer {

	public function __construct( private Config $config ) {
	}

	/**
	 * @param array<int, mixed> $results `results` of SearchResults::toArray()
	 * @return list<array{post_id: int, score: float, title: string, title_html: string, url: string, excerpt_html: string, post_type: string}>
	 */
	public function normalize( array $results ): array {
		$items = array();
		$seen  = array();
		foreach ( $results as $result ) {
			if ( ! is_array( $result ) ) {
				continue;
			}
			$metadata   = is_array( $result['metadata'] ?? null ) ? $result['metadata'] : array();
			$document   = is_array( $result['document'] ?? null ) ? $result['document'] : array();
			$highlights = is_array( $result['highlights'] ?? null ) ? $result['highlights'] : array();

			$postId = isset( $metadata['post_id'] ) && is_numeric( $metadata['post_id'] )
				? (int) $metadata['post_id']
				: DocumentMapper::postIdFromDocId( (string) ( $result['id'] ?? '' ) );
			if ( $postId <= 0 || isset( $seen[ $postId ] ) ) {
				continue;
			}
			$seen[ $postId ] = true;

			$title   = is_string( $document['title'] ?? null ) ? $document['title'] : '';
			$items[] = array(
				'post_id'      => $postId,
				'score'        => is_numeric( $result['score'] ?? null ) ? (float) $result['score'] : 0.0,
				'title'        => $title,
				'title_html'   => is_string( $highlights['title'] ?? null ) && $highlights['title'] !== ''
					? $this->safeHtml( $highlights['title'] )
					: esc_html( $title ),
				'url'          => esc_url_raw( is_string( $document['url'] ?? null ) ? $document['url'] : '' ),
				'excerpt_html' => $this->excerpt( $document, $highlights ),
				'post_type'    => sanitize_key( is_string( $metadata['post_type'] ?? null ) ? $metadata['post_type'] : '' ),
			);
		}
		return $items;
	}

	public function safeHtml( string $html ): string {
		$tag = $this->config->highlightTag();
		return str_replace(
			array( '&lt;' . $tag . '&gt;', '&lt;/' . $tag . '&gt;' ),
			array( '<' . $tag . '>', '</' . $tag . '>' ),
			esc_html( $html )
		);
	}

	/**
	 * Re-checks the database so stale index entries never expose private posts (spec §6.7).
	 *
	 * @template T of array{post_id: int}
	 * @param list<T> $items
	 * @return list<T>
	 */
	public function onlyPublic( array $items ): array {
		if ( $items === array() ) {
			return array();
		}
		$ids     = get_posts(
			array(
				'post__in'            => array_map( static fn ( array $item ): int => $item['post_id'], $items ),
				'post_type'           => $this->config->strings( 'indexed_post_types' ),
				'post_status'         => 'publish',
				'has_password'        => false,
				'fields'              => 'ids',
				'posts_per_page'      => count( $items ),
				'orderby'             => 'post__in',
				'ignore_sticky_posts' => true,
				'no_found_rows'       => true,
				'suppress_filters'    => true,
			)
		);
		$allowed = array_flip( array_map( 'intval', $ids ) );

		return array_values( array_filter( $items, static fn ( array $item ): bool => isset( $allowed[ $item['post_id'] ] ) ) );
	}

	/**
	 * @param array<string, mixed> $document
	 * @param array<string, mixed> $highlights
	 */
	private function excerpt( array $document, array $highlights ): string {
		foreach ( array( 'content', 'excerpt' ) as $field ) {
			if ( is_string( $highlights[ $field ] ?? null ) && trim( $highlights[ $field ] ) !== '' ) {
				return $this->safeHtml( $highlights[ $field ] );
			}
		}
		$excerpt = is_string( $document['excerpt'] ?? null ) ? $document['excerpt'] : '';
		$text    = $excerpt !== '' ? $excerpt : ( is_string( $document['content'] ?? null ) ? $document['content'] : '' );
		$length  = $this->config->int( 'snippet_length' );
		$snippet = mb_substr( $text, 0, $length );

		return esc_html( mb_strlen( $text ) > $length ? $snippet . '…' : $snippet );
	}
}
