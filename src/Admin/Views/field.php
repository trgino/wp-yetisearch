<?php
/** Single schema field row.
 *
 * @var string $key
 * @var array<string, mixed> $field
 * @var \WpYetiSearch\Core\Config $config
 */
declare(strict_types=1);

use WpYetiSearch\Admin\FieldInput;
use WpYetiSearch\Admin\FieldLabels;
use WpYetiSearch\Core\SettingsSchema;

$meta      = FieldLabels::label( $key );
$fieldType = (string) ( $field['type'] ?? 'string' );
$value     = $config->get( $key );
$name      = 'yetisearch[' . $key . ']';

$options = array();
if ( isset( $field['options'] ) && is_array( $field['options'] ) ) {
	$options = array_map( 'strval', $field['options'] );
} elseif ( ( $field['source'] ?? '' ) === 'post_types' ) {
	$options = array_map( 'strval', array_keys( get_post_types( array( 'public' => true ), 'names' ) ) );
} elseif ( ( $field['source'] ?? '' ) === 'taxonomies' ) {
	$options = array_map( 'strval', array_keys( get_taxonomies( array( 'public' => true ), 'names' ) ) );
} elseif ( ( $field['source'] ?? '' ) === 'search_fields' ) {
	$options = SettingsSchema::SEARCH_FIELDS;
}

$textarea = '';
if ( $fieldType === 'list' && is_array( $value ) ) {
	$textarea = implode( "\n", array_map( 'strval', $value ) );
} elseif ( $fieldType === 'map' && is_array( $value ) ) {
	$lines = array();
	foreach ( $value as $termName => $list ) {
		$lines[] = (string) $termName . ': ' . implode( ', ', array_map( 'strval', (array) $list ) );
	}
	$textarea = implode( "\n", $lines );
}
?>
<table class="form-table yetisearch-field" role="presentation">
	<tr>
		<th scope="row"><label for="yetisearch-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $meta['label'] ); ?></label></th>
		<td>
			<?php if ( $fieldType === 'bool' ) : ?>
				<input type="checkbox" id="yetisearch-<?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( $name ); ?>" value="1" <?php checked( ! empty( $value ) ); ?> />
			<?php elseif ( $fieldType === 'select' ) : ?>
				<select id="yetisearch-<?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( $name ); ?>">
					<?php foreach ( $options as $option ) : ?>
						<option value="<?php echo esc_attr( $option ); ?>" <?php selected( (string) $value === $option ); ?>><?php echo esc_html( $option ); ?></option>
					<?php endforeach; ?>
				</select>
			<?php elseif ( $fieldType === 'multiselect' ) : ?>
				<?php $selected = is_array( $value ) ? array_map( 'strval', $value ) : array(); ?>
				<?php foreach ( $options as $option ) : ?>
					<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[]" value="<?php echo esc_attr( $option ); ?>" <?php checked( in_array( $option, $selected, true ) ); ?> /> <?php echo esc_html( $option ); ?></label><br />
				<?php endforeach; ?>
			<?php elseif ( $fieldType === 'list' || $fieldType === 'map' ) : ?>
				<textarea id="yetisearch-<?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( $name ); ?>" rows="4" cols="50"><?php echo esc_textarea( $textarea ); ?></textarea>
			<?php elseif ( $fieldType === 'password' ) : ?>
				<input type="password" id="yetisearch-<?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( $name ); ?>" value="" autocomplete="new-password" class="regular-text" />
				<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>_clear" value="1" /> <?php echo esc_html__( 'Clear stored key', 'wp-yetisearch' ); ?></label>
			<?php elseif ( $fieldType === 'int' || $fieldType === 'float' ) : ?>
				<input type="number" id="yetisearch-<?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( (string) $value ); ?>"
					<?php echo isset( $field['min'] ) ? 'min="' . esc_attr( (string) $field['min'] ) . '"' : ''; ?>
					<?php echo isset( $field['max'] ) ? 'max="' . esc_attr( (string) $field['max'] ) . '"' : ''; ?>
					<?php echo $fieldType === 'float' ? 'step="any"' : 'step="1"'; ?> />
			<?php else : ?>
				<input type="text" id="yetisearch-<?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( FieldInput::textValue( $fieldType, $value ) ); ?>" class="regular-text" />
			<?php endif; ?>
			<?php if ( $meta['help'] !== '' ) : ?>
				<p class="description"><?php echo esc_html( $meta['help'] ); ?></p>
			<?php endif; ?>
		</td>
	</tr>
</table>
