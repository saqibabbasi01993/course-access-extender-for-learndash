<?php
/**
 * Students Data Class
 *
 * Provides methods to query LearnDash enrolled students,
 * their access expiry dates, and their WooCommerce purchase history.
 *
 * @package SKFreelancers\LearnDashCourseExtension
 * @since   1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CAEL_Students
 */
class CAEL_Students {

	/**
	 * Get a paginated list of students enrolled in a LearnDash course,
	 * along with their access expiry and status.
	 *
	 * @param array $args {
	 *     @type int    $course_id  Filter by course ID (0 = all courses).
	 *     @type string $search     Search by name or email.
	 *     @type string $status     'active', 'expired', 'all'. Default 'all'.
	 *     @type string $orderby    'name', 'expiry'. Default 'name'.
	 *     @type string $order      'ASC' or 'DESC'. Default 'ASC'.
	 *     @type int    $per_page   Default 20.
	 *     @type int    $paged      Default 1.
	 * }
	 * @return array { items: array, total: int }
	 */
	public static function get_enrolled_students( $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'course_id'     => 0,
				'search'        => '',
				'status'        => 'all',
				'orderby'       => 'name',
				'order'         => 'ASC',
				'per_page'      => 20,
				'paged'         => 1,
				'expiry_within' => 0,    // int: show only students expiring within N days.
				'enrolled_from' => '',   // YYYY-MM-DD: filter by enrollment date start.
				'enrolled_to'   => '',   // YYYY-MM-DD: filter by enrollment date end.
			)
		);

		// Gather all LearnDash courses to query.
		if ( $args['course_id'] ) {
			$course_ids = array( absint( $args['course_id'] ) );
		} else {
			$courses    = get_posts( array( 'post_type' => 'sfwd-courses', 'post_status' => 'publish', 'posts_per_page' => -1, 'fields' => 'ids' ) );
			$course_ids = array_map( 'absint', $courses );
		}

		if ( empty( $course_ids ) ) {
			return array( 'items' => array(), 'total' => 0 );
		}

		$now      = current_time( 'timestamp' ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested
		$extender = CAEL_Access_Extender::get_instance();
		$rows     = array();
		$seen     = array(); // user_id+course_id combo to avoid duplicates.

		foreach ( $course_ids as $course_id ) {
			// get_course_users() returns an array of WP_User objects.
			$users = learndash_get_course_users_access_from_meta( $course_id );

			if ( empty( $users ) || ! is_array( $users ) ) {
				// Fallback: query user meta directly.
				$users = self::get_course_users_fallback( $course_id );
			}

			if ( empty( $users ) ) {
				continue;
			}

			foreach ( $users as $user_id ) {
				$user_id = absint( $user_id );
				$key     = $user_id . '_' . $course_id;

				if ( isset( $seen[ $key ] ) ) {
					continue;
				}
				$seen[ $key ] = true;

				$user = get_userdata( $user_id );
				if ( ! $user ) {
					continue;
				}

				// Search filter.
				if ( $args['search'] ) {
					$search = strtolower( $args['search'] );
					if (
						false === strpos( strtolower( $user->display_name ), $search ) &&
						false === strpos( strtolower( $user->user_email ), $search )
					) {
						continue;
					}
				}

				$expiry = $extender->get_user_course_access_expiry( $user_id, $course_id );

				// PHP_INT_MAX means enrolled but course has no expiry setting — treat as active.
				$no_expiry_set = ( PHP_INT_MAX === $expiry );

				// Status filter.
				if ( 'active' === $args['status'] && ! $no_expiry_set && ( ! $expiry || $expiry <= $now ) ) {
					continue;
				}
				if ( 'expired' === $args['status'] && ( $no_expiry_set || ( $expiry && $expiry > $now ) ) ) {
					continue;
				}

				// Expiry-within filter: only show students whose access expires within N days.
				if ( $args['expiry_within'] && ! $no_expiry_set ) {
					$cutoff = $now + ( absint( $args['expiry_within'] ) * DAY_IN_SECONDS );
					if ( ! $expiry || $expiry <= $now || $expiry > $cutoff ) {
						continue;
					}
				}

				// Enrollment date range filter.
				$enrolled_ts = get_user_meta( $user_id, CAEL_Access_Extender::ENROLLED_SINCE_META_PREFIX . $course_id, true );
				if ( ! $enrolled_ts ) {
					$enrolled_ts = get_user_meta( $user_id, 'course_' . $course_id . '_access_from', true );
				}
				$enrolled_ts = $enrolled_ts ? absint( $enrolled_ts ) : 0;

				if ( $args['enrolled_from'] ) {
					$from_ts = strtotime( sanitize_text_field( $args['enrolled_from'] ) . ' 00:00:00' );
					if ( ! $enrolled_ts || $enrolled_ts < $from_ts ) {
						continue;
					}
				}
				if ( $args['enrolled_to'] ) {
					$to_ts = strtotime( sanitize_text_field( $args['enrolled_to'] ) . ' 23:59:59' );
					if ( ! $enrolled_ts || $enrolled_ts > $to_ts ) {
						continue;
					}
				}

				$days_left = ( $expiry && ! $no_expiry_set ) ? (int) ceil( ( $expiry - $now ) / DAY_IN_SECONDS ) : null;

				$rows[] = array(
					'user_id'      => $user_id,
					'course_id'    => $course_id,
					'display_name' => $user->display_name,
					'email'        => $user->user_email,
					'expiry'       => $no_expiry_set ? 0 : $expiry,
					'expiry_date'  => ( $expiry && ! $no_expiry_set ) ? gmdate( 'Y-m-d', $expiry ) : '',
					'expiry_f'     => $no_expiry_set ? __( 'No expiry', 'course-access-extender-for-learndash' ) : ( $expiry ? gmdate( 'M j, Y', $expiry ) : __( 'No expiry', 'course-access-extender-for-learndash' ) ),
					'days_left'    => $days_left,
					'is_active'    => $no_expiry_set || ( $expiry && $expiry > $now ),
					'course_title' => get_the_title( $course_id ),
				);
			}
		}

		// Sort.
		usort(
			$rows,
			function ( $a, $b ) use ( $args ) {
				$dir = 'ASC' === strtoupper( $args['order'] ) ? 1 : -1;
				if ( 'expiry' === $args['orderby'] ) {
					return $dir * ( ( isset( $a['expiry'] ) ? $a['expiry'] : 0 ) - ( isset( $b['expiry'] ) ? $b['expiry'] : 0 ) );
				}
				return $dir * strcasecmp( $a['display_name'], $b['display_name'] );
			}
		);

		$total  = count( $rows );
		$offset = ( absint( $args['paged'] ) - 1 ) * absint( $args['per_page'] );
		$items  = array_slice( $rows, $offset, absint( $args['per_page'] ) );

		return array( 'items' => $items, 'total' => $total );
	}

	/**
	 * Fallback: find users enrolled in a course via user meta query.
	 *
	 * @param int $course_id Course ID.
	 * @return int[] Array of user IDs.
	 */
	private static function get_course_users_fallback( $course_id ) {
		$users = get_users(
			array(
				'meta_key'   => 'course_' . $course_id . '_access_from', // phpcs:ignore WordPress.DB.SlowDBQuery
				'fields'     => 'ID',
				'number'     => -1,
			)
		);

		if ( empty( $users ) ) {
			// Broader fallback using _sfwd-course_progress.
			global $wpdb;
			$users = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					"SELECT DISTINCT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value LIKE %s",
					'_sfwd-course_progress',
					'%"' . $course_id . '"%'
				)
			);
		}

		return array_map( 'absint', $users );
	}

	/**
	 * Get a student's WooCommerce order history for course-related products.
	 *
	 * Includes:
	 *  - Products flagged as Course Extension (_ldce_is_course_extension)
	 *  - Products linked to the course via LearnDash's own WooCommerce meta
	 *    (supports both the legacy integration and the official LD WC add-on)
	 *
	 * @param int $user_id   User ID.
	 * @param int $course_id Course ID (0 = all extension orders).
	 * @return array Array of order data arrays.
	 */
	public static function get_student_purchase_history( $user_id, $course_id = 0 ) {
		$orders = wc_get_orders(
			array(
				'customer_id' => absint( $user_id ),
				'status'      => array( 'completed', 'processing', 'refunded', 'pending' ),
				'limit'       => -1,
				'orderby'     => 'date',
				'order'       => 'DESC',
			)
		);

		$history = array();

		foreach ( $orders as $order ) {
			foreach ( $order->get_items() as $item ) {
				$product_id = $item->get_product_id();

				// Is this product a Course Extension (our plugin's flag)?
				$is_extension     = 'yes' === get_post_meta( $product_id, '_cael_is_course_extension', true );
				$linked_course_id = absint( get_post_meta( $product_id, '_cael_linked_course_id', true ) );

				// Detect the LearnDash course linked to this product.
				// LD stores this differently across versions — check all known meta keys.
				$ld_course_id = self::get_product_linked_course_id( $product_id );

				$relevant = false;

				if ( $course_id ) {
					// Show if: product is our extension linked to this course,
					// OR product is the main course product linked via LearnDash.
					if ( $is_extension && $linked_course_id === $course_id ) {
						$relevant = true;
					} elseif ( $ld_course_id === $course_id ) {
						$relevant = true;
					}
				} else {
					// No course filter — show all extension products and all LD-linked products.
					$relevant = $is_extension || $ld_course_id > 0;
				}

				if ( ! $relevant ) {
					continue;
				}

				$history[] = array(
					'order_id'     => $order->get_id(),
					'order_url'    => admin_url( 'post.php?post=' . $order->get_id() . '&action=edit' ),
					'date'         => $order->get_date_created() ? $order->get_date_created()->date( 'M j, Y' ) : '—',
					'date_raw'     => $order->get_date_created() ? $order->get_date_created()->getTimestamp() : 0,
					'product_name' => $item->get_name(),
					'product_id'   => $product_id,
					'qty'          => $item->get_quantity(),
					'total'        => wc_price( $order->get_total() ),
					'status'       => $order->get_status(),
					'status_label' => wc_get_order_status_name( $order->get_status() ),
					'is_extension' => $is_extension,
				);
			}
		}

		return $history;
	}

	/**
	 * Resolve which LearnDash course ID is linked to a WooCommerce product.
	 *
	 * LearnDash has changed this meta key across versions and add-ons.
	 * We check all known locations so it works regardless of which
	 * integration method the site uses.
	 *
	 * @param int $product_id WooCommerce product ID.
	 * @return int Course ID, or 0 if none found.
	 */
	private static function get_product_linked_course_id( $product_id ) {
		// LearnDash WooCommerce integration (official add-on, current versions).
		$course_id = absint( get_post_meta( $product_id, '_related_course', true ) );
		if ( $course_id ) {
			return $course_id;
		}

		// LearnDash WooCommerce integration — array of course IDs (some versions).
		$courses = get_post_meta( $product_id, '_related_course', false );
		if ( ! empty( $courses ) && is_array( $courses ) ) {
			return absint( $courses[0] );
		}

		// LearnDash legacy single-key.
		$course_id = absint( get_post_meta( $product_id, '_learndash_course_id', true ) );
		if ( $course_id ) {
			return $course_id;
		}

		// LearnDash 4.x WC integration serialised array.
		$ld_wc_meta = get_post_meta( $product_id, '_ld_woocommerce_course_id', true );
		if ( $ld_wc_meta ) {
			return absint( $ld_wc_meta );
		}

		// WisdmLabs / LD WC Add-on.
		$wdm_meta = get_post_meta( $product_id, 'wdm_course_id', true );
		if ( $wdm_meta ) {
			return absint( $wdm_meta );
		}

		return 0;
	}

	/**
	 * Get all published LearnDash courses as an associative array for dropdowns.
	 *
	 * @return array { course_id => title }
	 */
	public static function get_courses_list() {
		$courses = get_posts(
			array(
				'post_type'      => 'sfwd-courses',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);

		$list = array();
		foreach ( $courses as $course ) {
			$list[ $course->ID ] = $course->post_title;
		}

		return $list;
	}
}
