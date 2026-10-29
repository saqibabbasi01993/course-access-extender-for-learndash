/**
 * Course Access Extender for LearnDash — Admin JS
 *
 * Handles:
 *  - Inline expiry date save (AJAX) with live row update
 *  - Purchase history modal (AJAX) — only on Students tab
 *  - Clear All Logs confirmation
 *
 * @package SKFreelancers\LearnDashCourseExtension
 */

/* global caelAdmin, jQuery */
( function ( $ ) {
	'use strict';

	// Everything runs inside DOM-ready so elements are guaranteed to exist.
	$( function () {

		// -------------------------------------------------------------------------
		// Adjust expiry — inline Save button
		// -------------------------------------------------------------------------

		$( document ).on( 'click', '.ldce-save-expiry', function () {
			var $btn        = $( this );
			var userId      = $btn.data( 'user-id' );
			var courseId    = $btn.data( 'course-id' );
			var key         = userId + '_' + courseId;
			var $feedback   = $( '#ldce-feedback-' + key );
			var $display    = $( '#ldce-expiry-display-' + key );
			var $row        = $( '#ldce-student-row-' + key );
			var $datePicker = $btn.closest( '.ldce-adjust-form' ).find( '.ldce-date-picker' );
			var newDate     = $datePicker.val();
			var saveLabel   = caelAdmin.saveLabel || 'Save';

			if ( ! newDate ) {
				$feedback.removeClass( 'ldce-feedback-ok' ).addClass( 'ldce-feedback-error' ).text( caelAdmin.errorMsg );
				return;
			}

			if ( ! window.confirm( caelAdmin.confirmAdjust ) ) {
				return;
			}

			$btn.prop( 'disabled', true ).text( caelAdmin.saving );
			$feedback.text( '' ).removeClass( 'ldce-feedback-error ldce-feedback-ok' );

			$.post(
				caelAdmin.ajaxUrl,
				{
					action    : 'cael_adjust_expiry',
					nonce     : caelAdmin.nonce,
					user_id   : userId,
					course_id : courseId,
					new_expiry: newDate,
				},
				function ( response ) {
					$btn.prop( 'disabled', false ).text( saveLabel );

					if ( response.success ) {
						var data = response.data;

						// 1. Update the Access Expiry display cell.
						$display.text( data.new_expiry_f );
						$display.data( 'expiry', data.new_expiry );

						// 2. Sync date picker to server-confirmed date.
						$datePicker.val( data.new_expiry );

						// 3. Update Days Left cell.
						var $daysCell = $row.find( 'td' ).eq( 5 ).find( 'span' );
						if ( data.is_active ) {
							$daysCell.removeClass( 'ldce-days-expired ldce-days-none' )
								.addClass( 'ldce-days-active' )
								.text( data.days_left );
						} else {
							$daysCell.removeClass( 'ldce-days-active ldce-days-none' )
								.addClass( 'ldce-days-expired' )
								.text( caelAdmin.expiredLabel || 'Expired' );
						}

						// 4. Update Status badge.
						var $badge = $row.find( '.ldce-badge--active, .ldce-badge--expired' );
						if ( data.is_active ) {
							$badge.removeClass( 'ldce-badge--expired' )
								.addClass( 'ldce-badge--active' )
								.text( caelAdmin.activeLabel || 'Active' );
						} else {
							$badge.removeClass( 'ldce-badge--active' )
								.addClass( 'ldce-badge--expired' )
								.text( caelAdmin.expiredBadgeLabel || 'Expired' );
						}

						// 5. Show success, then fade out.
						$feedback.removeClass( 'ldce-feedback-error' ).addClass( 'ldce-feedback-ok' ).text( caelAdmin.saved );
						setTimeout( function () {
							$feedback.fadeOut( 400, function () {
								$( this ).text( '' ).removeClass( 'ldce-feedback-ok' ).show();
							} );
						}, 2500 );

					} else {
						var msg = ( response.data && response.data.message ) ? response.data.message : caelAdmin.errorMsg;
						$feedback.removeClass( 'ldce-feedback-ok' ).addClass( 'ldce-feedback-error' ).text( msg );
					}
				}
			).fail( function ( xhr ) {
				$btn.prop( 'disabled', false ).text( saveLabel );
				var hint = xhr.responseText ? ' [' + xhr.responseText.substring( 0, 150 ).replace( /<[^>]+>/g, '' ).trim() + ']' : '';
				$feedback.removeClass( 'ldce-feedback-ok' ).addClass( 'ldce-feedback-error' ).text( caelAdmin.errorMsg + hint );
			} );
		} );

		// -------------------------------------------------------------------------
		// Purchase history modal — only present on Students tab.
		// Guarded with an existence check so no errors on other tabs.
		// -------------------------------------------------------------------------

		var $modal = $( '#ldce-history-modal' );

		// If the modal element does not exist (other tabs), skip all modal setup.
		if ( $modal.length ) {

			var $modalTitle   = $( '#ldce-modal-title' );
			var $modalContent = $( '#ldce-history-content' );

			/**
			 * Open the modal.
			 */
			function openModal() {
				$modal.removeClass( 'ldce-modal--hidden' ).attr( 'aria-hidden', 'false' );
				$( 'body' ).addClass( 'ldce-modal-open' );
				$modal.find( '.ldce-modal-close' ).trigger( 'focus' );
			}

			/**
			 * Close the modal.
			 */
			function closeModal() {
				$modal.addClass( 'ldce-modal--hidden' ).attr( 'aria-hidden', 'true' );
				$( 'body' ).removeClass( 'ldce-modal-open' );
			}

			// Open modal and load history via AJAX.
			$( document ).on( 'click', '.ldce-view-history', function () {
				var $btn        = $( this );
				var userId      = $btn.data( 'user-id' );
				var courseId    = $btn.data( 'course-id' );
				var studentName = $btn.data( 'student-name' );

				$modalTitle.text( studentName );
				$modalContent.html( '<p class="ldce-loading">' + ( caelAdmin.loadingHistory || 'Loading...' ) + '</p>' );
				openModal();

				$.post(
					caelAdmin.ajaxUrl,
					{
						action    : 'cael_get_purchase_history',
						nonce     : caelAdmin.historyNonce,
						user_id   : userId,
						course_id : courseId,
					},
					function ( response ) {
						if ( response.success ) {
							$modalContent.html( response.data.html );
						} else {
							var msg = ( response.data && response.data.message ) ? response.data.message : caelAdmin.errorMsg;
							$modalContent.html( '<p class="ldce-empty">' + msg + '</p>' );
						}
					}
				).fail( function () {
					$modalContent.html( '<p class="ldce-empty">' + caelAdmin.errorMsg + '</p>' );
				} );
			} );

			// Close on X button.
			$( document ).on( 'click', '.ldce-modal-close', closeModal );

			// Close on overlay click.
			$( document ).on( 'click', '.ldce-modal-overlay', closeModal );

			// Close on Escape key.
			$( document ).on( 'keydown', function ( e ) {
				if ( 27 === e.which && ! $modal.hasClass( 'ldce-modal--hidden' ) ) {
					closeModal();
				}
			} );

		} // end if $modal.length

		// -------------------------------------------------------------------------
		// Clear All Logs confirmation — replaces inline onclick= handler.
		// -------------------------------------------------------------------------

		$( document ).on( 'click', '.ldce-confirm-clear', function ( e ) {
			var msg = $( this ).data( 'confirm' ) || caelAdmin.confirmClear || 'Are you sure?';
			if ( ! window.confirm( msg ) ) {
				e.preventDefault();
				return false;
			}
		} );

	} ); // end $(function(){})

} )( jQuery );
