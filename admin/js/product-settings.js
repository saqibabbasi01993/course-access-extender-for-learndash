/**
 * Course Access Extender for LearnDash — Product Settings JS
 *
 * Toggles visibility of the Course Extension fields (course select +
 * access days input) on the WooCommerce product edit screen based on
 * whether the "Course Extension Product" checkbox is checked.
 *
 * Loaded only on product edit screens via wp_enqueue_script().
 *
 * @package SKFreelancers\LearnDashCourseExtension
 */

/* global jQuery */
( function ( $ ) {
	'use strict';

	function ldceToggleCourseExtensionFields() {
		var isChecked = $( '#_ldce_is_course_extension' ).is( ':checked' );
		$( '._ldce_linked_course_id_field, ._ldce_access_days_field' ).toggle( isChecked );
	}

	$( function () {
		ldceToggleCourseExtensionFields();
		$( '#_ldce_is_course_extension' ).on( 'change', ldceToggleCourseExtensionFields );
	} );

}( jQuery ) );
