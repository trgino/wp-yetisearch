<?php
declare(strict_types=1);

namespace WpYetiSearch\Admin;

/** Formats schema values for single-line text inputs (spec §7). */
final class FieldInput {

	/**
	 * Render a value for `<input type="text">`.
	 *
	 * `number_list` has no dedicated widget: comma-joined text round-trips
	 * through `SettingsSchema::sanitize()` (`numbers()` splits on [\s,]+),
	 * so saving the form preserves the defaults instead of wiping them.
	 */
	public static function textValue( string $type, mixed $value ): string {
		if ( $type === 'number_list' && is_array( $value ) ) {
			return implode( ', ', array_map( 'strval', $value ) );
		}

		return is_scalar( $value ) ? (string) $value : '';
	}
}
