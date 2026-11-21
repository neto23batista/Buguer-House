'use strict';

const cartDialog = document.querySelector('#carrinho');
const cartContent = document.querySelector('#cart-content');
const cartStatus = document.querySelector('[data-status]');
const dialogStatus = document.querySelector('[data-dialog-status]');

let cartRequest = Promise.resolve();
let cartGeneration = 0;
let pendingCartChanges = 0;
let cartNeedsReview = false;
const cartRequestTimeout = 10000;
let announcementTimer;
let dismissAnnouncementTimer;
let cartOpener;
let pointerStartedOutside = false;

if (typeof cartDialog?.showModal === 'function') {
    document.querySelectorAll('[data-cart-open]').forEach((opener) => {
        opener.addEventListener('click', (event) => {
            if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;

            event.preventDefault();
            if (cartDialog.classList.contains('is-page')) {
                cartDialog.removeAttribute('open');
                cartDialog.classList.remove('is-page');
            }
            if (cartDialog.open) return;

            cartOpener = opener;
            cartDialog.showModal();
        });
    });

    function clickedOutside(event) {
        const { left, right, top, bottom } = cartDialog.getBoundingClientRect();
        return event.target === cartDialog && (
            event.clientX < left || event.clientX > right ||
            event.clientY < top || event.clientY > bottom
        );
    }

    cartDialog.addEventListener('pointerdown', (event) => {
        pointerStartedOutside = clickedOutside(event);
    });

    cartDialog.addEventListener('click', (event) => {
        if (cartDialog.classList.contains('is-page')) return;

        const closeButton = event.target.closest('[data-cart-close]');
        if (closeButton || (pointerStartedOutside && clickedOutside(event))) {
            event.preventDefault();
            if (closeButton?.hasAttribute('data-cart-menu')) cartOpener = undefined;
            cartDialog.close();
            if (closeButton?.hasAttribute('data-cart-menu')) {
                const menu = document.querySelector('#cardapio');
                menu?.focus({ preventScroll: true });
                menu?.scrollIntoView();
            }
        }
        pointerStartedOutside = false;
    });

    cartDialog.addEventListener('close', () => {
        cartOpener?.focus();
        cartOpener = undefined;
    });
}

function dismissAnnouncement(status) {
    dismissAnnouncementTimer = setTimeout(() => { status.textContent = ''; }, 3500);
}

function announce(message, isError = false, persistent = false) {
    const status = cartDialog?.open ? dialogStatus ?? cartStatus : cartStatus;
    if (!status || !message) return;

    clearTimeout(announcementTimer);
    clearTimeout(dismissAnnouncementTimer);
    if (cartStatus) cartStatus.textContent = '';
    if (dialogStatus) dialogStatus.textContent = '';
    announcementTimer = setTimeout(() => {
        status.toggleAttribute('data-error', isError);
        status.textContent = message;
        if (!persistent) dismissAnnouncement(status);
    }, 50);
}

if (cartStatus?.textContent.trim()) dismissAnnouncement(cartStatus);

function renderCart({ cart, cartHtml }) {
    if (cart) {
        document.querySelectorAll('[data-cart-count]').forEach((badge) => {
            badge.textContent = cart.count;
        });
        const mobileCart = document.querySelector('[data-mobile-cart]');
        if (mobileCart) {
            mobileCart.hidden = cart.count === 0;
            mobileCart.querySelector('[data-mobile-total]').textContent = new Intl.NumberFormat('pt-BR', {
                style: 'currency', currency: 'BRL',
            }).format(cart.totalCents / 100);
        }
    }

    if (!cartContent || typeof cartHtml !== 'string') return;

    const activeControl = document.activeElement;
    const focusedForm = cartContent.contains(activeControl)
        ? activeControl.closest('[data-cart-form]')
        : null;
    const productId = focusedForm?.elements.namedItem('product_id')?.value;
    const action = focusedForm?.elements.namedItem('action')?.value;

    cartContent.innerHTML = cartHtml;
    if (!focusedForm) return;

    const enabledButton = 'button:not(:disabled)';
    const forms = [...cartContent.querySelectorAll('[data-cart-form]')];
    const productForms = forms.filter((form) => (
        form.elements.namedItem('product_id')?.value === productId
    ));
    const matchingForm = productForms.find((form) => (
        form.elements.namedItem('action')?.value === action
    ));
    const nextControl = matchingForm?.querySelector(enabledButton)
        ?? productForms.map((form) => form.querySelector(enabledButton)).find(Boolean)
        ?? cartContent.querySelector(enabledButton)
        ?? cartDialog?.querySelector('[data-cart-close]');

    nextControl?.focus({ preventScroll: true });
}

function updateCartState() {
    const busy = pendingCartChanges > 0;
    const limit = cartContent?.querySelector('[data-cart-limit]');
    const atLimit = limit && Number(limit.dataset.cartCountTotal) >= Number(limit.dataset.cartLimit);

    cartContent?.setAttribute('aria-busy', String(busy));
    document.querySelectorAll('[data-cart-form]').forEach((form) => {
        form.setAttribute('aria-busy', String(busy));
        const button = form.querySelector('button[type="submit"]');
        if (!button) return;
        if (button.hasAttribute('data-cart-wait')) {
            button.disabled = false;
            button.removeAttribute('data-cart-wait');
        }
        const action = form.elements.namedItem('action')?.value;
        const limitApplies = atLimit && ['add', 'increment'].includes(action);
        if (limitApplies && !button.disabled) {
            button.disabled = true;
            button.setAttribute('data-cart-limit-disabled', '');
        } else if (!limitApplies && button.hasAttribute('data-cart-limit-disabled')) {
            button.disabled = false;
            button.removeAttribute('data-cart-limit-disabled');
        }
        if ((busy || cartNeedsReview) && !button.disabled) {
            button.disabled = true;
            button.setAttribute('data-cart-wait', '');
        }
    });
    document.querySelectorAll('[data-checkout]').forEach((link) => {
        if (busy || cartNeedsReview) link.setAttribute('aria-disabled', 'true');
        else link.removeAttribute('aria-disabled');
    });
    document.querySelectorAll('[data-cart-recovery]').forEach((notice) => {
        notice.hidden = !cartNeedsReview;
    });
}

function requestCartReview() {
    announce('A última alteração não foi confirmada. Abra o carrinho e use “Recarregar e conferir carrinho” antes de continuar.', true, true);
}

async function updateCart(url, body) {
    const controller = new AbortController();
    let deadline;
    try {
        // A timeout cannot tell us whether the server applied the POST. Never retry it automatically.
        const timeout = new Promise((resolve, reject) => {
            deadline = setTimeout(() => {
                controller.abort();
                reject(new Error('A atualização demorou demais.'));
            }, cartRequestTimeout);
        });
        const request = (async () => {
            const response = await fetch(url, {
                method: 'POST',
                headers: { Accept: 'application/json' },
                body,
                signal: controller.signal,
            });
            return { response, payload: await response.json() };
        })();
        const { response, payload } = await Promise.race([request, timeout]);
        const reconciled = typeof payload?.cartHtml === 'string'
            && Number.isSafeInteger(payload.cart?.count) && payload.cart.count >= 0
            && Number.isSafeInteger(payload.cart?.totalCents) && payload.cart.totalCents >= 0;
        if (!reconciled) throw new Error('O carrinho não foi confirmado.');
        renderCart(payload);
        announce(payload.message || (response.ok ? 'Carrinho atualizado.' : 'Não foi possível atualizar o carrinho.'), !response.ok);
        return { successful: response.ok, reconciled: true };
    } catch {
        cartNeedsReview = true;
        requestCartReview();
        return { successful: false, reconciled: false };
    } finally {
        clearTimeout(deadline);
    }
}

document.addEventListener('submit', (event) => {
    const form = event.target;
    if (!form.matches('[data-cart-form]')) return;

    event.preventDefault();
    if (cartNeedsReview) {
        requestCartReview();
        return;
    }
    // A control named "action" shadows HTMLFormElement.action in browsers.
    const url = form.getAttribute('action') || window.location.href;
    const body = new FormData(form);
    const generation = cartGeneration;
    pendingCartChanges += 1;
    updateCartState();
    announce('Atualizando carrinho…', false, true);
    cartRequest = cartRequest.then(async () => {
        if (generation !== cartGeneration || cartNeedsReview) return;
        const result = await updateCart(url, body);
        if (!result.successful) cartGeneration += 1;
    }).finally(() => {
        pendingCartChanges -= 1;
        updateCartState();
    });
});

cartContent?.addEventListener('click', (event) => {
    const link = event.target.closest('[data-checkout]');
    if (!link) return;
    if (cartNeedsReview) {
        event.preventDefault();
        requestCartReview();
        return;
    }
    const modified = event.metaKey || event.ctrlKey || event.shiftKey || event.altKey;
    if (modified && pendingCartChanges === 0) return;

    event.preventDefault();
    if (modified) {
        announce('Aguarde a atualização do carrinho antes de abrir o checkout.', false, true);
        return;
    }
    const generation = cartGeneration;
    cartRequest.then(() => {
        if (cartNeedsReview) requestCartReview();
        else if (generation === cartGeneration) window.location.assign(link.href);
        else announce('Confira o carrinho atualizado e selecione Finalizar pedido novamente.', true, true);
    });
});

updateCartState();

const filters = document.querySelectorAll('[data-filter]');
const menuSections = document.querySelectorAll('[data-category]');
const emptyFilter = document.querySelector('[data-filter-empty]');
const filterControls = document.querySelector('[data-filters]');

filters.forEach((button) => {
    button.addEventListener('click', () => {
        const category = button.dataset.filter;
        let hasVisibleProducts = false;

        filters.forEach((filter) => filter.setAttribute('aria-pressed', String(filter === button)));
        menuSections.forEach((section) => {
            section.hidden = category !== 'todos' && section.dataset.category !== category;
            hasVisibleProducts ||= !section.hidden;
        });

        if (emptyFilter) emptyFilter.hidden = hasVisibleProducts;
    });
});

if (filterControls && filters.length) filterControls.hidden = false;
