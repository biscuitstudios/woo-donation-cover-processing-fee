/* global jQuery, wooCoverFee */
jQuery(function ($) {
	'use strict';

	var BOX = '#woo-cover-fee-wrapper';

	/**
	 * Physically move the box to sit directly above #payment.
	 * Runs on load and after every checkout refresh, so it stays in place
	 * no matter what hook/priority order the theme or other plugins use.
	 */
	function repositionBox() {
		var $box = $(BOX);
		var $payment = $('#payment');
		if ($box.length && $payment.length) {
			$box.insertBefore($payment);
		}
	}

	/**
	 * Show a validation or network message.
	 *
	 * Uses .text() rather than .html() — the string may originate from the
	 * server, and jQuery executes markup passed to .html().
	 */
	function showError(message) {
		$(BOX).find('.wcf-error').text(message).prop('hidden', false);
	}

	function clearError() {
		$(BOX).find('.wcf-error').text('').prop('hidden', true);
	}

	/**
	 * Push state to the server, then let WooCommerce recalculate.
	 *
	 * The box normally lives inside the order-review fragment, so
	 * update_checkout re-renders it from session state on the PHP side.
	 * But repositionBox() may have moved it next to #payment, which some
	 * themes place outside the replaced region — in that case the re-render
	 * never touches it. syncSelection() reconciles the buttons against the
	 * amount the server just confirmed, so the visible state is correct
	 * either way. The server remains the only authority on the amount.
	 */
	function syncSelection(data) {
		var $box = $(BOX);
		var amount = parseFloat(data && data.donation) || 0;
		var $presets = $box.find('.wcf-amount').not('.wcf-other-toggle');
		var $other = $box.find('.wcf-other-toggle');
		var matched = false;

		// aria-pressed belongs only to the presets; the Other button is an
		// expand control and carries aria-expanded instead.
		$box.find('.wcf-amount').removeClass('is-selected');
		$presets.attr('aria-pressed', 'false');

		if (amount > 0) {
			$presets.each(function () {
				if (parseFloat($(this).data('amount')) === amount) {
					$(this).addClass('is-selected').attr('aria-pressed', 'true');
					matched = true;
				}
			});
			if (!matched) {
				// Came from the custom field.
				$other.addClass('is-selected');
			}
		} else {
			$box.find('.wcf-other-input').val('');
		}

		// A mouse click leaves :focus on the button; drop it so no theme
		// focus style can imitate the selected fill.
		$box.find('.wcf-amount:focus, .wcf-apply:focus').trigger('blur');
	}

	function push(payload) {
		var $box = $(BOX);
		if ($box.hasClass('is-busy')) {
			return;
		}
		$box.addClass('is-busy');
		clearError();

		payload.action = 'woo_cover_fee_set';
		payload.nonce = wooCoverFee.nonce;

		$.post(wooCoverFee.ajaxUrl, payload)
			.done(function (response) {
				if (response && response.success) {
					syncSelection(response.data);
					$(document.body).trigger('update_checkout');
					return;
				}
				var message =
					response && response.data && response.data.message
						? response.data.message
						: wooCoverFee.genericError;
				showError(message);
			})
			.fail(function (xhr) {
				var message = wooCoverFee.genericError;
				if (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
					message = xhr.responseJSON.data.message;
				}
				showError(message);
			})
			.always(function () {
				$box.removeClass('is-busy');
			});
	}

	/* ------------------------------------------------------------------ */

	// Preset amount: click applies, clicking the selected one clears it.
	$(document).on('click', BOX + ' .wcf-amount:not(.wcf-other-toggle)', function () {
		var $btn = $(this);
		var amount = $btn.hasClass('is-selected') ? 0 : $btn.data('amount');
		push({ amount: amount });
	});

	// "Other": accordion. Opening does not apply anything on its own —
	// the amount is committed with the Apply button.
	$(document).on('click', BOX + ' .wcf-other-toggle', function () {
		var $btn = $(this);
		var $panel = $(BOX).find('.wcf-other-panel');

		// Already applied via Other — a second click clears it.
		if ($btn.hasClass('is-selected')) {
			push({ amount: 0 });
			return;
		}

		var isOpen = $panel.is(':visible');
		$panel.prop('hidden', isOpen);
		$btn.attr('aria-expanded', isOpen ? 'false' : 'true');
		clearError();

		if (!isOpen) {
			$panel.find('.wcf-other-input').trigger('focus');
		}
	});

	// Apply button commits the custom amount.
	$(document).on('click', BOX + ' .wcf-apply', function () {
		var value = $(BOX).find('.wcf-other-input').val();
		push({ amount: '' === $.trim(String(value)) ? 0 : value });
	});

	// Enter inside the field applies rather than submitting the checkout.
	$(document).on('keydown', BOX + ' .wcf-other-input', function (e) {
		if (13 === e.which) {
			e.preventDefault();
			$(BOX).find('.wcf-apply').trigger('click');
		}
	});

	// Processing-fee checkbox.
	$(document).on('change', BOX + ' #woo_cover_fee', function () {
		push({ fee: $(this).is(':checked') ? 'true' : 'false' });
	});

	/* ------------------------------------------------------------------ */

	repositionBox();
	$(document.body).on('updated_checkout', repositionBox);
});
