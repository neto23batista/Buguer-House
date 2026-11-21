'use strict';

const cartDialog = document.querySelector('#carrinho');
const cartContent = document.querySelector('#cart-content');
const cartStatus = document.querySelector('[data-status]');
const dialogStatus = document.querySelector('[data-dialog-status]');

let cartRequest = Promise.resolve();
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

function announce(message, isError = false) {
    const status = cartDialog?.open ? dialogStatus ?? cartStatus : cartStatus;
    if (!status || !message) return;

    clearTimeout(announcementTimer);
    clearTimeout(dismissAnnouncementTimer);
    if (cartStatus) cartStatus.textContent = '';
    if (dialogStatus) dialogStatus.textContent = '';
    announcementTimer = setTimeout(() => {
        status.toggleAttribute('data-error', isError);
        status.textContent = message;
        dismissAnnouncement(status);
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

async function updateCart(url, body) {
    try {
        const response = await fetch(url, {
            method: 'POST',
            headers: { Accept: 'application/json' },
            body,
        });
        const payload = await response.json();
        renderCart(payload);
        announce(payload.message || (response.ok ? 'Carrinho atualizado.' : 'Não foi possível atualizar o carrinho.'), !response.ok);
    } catch {
        announce('Não foi possível confirmar a atualização do carrinho. Recarregue a página para conferir.', true);
    }
}

document.addEventListener('submit', (event) => {
    const form = event.target;
    if (!form.matches('[data-cart-form]')) return;

    event.preventDefault();
    const url = form.action;
    const body = new FormData(form);
    cartRequest = cartRequest.then(() => updateCart(url, body));
});

cartContent?.addEventListener('click', (event) => {
    const link = event.target.closest('[data-checkout]');
    if (!link || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;

    event.preventDefault();
    cartRequest.then(() => { window.location.assign(link.href); });
});

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
