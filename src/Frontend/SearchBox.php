<?php
declare(strict_types=1);

namespace WpYetiSearch\Frontend;

/** Shared search-box markup for the classic widget and the block. */
final class SearchBox {

	public static function form( string $title, string $fieldId ): string {
		$html = '';
		if ( $title !== '' ) {
			$html .= '<p class="yetisearch-box-title">' . esc_html( $title ) . '</p>';
		}
		$html .= '<form role="search" method="get" action="' . esc_url( home_url( '/' ) ) . '">';
		$html .= '<label class="screen-reader-text" for="' . esc_attr( $fieldId ) . '">' . esc_html__( 'Search', 'wp-yetisearch' ) . '</label>';
		$html .= '<input type="search" id="' . esc_attr( $fieldId ) . '" name="s" placeholder="' . esc_attr__( 'Search…', 'wp-yetisearch' ) . '" />';
		$html .= '<button type="submit">' . esc_html__( 'Search', 'wp-yetisearch' ) . '</button>';
		$html .= '</form>';
		return $html;
	}
}
