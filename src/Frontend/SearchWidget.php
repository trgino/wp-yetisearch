<?php
declare(strict_types=1);

namespace WpYetiSearch\Frontend;

/** Classic search-box widget. The input matches the typeahead selector, so suggestions attach automatically. */
final class SearchWidget extends \WP_Widget {

	public function __construct() {
		parent::__construct(
			'yetisearch',
			__( 'WP YetiSearch', 'wp-yetisearch' ),
			array( 'description' => __( 'Site search box with live suggestions.', 'wp-yetisearch' ) )
		);
	}

	public static function register(): void {
		add_action( 'widgets_init', array( self::class, 'registerWidget' ) );
	}

	public static function registerWidget(): void {
		register_widget( self::class );
	}

	/**
	 * @param array<string, mixed> $args
	 * @param array<string, mixed> $instance
	 * @return void
	 */
	public function widget( $args, $instance ) {
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- theme-provided widget wrappers, WP convention.
		echo $args['before_widget'];
		$title = isset( $instance['title'] ) && is_string( $instance['title'] ) ? $instance['title'] : '';
		if ( $title !== '' ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- theme-provided widget wrappers, WP convention.
			echo $args['before_title'];
			echo esc_html( $title );
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- theme-provided widget wrappers, WP convention.
			echo $args['after_title'];
		}
		?>
		<?php
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SearchBox::form escapes every part.
		echo SearchBox::form( $title, $this->get_field_id( 's' ) );
		?>
		<?php
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- theme-provided widget wrappers, WP convention.
		echo $args['after_widget'];
	}

	/**
	 * @param array<string, mixed> $instance
	 * @return void
	 */
	public function form( $instance ) {
		$title = isset( $instance['title'] ) && is_string( $instance['title'] ) ? $instance['title'] : '';
		?>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>"><?php echo esc_html__( 'Title:', 'wp-yetisearch' ); ?></label>
			<input class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'title' ) ); ?>" type="text" value="<?php echo esc_attr( $title ); ?>" />
		</p>
		<?php
	}

	/**
	 * @param array<string, mixed> $new_instance
	 * @param array<string, mixed> $old_instance
	 * @return array<string, mixed>
	 */
	public function update( $new_instance, $old_instance ) {
		$instance          = $old_instance;
		$instance['title'] = isset( $new_instance['title'] ) && is_string( $new_instance['title'] ) ? sanitize_text_field( $new_instance['title'] ) : '';
		return $instance;
	}
}
