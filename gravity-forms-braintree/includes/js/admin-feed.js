(function($){
	'use strict';

	const state = {
		loading: false,
		plans: [],
		lastError: null
	};

	function log(){
		if(window.gfBraintreeDebug){ // optional debug flag
			// eslint-disable-next-line no-console
			console.log.apply(console, ['[GF Braintree Feed]'].concat([].slice.call(arguments)));
		}
	}

	function isSubscription(){
		return $('select[name="transactionType"]').val() === 'subscription';
	}

	function planSource(){
		return $('select[name="subscription_plan_source"]').val();
	}

	function $planSelect(){
		return $('select[name="plan_id"]');
	}

	function $planFieldSelect(){
		return $('select[name="plan_field"]');
	}

	function $status(){
		return $('[data-gf-braintree-role="plan-status"]');
	}

	function $refreshBtn(){
		return $('[data-gf-braintree-role="refresh-plans"]');
	}

	function setStatus(text, cls){
		const $s = $status();
		$s.removeClass('loading error ok').text(text || '');
		if(cls){
			$s.addClass(cls);
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
		if(currentVal){
			$sel.val(currentVal);
		}
		if(!$sel.val()){
			$sel.prop('selectedIndex',0);
		}
		$sel.trigger('change');
	}

	let inFlightXHR = null;

	function fetchPlans(manual){
		if(!gfBraintreeFeed.hasCreds){
			setStatus('Credentials incomplete', 'error');
			return;
		}
		if(state.loading){
			return;
		}
		state.loading = true;
		state.lastError = null;

		setStatus(gfBraintreeFeed.i18n.loading,'loading');
		$refreshBtn().prop('disabled', true);

		if(inFlightXHR){
			inFlightXHR.abort();
		}

		inFlightXHR = $.ajax({
			url: gfBraintreeFeed.ajax,
			method:'POST',
			dataType:'json',
			data:{
				action:'gf_braintree_fetch_plans',
				nonce: gfBraintreeFeed.nonce,
				manual: manual ? 1 : 0
			}
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
			if(xhr.statusText === 'abort'){
				return;
			}
			state.plans = [];
			state.lastError = xhr.status;
			setStatus(gfBraintreeFeed.i18n.failed,'error');
		}).always(function(){
			state.loading = false;
			$refreshBtn().prop('disabled', false);
		});
	}

	function maybeAutoLoad(){
		if(isSubscription() && planSource()==='fixed' && state.plans.length===0){
			fetchPlans(false);
		}
	}

	function onTypeChange(){
		if(isSubscription()){
			// Show plan sections handled by GF dependency already; we just ensure plans present.
			maybeAutoLoad();
		}else{
			setStatus('', '');
		}
	}

	function onPlanSourceChange(){
		if(planSource()==='fixed'){
			maybeAutoLoad();
		}else{
			setStatus('', '');
		}
	}

	function validateBeforeSave(){
		const $form = $('form#gform-settings');
		if(! $form.length){ return; }

		$form.on('submit', function(e){
			if(!isSubscription()){
				return; // product: no plan required
			}
			const src = planSource();
			if(src === 'fixed'){
				const plan = $planSelect().val();
				if(!plan){
					e.preventDefault();
					alert(gfBraintreeFeed.i18n.needPlan);
					$planSelect().focus();
					return false;
				}
			}else if(src === 'field'){
				const fld = $planFieldSelect().val();
				if(!fld){
					e.preventDefault();
					alert(gfBraintreeFeed.i18n.needPlanField);
					$planFieldSelect().focus();
					return false;
				}
			}
			return true;
		});
	}

	function bindEvents(){
		$('select[name="transactionType"]').on('change', onTypeChange);
		$('select[name="subscription_plan_source"]').on('change', onPlanSourceChange);
		$refreshBtn().on('click', function(){
			fetchPlans(true);
		});
	}

	$(function(){
		// Store initially selected plan (GF repop will set it; we capture for repopulate).
		const current = $planSelect().val();
		if(current){
			$planSelect().data('stored', current);
		}
		bindEvents();
		validateBeforeSave();
		maybeAutoLoad();
	});

})(jQuery);