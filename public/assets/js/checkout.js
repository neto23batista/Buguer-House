'use strict';

const checkoutForm = document.querySelector('[data-checkout-form]');
const submitOrder = document.querySelector('[data-submit-order]');
const checkoutProgress = document.querySelector('[data-checkout-progress]');

if (checkoutForm && submitOrder) {
    const initialButtonHtml = submitOrder.innerHTML;
    const initiallyDisabled = submitOrder.disabled;
    let submittingOrder = false;

    checkoutForm.addEventListener('submit', (event) => {
        if (submittingOrder) {
            event.preventDefault();
            return;
        }
        submittingOrder = true;
        checkoutForm.setAttribute('aria-busy', 'true');
        submitOrder.disabled = true;
        submitOrder.textContent = 'Enviando pedido…';
        if (checkoutProgress) {
            checkoutProgress.hidden = false;
            checkoutProgress.textContent = 'Aguarde a confirmação do pedido antes de enviar novamente.';
        }
        // Keep the native POST and its confirmation token: the server remains authoritative.
    });

    // Browsers may restore disabled controls when returning with Back or from the page cache.
    window.addEventListener('pageshow', () => {
        submittingOrder = false;
        checkoutForm.removeAttribute('aria-busy');
        submitOrder.disabled = initiallyDisabled;
        submitOrder.innerHTML = initialButtonHtml;
        if (checkoutProgress) {
            checkoutProgress.hidden = true;
            checkoutProgress.textContent = '';
        }
    });
}
