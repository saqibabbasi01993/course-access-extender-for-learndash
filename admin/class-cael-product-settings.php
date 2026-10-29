<?php
/**
 * Product Settings Class
 *
 * Adds "Course Extension" tab to the WooCommerce product edit screen.
 * Each product can define its own access duration in days, overriding
 * the global plugin setting to enable 30-day, 40-day, 90-day products.
 *
 * @package SKFreelancers\LearnDashCourseExtension
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CAEL_Product_Settings
 */
class CAEL_Product_Settings {

	/** @var CAEL_Product_Settings|null */
	private static $instance = null;

	/** @return CAEL_Product_Settings */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_filter( 'woocommerce_product_data_tabs', array( $this, 'add_tab' ) );
		add_action( 'woocommerce_product_data_panels', array( $this, 'render_panel' ) );
		add_action( 'woocommerce_process_product_meta', array( $this, 'save_meta' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_product_assets' ) );
	}

	/**
	 * Register the product data tab.
	 *
	 * @param array $tabs Existing product data tabs.
	 * @return array
	 */
	public function add_tab( $tabs ) {
		$tabs['cael_course_extension'] = array(
			'label'    => __( 'Course Extension', 'course-access-extender-for-learndash' ),
			'target'   => 'cael_course_extension_data',
			'class'    => array(),
			'priority' => 75,
		);
		return $tabs;
	}

	/**
	 * Render the Course Extension panel inside the WooCommerce Product Data metabox.
	 */
	public function render_panel() {
		global $post;

		$is_extension  = get_post_meta( $post->ID, '_cael_is_course_extension', true );
		// Migration fallback: read old _ldce_ key if new key not yet saved.
		if ( '' === $is_extension ) {
			$is_extension = get_post_meta( $post->ID, '_ldce_is_course_extension', true );
		}

		$linked_course = absint( get_post_meta( $post->ID, '_cael_linked_course_id', true ) );
		// Migration fallback: read old _ldce_ key if new key not yet saved.
		if ( ! $linked_course ) {
			$linked_course = absint( get_post_meta( $post->ID, '_ldce_linked_course_id', true ) );
		}

		$saved_days  = get_post_meta( $post->ID, '_cael_access_days', true );
		$custom_days = ( '' !== $saved_days ) ? absint( $saved_days ) : absint( get_option( 'cael_extension_days', 90 ) );

		$courses = get_posts(
			array(
				'post_type'      => 'sfwd-courses',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);
		?>
		<div id="cael_course_extension_data" class="panel woocommerce_options_panel">
			<div class="options_group">

				<?php
				woocommerce_wp_checkbox(
					array(
						'id'          => '_cael_is_course_extension',
						'label'       => __( 'Course Extension Product', 'course-access-extender-for-learndash' ),
						'description' => __( 'Enable to treat this product as a Course Extension. Purchasing it will extend the linked LearnDash course access by the number of days set below.', 'course-access-extender-for-learndash' ),
						'value'       => ( 'yes' === $is_extension ) ? 'yes' : 'no',
					)
				);
				?>

				<p class="form-field _cael_linked_course_id_field">
					<label for="_cael_linked_course_id">
						<?php esc_html_e( 'Linked LearnDash Course', 'course-access-extender-for-learndash' ); ?>
					</label>
					<select id="_cael_linked_course_id" name="_cael_linked_course_id" class="select short">
						<option value=""><?php esc_html_e( '— Select a Course —', 'course-access-extender-for-learndash' ); ?></option>
						<?php foreach ( $courses as $course ) : ?>
							<option value="<?php echo esc_attr( $course->ID ); ?>" <?php selected( $linked_course, $course->ID ); ?>>
								<?php echo esc_html( $course->post_title ); ?>
								(ID: <?php echo absint( $course->ID ); ?>)
							</option>
						<?php endforeach; ?>
					</select>
					<?php
					echo wp_kses_post(
						wc_help_tip(
							__( 'The LearnDash course whose access should be extended when this product is purchased.', 'course-access-extender-for-learndash' )
						)
					);
					?>
				</p>

				<?php
				woocommerce_wp_text_input(
					array(
						'id'                => '_cael_access_days',
						'label'             => __( 'Access Days', 'course-access-extender-for-learndash' ),
						'description'       => __( 'Number of days of course access granted per unit purchased. Overrides the global plugin default. Example: set 30 for a "30-day extension" product.', 'course-access-extender-for-learndash' ),
						'desc_tip'          => true,
						'type'              => 'number',
						'custom_attributes' => array(
							'min'  => '1',
							'max'  => '3650',
							'step' => '1',
						),
						'value'             => $custom_days,
						'wrapper_class'     => '_cael_access_days_field',
					)
				);
				?>

			</div>
		</div>
		<?php
	}

	/**
	 * Save all Course Extension product meta on product save.
	 *
	 * WooCommerce verifies its own nonce before firing woocommerce_process_product_meta.
	 *
	 * @param int $post_id Product post ID.
	 */
	public function save_meta( $post_id ) {
		// phpcs:disable WordPress.Security.NonceVerification
		update_post_meta(
			$post_id,
			'_cael_is_course_extension',
			isset( $_POST['_cael_is_course_extension'] ) ? 'yes' : 'no'
		);

		update_post_meta(
			$post_id,
			'_cael_linked_course_id',
			isset( $_POST['_cael_linked_course_id'] ) ? absint( $_POST['_cael_linked_course_id'] ) : 0
		);

		update_post_meta(
			$post_id,
			'_cael_access_days',
			isset( $_POST['_cael_access_days'] ) ? absint( $_POST['_cael_access_days'] ) : 0
		);
		// phpcs:enable
	}

	/**
	 * Enqueue the product settings JS on the product edit screen.
	 *
	 * Uses wp_enqueue_script() with an external file — no inline <script> tags.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_product_assets( $hook ) {
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}

		global $post;
		if ( ! $post || 'product' !== get_post_type( $post ) ) {
			return;
		}

		wp_enqueue_script(
			'cael-product-settings',
			CAEL_PLUGIN_URL . 'admin/js/product-settings.js',
			array( 'jquery', 'woocommerce_admin' ),
			CAEL_VERSION . '.2',
			true
		);
	}
}
