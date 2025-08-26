(function($){
	'use strict';

	function updateStatus(msg, isError){
		const $s = $('#gf-braintree-refresh-status');
		$s.text(msg);
		$s.toggleClass('gf-braintree-error', !!isError);
	}

	function rebuildPlanSelect(plans){
		const $sel = $('#plan_id');
		if (!$sel.length) {
			return;
		}
		const currentVal = $sel.val();
		$sel.empty();

		if (!plans.length) {
			$sel.append(
				$('<option/>').val('').text(GFBraintreeAdmin.strings.none)
			);
		} else {
			plans.forEach(function(p){
				$sel.append(
					$('<option/>').val(p.id).text(p.name)
				);
			});
		}

		// Try to preserve previously selected plan if it still exists.
		if (currentVal && $sel.find('option[value="' + currentVal + '"]').length) {
			$sel.val(currentVal);
		} else if (!plans.length) {
			$sel.val('');
		}
	}

	$(document).on('click', '#gf-braintree-refresh-plans', function(e){
		e.preventDefault();
		const $btn = $(this);
		if ($btn.data('loading')) {
			return;
		}
		$btn.data('loading', true)
			.prop('disabled', true)
			.addClass('updating-message');
		updateStatus(GFBraintreeAdmin.strings.refreshing, false);

		$.ajax({
			url: GFBraintreeAdmin.ajaxUrl,
			type: 'POST',
			dataType: 'json',
			data: {
				action: 'gf_braintree_refresh_plans',
				nonce: GFBraintreeAdmin.nonce
			}
		}).done(function(resp){
			if (resp && resp.success) {
				rebuildPlanSelect(resp.data.plans || []);
				updateStatus(resp.data.message || GFBraintreeAdmin.strings.done, false);
			} else {
				updateStatus(
					(resp && resp.data && resp.data.message) ? resp.data.message : GFBraintreeAdmin.strings.error,
					true
				);
			}
		}).fail(function(){
			updateStatus(GFBraintreeAdmin.strings.error, true);
		}).always(function(){
			$btn.data('loading', false)
				.prop('disabled', false)
				.removeClass('updating-message');
		});
	});

})(jQuery);