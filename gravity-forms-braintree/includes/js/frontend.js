(function($){
	'use strict';

	if(!window.gfBraintreeForms){ return; }

	var instances = {}; // formId => state object
	var errorMessages = {
		'HOSTED_FIELDS_FIELDS_EMPTY'                    : 'Please enter your card details.',
		'HOSTED_FIELDS_FIELDS_INVALID'                  : 'Some card fields are invalid.',
		'HOSTED_FIELDS_FIELD_EMPTY'                     : 'This field is required.',
		'HOSTED_FIELDS_TOKENIZATION_FAIL_ON_DUPLICATE'  : 'This card was already used and cannot be saved again.',
		'HOSTED_FIELDS_TOKENIZATION_CVV_VERIFICATION_FAILED' : 'Card security code failed verification.',
		'HOSTED_FIELDS_FAILED_TOKENIZATION'             : 'We could not process your card. Please verify details or try another card.',
		'HOSTED_FIELDS_TOKENIZATION_NETWORK_ERROR'      : 'Network error while processing card. Please retry.',
		'HOSTED_FIELDS_CARD_TYPE_NOT_ACCEPTED'          : 'That card type is not accepted.',
		'HOSTED_FIELDS_UNSUPPORTED_CARD_TYPE'           : 'Unsupported card type.'
	};

	function mapErrorMessage(err){
		if(!err) return 'Card processing error.';
		if(err.code && errorMessages[err.code]) return errorMessages[err.code];
		if(err.details && err.details.invalidFieldKeys) return 'Some card fields are invalid.';
		return err.message || 'Card processing error.';
	}

	function scrollIntoViewIfNeeded(el){
		if(!el) return;
		try {
			el.scrollIntoView({behavior:'smooth', block:'center'});
		}catch(_){}
	}

	function showGlobalError(inst, msg){
		var $errors = inst.$container.find('.gf-braintree-errors');
		$errors.text(msg);
		inst.$container.addClass('gfield_error');
		scrollIntoViewIfNeeded(inst.$container[0]);
	}

	function clearGlobalError(inst){
		inst.$container.removeClass('gfield_error');
		inst.$container.find('.gf-braintree-errors').text('');
	}

	function setSubmitting(inst, submitting){
		var $form = inst.$form;
		var $buttons = $form.find('button, input[type=submit]');
		if(submitting){
			$buttons.prop('disabled', true).addClass('bt-disabled');
		}else{
			$buttons.prop('disabled', false).removeClass('bt-disabled');
		}
	}

	function updateCardIcons($container, types){
		$container.find('.gform_card_icon').removeClass('gform_card_icon_active');
		if(!types || !types.length) return;
		var type = types[0].type;
		var map = {
			'visa':'visa',
			'mastercard':'mastercard',
			'american-express':'amex',
			'discover':'discover'
		};
		var cls = map[type];
		if(cls){
			$container.find('.gform_card_icon_'+cls).addClass('gform_card_icon_active');
		}
	}

	function fieldLabel(name){
		switch(name){
			case 'number': return 'Card number';
			case 'cvv': return 'Security code';
			case 'expirationDate': return 'Expiration date';
			case 'postalCode': return 'Postal code';
			default: return name;
		}
	}

	function fetchClientToken(){
		return $.getJSON(gfBraintreeFront.ajax, {
			action   : 'gf_braintree_client_token',
			_wpnonce : gfBraintreeFront.nonce,
			t        : Date.now()
		});
	}

	function initDataCollector(clientInstance, inst){
		var cfg = window.gfBraintreeForms[inst.formId];
		if(!cfg || !cfg.collectDeviceData) return;
		if(!window.braintree || !window.braintree.dataCollector) return;
		window.braintree.dataCollector.create({
			client: clientInstance,
			kount: true
		}, function(err, dataCollectorInstance){
			if(err){ return; }
			var deviceData = dataCollectorInstance.deviceData;
			inst.deviceData = deviceData;
			var $field = inst.$form.find('input[name="gf_braintree_device_data"]');
			if(!$field.length){
				$field = $('<input/>',{type:'hidden',name:'gf_braintree_device_data'});
				inst.$form.append($field);
			}
			$field.val(deviceData);
		});
	}

	function initHostedFields(inst, clientToken){
		if(!window.braintree || !window.braintree.hostedFields){
			showGlobalError(inst, 'Braintree JS SDK not fully loaded.');
			return;
		}
		window.braintree.client.create({
			authorization: clientToken
		}, function(err, clientInstance){
			if(err){
				showGlobalError(inst, mapErrorMessage(err));
				return;
			}

			initDataCollector(clientInstance, inst);

			var idPrefix = 'bt-' + inst.formId + '-';
			var fieldsConfig = {
				number:   { selector: '#' + idPrefix + 'number', placeholder: '4111 1111 1111 1111' },
				cvv:      { selector: '#' + idPrefix + 'cvv', placeholder: '123' },
				expirationDate: { selector: '#' + idPrefix + 'exp', placeholder: 'MM/YY' }
			};
			if(inst.config.enablePostal){
				fieldsConfig.postalCode = { selector: '#' + idPrefix + 'postal', placeholder: 'Postal' };
			}

			window.braintree.hostedFields.create({
				client: clientInstance,
				fields: fieldsConfig,
				styles: inst.config.styles || {
					'input': {'font-size':'16px','font-family':'inherit','color':'#2c3e50'},
					':focus': {'color':'#000'},
					'.valid': {'color':'#2f855a'},
					'.invalid': {'color':'#e53e3e'}
				}
			}, function(err, hostedFields){
				if(err){
					showGlobalError(inst, mapErrorMessage(err));
					return;
				}

				inst.hostedFields = hostedFields;
				inst.ready = true;

				// Events
				hostedFields.on('cardTypeChange', function(event){
					updateCardIcons(inst.$container, event.cards || []);
				});

				hostedFields.on('focus', function(event){
					inst.$container.addClass('bt-focus-' + event.emittedBy);
				});
				hostedFields.on('blur', function(event){
					inst.$container.removeClass('bt-focus-' + event.emittedBy);
				});

				hostedFields.on('validityChange', function(event){
					var field = event.fields[event.emittedBy];
					var wrapId = '#bt-' + inst.formId + '-' + (event.emittedBy === 'expirationDate' ? 'exp' : event.emittedBy) + '-wrap';
					var $wrap = $(wrapId);
					var $err = $wrap.find('.bt-field-error');
					if(field.isValid){
						$wrap.removeClass('bt-field-invalid').addClass('bt-field-valid');
						$err.text('');
					}else if(!field.isPotentiallyValid){
						$wrap.removeClass('bt-field-valid').addClass('bt-field-invalid');
						$err.text('Invalid ' + fieldLabel(event.emittedBy));
					}else{
						$wrap.removeClass('bt-field-valid bt-field-invalid');
						$err.text('');
					}
				});

				hostedFields.on('inputSubmitRequest', function(){
					// Optionally attempt auto-submit if all valid
					var state = hostedFields.getState();
					var invalid = Object.keys(state.fields)
						.filter(function(k){ return !state.fields[k].isValid; });
					if(invalid.length){
						var first = invalid[0];
						hostedFields.focus(first);
					}else{
						inst.$form.trigger('submit');
					}
				});
			});
		});
	}

	function tokenizeAndSubmit(inst){
		if(inst.tokenized || !inst.ready){
			return;
		}
		clearGlobalError(inst);
		setSubmitting(inst, true);

		inst.hostedFields.tokenize(function(err, payload){
			if(err){
				setSubmitting(inst, false);
				showGlobalError(inst, mapErrorMessage(err));

				// Highlight each invalid field
				if(err.details && err.details.invalidFieldKeys){
					err.details.invalidFieldKeys.forEach(function(key){
						var wrapId = '#bt-' + inst.formId + '-' + (key === 'expirationDate' ? 'exp' : key) + '-wrap';
						$(wrapId).addClass('bt-field-invalid');
					});
				}
				return;
			}
			var $nonceField = inst.$form.find('input[name="gf_braintree_nonce"]');
			if(!$nonceField.length){
				$nonceField = $('<input/>',{type:'hidden',name:'gf_braintree_nonce'});
				inst.$form.append($nonceField);
			}
			$nonceField.val(payload.nonce);
			inst.tokenized = true;
			setSubmitting(inst, false);
			inst.$form.trigger('submit');
		});
	}

	function bindFormSubmission(inst){
		inst.$form.on('submit.gfBraintree', function(e){
			if(inst.tokenized){
				return; // allow normal submit
			}
			if(!inst.hostedFields){
				return; // fallback
			}
			e.preventDefault();
			tokenizeAndSubmit(inst);
		});
	}

	function initForm(formId){
		var cfg = window.gfBraintreeForms[formId];
		if(!cfg){ return; }

		var $container = $('.gf_braintree_cc_container[data-form-id="'+formId+'"]');
		if(!$container.length){ return; }

		var inst = {
			formId: formId,
			config: cfg,
			$container: $container,
			$form: $('#gform_'+formId),
			hostedFields: null,
			ready: false,
			tokenized: false,
			deviceData: null
		};
		instances[formId] = inst;

		fetchClientToken().done(function(resp){
			if(!resp || !resp.success || !resp.data || !resp.data.token){
				showGlobalError(inst, 'Unable to initialize payment fields.');
				return;
			}
			initHostedFields(inst, resp.data.token);
		}).fail(function(){
			showGlobalError(inst, 'Network error initializing payment fields.');
		});

		bindFormSubmission(inst);
	}

	$(function(){
		Object.keys(window.gfBraintreeForms).forEach(function(formId){
			initForm(formId);
		});
	});
})(jQuery);