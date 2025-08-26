/* global jQuery, GFBraintreeData, braintree */
(function($){
  'use strict';

  if (!window.GFBraintreeData || !GFBraintreeData.clientToken) return;

  const cfg         = window.GFBraintreeData;
  const selectors   = cfg.selectors || {};
  const nonceName   = cfg.nonceFieldName || 'gf_braintree_nonce';
  const deviceName  = cfg.deviceDataField || 'gf_braintree_device_data';

  let clientInstance = null;
  let hostedFieldsInstance = null;
  let initializing = false;
  let initTried = false;

  const REQUIRED_KEYS = ['number','cvv','expiration'];
  // postalCode optional

  /* ================= Utility ================= */

  function log(){ if (cfg.debug){ console.log('[GF Braintree]', ...arguments); } } // eslint-disable-line no-console
  function warn(){ if (cfg.debug){ console.warn('[GF Braintree]', ...arguments); } } // eslint-disable-line no-console
  function err(){ console.error('[GF Braintree]', ...arguments); } // eslint-disable-line no-console

  function safeJQ(el){
    const $el = (el && el.jquery) ? el : $(el);
    return ($el && $el.length) ? $el : $();
  }

  function findWrapperForField(selector){
    if (!selector) return $();
    const $el = $(selector);
    if (!$el.length) return $();
    const $w = $el.closest('.gf-braintree-wrapper');
    return $w.length ? $w : $();
  }

  function ensureHidden($form, name, value){
    if (!$form.length) return $();
    let $f = $form.find('input[name="'+name+'"]');
    if (!$f.length){
      $f = $('<input>', { type:'hidden', name:name });
      $form.append($f);
    }
    if (value !== undefined) $f.val(value);
    return $f;
  }

  function addMessage($context, msg){
    const $c = safeJQ($context);
    if (!$c.length) {
      warn('addMessage called with empty context', msg);
      return;
    }
    // Prefer wrapper if inside one
    let $wrapper = $c.hasClass('gf-braintree-wrapper') ? $c : $c.closest('.gf-braintree-wrapper');
    if (!$wrapper.length){
      // Try form then wrap
      const $form = $c.is('form') ? $c : $c.closest('form');
      if ($form.length){
        $wrapper = $form.find('.gf-braintree-wrapper').first();
      }
    }
    if (!$wrapper.length){
      warn('No wrapper found for message; skipping render', msg);
      return;
    }
    let $msg = $wrapper.find('.gf-braintree-messages').first();
    if (!$msg.length){
      $msg = $('<div class="gf-braintree-messages" aria-live="polite"></div>');
      $wrapper.append($msg);
    }
    $msg.text(msg || '');
  }

  function allTargetElementsPresent(){
    for (const key of REQUIRED_KEYS){
      const selKey = (key === 'expiration') ? 'expiration' : key;
      const map = {
        number: selectors.number,
        cvv: selectors.cvv,
        expiration: selectors.expiration
      };
      if (!map[key] || !$(map[key]).length){
        return false;
      }
    }
    if (selectors.postalCode && !$(selectors.postalCode).length){
      // If postal selector configured but not present, treat as not ready (prevents partial init)
      return false;
    }
    return true;
  }

  function markTokenized($form){
    $form.data('gf-braintree-tokenized', true);
  }
  function isTokenized($form){
    return !!$form.data('gf-braintree-tokenized');
  }

  /* ================= Hosted Fields Init ================= */

  function initHostedFields(){
    if (hostedFieldsInstance || initializing) return;
    if (!clientInstance) return;
    if (!allTargetElementsPresent()){
      log('Hosted Fields targets not yet present; postponing.');
      return;
    }

    initializing = true;
    log('Creating Hosted Fields.');

    const isMonospaceVariant = $('.gf-braintree-wrapper').first().hasClass('gf-braintree-style--monospace');
    const baseFontFamily     = isMonospaceVariant ? 'courier, monospace' : 'inherit';
    const baseFontColor      = isMonospaceVariant ? '#666' : '#1d2327';

    const fields = {
      number: {
        selector: selectors.number,
        placeholder: isMonospaceVariant ? '4111 1111 1111 1111' : ''
      },
      cvv: {
        selector: selectors.cvv,
        placeholder: isMonospaceVariant ? '123' : ''
      },
      expirationDate: {
        selector: selectors.expiration,
        placeholder: isMonospaceVariant ? 'MM/YY' : ''
      }
    };
    if (selectors.postalCode){
      fields.postalCode = {
        selector: selectors.postalCode,
        placeholder: isMonospaceVariant ? '11111' : ''
      };
    }

    braintree.hostedFields.create({
      client: clientInstance,
      styles: {
        'input': {
          'font-size': '16px',
          'font-family': baseFontFamily,
          'font-weight': isMonospaceVariant ? '600' : '400',
          'color': baseFontColor,
          'background-color':'transparent',
          'transition':'color .2s ease'
        },
        '::placeholder': {
          'color': isMonospaceVariant ? '#ccc' : '#6d6d6d'
        },
        ':focus': {
          'color': isMonospaceVariant ? 'black' : '#111'
        },
        '.valid': {
          'color': isMonospaceVariant ? '#64d18a' : '#2f855a'
        },
        '.invalid': {
          'color': '#ed574a'
        }
      },
      fields
    }, function(hfErr, hf){
      initializing = false;
      if (hfErr){
        err('hostedFields.create error', hfErr);
        addMessage($(selectors.number).closest('form'), cfg.messages.initError || 'Payment fields could not be initialized.');
        return;
      }
      hostedFieldsInstance = hf;
      bindHFEvents(hf);
      bindSubmission(hf);
      log('Hosted Fields ready.');
    });
  }

  function bindHFEvents(hf){
    const map = {
      number: selectors.number,
      cvv: selectors.cvv,
      expirationDate: selectors.expiration,
      postalCode: selectors.postalCode
    };

    function container(sel){
      if (!sel) return $();
      const $el = $(sel);
      if (!$el.length) return $();
      const $f = $el.closest('.gf-braintree-field');
      return $f.length ? $f : $el;
    }

    function update(e){
      const sel = map[e.emittedBy];
      if (!sel) return;
      const $c = container(sel);
      if (!$c.length) return;

      if (e.type === 'focus') {
        $c.addClass('is-focus');
      } else if (e.type === 'blur') {
        $c.removeClass('is-focus');
      }

      if (e.fields && e.fields[e.emittedBy]){
        const f = e.fields[e.emittedBy];
        if (f.isValid){
          $c.addClass('is-valid').removeClass('is-invalid');
        } else if (!f.isPotentiallyValid){
          $c.addClass('is-invalid').removeClass('is-valid');
        } else {
          $c.removeClass('is-valid is-invalid');
        }
      }
    }

    hf.on('focus', update);
    hf.on('blur', update);
    hf.on('validityChange', update);

    hf.on('cardTypeChange', function(event){
      if (!selectors.number) return;
      const $field = $(selectors.number).closest('.gf-braintree-field');
      if (!$field.length) return;
      const $badge = $field.find('.gf-braintree-brand-badge');
      $field.removeClass(function(i, cls){
        return (cls.match(/(^|\s)brand-\S+/g) || []).join(' ');
      }).removeClass('has-brand');

      if (!event.cards || !event.cards.length){
        if ($badge.length) $badge.attr('data-brand-short','');
        return;
      }

      const raw = (event.cards[0].type || '').toLowerCase();
      const mapType = {
        'visa':'visa',
        'master-card':'mastercard',
        'mastercard':'mastercard',
        'american-express':'amex',
        'discover':'discover',
        'diners-club':'diners',
        'jcb':'jcb',
        'unionpay':'unionpay'
      };
      const css = mapType[raw];
      if (!css) return;

      $field.addClass('has-brand brand-' + css);
      if ($badge.length){
        const shortMap = { visa:'V', mastercard:'MC', amex:'AX', discover:'DI', diners:'DC', jcb:'JCB', unionpay:'UP' };
        $badge.attr('data-brand-short', shortMap[css] || css.substring(0,2).toUpperCase());
      }
    });
  }

  function bindSubmission(hf){
    $('form').each(function(){
      const $form = $(this);
      if (!selectors.number || !$form.find(selectors.number).length) return;

      $form.off('submit.gfBraintree').on('submit.gfBraintree', function(e){
        if (isTokenized($form)) return true;
        e.preventDefault();

        hf.tokenize(function(tErr, payload){
          if (tErr){
            warn('Tokenize error', tErr);
            addMessage($form, cfg.messages.cardError || 'Card validation error.');
            return;
          }
          addMessage($form, '');
          ensureHidden($form, nonceName, payload.nonce);
          markTokenized($form);
          $form.trigger('submit');
        });
      });
    });
  }

  /* ================= Client Init ================= */

  function initClient(){
    if (initTried) return;
    initTried = true;

    braintree.client.create({
      authorization: cfg.clientToken
    }, function(cErr, client){
      if (cErr){
        err('client.create error', cErr);
        // Try to show a message near first wrapper if available
        const firstWrapper = $('.gf-braintree-wrapper').first();
        addMessage(firstWrapper.length ? firstWrapper : $('form').first(), cfg.messages.initError || 'Payment initialization error.');
        return;
      }
      clientInstance = client;

      if (cfg.collectDeviceData){
        braintree.dataCollector.create({
          client: client,
          kount: true
        }, function(dcErr, dc){
          if (dcErr){
            warn('Device data error', dcErr);
          } else {
            const dd = dc.deviceData;
            $('form').each(function(){
              ensureHidden($(this), deviceName, dd);
            });
          }
        });
      }

      initHostedFields();
    });
  }

  /* ================= Delayed / Reactive Init ================= */

  function attemptInitWithRetry(maxTries, interval){
    let tries = 0;
    const timer = setInterval(function(){
      if (hostedFieldsInstance){
        clearInterval(timer);
        return;
      }
      if (allTargetElementsPresent()){
        initHostedFields();
      }
      if (++tries >= maxTries){
        clearInterval(timer);
      }
    }, interval);
  }

  // Gravity Forms re-render events (multi-page / AJAX)
  $(document).on('gform_post_render', function(){
    if (clientInstance && !hostedFieldsInstance){
      initHostedFields();
    }
  });

  // Mutation observer: watch body for insertion of required elements
  const observer = new MutationObserver(function(){
    if (clientInstance && !hostedFieldsInstance && allTargetElementsPresent()){
      initHostedFields();
    }
  });
  if (window.MutationObserver){
    observer.observe(document.documentElement || document.body, { childList:true, subtree:true });
  }

  $(function(){
    initClient();
    // In case fields load late (theme builders), poll lightly
    attemptInitWithRetry(20, 300);
  });

})(jQuery);