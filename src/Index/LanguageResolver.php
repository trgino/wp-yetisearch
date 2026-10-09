<?php
declare(strict_types=1);

namespace WpYetiSearch\Index;

use WpYetiSearch\Core\Config;

/**
 * Resolves post/request languages to index names (Task 17).
 * Default language reuses the legacy `wp_posts` tables (no migration);
 * other languages get `wp_posts_{lang}` in the same SQLite file.
 */
final class LanguageResolver {

	public function postLanguage( \WP_Post $post ): ?string {
		if ( function_exists( 'pll_get_post_language' ) ) {
			$lang = pll_get_post_language( $post->ID );
			return is_string( $lang ) && $lang !== '' ? sanitize_key( $lang ) : null;
		}
		if ( function_exists( 'apply_filters' ) && function_exists( 'has_filter' ) && has_filter( 'wpml_element_language_code' ) ) {
			$lang = apply_filters(
				'wpml_element_language_code',
				null,
				array(
					'element_id'   => $post->ID,
					'element_type' => 'post_' . $post->post_type,
				)
			);
			return is_string( $lang ) && $lang !== '' ? sanitize_key( $lang ) : null;
		}
		return null;
	}

	public function defaultLanguage(): string {
		if ( function_exists( 'pll_default_language' ) ) {
			$lang = pll_default_language();
			if ( is_string( $lang ) && $lang !== '' ) {
				return sanitize_key( $lang );
			}
		}
		if ( function_exists( 'apply_filters' ) && function_exists( 'has_filter' ) && has_filter( 'wpml_default_language' ) ) {
			$lang = apply_filters( 'wpml_default_language', null );
			if ( is_string( $lang ) && $lang !== '' ) {
				return sanitize_key( $lang );
			}
		}
		if ( function_exists( 'get_locale' ) ) {
			$locale = strtolower( (string) get_locale() );
			$code   = (string) preg_replace( '/[^a-z].*/', '', $locale );
			if ( $code !== '' ) {
				return $code;
			}
		}
		return 'en';
	}

	public function currentLanguage(): ?string {
		if ( function_exists( 'pll_current_language' ) ) {
			$lang = pll_current_language();
			return is_string( $lang ) && $lang !== '' ? sanitize_key( $lang ) : null;
		}
		if ( function_exists( 'apply_filters' ) && function_exists( 'has_filter' ) && has_filter( 'wpml_current_language' ) ) {
			$lang = apply_filters( 'wpml_current_language', null );
			return is_string( $lang ) && $lang !== '' ? sanitize_key( $lang ) : null;
		}
		return null;
	}

	public function indexForPost( \WP_Post $post ): string {
		return $this->indexForCode( $this->postLanguage( $post ) );
	}

	public function indexForCurrent(): string {
		return $this->indexForCode( $this->currentLanguage() );
	}

	public function indexForCode( ?string $lang ): string {
		if ( $lang === null || $lang === '' || $lang === $this->defaultLanguage() ) {
			return Config::INDEX;
		}
		return Config::INDEX . '_' . sanitize_key( $lang );
	}

	public static function isPluginIndex( string $name ): bool {
		return $name === Config::INDEX || str_starts_with( $name, Config::INDEX . '_' );
	}
}
