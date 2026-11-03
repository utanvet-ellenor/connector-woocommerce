(function( $ ) {
    'use strict';

    var lastSentPayload = null;
    var debounceTimer = null;
    var pollerTimer = null;
    var pollerCount = 0;
    var maxPolls = 20;
    var pollIntervalMs = 1000;
    var usesBlocks = !!ajax_object.uses_blocks;
    var blocksUnsubscribe = null;
    var lastObservedBlocksState = null;
    var blocksRequestPending = false;

    function isUsableElement(element) {
        if (!element) {
            return false;
        }

        if (element.disabled) {
            return false;
        }

        var type = (element.getAttribute('type') || '').toLowerCase();
        if (type === 'hidden') {
            return false;
        }

        if (typeof element.getClientRects === 'function' && element.getClientRects().length === 0) {
            return false;
        }

        return true;
    }

    function getPrimaryFieldElement(selectors) {
        var fallback = null;
        var firstUsable = null;
        var shippingToggle = document.querySelector('#ship-to-different-address-checkbox');

        for (var i = 0; i < selectors.length; i++) {
            var element = document.querySelector(selectors[i]);
            if (!element) {
                continue;
            }
            var fieldName = element.name || element.id || '';
            if (shippingToggle && !shippingToggle.checked && fieldName.indexOf('shipping_') === 0) {
                continue;
            }
            // Country-specific fields and enhanced selects may be hidden but still submitted.
            if (shippingToggle && shippingToggle.checked && fieldName.indexOf('shipping_') === 0 && !element.disabled) {
                return element;
            }

            if (!fallback) {
                fallback = element;
            }

            if (!isUsableElement(element) || firstUsable) {
                continue;
            }

            firstUsable = element;
        }

        return firstUsable || fallback;
    }

    function getFieldValue(selectors) {
        var element = getPrimaryFieldElement(selectors);
        if (!element) {
            return '';
        }

        return getElementValue(element);
    }

    var shippingPhoneSelectors = ['input[name=shipping_phone]', '#shipping_phone', '#shipping-phone'];
    var billingPhoneSelectors = ['input[name=billing_phone]', '#billing_phone', '#billing-phone', 'input[name=phone]', '#phone', 'input[name*=phone]', 'input[type=tel]'];

    var SELECTORS = {
        email: [
            'input[name=shipping_email]',
            '#shipping_email',
            'input[name=billing_email]',
            '#billing_email',
            '.woocommerce-checkout input#email',
            'input[name=email]',
            '#email',
            'input[name*=email]'
        ],
        phone: shippingPhoneSelectors.concat(billingPhoneSelectors),
        country: [
            'select[name=shipping_country]',
            'input[name=shipping_country]',
            '#shipping_country',
            '#shipping-country',
            'select[name=billing_country]',
            'input[name=billing_country]',
            '#billing_country',
            '#billing-country',
            'select[name=country]',
            'input[name=country]',
            '#country',
            'select[name*=country]',
            'input[name*=country]'
        ],
        postal: [
            'input[name=shipping_postcode]',
            '#shipping_postcode',
            'input[name=billing_postcode]',
            '#billing_postcode',
            'input[name=postcode]',
            '#postcode',
            'input[name*=postcode]',
            'input[name*=postal]',
            'input[name*=zip]'
        ],
        address1: [
            'input[name=shipping_address_1]',
            '#shipping_address_1',
            'textarea[name=shipping_address_1]',
            'input[name=billing_address_1]',
            '#billing_address_1',
            'textarea[name=billing_address_1]',
            'input[name=address_1]',
            '#address_1',
            'textarea[name=address_1]',
            'input[name*=address_1]',
            'input[name*=address-1]',
            'input[name*=address1]',
            'input[name*=address-line-1]'
        ],
        address2: [
            'input[name=shipping_address_2]',
            '#shipping_address_2',
            'textarea[name=shipping_address_2]',
            'input[name=billing_address_2]',
            '#billing_address_2',
            'textarea[name=billing_address_2]',
            'input[name=address_2]',
            '#address_2',
            'textarea[name=address_2]',
            'input[name*=address_2]',
            'input[name*=address-2]',
            'input[name*=address2]',
            'input[name*=address-line-2]'
        ]
    };

    function buildSelector(keys) {
        var selectors = [];

        for (var i = 0; i < keys.length; i++) {
            selectors = selectors.concat(SELECTORS[keys[i]]);
        }

        return selectors.join(', ');
    }

    function getElementValue(element) {
        if (!element || typeof element.value !== 'string') {
            return '';
        }

        return element.value.trim();
    }

    function isTextLikeField(element) {
        if (!element || !element.tagName) {
            return false;
        }

        var tagName = element.tagName.toLowerCase();
        if (tagName === 'textarea') {
            return true;
        }

        if (tagName !== 'input') {
            return false;
        }

        var type = (element.getAttribute('type') || 'text').toLowerCase();
        return ['text', 'email', 'search', 'tel', 'url', 'password', 'number'].indexOf(type) !== -1;
    }

    function buildPayload() {
        if (usesBlocks) {
            return buildBlocksPayload();
        }

        return {
            email: getFieldValue(SELECTORS.email),
            phone_number: getFieldValue(SELECTORS.phone),
            country_code: getFieldValue(SELECTORS.country),
            postal_code: getFieldValue(SELECTORS.postal),
            address_line: [
                getFieldValue(SELECTORS.address1),
                getFieldValue(SELECTORS.address2)
            ].filter(Boolean).join(' ').trim()
        };
    }

    function getBlocksCart() {
        return window.wp.data.select(window.wc.wcBlocksData.cartStore);
    }

    function buildBlocksPayload() {
        var cart = getBlocksCart().getCartData();
        var billing = cart.billingAddress || {};
        var shipping = cart.shippingAddress || {};

        // Match the Store API checkout hook so both paths share the same cache key.
        return {
            email: shipping.email ?? billing.email ?? '',
            phone_number: shipping.phone ?? billing.phone ?? '',
            country_code: shipping.country ?? billing.country ?? '',
            postal_code: shipping.postcode ?? billing.postcode ?? '',
            address_line: [
                shipping.address_1 ?? billing.address_1 ?? '',
                shipping.address_2 ?? billing.address_2 ?? ''
            ].filter(Boolean).join(' ').trim()
        };
    }

    function isFieldRequired(selectors) {
        var element = getPrimaryFieldElement(selectors);
        if (!element) {
            return false;
        }

        return !!(element.required
            || element.getAttribute('required') !== null
            || element.getAttribute('aria-required') === 'true'
            || element.getAttribute('data-required') === 'true'
            || (element.closest && element.closest('.validate-required')));
    }

    function isCompletePayload(payload) {
        if (!payload.email) {
            return false;
        }

        var phoneSelectors = SELECTORS.phone;
        if (usesBlocks) {
            var cart = getBlocksCart().getCartData();
            phoneSelectors = cart.shippingAddress && cart.shippingAddress.phone != null ? shippingPhoneSelectors : billingPhoneSelectors;
        }
        var emailElement = getPrimaryFieldElement(SELECTORS.email);
        var isEmailValid;

        if (!usesBlocks && emailElement && typeof emailElement.checkValidity === 'function' && (emailElement.type || '').toLowerCase() === 'email') {
            isEmailValid = emailElement.checkValidity();
        } else {
            isEmailValid = /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(payload.email);
        }

        return !!(
            isEmailValid &&
            payload.country_code &&
            payload.postal_code &&
            payload.address_line &&
            (payload.phone_number || !isFieldRequired(phoneSelectors))
        );
    }

    function checkUVBService(payload) {
        if (usesBlocks && (!hasBlocksCartItems() || blocksRequestPending || getBlocksCart().isCustomerDataUpdating())) {
            return;
        }

        if (!isCompletePayload(payload)) {
            return;
        }

        var payloadKey = JSON.stringify(payload);
        if (payloadKey === lastSentPayload) {
            return;
        }

        lastSentPayload = payloadKey;
        console.log(ajax_object.checking_message);

        if (usesBlocks) {
            checkBlocksCustomer(payload, payloadKey);
            return;
        }

        jQuery.post(
            ajax_object.ajax_url ?? ajax_object.ajaxurl,
            Object.assign({ action: 'check_if_email_is_flagged' }, payload),
            function() {
                $('body').trigger('update_checkout');
                console.log(ajax_object.checked_message);
            }
        ).fail(function() {
            if (lastSentPayload !== payloadKey) {
                return;
            }

            lastSentPayload = null;
        });
    }

    async function checkBlocksCustomer(payload, payloadKey) {
        blocksRequestPending = true;

        try {
            await window.wc.blocksCheckout.extensionCartUpdate({
                namespace: ajax_object.blocks_namespace,
                data: payload,
                overwriteDirtyCustomerData: false
            });
            console.log(ajax_object.checked_message);
        } catch {
            lastSentPayload = null;
        } finally {
            blocksRequestPending = false;
            if (blocksUnsubscribe && JSON.stringify(buildBlocksPayload()) !== payloadKey) {
                scheduleCheck(false);
            }
        }
    }

    function handleBlocksCartChange() {
        var state = JSON.stringify({
            payload: buildBlocksPayload(),
            hasItems: hasBlocksCartItems(),
            updating: getBlocksCart().isCustomerDataUpdating()
        });
        if (state === lastObservedBlocksState) {
            return;
        }

        lastObservedBlocksState = state;
        if (!hasBlocksCartItems()) {
            clearTimeout(debounceTimer);
            lastSentPayload = null;
            return;
        }
        scheduleCheck(false);
    }

    function hasBlocksCartItems() {
        var cart = getBlocksCart().getCartData();
        return Array.isArray(cart.items) && cart.items.length > 0;
    }

    function startBlocksSubscription() {
        if (blocksUnsubscribe) {
            return;
        }

        blocksUnsubscribe = window.wp.data.subscribe(handleBlocksCartChange, window.wc.wcBlocksData.cartStore);
        lastObservedBlocksState = null;
        lastSentPayload = null;
        handleBlocksCartChange();
    }

    function stopBlocksSubscription() {
        if (blocksUnsubscribe) {
            blocksUnsubscribe();
            blocksUnsubscribe = null;
        }
        clearTimeout(debounceTimer);
    }

    var LISTENERS_SELECTOR = buildSelector(['email', 'phone', 'country', 'postal', 'address1', 'address2']);
    var SELECT2_SELECTOR = buildSelector(['country']);

    function isTrackedFieldFocused() {
        if (!document.activeElement || !document.activeElement.matches) {
            return false;
        }

        return document.activeElement.matches(LISTENERS_SELECTOR);
    }

    function scheduleCheck(skipIfFocused) {
        if (skipIfFocused && isTrackedFieldFocused()) {
            return;
        }

        if (debounceTimer) {
            clearTimeout(debounceTimer);
        }

        debounceTimer = setTimeout(function() {
            checkUVBService(buildPayload());
        }, 750);
    }

    function handleFieldFocus(event) {
        if (!event || !isTextLikeField(event.target)) {
            return;
        }

        event.target.setAttribute('data-uvb-focus-value', getElementValue(event.target));
    }

    function handleTextFieldBlur(event) {
        if (!event || !isTextLikeField(event.target)) {
            return;
        }

        var previousValue = event.target.getAttribute('data-uvb-focus-value');
        var currentValue = getElementValue(event.target);

        if (previousValue !== null && previousValue === currentValue) {
            return;
        }

        event.target.setAttribute('data-uvb-focus-value', currentValue);
        scheduleCheck(false);
    }

    function handleNonTextChange(event) {
        if (!event || isTextLikeField(event.target)) {
            return;
        }

        scheduleCheck(false);
    }

    function startPoller() {
        if (pollerTimer) {
            clearInterval(pollerTimer);
        }

        pollerCount = 0;
        pollerTimer = setInterval(function() {
            pollerCount += 1;
            scheduleCheck(true);

            if (pollerCount < maxPolls) {
                return;
            }

            clearInterval(pollerTimer);
            pollerTimer = null;
        }, pollIntervalMs);

        scheduleCheck(true);
    }

    if (usesBlocks) {
        if (document.readyState === 'complete') {
            startBlocksSubscription();
        }
        window.addEventListener('load', startBlocksSubscription);
        window.addEventListener('pageshow', startBlocksSubscription);
        window.addEventListener('pagehide', stopBlocksSubscription);
        return;
    }

    window.addEventListener('load', function() {
        startPoller();
    });

    $(document).on(
        'change',
        LISTENERS_SELECTOR,
        handleNonTextChange
    );

    $(document).on('focusin', LISTENERS_SELECTOR, handleFieldFocus);
    $(document).on('blur', LISTENERS_SELECTOR, handleTextFieldBlur);

    $(document).on(
        'select2:select select2:clear',
        SELECT2_SELECTOR,
        function() {
            scheduleCheck(false);
        }
    );

    $(document.body).on('updated_checkout updated_shipping_method country_to_state_changed wc_fragments_refreshed', startPoller);

})( jQuery );
