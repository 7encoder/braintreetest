(function($){
	'use strict';

	const state = {
		loading: false,
		plans: [],
		lastError: null
	};

	function isSubscription(){
		return $('select[name="transactionType"]').val() === 'subscription';
	}
	function planSource(){
		return $('select[name="subscription_plan_source"]').val();
	}

	function $planSelect(){ return $('select[name="plan_id"]'); }
	function $planFieldSelect(){ return $('select[name="plan_field"]'); }
	function $status(){ return $('[data-gf-braintree-role="plan-status"]'); }
	function $refreshBtn(){ return $('[data-gf-braintree-role="refresh-plans"]'); }

	function setStatus(text, cls){
		const $s = $status();
		$s.removeClass('loading error ok').text(text || '');
		if(cls){ $s.addClass(cls); }
	}

	function disablePlanUI(disabled){
		$planSelect().prop('disabled', disabled);
		$planFieldSelect().prop('disabled', disabled);
		$refreshBtn().prop('disabled', disabled);
		if(disabled){
			setStatus('', '');
		}
	}

	function populatePlans(){
		const $sel = $planSelect();
		const currentVal = $sel.data('stored') || $sel.val();
		$sel.empty();
		$sel.append($('<option/>',{value:'',text:gfBraintreeFeed.i18n.selectPlan}));
		state.plans.forEach(p=>{
			$sel.append($('<option/>',{value:p.id,text:p.name}));
		});
		if(currentVal){ $sel.val(currentVal); }
		if(!$sel.val()){ $sel.prop('selectedIndex',0); }
		$sel.trigger('change');
	}

	let inFlightXHR = null;

	function fetchPlans(manual){
		if(!isSubscription()){
			return;
		}
		if(!gfBraintreeFeed.hasCreds){
			setStatus('Credentials incomplete','error');
			return;
		}
		if(state.loading){ return; }
		state.loading = true;
		state.lastError = null;

		setStatus(gfBraintreeFeed.i18n.loading,'loading');
		$refreshBtn().prop('disabled', true);

		if(inFlightXHR){ inFlightXHR.abort(); }

		inFlightXHR = $.ajax({
			url: gfBraintreeFeed.ajax,
			method:'POST',
			dataType:'json',
			data:{ action:'gf_braintree_fetch_plans', nonce: gfBraintreeFeed.nonce, manual: manual ? 1 : 0 }
		}).done(function(resp){
			if(!resp || !resp.success){
				state.plans = [];
				state.lastError = (resp && resp.data && resp.data.message) || 'error';
				setStatus(gfBraintreeFeed.i18n.failed,'error');
				return;
			}
			const data = resp.data;
			if(data.empty){
				state.plans = [];
				populatePlans();
				setStatus(gfBraintreeFeed.i18n.empty,'ok');
				return;
			}
			state.plans = data.plans || [];
			populatePlans();
			setStatus('Loaded ' + state.plans.length + ' plan(s)','ok');
		}).fail(function(xhr){
			if(xhr.statusText === 'abort'){ return; }
			state.plans = [];
			state.lastError = xhr.status;
			setStatus(gfBraintreeFeed.i18n.failed,'error');
		}).always(function(){
			state.loading = false;
			if(isSubscription()){
				$refreshBtn().prop('disabled', false);
			}
		});
	}

	function togglePlanFields(){
		const subscription = isSubscription();
		const source = planSource();
		$('.gf-braintree-subscription-control').closest('tr').toggle(subscription);
		const showStatic = subscription && source === 'static';
		const showField  = subscription && source === 'field';
		$('.gf-braintree-plan-static').closest('tr')[showStatic ? 'show' : 'hide']();
		$('.gf-braintree-plan-field-select').closest('tr')[showField ? 'show' : 'hide']();
		disablePlanUI(!subscription);
		if(subscription && source === 'static' && !state.plans.length){
			fetchPlans(false);
		}
	}

	$(document).on('change', 'select[name="transactionType"], select[name="subscription_plan_source"]', togglePlanFields);
	$(document).on('click', '[data-gf-braintree-role="refresh-plans"]', function(e){
		e.preventDefault();
		if(!isSubscription()) return;
		fetchPlans(true);
	});

	$(function(){
		togglePlanFields();
	});

})(jQuery);