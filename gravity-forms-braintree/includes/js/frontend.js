(function($){
	'use strict';

	if(!window.gfBraintreeForms){ return; }

	var instances = {}; // formId => { hf, ready, tokenize, $container }

	// Map Braintree error codes to user-friendly messages.
	var errorMessages = {
		//'HOSTED_FIELDS_FIELDS_EMPTY': ...
		'HOSTED_FIELDS_FIELDS_EMPTY'           : 'Please enter your card details.',
		'HOSTED_FIELDS_FIELDS_INVALID'         : 'Some card fields are invalid. Please correct the highlighted fields.',
		'HOSTED_FIELDS_FIELD_EMPTY'            : 'This field is required.',
		'HOSTED_FIELDS_TOKENIZATION_FAIL_ON_DUPLICATE' : 'This card was already used and cannot be saved again.',
		'HOSTED_FIELDS_TOKENIZATION_CVV_VERIFICATION_FAILED' : 'Card security code failed verification.',
		'HOSTED_FIELDS_FAILED_TOKENIZATION'    : 'We could not process your card. Please verify details or try another card.',
		'HOSTED_FIELDS_TOKENIZATION_NETWORK_ERROR' : 'Network error while processing card. Please retry.',
		'HOSTED_FIELDS_CARD_TYPE_NOT_ACCEPTED' : 'That card type is not accepted.',
		'HOSTED_FIELDS_UNSUPPORTED_CARD_TYPE'  : 'Unsupported card type.',
	};

	function log(){
		if(!window.gfBraintreeDebug) return;
		// eslint-disable-next-line no-console
		console.log.apply(console, ['[GF Braintree]'].concat([].slice.call(arguments)));
	}

	function fetchClientToken(){
		return $.getJSON(gfBraintreeFront.ajax, { action: 'gf_braintree_client_token', t: Date.now() });
	}

	function ensureFieldErrorPlaceholders($container){
		$container.find('.bt-field-wrapper').each(function(){
			var $w = $(this);
			if(!$w.find('.bt-field-error').length){
				$w.append('<div class="bt-field-error" aria-live="polite"></div>');
			}
		});
		if(!$container.find('.gf-braintree-errors').length){
			$container.append('<div class="gf-braintree-errors" aria-live="polite"></div>');
		}
	}

	function renderStructure(formId){
		// Already present from PHP markup; we just wrap each row so we can show inline field errors.
		var $container = $('.gf-braintree-hosted-fields[data-form-id="' + formId + '"]');
		if(!$container.length){ return $container; }
		$container.find('.gf-braintree-row, .gf-braintree-sub').each(function(){
			var $row = $(this);
			if(!$row.hasClass('bt-field-wrapper')){
				$row.addClass('bt-field-wrapper');
			}
		});
		ensureFieldErrorPlaceholders($container);
		return $container;
	}

	function clearErrors($container){
		$container.find('.bt-field-error').text('');
		$container.find('.bt-input').removeClass('bt-invalid');
		$container.find('.gf-braintree-errors').text('');
	}

	function showGlobalError($container, msg){
		$container.find('.gf-braintree-errors').text(msg);
	}

	function showFieldError($el, msg){
		var $wrap = $el.closest('.bt-field-wrapper');
		$wrap.find('.bt-field-error').text(msg);
		$el.addClass('bt-invalid');
	}

	function mapErrorMessage(err){
		if(!err) return 'Card processing error.';
		if(err.code && errorMessages[err.code]){
			return errorMessages[err.code];
		}
		if(err.details && err.details.invalidFieldKeys && err.code === 'HOSTED_FIELDS_FIELDS_INVALID'){
			return errorMessages['HOSTED_FIELDS_FIELDS_INVALID'];
		}
		return err.message || 'Card processing error.';
	}

	function highlightInvalidFields(formId, err){
		var inst = instances[formId];
		if(!inst || !inst.hf) return;
		if(!err || !err.details) return;

		var invalidKeys = [];
		if(err.details.invalidFieldKeys){
			invalidKeys = err.details.invalidFieldKeys;
		} else if(err.fields){
			// Some legacy shapes
			Object.keys(err.fields).forEach(function(key){
				if(err.fields[key] && err.fields[key].isValid === false){
					invalidKeys.push(key);
				}
			});
		}

		invalidKeys.forEach(function(key){
			var field = inst.hf.getState().fields[key];
			if(field && field.container){
				showFieldError($(field.container), errorMessages['HOSTED_FIELDS_FIELD_EMPTY']);
			}
		});
	}

	function initHostedFields(formId){
		if(instances[formId] && instances[formId].ready) return;
		var cfg = window.gfBraintreeForms[formId];
		if(!cfg){ return; }

		var $container = renderStructure(formId);
		if(!$container.length){ return; }

		fetchClientToken().done(function(resp){
			if(!resp.success || !resp.data || !resp.data.token){
				showGlobalError($container, 'Unable to initialize payment fields.');
				return;
			}
			braintree.client.create({ authorization: resp.data.token }, function(err, client){
				if(err){
					showGlobalError($container, 'Client initialization failed.');
					log('Client error', err);
					return;
				}
				var hfConfig = {
					client: client,
					styles: {
						input: {
							'font-size':'15px',
							'font-family':'Helvetica, Arial, sans-serif',
							'color':'#333'
						},
						':focus': { 'color':'#000' },
						'.valid': { 'color':'#2c7' },
						'.invalid': { 'color':'#e53' }
					},
					fields: {
						number: { selector: '#bt-card-number-' + formId, placeholder: '•••• •••• •••• ••••' },
						expirationDate: { selector: '#bt-expiration-date-' + formId, placeholder: 'MM/YY' },
						cvv: { selector: '#bt-cvv-' + formId, placeholder: 'CVV' }
					}
				};
				if(cfg.postal){
					hfConfig.fields.postalCode = { selector: '#bt-postal-' + formId, placeholder:'12345' };
				}
				braintree.hostedFields.create(hfConfig, function(hfErr, hf){
					if(hfErr){
						showGlobalError($container, 'Card fields failed to load.');
						log('Hosted fields error', hfErr);
						return;
					}
					instances[formId] = { hf: hf, ready: true, $container: $container };
					log('Hosted Fields ready', formId);

					hf.on('validityChange', function(event){
						var field = event.fields[event.emittedBy];
						var $el = $(field.container);
						if(field.isValid){
							$el.removeClass('bt-invalid').addClass('bt-valid');
							$el.closest('.bt-field-wrapper').find('.bt-field-error').text('');
						}else if(!field.isPotentiallyValid){
							$el.removeClass('bt-valid').addClass('bt-invalid');
						}else{
							$el.removeClass('bt-valid bt-invalid');
						}
					});
					hf.on('focus', function(e){ $(e.fields[e.emittedBy].container).addClass('bt-focus'); });
					hf.on('blur', function(e){ $(e.fields[e.emittedBy].container).removeClass('bt-focus'); });
					hf.on('cardTypeChange', function(e){
						if(e.cards.length === 1){
							$container.attr('data-card-type', e.cards[0].type);
						}else{
							$container.removeAttr('data-card-type');
						}
					});

					instances[formId].tokenize = function(){
						return new Promise(function(resolve, reject){
							clearErrors($container);
							hf.tokenize(function(tErr, payload){
								if(tErr){
									log('Tokenize error', tErr);
									var msg = mapErrorMessage(tErr);
									showGlobalError($container, msg);
									highlightInvalidFields(formId, tErr);
									reject(tErr);
									return;
								}
								log('Tokenize success', payload);
								resolve(payload);
							});
						});
					};
				});
			});
		}).fail(function(){
			showGlobalError($container, 'Unable to reach payment server.');
		});
	}

	function parseFormId($form){
		var idAttr = $form.attr('id') || '';
		var formId = parseInt(idAttr.replace('gform_', ''), 10);
		return isNaN(formId) ? null : formId;
	}

	// GF re-render (multi-page / ajax)
	$(document).on('gform_post_render', function(e, formId){
		initHostedFields(formId);
	});

	// Submission interception
	$(document).on('submit', 'form.gform_wrapper form', function(e){
		var $form = $(this);
		var formId = parseFormId($form);
		if(!formId || !window.gfBraintreeForms || !window.gfBraintreeForms[formId]) return;

		// If hosted fields container not present just exit (maybe feed inactive).
		var $container = $('.gf-braintree-hosted-fields[data-form-id="' + formId + '"]');
		if(!$container.length){
			return;
		}

		var $nonce = $form.find('input[name="braintree_payment_method_nonce"]');
		if(!$nonce.length){
			return;
		}
		if($nonce.val()){
			return; // Already tokenized
		}

		var inst = instances[formId];
		if(!inst || !inst.ready){
			e.preventDefault();
			showGlobalError($container, 'Card fields not ready. Please wait a second and submit again.');
			return;
		}

		e.preventDefault();
		if($form.data('braintreeProcessing')) return;
		$form.data('braintreeProcessing', true);

		inst.tokenize()
			.then(function(payload){
				$nonce.val(payload.nonce);
				var cardType = (payload.details && payload.details.cardType) || '';
				var last4 = (payload.details && (payload.details.lastFour || payload.details.last4)) || '';
				$form.find('input[name="braintree_card_type"]').val(cardType);
				$form.find('input[name="braintree_card_last4"]').val(last4);
				$form.removeData('braintreeProcessing');
				$form.trigger('submit');
			})
			.catch(function(){
				$form.removeData('braintreeProcessing');
			});
	});

	// Initialize for non-AJAX single load
	$(function(){
		$('.gf-braintree-hosted-fields').each(function(){
			var formId = parseInt($(this).data('form-id'), 10);
			if(!isNaN(formId)){ initHostedFields(formId); }
		});
	});
})(jQuery);