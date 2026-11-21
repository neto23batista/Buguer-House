'use strict';

const receipt = document.querySelector('[data-receipt-status]');
const refresh = document.querySelector('[data-receipt-refresh]');
const privateLink = document.querySelector('[data-private-receipt]');
const copyButton = document.querySelector('[data-copy-receipt]');
const copyStatus = document.querySelector('[data-copy-status]');
const connection = document.querySelector('[data-receipt-connection]');
const refreshInterval = 8000;
const requestTimeout = 5000;
let timer;
let requestActive = false;

function scheduleRefresh() {
    clearTimeout(timer);
    if (receipt && !document.hidden && !['cancelled', 'completed', 'unavailable'].includes(receipt.dataset.state)) {
        timer = setTimeout(updateReceipt, refreshInterval);
    }
}

async function updateReceipt() {
    if (requestActive || !receipt || document.hidden) return;
    requestActive = true;
    const controller = new AbortController();
    const deadline = setTimeout(() => controller.abort(), requestTimeout);
    try {
        const url = new URL(window.location.href);
        url.searchParams.set('atualizacao', '1');
        const response = await fetch(url, {
            headers: { Accept: 'application/json' },
            cache: 'no-store',
            signal: controller.signal,
        });
        if (response.status === 404) {
            receipt.dataset.state = 'unavailable';
            const heading = document.createElement('h2');
            heading.id = 'receipt-title';
            heading.textContent = 'Acompanhamento indisponível';
            const explanation = document.createElement('p');
            explanation.textContent = 'Abra a confirmação no navegador em que fez o pedido.';
            receipt.replaceChildren(heading, explanation);
            refresh.hidden = true;
            connection.hidden = true;
            return;
        }
        if (!response.ok) throw new Error('Acompanhamento indisponível');
        const data = await response.json();
        if (data.status !== receipt.dataset.state) {
            receipt.dataset.state = data.status;
            receipt.innerHTML = data.html;
            if (['cancelled', 'completed'].includes(data.status)) refresh.hidden = true;
        }
        connection.hidden = true;
    } catch {
        if (!document.hidden && connection.hidden) {
            connection.hidden = false;
            connection.textContent = 'Exibindo o último status recebido. Tentando reconectar…';
        }
    } finally {
        clearTimeout(deadline);
        requestActive = false;
        scheduleRefresh();
    }
}

document.addEventListener('visibilitychange', () => {
    if (document.hidden) clearTimeout(timer);
    else scheduleRefresh();
});
scheduleRefresh();

if (copyButton && privateLink && navigator.clipboard?.writeText) {
    copyButton.hidden = false;
    copyButton.addEventListener('click', async () => {
        try {
            await navigator.clipboard.writeText(privateLink.href);
            copyStatus.textContent = 'Link copiado.';
        } catch {
            copyStatus.textContent = 'Abra o link privado e copie o endereço do navegador.';
        }
    });
}
