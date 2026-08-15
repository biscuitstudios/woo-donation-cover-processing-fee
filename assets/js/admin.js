/* global jQuery, wooCoverFeeAdmin */
jQuery(function ($) {
	'use strict';

	$('.wcf-color').wpColorPicker();

	var $repeater = $('#wcf-amounts');

	/**
	 * Build a repeater row.
	 *
	 * Constructed with DOM methods rather than an HTML string so the
	 * currency symbol and label — both server-supplied — are never parsed
	 * as markup.
	 */
	function buildRow() {
		var $row = $('<div/>', { 'class': 'wcf-repeater-row' });

		$('<span/>', { 'class': 'wcf-currency' })
			.text(wooCoverFeeAdmin.currency)
			.appendTo($row);

		$('<input/>', {
			type: 'number',
			step: '0.01',
			min: '0',
			name: 'wcf[amounts][]',
			value: ''
		}).appendTo($row);

		$('<button/>', {
			type: 'button',
			'class': 'wcf-remove-row',
			'aria-label': wooCoverFeeAdmin.removeLabel
		})
			.text('×')
			.appendTo($row);

		return $row;
	}

	$('#wcf-add-amount').on('click', function () {
		var $row = buildRow();
		$repeater.append($row);
		$row.find('input').trigger('focus');
	});

	$repeater.on('click', '.wcf-remove-row', function () {
		$(this).closest('.wcf-repeater-row').remove();
	});
});
