/*
 * Psyerns AuctionHouse — admin JS.
 *
 * Tab switching is server-rendered (?tab=...), so this file only handles:
 *  - API-key rotate confirmation
 *  - Admin-cancel inline confirmation
 *  - Reset-data two-step confirmation (type RESET)
 *
 * Localized strings live on `PsyernAHAdmin.i18n` (see enqueue_assets()).
 */
(function ($) {
	'use strict';

	$(function () {
		var i18n = (window.PsyernAHAdmin && window.PsyernAHAdmin.i18n) || {};

		// ── Rotate API key confirmation ──────────────────────────
		$('form.psyern-ah-admin__rotate-form').on('submit', function (e) {
			var msg = i18n.confirmRotate || 'Rotate the API key?';
			if (!window.confirm(msg)) {
				e.preventDefault();
				return false;
			}
			return true;
		});

		// ── Admin-cancel confirmation ────────────────────────────
		$('form.psyern-ah-admin__cancel-form').on('submit', function (e) {
			var msg = i18n.confirmCancel || 'Cancel this listing?';
			if (!window.confirm(msg)) {
				e.preventDefault();
				return false;
			}
			return true;
		});

		// ── Reset-data two-step ──────────────────────────────────
		var $resetForm = $('form.psyern-ah-admin__reset-form');
		var $resetInput = $resetForm.find('input[name="confirm"]');
		var $resetButton = $resetForm.find('button[type="submit"]');

		function updateResetState() {
			var literal = i18n.resetLiteral || 'RESET';
			$resetButton.prop('disabled', $resetInput.val() !== literal);
		}

		$resetInput.on('input keyup change', updateResetState);
		updateResetState();

		$resetForm.on('submit', function (e) {
			var literal = i18n.resetLiteral || 'RESET';
			if ($resetInput.val() !== literal) {
				e.preventDefault();
				return false;
			}
			// Extra safety: native confirm() before the destructive POST.
			if (!window.confirm('Wipe all plugin data? This cannot be undone.')) {
				e.preventDefault();
				return false;
			}
			return true;
		});

		// ── JSON textarea shallow validation hint ────────────────
		$('textarea.psyern-ah-admin__json-editor').on('blur', function () {
			var $t = $(this);
			var val = $.trim($t.val() || '');
			var $hint = $t.siblings('.psyern-ah-admin__json-hint');
			if (!$hint.length) {
				$hint = $('<p class="psyern-ah-admin__json-hint psyern-ah-admin__help"></p>');
				$t.after($hint);
			}
			if (val === '') {
				$hint.text('').removeClass('notice notice-error notice-success');
				return;
			}
			try {
				JSON.parse(val);
				$hint.text('JSON syntax looks valid. Save to apply schema validation.').css('color', '#007017');
			} catch (err) {
				$hint.text('JSON parser error: ' + err.message).css('color', '#a00');
			}
		});
	});
})(jQuery);
