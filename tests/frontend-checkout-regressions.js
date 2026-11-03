const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync(require('node:path').join(__dirname, '../public/js/uvb-connector-woocommerce-public.js'), 'utf8');

function setup({ blocks = true, ready = 'loading', billing = {}, shipping = {}, needsShipping = true, fields = {} } = {}) {
    const timers = new Map();
    const intervals = new Map();
    const listeners = {};
    const subscriptions = new Set();
    const requests = [];
    const posts = [];
    const events = [];
    let timerId = 0;
    let updating = false;
    const cart = {
        billingAddress: { email: 'buyer@example.test', phone: '+3612345678', country: 'HU', postcode: '1111', address_1: 'Test street 1', address_2: '', ...billing },
        shippingAddress: shipping,
        needsShipping,
        paymentMethods: ['cod'],
        items: [{ id: 1 }],
    };
    const notify = () => [...subscriptions].forEach(callback => callback());
    const selectors = { getCartData: () => cart, isCustomerDataUpdating: () => updating };
    const jquery = () => ({ on: () => {}, trigger: name => events.push(name) });
    jquery.post = (url, data, callback) => {
        const request = { url, data, resolve: callback };
        posts.push(request);
        return { fail: handler => { request.reject = handler; } };
    };
    const window = {
        addEventListener: (name, callback) => (listeners[name] ||= []).push(callback),
        wp: { data: {
            select: () => selectors,
            subscribe: callback => { subscriptions.add(callback); return () => subscriptions.delete(callback); },
        } },
        wc: {
            wcBlocksData: { cartStore: { name: 'wc/store/cart' } },
            blocksCheckout: { extensionCartUpdate: args => new Promise((resolve, reject) => {
                requests.push({ args, reject, resolve: methods => {
                    cart.paymentMethods = methods;
                    notify();
                    resolve({ payment_methods: methods });
                } });
            }) },
        },
    };
    const context = {
        window,
        document: { readyState: ready, body: {}, querySelector: selector => fields[selector] || null },
        jQuery: jquery,
        ajax_object: { uses_blocks: blocks ? '1' : '', blocks_namespace: 'uvb-connector-woocommerce', ajax_url: '/admin-ajax.php' },
        setTimeout: callback => { timers.set(++timerId, callback); return timerId; },
        clearTimeout: id => timers.delete(id),
        setInterval: callback => { intervals.set(++timerId, callback); return timerId; },
        clearInterval: id => intervals.delete(id),
        console: { log: () => {} },
    };
    vm.runInNewContext(source, context);
    return {
        cart, requests, posts, events, subscriptions, timers,
        notify,
        setUpdating(value) { updating = value; notify(); },
        event(name) { (listeners[name] || []).forEach(callback => callback()); },
        async tick() {
            const callbacks = [...timers.values()];
            timers.clear();
            callbacks.forEach(callback => callback());
            await Promise.resolve();
            await Promise.resolve();
        },
    };
}

let passed = 0;
async function test(name, callback) { await callback(); passed++; console.log('PASS ' + name); }

(async () => {
    await test('Collapsed saved address checks on load and refreshes native payment methods', async () => {
        const app = setup();
        app.event('load'); app.event('pageshow'); await app.tick();
        assert.equal(app.subscriptions.size, 1);
        assert.equal(app.requests.length, 1);
        assert.equal(app.requests[0].args.data.email, 'buyer@example.test');
        assert.equal(app.requests[0].args.data.address_line, 'Test street 1');
        assert.equal(app.requests[0].args.namespace, 'uvb-connector-woocommerce');
        assert.equal(app.requests[0].args.overwriteDirtyCustomerData, false);
        app.requests[0].resolve(['bacs']); await app.tick();
        assert.deepEqual(app.cart.paymentMethods, ['bacs']);
        app.notify(); await app.tick();
        assert.equal(app.requests.length, 1);
        assert.equal(app.posts.length, 0);
        assert.equal(app.events.length, 0);
    });
    await test('Initial incomplete address is checked once it becomes complete', async () => {
        const app = setup({ billing: { address_1: '' } });
        app.event('load'); await app.tick();
        assert.equal(app.requests.length, 0);
        app.cart.billingAddress.address_1 = 'New street 1'; app.notify(); await app.tick();
        assert.equal(app.requests.length, 1);
    });
    await test('Shipping address components take priority over billing', async () => {
        const app = setup({ shipping: { country: 'AT', postcode: '1010', address_1: 'Other street', address_2: '2', phone: 'shipping-phone' } });
        app.event('load'); await app.tick();
        const payload = app.requests[0].args.data;
        assert.equal(payload.country_code, 'AT');
        assert.equal(payload.postal_code, '1010');
        assert.equal(payload.address_line, 'Other street 2');
        assert.equal(payload.phone_number, 'shipping-phone');
    });
    await test('An explicitly empty shipping phone does not fall back to billing', async () => {
        const app = setup({ shipping: { country: 'HU', postcode: '1111', address_1: 'Test street', phone: '' } });
        app.event('load'); await app.tick();
        assert.equal(app.requests[0].args.data.phone_number, '');
    });
    await test('Wait for native customer update before checking', async () => {
        const app = setup();
        app.setUpdating(true); app.event('load'); await app.tick();
        assert.equal(app.requests.length, 0);
        app.setUpdating(false); await app.tick();
        assert.equal(app.requests.length, 1);
    });
    await test('Address edits during a request are checked afterward without parallel requests', async () => {
        const app = setup(); app.event('load'); await app.tick();
        app.cart.billingAddress.email = 'other@example.test'; app.notify(); await app.tick();
        assert.equal(app.requests.length, 1);
        app.requests[0].resolve(['bacs']); await app.tick(); await app.tick();
        assert.equal(app.requests.length, 2);
        assert.equal(app.requests[1].args.data.email, 'other@example.test');
        app.requests[1].resolve(['cod']); await app.tick();
        assert.deepEqual(app.cart.paymentMethods, ['cod']);
        assert.equal(app.requests.length, 2);
    });
    await test('Failed request can retry on the next customer update', async () => {
        const app = setup(); app.event('load'); await app.tick();
        app.requests[0].reject(new Error('Network error')); await app.tick();
        app.setUpdating(true); await app.tick(); app.setUpdating(false); await app.tick();
        assert.equal(app.requests.length, 2);
    });
    await test('No requests for unrelated cart changes or invalid email', async () => {
        const app = setup({ billing: { email: 'invalid' } }); app.event('load'); await app.tick();
        app.cart.paymentMethods = ['bacs']; app.notify(); await app.tick();
        assert.equal(app.requests.length, 0);
    });
    await test('Late script load initializes immediately', async () => {
        const app = setup({ ready: 'complete' }); await app.tick();
        assert.equal(app.requests.length, 1);
    });
    await test('Page lifecycle unsubscribes and resumes once on history navigation', async () => {
        const app = setup(); app.event('load');
        app.event('pagehide'); await app.tick();
        assert.equal(app.subscriptions.size, 0);
        assert.equal(app.requests.length, 0);
        app.event('pageshow'); await app.tick();
        assert.equal(app.subscriptions.size, 1);
        assert.equal(app.requests.length, 1);
    });
    await test('Classic checkout keeps AJAX and update_checkout behavior', async () => {
        const element = value => ({ value, getAttribute: () => null, getClientRects: () => [1] });
        const app = setup({ blocks: false, fields: {
            '#billing_email': element('buyer@example.test'), '#billing_phone': element('123'),
            '#billing_country': element('HU'), '#billing_postcode': element('1111'), '#billing_address_1': element('Test street 1'),
        } });
        app.event('load'); await app.tick();
        assert.equal(app.posts.length, 1); assert.equal(app.requests.length, 0);
        app.posts[0].resolve();
        assert.deepEqual(app.events, ['update_checkout']);
        assert.equal(app.subscriptions.size, 0);
    });
    await test('Empty cart never checks retained customer addresses', async () => {
        const app = setup(); app.cart.items = [];
        app.event('load'); await app.tick(); app.notify(); await app.tick();
        assert.equal(app.requests.length, 0);
    });
    await test('An item added to an empty cart enables checking', async () => {
        const app = setup(); app.cart.items = [];
        app.event('load'); await app.tick();
        app.cart.items = [{ id: 2 }]; app.notify(); await app.tick();
        assert.equal(app.requests.length, 1);
    });
    await test('Cart emptied before debounce prevents the pending check', async () => {
        const app = setup(); app.event('load');
        app.cart.items = []; app.notify(); await app.tick();
        assert.equal(app.requests.length, 0);
    });
    await test('Completed cart does not trigger another check after an in-flight response', async () => {
        const app = setup(); app.event('load'); await app.tick();
        app.cart.items = []; app.notify();
        app.requests[0].resolve([]); await app.tick(); await app.tick();
        assert.equal(app.requests.length, 1);
    });
    await test('Virtual cart also gives shipping fields priority', async () => {
        const app = setup({ needsShipping: false, shipping: { country: 'AT', postcode: '1010', address_1: 'Shipping street', phone: 'shipping-phone' } });
        app.event('load'); await app.tick();
        assert.equal(app.requests.length, 1);
        const payload = app.requests[0].args.data;
        assert.equal(payload.country_code, 'AT');
        assert.equal(payload.postal_code, '1010');
        assert.equal(payload.address_line, 'Shipping street');
        assert.equal(payload.phone_number, 'shipping-phone');
    });
    await test('Virtual cart falls back to billing for missing shipping fields', async () => {
        const app = setup({ needsShipping: false, shipping: { country: 'AT', postcode: null, address_1: null } });
        app.event('load'); await app.tick();
        const payload = app.requests[0].args.data;
        assert.equal(payload.country_code, 'AT');
        assert.equal(payload.postal_code, '1111');
        assert.equal(payload.address_line, 'Test street 1');
        assert.equal(payload.phone_number, '+3612345678');
    });
    await test('Partial shipping address falls back independently for null or missing fields', async () => {
        const app = setup({ billing: { address_2: 'Billing floor' }, shipping: { country: 'AT', postcode: null, address_1: 'Shipping street', address_2: null } });
        app.event('load'); await app.tick();
        const payload = app.requests[0].args.data;
        assert.equal(payload.country_code, 'AT');
        assert.equal(payload.postal_code, '1111');
        assert.equal(payload.address_line, 'Shipping street Billing floor');
        assert.equal(payload.phone_number, '+3612345678');
    });
    await test('Empty shipping address line two remains empty instead of using billing', async () => {
        const app = setup({ billing: { address_2: 'Billing floor' }, shipping: { address_2: '' } });
        app.event('load'); await app.tick();
        assert.equal(app.requests[0].args.data.address_line, 'Test street 1');
    });
    await test('Classic empty shipping fields are not replaced with billing values', async () => {
        const element = (name, value) => ({ name, value, getAttribute: () => null, getClientRects: () => [1] });
        const app = setup({ blocks: false, fields: {
            '#ship-to-different-address-checkbox': { checked: true },
            '#billing_email': element('billing_email', 'buyer@example.test'),
            '#shipping_phone': element('shipping_phone', ''), '#billing_phone': element('billing_phone', '123'),
            '#shipping_country': element('shipping_country', 'AT'), '#billing_country': element('billing_country', 'HU'),
            '#shipping_postcode': element('shipping_postcode', '1010'),
            '#shipping_address_1': element('shipping_address_1', 'Shipping street'),
            '#shipping_address_2': element('shipping_address_2', ''), '#billing_address_2': element('billing_address_2', 'Billing floor'),
        } });
        app.event('load'); await app.tick();
        assert.equal(app.posts.length, 1);
        assert.equal(app.posts[0].data.phone_number, '');
        assert.equal(app.posts[0].data.country_code, 'AT');
        assert.equal(app.posts[0].data.address_line, 'Shipping street');
    });
    await test('Classic inactive shipping fields do not override billing', async () => {
        const element = (name, value) => ({ name, value, getAttribute: () => null, getClientRects: () => [1] });
        const app = setup({ blocks: false, fields: {
            '#ship-to-different-address-checkbox': { checked: false },
            '#billing_email': element('billing_email', 'buyer@example.test'),
            '#shipping_country': element('shipping_country', 'AT'), '#billing_country': element('billing_country', 'HU'),
            '#shipping_postcode': element('shipping_postcode', '1010'), '#billing_postcode': element('billing_postcode', '1111'),
            '#shipping_address_1': element('shipping_address_1', 'Stale shipping'), '#billing_address_1': element('billing_address_1', 'Billing street'),
        } });
        app.event('load'); await app.tick();
        assert.equal(app.posts.length, 1);
        assert.equal(app.posts[0].data.country_code, 'HU');
        assert.equal(app.posts[0].data.postal_code, '1111');
        assert.equal(app.posts[0].data.address_line, 'Billing street');
    });
    await test('Hidden active shipping selects still take priority over billing', async () => {
        const element = (name, value, visible = true) => ({ name, value, getAttribute: () => null, getClientRects: () => visible ? [1] : [] });
        const app = setup({ blocks: false, fields: {
            '#ship-to-different-address-checkbox': { checked: true },
            '#billing_email': element('billing_email', 'buyer@example.test'),
            '#shipping_country': element('shipping_country', 'AT', false), '#billing_country': element('billing_country', 'HU'),
            '#shipping_postcode': element('shipping_postcode', '1010'), '#billing_postcode': element('billing_postcode', '1111'),
            '#shipping_address_1': element('shipping_address_1', 'Shipping street'),
        } });
        app.event('load'); await app.tick();
        assert.equal(app.posts.length, 1);
        assert.equal(app.posts[0].data.country_code, 'AT');
    });
    for (const blocks of [false, true]) {
        for (const shippingRequired of [false, true]) {
            await test((blocks ? 'Blocks' : 'Classic') + ' checks the selected shipping phone requirement: ' + shippingRequired, async () => {
                const element = (name, value, required = false) => ({ name, value, required, getAttribute: () => null, getClientRects: () => [1] });
                const app = setup({ blocks, shipping: { phone: '' }, fields: {
                    '#ship-to-different-address-checkbox': { checked: true },
                    '#billing_email': element('billing_email', 'buyer@example.test'),
                    '#billing_phone': element('billing_phone', '123', true),
                    '#shipping_phone': element('shipping_phone', '', shippingRequired),
                    '#billing_country': element('billing_country', 'HU'),
                    '#billing_postcode': element('billing_postcode', '1111'),
                    '#billing_address_1': element('billing_address_1', 'Billing street'),
                } });
                app.event('load'); await app.tick();
                const checks = blocks ? app.requests : app.posts;
                assert.equal(checks.length, shippingRequired ? 0 : 1);
                if (!shippingRequired) {
                    assert.equal((blocks ? checks[0].args.data : checks[0].data).phone_number, '');
                }
            });
        }
    }
    await test('Inactive required shipping phone cannot prevent checking an optional empty billing phone', async () => {
        const element = (name, value, required = false) => ({ name, value, required, getAttribute: () => null, getClientRects: () => [1] });
        const app = setup({ blocks: false, fields: {
            '#ship-to-different-address-checkbox': { checked: false },
            '#billing_email': element('billing_email', 'buyer@example.test'),
            '#billing_phone': element('billing_phone', ''), '#shipping_phone': element('shipping_phone', '', true),
            '#billing_country': element('billing_country', 'HU'), '#billing_postcode': element('billing_postcode', '1111'),
            '#billing_address_1': element('billing_address_1', 'Billing street'),
        } });
        app.event('load'); await app.tick();
        assert.equal(app.posts.length, 1);
        assert.equal(app.posts[0].data.phone_number, '');
    });
    await test('Blocks does not borrow billing requiredness for a shipping phone without a rendered field', async () => {
        const app = setup({ shipping: { phone: '' }, fields: {
            '#billing-phone': { name: 'billing_phone', value: '123', required: true, getAttribute: () => null, getClientRects: () => [1] },
        } });
        app.event('load'); await app.tick();
        assert.equal(app.requests.length, 1);
        assert.equal(app.requests[0].args.data.phone_number, '');
    });
    await test('Blocks waits for a required billing phone when shipping phone is missing', async () => {
        const app = setup({ billing: { phone: '' }, shipping: {}, fields: {
            '#billing-phone': { name: 'billing_phone', value: '', required: true, getAttribute: () => null, getClientRects: () => [1] },
        } });
        app.event('load'); await app.tick();
        assert.equal(app.requests.length, 0);
    });
    console.log(passed + ' JavaScript scenarios passed.');
})().catch(error => { console.error(error); process.exitCode = 1; });
