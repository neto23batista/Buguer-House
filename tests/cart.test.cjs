'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const source = fs.readFileSync(path.join(__dirname, '../public/assets/js/app.js'), 'utf8');

function deferred() {
    let resolve, reject;
    const promise = new Promise((yes, no) => { resolve = yes; reject = no; });
    return { promise, resolve, reject };
}

function element() {
    const attributes = new Map();
    return {
        disabled: false, hidden: true, textContent: '',
        setAttribute: (key, value) => attributes.set(key, value),
        getAttribute: (key) => attributes.get(key),
        hasAttribute: (key) => attributes.has(key),
        removeAttribute: (key) => attributes.delete(key),
        toggleAttribute(key, enabled) { if (enabled) attributes.set(key, ''); else attributes.delete(key); },
    };
}

function harness(initialCount = 0) {
    const timers = new Map();
    const listeners = new Map();
    const calls = [];
    const navigations = [];
    const status = element();
    const recovery = element();
    const checkout = { ...element(), href: 'http://burger.test/checkout.php' };
    const limit = { dataset: { cartLimit: '40', cartCountTotal: String(initialCount) } };
    const forms = ['add', 'decrement'].map((action) => ({
        ...element(), action: { value: action, toString: () => '[object HTMLInputElement]' }, button: element(),
        elements: { namedItem: (key) => ({ value: key === 'action' ? action : '1' }) },
        matches: (selector) => selector === '[data-cart-form]',
        querySelector() { return this.button; },
    }));
    forms.forEach((form) => form.setAttribute('action', 'http://burger.test/'));
    let clickHandler;
    let nextTimer = 0;
    const cartContent = {
        ...element(),
        contains: () => false,
        querySelector: (selector) => selector === '[data-cart-limit]' ? limit : null,
        addEventListener(type, handler) { clickHandler = handler; },
        set innerHTML(html) {
            const count = html.match(/data-cart-count-total="(\d+)"/);
            if (count) limit.dataset.cartCountTotal = count[1];
        },
    };
    const context = {
        document: {
            activeElement: null,
            querySelector: (selector) => ({ '#cart-content': cartContent, '[data-status]': status }[selector] ?? null),
            querySelectorAll: (selector) => ({
                '[data-cart-form]': forms,
                '[data-checkout]': [checkout],
                '[data-cart-recovery]': [recovery],
            }[selector] ?? []),
            addEventListener: (type, handler) => listeners.set(type, handler),
        },
        window: { location: { href: 'http://burger.test/?carrinho=1', assign: (url) => navigations.push(url) } },
        fetch(url, options) {
            const request = deferred();
            calls.push({ url, options, ...request });
            return request.promise;
        },
        FormData: class { constructor(form) { this.action = form.elements.namedItem('action').value; } },
        AbortController,
        setTimeout(callback, delay) { const id = ++nextTimer; timers.set(id, { callback, delay }); return id; },
        clearTimeout: (id) => timers.delete(id),
    };
    vm.runInNewContext(source, context);
    return {
        calls, navigations, forms, recovery, cartContent, checkout, limit,
        submit(index = 0) { listeners.get('submit')({ target: forms[index], preventDefault() {} }); },
        checkoutClick(options = {}) { clickHandler({ target: { closest: () => checkout }, preventDefault() {}, ...options }); },
        timeout() {
            const timer = [...timers].find(([, entry]) => entry.delay === 10000);
            assert.ok(timer, 'A requisição precisa de um prazo definido.');
            timers.delete(timer[0]);
            timer[1].callback();
        },
        flush: async () => { for (let step = 0; step < 30; step++) await Promise.resolve(); },
    };
}

function response(count, ok = true) {
    return {
        ok,
        json: async () => ({
            cart: { count, totalCents: count * 2890 },
            cartHtml: `<p data-cart-limit="40" data-cart-count-total="${count}"></p>`,
            message: ok ? 'Carrinho atualizado.' : 'O limite é de 40 unidades por pedido.',
        }),
    };
}

test('checkout espera todas as alterações confirmadas e informa processamento', async () => {
    const app = harness();
    app.submit(); app.submit(); app.checkoutClick();
    await app.flush();
    assert.equal(app.calls.length, 1);
    assert.equal(app.cartContent.getAttribute('aria-busy'), 'true');
    assert.equal(app.forms[0].button.disabled, true);
    assert.equal(app.navigations.length, 0);
    app.calls[0].resolve(response(1));
    await app.flush();
    assert.equal(app.calls.length, 2);
    assert.equal(app.navigations.length, 0);
    app.calls[1].resolve(response(2));
    await app.flush();
    assert.deepEqual(app.navigations, [app.checkout.href]);
    assert.equal(app.cartContent.getAttribute('aria-busy'), 'false');
    assert.equal(app.forms[0].button.disabled, false);
});

test('timeout descarta fila e checkout, oferece conferência e ignora resposta tardia', async () => {
    const app = harness();
    app.submit(); app.submit(); app.checkoutClick();
    await app.flush();
    app.timeout();
    await app.flush();
    assert.equal(app.calls.length, 1);
    assert.equal(app.calls[0].options.signal.aborted, true);
    assert.equal(app.recovery.hidden, false);
    assert.equal(app.cartContent.getAttribute('aria-busy'), 'false');
    assert.equal(app.forms[0].button.disabled, true);
    assert.equal(app.navigations.length, 0);
    app.calls[0].resolve(response(1));
    await app.flush();
    assert.equal(app.limit.dataset.cartCountTotal, '0');
    app.submit(); app.checkoutClick();
    await app.flush();
    assert.equal(app.calls.length, 1, 'Não pode reenviar o POST nem aplicar novas alterações antes da conferência.');
    assert.equal(app.navigations.length, 0);
});

test('prazo também cobre uma resposta cujo corpo nunca termina', async () => {
    const app = harness();
    app.submit();
    await app.flush();
    app.calls[0].resolve({ ok: true, json: () => new Promise(() => {}) });
    await app.flush();
    app.timeout();
    await app.flush();
    assert.equal(app.recovery.hidden, false);
    assert.equal(app.cartContent.getAttribute('aria-busy'), 'false');
});

test('rejeição conhecida reconcilia o carrinho, cancela a fila e permite diminuir', async () => {
    const app = harness(39);
    app.submit(); app.submit(); app.checkoutClick();
    await app.flush();
    app.calls[0].resolve(response(40, false));
    await app.flush();
    assert.equal(app.calls.length, 1);
    assert.equal(app.navigations.length, 0);
    assert.equal(app.recovery.hidden, true);
    assert.equal(app.forms[0].button.disabled, true, 'Adicionar deve ficar desabilitado no limite.');
    assert.equal(app.forms[1].button.disabled, false, 'Diminuir precisa continuar disponível.');
    app.checkoutClick();
    await app.flush();
    assert.equal(app.navigations.length, 1, 'Uma nova confirmação pode navegar após a reconciliação.');
    app.submit(1);
    await app.flush();
    app.calls[1].resolve(response(39));
    await app.flush();
    assert.equal(app.forms[0].button.disabled, false, 'Diminuir deve liberar espaço para outro produto.');
});

test('falha de rede bloqueia alterações e não tenta repetir o POST', async () => {
    const app = harness();
    app.submit(); app.submit();
    await app.flush();
    app.calls[0].reject(new Error('Conexão perdida'));
    await app.flush();
    assert.equal(app.calls.length, 1);
    assert.equal(app.recovery.hidden, false);
});

test('JSON inválido ou resposta incompleta exige conferência antes de navegar', async () => {
    for (const invalidResponse of [
        { ok: false, json: async () => { throw new SyntaxError('JSON inválido'); } },
        { ok: true, json: async () => ({ message: 'OK' }) },
    ]) {
        const app = harness();
        app.submit(); app.checkoutClick();
        await app.flush();
        app.calls[0].resolve(invalidResponse);
        await app.flush();
        assert.equal(app.recovery.hidden, false);
        assert.equal(app.navigations.length, 0);
    }
});

test('menu já começa respeitando o limite total do carrinho renderizado', () => {
    const app = harness(40);
    assert.equal(app.forms[0].button.disabled, true);
    assert.equal(app.forms[1].button.disabled, false);
});

test('input name=action não sobrescreve a URL de destino do carrinho', async () => {
    const app = harness();
    assert.equal(String(app.forms[0].action), '[object HTMLInputElement]');
    app.submit();
    await app.flush();
    assert.equal(app.calls[0].url, 'http://burger.test/');
    assert.equal(app.calls[0].options.body.action, 'add');
    app.calls[0].resolve(response(1));
    await app.flush();

    app.forms[0].removeAttribute('action');
    app.submit();
    await app.flush();
    assert.equal(app.calls[1].url, 'http://burger.test/?carrinho=1');
    app.calls[1].resolve(response(2));
    await app.flush();
});

test('input name=action não sobrescreve a URL de atualização da cozinha', async () => {
    const calls = [];
    let submit;
    const board = {
        querySelector: () => null,
        querySelectorAll: () => [],
        addEventListener(type, handler) { if (type === 'submit') submit = handler; },
    };
    const nodes = {
        '[data-kitchen-board]': board,
        '[data-kitchen-connection]': { ...element(), classList: { toggle() {} } },
        '[data-kitchen-message]': { ...element(), classList: { toggle() {} } },
        '[data-kitchen-refresh]': { addEventListener() {} },
        '[data-kitchen-audio]': element(),
    };
    const context = {
        document: { hidden: false, querySelector: (key) => nodes[key], addEventListener() {} },
        window: { location: { href: 'http://burger.test/admin/cozinha.php' } },
        AbortController,
        FormData: class {},
        setTimeout: () => 1, clearTimeout() {}, setInterval: () => 1,
        fetch(url, options) {
            const pending = deferred();
            calls.push({ url, options, ...pending });
            return pending.promise;
        },
    };
    vm.runInNewContext(fs.readFileSync(path.join(__dirname, '../public/assets/js/kitchen.js'), 'utf8'), context);
    const form = {
        action: { value: 'change_status', toString: () => '[object HTMLInputElement]' },
        matches: () => true,
        getAttribute: (name) => name === 'action' ? 'cozinha.php' : null,
    };
    const submission = submit({ target: form, preventDefault() {} });
    assert.equal(calls[0].url, 'cozinha.php');
    assert.equal(calls[0].options.method, 'POST');
    calls[0].resolve({ status: 200, ok: true, json: async () => ({ message: 'Status atualizado.' }) });
    await submission;
});
