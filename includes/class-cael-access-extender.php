<?php
/**
 * Core Access Extender Class
 *
 * @package SKFreelancers\LearnDashCourseExtension
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CAEL_Access_Extender
 */
class CAEL_Access_Extender {

	/** @var CAEL_Access_Extender|null */
	private static $instance = null;

	/** @var int Global fallback days per extension purchase. */
	private $extension_days;

	/**
	 * Flag set to true while write_expiry_to_learndash() is running.
	 * Prevents sync_on_learndash_enrollment_date_change() from treating
	 * our own meta writes as external enrollment date changes.
	 *
	 * @var bool
	 */
	private $writing_learndash_expiry = false;

	/**
	 * Meta key: admin-set expiry override (read by Students tab, used for extension stacking).
	 * Format: _ldce_admin_expiry_{course_id}
	 */
	const ADMIN_EXPIRY_META_PREFIX = '_cael_admin_expiry_';

	/**
	 * Meta key: preserves the original enrollment timestamp so the LearnDash
	 * "Since" date can be restored after an admin expiry adjustment shifts access_from.
	 * Format: _ldce_enrolled_since_{course_id}
	 */
	const ENROLLED_SINCE_META_PREFIX = '_cael_enrolled_since_';

	/**
	 * Order meta key: the student's access expiry captured BEFORE any plugin
	 * (e.g. LearnDash's WooCommerce integration) re-enrolls them for this order.
	 * Format: _cael_pre_purchase_expiry_{course_id}
	 *
	 * @since 1.1.4
	 */
	const PRE_PURCHASE_EXPIRY_META_PREFIX = '_cael_pre_purchase_expiry_';

	/** @return CAEL_Access_Extender */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->extension_days = absint( get_option( 'cael_extension_days', 90 ) );

		add_action( 'woocommerce_order_status_completed', array( $this, 'process_order' ), 20, 1 );
		add_action( 'woocommerce_payment_complete', array( $this, 'maybe_process_on_payment' ), 20, 1 );

		/*
		 * Pre-purchase expiry snapshot (since 1.1.4).
		 *
		 * When the extension product is ALSO linked to the course under
		 * General → "LearnDash Courses", LearnDash's WooCommerce integration
		 * re-enrolls an expired student (access_from = now) at priority 10 on
		 * the same status hooks — before process_order() runs at priority 20.
		 * process_order() then saw a brand-new "active" 90 days and stacked
		 * another 90 on top (180 total). We record the real expiry first, at
		 * checkout and at priority 1 on each paid status, so the extension
		 * logic knows what the student had before this order touched anything.
		 */
		add_action( 'woocommerce_checkout_order_processed', array( $this, 'snapshot_pre_purchase_expiry' ), 1, 1 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'snapshot_pre_purchase_expiry' ), 1, 1 );
		add_action( 'woocommerce_order_status_on-hold', array( $this, 'snapshot_pre_purchase_expiry' ), 1, 1 );
		add_action( 'woocommerce_order_status_processing', array( $this, 'snapshot_pre_purchase_expiry' ), 1, 1 );
		add_action( 'woocommerce_order_status_completed', array( $this, 'snapshot_pre_purchase_expiry' ), 1, 1 );
		add_action( 'woocommerce_payment_complete', array( $this, 'snapshot_pre_purchase_expiry' ), 1, 1 );
		add_action( 'cael_cleanup_logs', array( $this, 'cleanup_logs' ) );
		add_action( 'wp_ajax_cael_adjust_expiry', array( $this, 'ajax_adjust_expiry' ) );

		/*
		 * LearnDash profile "Since" date filter.
		 *
		 * When an admin adjusts expiry, we must shift access_from so LearnDash
		 * displays the correct "expires" date. This means access_from no longer
		 * equals the real enrollment date. We save the original enrollment date
		 * in _ldce_enrolled_since_{course_id} and restore it here so the user
		 * profile always shows the real "Since" date.
		 */
		add_filter( 'learndash_user_course_access_from_time', array( $this, 'filter_course_access_from_time' ), 10, 3 );

		/*
		 * Sync hook: when admin manually changes the enrollment date on the
		 * LearnDash user profile page ("Set Enrolled Date" field), LearnDash
		 * writes a new value to course_{id}_access_from user meta. We hook into
		 * user_meta updates for that key and clear our overrides so the Students
		 * tab immediately re-reads from LearnDash instead of stale cached values.
		 */
		add_action( 'updated_user_meta', array( $this, 'sync_on_learndash_enrollment_date_change' ), 10, 4 );
		add_action( 'added_user_meta',   array( $this, 'sync_on_learndash_enrollment_date_change' ), 10, 4 );
	}

	// -------------------------------------------------------------------------
	// LearnDash "Since" date filter
	// -------------------------------------------------------------------------

	/**
	 * Restore the original enrollment "Since" date on the user profile.
	 *
	 * LearnDash fires this filter when building the "Since: ..." display string.
	 * If we have saved an original enrollment timestamp for this user+course,
	 * we return that instead of the shifted access_from value.
	 *
	 * @param int $access_from Current access_from timestamp.
	 * @param int $user_id     User ID.
	 * @param int $course_id   Course ID.
	 * @return int Original enrollment timestamp if saved, otherwise unchanged.
	 */
	public function filter_course_access_from_time( $access_from, $user_id, $course_id ) {
		$original = get_user_meta( $user_id, self::ENROLLED_SINCE_META_PREFIX . $course_id, true );
		if ( $original ) {
			return absint( $original );
		}
		return $access_from;
	}

	// -------------------------------------------------------------------------
	// LearnDash enrollment date sync
	// -------------------------------------------------------------------------

	/**
	 * Detect when LearnDash writes a new enrollment date on the user profile.
	 *
	 * LearnDash stores the enrollment start timestamp in two meta keys:
	 *   course_{course_id}_access_from
	 *
	 * When an admin edits a user profile and changes the "Set Enrolled Date"
	 * field, LearnDash updates this meta. We hook into the WP meta update
	 * actions, detect the pattern, and:
	 *
	 *  1. Clear _ldce_admin_expiry_{course_id} — our override is now stale
	 *     because LearnDash has a new access_from. The Students tab will
	 *     re-read from LearnDash and show the correct dates.
	 *  2. Update _ldce_enrolled_since_{course_id} to the new enrollment date
	 *     so our "Since" filter shows the freshly set date going forward.
	 *  3. Log the change to the Activity Log.
	 *
	 * @param int    $meta_id   Meta row ID (unused).
	 * @param int    $user_id   WordPress user ID.
	 * @param string $meta_key  Meta key being written.
	 * @param mixed  $meta_value New meta value.
	 */
	public function sync_on_learndash_enrollment_date_change( $meta_id, $user_id, $meta_key, $meta_value ) {
		// We only care about LearnDash's course access_from meta keys.
		// Pattern: course_{numeric_id}_access_from
		if ( ! preg_match( '/^course_(\d+)_access_from$/', $meta_key, $matches ) ) {
			return;
		}

		$course_id   = absint( $matches[1] );
		$new_from    = absint( $meta_value );

		// Skip if this was written by our own plugin (prevent infinite loops).
		// We set a flag before calling write_expiry_to_learndash() and clear it after.
		if ( $this->writing_learndash_expiry ) {
			return;
		}

		// Retrieve the previous expiry before clearing the override.
		$prev_admin_expiry = get_user_meta( $user_id, self::ADMIN_EXPIRY_META_PREFIX . $course_id, true );

		// 1. Clear our admin override — LearnDash now has authoritative data.
		delete_user_meta( $user_id, self::ADMIN_EXPIRY_META_PREFIX . $course_id );

		// 2. Update enrolled-since to the new enrollment date set on the profile.
		update_user_meta( $user_id, self::ENROLLED_SINCE_META_PREFIX . $course_id, $new_from );

		// 3. Flush caches.
		$this->flush_cache( $user_id, $course_id );

		// 4. Calculate new expiry for the log message.
		$expire_days = absint( learndash_get_setting( $course_id, 'expire_access_days' ) );
		$new_expiry  = $expire_days ? $new_from + ( $expire_days * DAY_IN_SECONDS ) : 0;

		$old_expiry_str = $prev_admin_expiry
			? wp_date( 'M j, Y', absint( $prev_admin_expiry ) )
			: __( 'plugin override cleared', 'course-access-extender-for-learndash' );

		$new_enrolled_str = wp_date( 'M j, Y g:i a', $new_from );
		$new_expiry_str   = $new_expiry ? wp_date( 'M j, Y', $new_expiry ) : __( 'no expiry', 'course-access-extender-for-learndash' );

		CAEL_Logger::log(
			$user_id,
			0,
			0,
			'profile_updated',
			sprintf(
				/* translators: 1: new enrollment date, 2: new expiry date, 3: old plugin override date */
				__( 'LearnDash enrollment date changed on user profile. New enrollment date: %1$s. New expiry: %2$s (was: %3$s). Plugin overrides cleared — Students tab now reflects LearnDash data.', 'course-access-extender-for-learndash' ),
				$new_enrolled_str,
				$new_expiry_str,
				$old_expiry_str
			),
			$course_id
		);
	}

	// -------------------------------------------------------------------------
	// Order processing
	// -------------------------------------------------------------------------

	/**
	 * Process a completed WooCommerce order.
	 *
	 * @param int $order_id Order ID.
	 */
	public function process_order( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		if ( $order->get_meta( '_cael_processed', true ) ) {
			return;
		}

		$user_id = $order->get_user_id();
		if ( ! $user_id ) {
			CAEL_Logger::log( 0, $order_id, 0, 'skipped', __( 'Guest order — no user account.', 'course-access-extender-for-learndash' ) );
			return;
		}

		$extended = false;

		foreach ( $order->get_items() as $item ) {
			$product_id   = $item->get_product_id();
			$is_extension = get_post_meta( $product_id, '_cael_is_course_extension', true );

			if ( 'yes' !== $is_extension ) {
				continue;
			}

			$linked_course_id = $this->get_linked_course_id( $product_id );

			if ( ! $linked_course_id ) {
				CAEL_Logger::log( $user_id, $order_id, $product_id, 'skipped', __( 'No linked course configured on product.', 'course-access-extender-for-learndash' ) );
				continue;
			}

			// Per-product days override; falls back to global setting.
			$product_days = absint( get_post_meta( $product_id, '_cael_access_days', true ) );
			if ( ! $product_days ) {
				$product_days = $this->extension_days;
			}

			$quantity    = max( 1, absint( $item->get_quantity() ) );
			$days_to_add = $product_days * $quantity;

			// Expiry as it was before this order (null if no snapshot was taken).
			$snapshot_key        = self::PRE_PURCHASE_EXPIRY_META_PREFIX . $linked_course_id;
			$pre_purchase_expiry = $order->meta_exists( $snapshot_key )
				? absint( $order->get_meta( $snapshot_key, true ) )
				: null;

			$this->extend_course_access( $user_id, $linked_course_id, $days_to_add, $order_id, $product_id, $pre_purchase_expiry );
			$extended = true;
		}

		if ( $extended ) {
			$order->update_meta_data( '_cael_processed', true );
			$order->save();
		}
	}

	/**
	 * Record each linked course's expiry before this order changes anything.
	 *
	 * Runs at priority 1 so it fires ahead of LearnDash's WooCommerce
	 * integration. Only the FIRST snapshot per course is kept, so later status
	 * hooks (after LearnDash has already re-enrolled the student) cannot
	 * overwrite the true pre-purchase value.
	 *
	 * @since 1.1.4
	 * @param int|WC_Order $order_or_id Order ID or object (Store API passes the object).
	 */
	public function snapshot_pre_purchase_expiry( $order_or_id ) {
		$order = $order_or_id instanceof WC_Order ? $order_or_id : wc_get_order( $order_or_id );
		if ( ! $order instanceof WC_Order || $order->get_meta( '_cael_processed', true ) ) {
			return;
		}

		$user_id = $order->get_user_id();
		if ( ! $user_id ) {
			return;
		}

		$changed = false;
		foreach ( $order->get_items() as $item ) {
			$product_id = $item->get_product_id();
			if ( 'yes' !== get_post_meta( $product_id, '_cael_is_course_extension', true ) ) {
				continue;
			}

			$course_id = $this->get_linked_course_id( $product_id );
			$key       = self::PRE_PURCHASE_EXPIRY_META_PREFIX . $course_id;
			if ( ! $course_id || $order->meta_exists( $key ) ) {
				continue;
			}

			$expiry = $this->get_user_course_access_expiry( $user_id, $course_id );
			// 0 = not enrolled. PHP_INT_MAX (lifetime) is stored as-is.
			$order->update_meta_data( $key, $expiry ? (string) $expiry : '0' );
			$changed = true;
		}

		if ( $changed ) {
			$order->save();
		}
	}

	/**
	 * Resolve the LearnDash course linked to an extension product.
	 *
	 * @since 1.1.4
	 * @param int $product_id Product ID.
	 * @return int Course ID or 0.
	 */
	private function get_linked_course_id( $product_id ) {
		$linked_course_id = absint( get_post_meta( $product_id, '_cael_linked_course_id', true ) );

		// Migration fallback: support products saved under the old _ldce_ prefix before v1.1.2.
		if ( ! $linked_course_id ) {
			$linked_course_id = absint( get_post_meta( $product_id, '_ldce_linked_course_id', true ) );
			if ( $linked_course_id ) {
				// Migrate the value to the new key so future reads use the correct key.
				update_post_meta( $product_id, '_cael_linked_course_id', $linked_course_id );
			}
		}

		return $linked_course_id;
	}

	/**
	 * Handle virtual products that auto-complete on payment.
	 *
	 * @param int $order_id Order ID.
	 */
	public function maybe_process_on_payment( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( $order instanceof WC_Order && 'completed' === $order->get_status() ) {
			$this->process_order( $order_id );
		}
	}

	// -------------------------------------------------------------------------
	// Checkout-triggered access extension
	// -------------------------------------------------------------------------

	/**
	 * Extend course access after a purchase.
	 *
	 * Extension logic:
	 *  ACTIVE ACCESS  → new expiry = current expiry + days_to_add (additive, never resets).
	 *    Example: 3 days left + 30-day product = 33 days total remaining. ✅
	 *  EXPIRED / NONE → new expiry = today + days_to_add.
	 *
	 * @param int $user_id     User ID.
	 * @param int $course_id   Course ID.
	 * @param int $days_to_add Days to add.
	 * @param int $order_id    Order ID (for log).
	 * @param int $product_id  Product ID (for log).
	 * @param int|null $pre_purchase_expiry Expiry captured before the order was
	 *                                      processed (null = no snapshot). Since 1.1.4.
	 */
	public function extend_course_access( $user_id, $course_id, $days_to_add, $order_id = 0, $product_id = 0, $pre_purchase_expiry = null ) {
		// Real Unix timestamp — LearnDash and wp_date() both expect UTC, not
		// the offset-shifted value from current_time( 'timestamp' ).
		$now            = time();
		$current_expiry = $this->get_user_course_access_expiry( $user_id, $course_id );

		/*
		 * If the snapshot shows the student had NO active access before this
		 * order, any "active" expiry we read now was created by another plugin
		 * re-enrolling them for this same order. Ignore it and grant fresh
		 * access (now + days) instead of stacking on top of it.
		 */
		if ( null !== $pre_purchase_expiry ) {
			$was_active_before = (
				$pre_purchase_expiry &&
				PHP_INT_MAX !== $pre_purchase_expiry &&
				$pre_purchase_expiry > $now
			);
			if ( ! $was_active_before && PHP_INT_MAX !== $pre_purchase_expiry ) {
				$current_expiry = $pre_purchase_expiry ? $pre_purchase_expiry : false;
			}
		}

		$has_active_expiry = (
			$current_expiry &&
			PHP_INT_MAX !== $current_expiry &&
			$current_expiry > $now
		);

		$old_expiry_str = ( $current_expiry && PHP_INT_MAX !== $current_expiry )
			? wp_date( 'M j, Y', $current_expiry )
			: __( 'none', 'course-access-extender-for-learndash' );

		if ( $has_active_expiry ) {
			$new_expiry = $current_expiry + ( $days_to_add * DAY_IN_SECONDS );
			$action     = 'extended';
			$message    = sprintf(
				/* translators: 1: old expiry, 2: new expiry, 3: days added */
				__( 'Access extended via purchase. Was: %1$s → Now: %2$s (+%3$d days stacked on existing expiry).', 'course-access-extender-for-learndash' ),
				$old_expiry_str,
				wp_date( 'M j, Y', $new_expiry ),
				$days_to_add
			);
		} else {
			$new_expiry = $now + ( $days_to_add * DAY_IN_SECONDS );
			$action     = 'granted';
			$message    = sprintf(
				/* translators: 1: new expiry, 2: days added */
				__( 'Fresh access granted via purchase. Was: no active access → Now: expires %1$s (%2$d days from today).', 'course-access-extender-for-learndash' ),
				wp_date( 'M j, Y', $new_expiry ),
				$days_to_add
			);
		}

		// Checkout path: update LearnDash native fields + clear any admin override.
		$this->write_expiry_to_learndash( $user_id, $course_id, $new_expiry );
		delete_user_meta( $user_id, self::ADMIN_EXPIRY_META_PREFIX . $course_id );

		$this->ensure_enrollment( $user_id, $course_id );

		CAEL_Logger::log( $user_id, $order_id, $product_id, $action, $message, $course_id );

		/**
		 * Fires after course access is extended via checkout.
		 *
		 * @param int    $user_id    User ID.
		 * @param int    $course_id  Course ID.
		 * @param int    $new_expiry New expiry timestamp.
		 * @param int    $order_id   Order ID.
		 * @param string $action     'extended' or 'granted'.
		 */
		do_action( 'cael_after_course_access_extended', $user_id, $course_id, $new_expiry, $order_id, $action );
	}

	// -------------------------------------------------------------------------
	// Admin manual expiry adjustment
	// -------------------------------------------------------------------------

	/**
	 * AJAX: admin sets an absolute expiry date from the Students tab.
	 *
	 * Strategy to keep "Since" unchanged on the user profile:
	 *
	 *  1. Save the original access_from as _ldce_enrolled_since_{course_id}
	 *     (only on the first admin adjustment — never overwrite it).
	 *  2. Update access_from to (new_expiry - expire_access_days) so LearnDash
	 *     calculates and displays the correct "expires" date natively.
	 *  3. The filter_course_access_from_time() hook intercepts LearnDash's
	 *     "Since" display and returns the saved original timestamp instead.
	 *  4. Also store in _ldce_admin_expiry_{course_id} so our Students tab
	 *     and the checkout extension stacking logic use the correct base date.
	 */
	public function ajax_adjust_expiry() {
		if ( ! check_ajax_referer( 'cael_adjust_expiry_nonce', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed. Please refresh the page and try again.', 'course-access-extender-for-learndash' ) ) );
			return;
		}

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'course-access-extender-for-learndash' ) ) );
			return;
		}

		$user_id   = absint( isset( $_POST['user_id'] ) ? $_POST['user_id'] : 0 );              // phpcs:ignore WordPress.Security.NonceVerification
		$course_id = absint( isset( $_POST['course_id'] ) ? $_POST['course_id'] : 0 );             // phpcs:ignore WordPress.Security.NonceVerification
		$new_date  = isset( $_POST['new_expiry'] ) ? sanitize_text_field( wp_unslash( $_POST['new_expiry'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification

		if ( ! $user_id || ! $course_id || ! $new_date ) {
			wp_send_json_error( array( 'message' => __( 'Missing required fields.', 'course-access-extender-for-learndash' ) ) );
			return;
		}

		// Parse date at end of chosen day in site timezone.
		$tz_string = get_option( 'timezone_string' );
		$tz        = $tz_string ? new DateTimeZone( $tz_string ) : new DateTimeZone( 'UTC' );
		$dt        = new DateTime( $new_date . ' 23:59:59', $tz );
		$timestamp = $dt->getTimestamp();

		if ( ! $timestamp ) {
			wp_send_json_error( array( 'message' => __( 'Invalid date format.', 'course-access-extender-for-learndash' ) ) );
			return;
		}

		/*
		 * Preserve the original enrollment "Since" date before we touch access_from.
		 * We only save it once — subsequent adjustments don't overwrite it so the
		 * real enrollment date is never lost.
		 */
		$existing_since = get_user_meta( $user_id, self::ENROLLED_SINCE_META_PREFIX . $course_id, true );
		if ( ! $existing_since ) {
			$original_access_from = get_user_meta( $user_id, 'course_' . $course_id . '_access_from', true );
			if ( $original_access_from ) {
				update_user_meta( $user_id, self::ENROLLED_SINCE_META_PREFIX . $course_id, absint( $original_access_from ) );
			}
		}

		// Capture the PREVIOUS effective expiry BEFORE writing anything — used in log message.
		$prev_expiry     = $this->get_user_course_access_expiry( $user_id, $course_id );
		$prev_expiry_str = ( $prev_expiry && PHP_INT_MAX !== $prev_expiry )
			? wp_date( 'M j, Y', absint( $prev_expiry ) )
			: __( 'none', 'course-access-extender-for-learndash' );

		// Store in our override meta (used by Students tab and checkout stacking).
		update_user_meta( $user_id, self::ADMIN_EXPIRY_META_PREFIX . $course_id, $timestamp );

		/*
		 * Update LearnDash's native fields so the user profile "expires" date
		 * updates to match. We must touch access_from because that is what
		 * LearnDash reads to display the expiry (access_from + expire_access_days).
		 * The "Since" date is protected by filter_course_access_from_time() above.
		 */
		$this->write_expiry_to_learndash( $user_id, $course_id, $timestamp );

		$this->flush_cache( $user_id, $course_id );

		// Recalculate for JS row update.
		$now       = current_time( 'timestamp' ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested
		$days_left = (int) ceil( ( $timestamp - $now ) / DAY_IN_SECONDS );
		$is_active = $timestamp > $now;

		$display_date = wp_date( 'M j, Y', $timestamp );
		$input_date   = wp_date( 'Y-m-d', $timestamp );

		// Resolve the real enrollment date for the log message.
		$enrolled_since_meta = get_user_meta( $user_id, self::ENROLLED_SINCE_META_PREFIX . $course_id, true );
		if ( $enrolled_since_meta ) {
			$enrolled_since_str = wp_date( 'M j, Y g:i a', absint( $enrolled_since_meta ) );
		} else {
			$raw_access_from = get_user_meta( $user_id, 'course_' . $course_id . '_access_from', true );
			$enrolled_since_str = $raw_access_from
				? wp_date( 'M j, Y g:i a', absint( $raw_access_from ) )
				: __( 'unknown', 'course-access-extender-for-learndash' );
		}

		CAEL_Logger::log(
			$user_id,
			0,
			0,
			'admin_adjusted',
			sprintf(
				/* translators: 1: new expiry date, 2: original enrollment date, 3: previous expiry */
				__( 'Admin manually adjusted expiry. Was: %3$s → Now: %1$s. Student originally enrolled: %2$s.', 'course-access-extender-for-learndash' ),
				$display_date,
				$enrolled_since_str,
				$prev_expiry_str
			),
			$course_id
		);

		wp_send_json_success(
			array(
				'message'      => __( 'Expiry date updated successfully.', 'course-access-extender-for-learndash' ),
				'new_expiry'   => $input_date,
				'new_expiry_f' => $display_date,
				'days_left'    => $days_left,
				'is_active'    => $is_active,
			)
		);
	}

	// -------------------------------------------------------------------------
	// Expiry read / write
	// -------------------------------------------------------------------------

	/**
	 * Get the effective access expiry for a user/course.
	 *
	 * Priority:
	 *  1. Admin override  (_ldce_admin_expiry_{course_id})
	 *  2. LearnDash canonical meta (course_{id}_access_from + expire_access_days)
	 *  3. LearnDash activity table
	 *  4. Legacy _sfwd-course_progress meta
	 *
	 * @param int $user_id   User ID.
	 * @param int $course_id Course ID.
	 * @return int|false Expiry timestamp, PHP_INT_MAX for no-expiry, false if not enrolled.
	 */
	public function get_user_course_access_expiry( $user_id, $course_id ) {

		// 1. Admin override.
		$admin_expiry = get_user_meta( $user_id, self::ADMIN_EXPIRY_META_PREFIX . $course_id, true );
		if ( $admin_expiry ) {
			return absint( $admin_expiry );
		}

		// 2. LearnDash canonical.
		$access_from = get_user_meta( $user_id, 'course_' . $course_id . '_access_from', true );
		if ( $access_from ) {
			$expire_days = absint( learndash_get_setting( $course_id, 'expire_access_days' ) );
			if ( $expire_days ) {
				return absint( $access_from ) + ( $expire_days * DAY_IN_SECONDS );
			}
			return PHP_INT_MAX;
		}

		// 3. Activity table.
		if ( function_exists( 'learndash_get_user_activity' ) ) {
			$activity = learndash_get_user_activity(
				array(
					'post_id'         => $course_id,
					'user_id'         => $user_id,
					'activity_type'   => 'access',
					'activity_status' => 'IN_PROGRESS',
				)
			);
			if ( ! empty( $activity ) && is_object( $activity ) && ! empty( $activity->activity_expiry ) ) {
				return absint( $activity->activity_expiry );
			}
		}

		// 4. Legacy _sfwd-course_progress.
		$progress = get_user_meta( $user_id, '_sfwd-course_progress', true );
		if ( is_array( $progress ) && isset( $progress[ $course_id ]['access_from'] ) ) {
			$expire_days = absint( learndash_get_setting( $course_id, 'expire_access_days' ) );
			if ( $expire_days ) {
				return absint( $progress[ $course_id ]['access_from'] ) + ( $expire_days * DAY_IN_SECONDS );
			}
			return PHP_INT_MAX;
		}

		return false;
	}

	/**
	 * Write a new expiry into all LearnDash storage locations.
	 *
	 * Used by BOTH the checkout path and the admin adjustment path.
	 * LearnDash computes displayed expiry as: access_from + expire_access_days.
	 * We reverse-calculate: access_from = new_expiry - expire_access_days.
	 *
	 * @param int $user_id    User ID.
	 * @param int $course_id  Course ID.
	 * @param int $new_expiry Target expiry Unix timestamp.
	 */
	private function write_expiry_to_learndash( $user_id, $course_id, $new_expiry ) {
		global $wpdb;

		// Set flag so our meta update hooks know this write is internal.
		$this->writing_learndash_expiry = true;

		$expire_days = absint( learndash_get_setting( $course_id, 'expire_access_days' ) );
		if ( ! $expire_days ) {
			$expire_days = absint( get_option( 'cael_extension_days', 90 ) );
		}
		if ( ! $expire_days ) {
			$expire_days = 90;
		}

		// access_from = new_expiry - expire_access_days.
		$new_access_from = $new_expiry - ( $expire_days * DAY_IN_SECONDS );

		// 1. Primary LD user meta.
		update_user_meta( $user_id, 'course_' . $course_id . '_access_from', $new_access_from );

		// 2. Activity table — resolve table name with fallback.
		// esc_sql() is applied to the table name because it may come from
		// LD_QuizPro::get_activity_table_name() (external code). The value
		// is used via interpolation inside prepare() queries below.
		$ldce_raw_table = null;
		if ( class_exists( 'LD_QuizPro' ) && method_exists( 'LD_QuizPro', 'get_activity_table_name' ) ) {
			$ldce_raw_table = LD_QuizPro::get_activity_table_name();
		}
		if ( ! $ldce_raw_table ) {
			$ldce_raw_table = $wpdb->prefix . 'learndash_user_activity';
		}
		$table = esc_sql( $ldce_raw_table );

		/*
		 * All queries below target the LearnDash activity table.
		 * - NoCaching is suppressed: this data must always be read fresh since
		 *   we are about to write it; serving cached access-expiry data could
		 *   incorrectly block or grant course access.
		 * - $table is esc_sql()-escaped (see above) before interpolation.
		 */
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $ldce_raw_table ) ) === $ldce_raw_table ) {
			$existing = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM {$table} WHERE user_id = %d AND post_id = %d AND activity_type = 'access'",
					$user_id,
					$course_id
				)
			);

			if ( $existing ) {
				$wpdb->update(
					$table,
					array( 'activity_expiry' => $new_expiry ),
					array( 'user_id' => $user_id, 'post_id' => $course_id, 'activity_type' => 'access' ),
					array( '%d' ),
					array( '%d', '%d', '%s' )
				);
			} else {
				// Insert minimal access row if none exists.
				$now = current_time( 'timestamp' ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested
				$wpdb->insert(
					$table,
					array(
						'user_id'            => $user_id,
						'post_id'            => $course_id,
						'course_id'          => $course_id,
						'activity_type'      => 'access',
						'activity_status'    => 1,
						'activity_started'   => $now,
						'activity_completed' => 0,
						'activity_expiry'    => $new_expiry,
					),
					array( '%d', '%d', '%d', '%s', '%d', '%d', '%d', '%d' )
				);
			}
		}
		// phpcs:enable

		// 3. Legacy _sfwd-course_progress meta.
		$progress = get_user_meta( $user_id, '_sfwd-course_progress', true );
		$progress = is_array( $progress ) ? $progress : array();
		if ( ! isset( $progress[ $course_id ] ) ) {
			$progress[ $course_id ] = array();
		}
		$progress[ $course_id ]['access_from'] = $new_access_from;
		update_user_meta( $user_id, '_sfwd-course_progress', $progress );

		// Trigger LearnDash enrollment refresh.
		if ( function_exists( 'ld_update_course_access' ) ) {
			ld_update_course_access( $user_id, $course_id, false );
		}

		$this->flush_cache( $user_id, $course_id );

		// Clear flag — subsequent meta writes from other sources should be tracked.
		$this->writing_learndash_expiry = false;
	}

	/**
	 * Ensure the user is enrolled in the course.
	 *
	 * @param int $user_id   User ID.
	 * @param int $course_id Course ID.
	 */
	private function ensure_enrollment( $user_id, $course_id ) {
		if ( function_exists( 'ld_update_course_access' ) ) {
			ld_update_course_access( $user_id, $course_id, false );
		}
	}

	/**
	 * Flush LearnDash caches for a user/course.
	 *
	 * @param int $user_id   User ID.
	 * @param int $course_id Course ID.
	 */
	private function flush_cache( $user_id, $course_id ) {
		delete_transient( 'learndash_course_access_' . $user_id . '_' . $course_id );
		delete_transient( 'learndash_user_' . $user_id . '_courses' );
		wp_cache_delete( 'learndash_course_access_' . $user_id . '_' . $course_id, 'learndash' );
		if ( function_exists( 'learndash_clear_user_activity_transients' ) ) {
			learndash_clear_user_activity_transients( $user_id );
		}
	}

	/** Run cron log cleanup. */
	public function cleanup_logs() {
		CAEL_Logger::cleanup();
	}
}
