'use strict';

const board = document.querySelector('[data-kitchen-board]');
const connection = document.querySelector('[data-kitchen-connection]');
const feedback = document.querySelector('[data-kitchen-message]');
const refreshLink = document.querySelector('[data-kitchen-refresh]');
const audioButton = document.querySelector('[data-kitchen-audio]');
const AudioContextClass = window.AudioContext ?? window.webkitAudioContext;
const interval = 8000;
const refreshTimeout = 5000;
const actionTimeout = 15000;

let timer;
let controller;
let submitting = false;
let awaitingRefresh = false;
let sessionExpired = false;
let audio;
let soundEnabled = false;
let clockOffset = 0;

function announce(text, isError = false) {
    feedback.textContent = text;
    feedback.hidden = false;
    feedback.classList.toggle('admin-alert-error', isError);
}

function setConnection(text, isOffline = false) {
    connection.hidden = false;
    connection.classList.toggle('is-offline', isOffline);
    if (connection.textContent !== text) connection.textContent = text;
}

function updateElapsed() {
    const now = Date.now() + clockOffset;
    board.querySelectorAll('[data-kitchen-due]').forEach((node) => {
        const remaining = Number(node.dataset.kitchenDue) * 1000 - now;
        const minutes = Math.ceil(Math.abs(remaining) / 60000);
        node.classList.toggle('is-overdue', remaining < 0);
        node.textContent = remaining < 0 ? `Atrasado há ${minutes} min` : `Vence em ${minutes} min`;
    });
    board.querySelectorAll('[data-kitchen-created]').forEach((node) => {
        const elapsed = Math.max(0, Math.floor((now - Number(node.dataset.kitchenCreated) * 1000) / 60000));
        const hours = Math.floor(elapsed / 60);
        const label = hours ? `Há ${hours} h ${elapsed % 60} min` : `Há ${elapsed} min`;
        if (node.textContent !== label) node.textContent = label;
    });
}

function signalArrival() {
    if (!soundEnabled || audio?.state !== 'running') return;
    const tone = audio.createOscillator();
    const gain = audio.createGain();
    const start = audio.currentTime;
    tone.frequency.setValueAtTime(660, start);
    tone.frequency.setValueAtTime(880, start + .12);
    gain.gain.setValueAtTime(.001, start);
    gain.gain.exponentialRampToValueAtTime(.09, start + .025);
    gain.gain.exponentialRampToValueAtTime(.001, start + .3);
    tone.connect(gain);
    gain.connect(audio.destination);
    tone.start(start);
    tone.stop(start + .31);
}

function updateCsrf(token) {
    document.querySelectorAll('[data-kitchen-form] input[name="csrf"], .admin-header-actions input[name="csrf"]')
        .forEach((input) => { input.value = token; });
}

function renderFeed(source) {
    const freshStages = [...source.querySelectorAll('[data-kitchen-stage]')];
    const csrf = freshStages[0]?.dataset.kitchenCsrf;
    if (!csrf) throw new Error('Fila indisponível');
    clockOffset = Number(freshStages[0].dataset.kitchenTime) * 1000 - Date.now();

    const currentStages = new Map([...board.querySelectorAll('[data-kitchen-stage]')].map((stage) => [stage.dataset.kitchenStage, stage]));
    if (currentStages.size === 0) {
        board.replaceChildren(...freshStages);
        updateCsrf(csrf);
        updateElapsed();
        return;
    }

    const active = document.activeElement;
    const activeTicket = active?.closest('[data-kitchen-order]');
    const activeKey = activeTicket?.dataset.kitchenOrder;
    const activeStage = activeTicket?.closest('[data-kitchen-stage]');
    const tickets = new Map([...board.querySelectorAll('[data-kitchen-order]')].map((card) => [card.dataset.kitchenOrder, card]));
    const incoming = new Map([...source.querySelectorAll('[data-kitchen-order]')].map((card) => [card.dataset.kitchenOrder, card]));

    tickets.forEach((card, id) => {
        if (incoming.get(id)?.dataset.orderSignature !== card.dataset.orderSignature) card.remove();
    });

    let arrivals = 0;
    freshStages.forEach((stage) => {
        const stageName = stage.dataset.kitchenStage;
        const current = currentStages.get(stageName);
        const currentList = current.querySelector('[data-kitchen-list]');
        const cards = [...stage.querySelector('[data-kitchen-list]').children];
        let cursor = currentList.firstElementChild;

        cards.forEach((card) => {
            const id = card.dataset.kitchenOrder;
            const existing = tickets.get(id);
            const visibleCard = existing?.isConnected ? existing : card;
            if (visibleCard === cursor) {
                cursor = cursor.nextElementSibling;
            } else {
                currentList.insertBefore(visibleCard, cursor);
            }
            if (!existing && stageName === 'received') {
                visibleCard.classList.add('kitchen-ticket-new');
                arrivals += 1;
            }
        });

        const count = current.querySelector('[data-kitchen-count]');
        count.textContent = String(cards.length);
        count.setAttribute('aria-label', cards.length === 1 ? '1 pedido' : `${cards.length} pedidos`);
        current.querySelector('[data-kitchen-empty]').hidden = cards.length > 0;
    });

    if (activeKey && !active.isConnected) {
        const nextFocus = incoming.get(activeKey)?.querySelector('h3') ?? activeStage?.querySelector('h2');
        nextFocus?.focus();
    }

    updateCsrf(csrf);
    updateElapsed();
    if (arrivals > 0) {
        announce(arrivals === 1 ? 'Novo pedido na fila.' : `${arrivals} novos pedidos na fila.`);
        signalArrival();
    }
}

function scheduleRefresh() {
    clearTimeout(timer);
    if (!document.hidden && !sessionExpired && !submitting) timer = setTimeout(refresh, interval);
}

function endSession() {
    sessionExpired = true;
    setConnection('Sessão encerrada. Entre no painel novamente.', true);
}

async function refresh() {
    clearTimeout(timer);
    if (document.hidden || sessionExpired || submitting) return;
    controller?.abort();
    const request = new AbortController();
    controller = request;
    const deadline = setTimeout(() => request.abort(new DOMException('', 'TimeoutError')), refreshTimeout);

    try {
        const response = await fetch('cozinha.php?atualizacao=1', { cache: 'no-store', signal: request.signal });
        if (response.status === 401) {
            endSession();
            return;
        }
        if (!response.ok) throw new Error('Fila indisponível');
        const html = await response.text();
        if (request.signal.aborted || document.hidden) return;

        const fragment = document.createElement('template');
        fragment.innerHTML = html;
        renderFeed(fragment.content);
        setConnection('Fila conectada');
        awaitingRefresh = false;
    } catch {
        if (controller === request && (!request.signal.aborted || request.signal.reason?.name === 'TimeoutError')) {
            setConnection('Fila desatualizada. Tentando reconectar…', true);
        }
    } finally {
        clearTimeout(deadline);
        if (controller === request) {
            controller = undefined;
            scheduleRefresh();
        }
    }
}

refreshLink.addEventListener('click', (event) => {
    if (sessionExpired || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
    event.preventDefault();
    refresh();
});

board.addEventListener('submit', async (event) => {
    if (!event.target.matches('[data-kitchen-form]')) return;
    event.preventDefault();
    if (submitting) return;
    if (sessionExpired) {
        announce('Sua sessão encerrou. Use Atualizar para entrar novamente.', true);
        return;
    }
    if (awaitingRefresh) {
        announce('Aguarde a atualização da fila antes de tentar novamente.', true);
        refresh();
        return;
    }

    submitting = true;
    clearTimeout(timer);
    controller?.abort();
    const button = event.submitter;
    button?.setAttribute('aria-disabled', 'true');
    const request = new AbortController();
    const deadline = setTimeout(() => request.abort(), actionTimeout);
    try {
        // Read the attribute: the hidden action input shadows the DOM action property.
        const response = await fetch(event.target.getAttribute('action') || window.location.href, {
            method: 'POST',
            headers: { Accept: 'application/json' },
            body: new FormData(event.target),
            signal: request.signal,
        });
        if (response.status === 401) {
            endSession();
            return;
        }
        const result = await response.json();
        announce(result.message, !response.ok);
    } catch {
        awaitingRefresh = true;
        announce('A atualização não foi confirmada. Confira a fila antes de tentar novamente.', true);
    } finally {
        clearTimeout(deadline);
        button?.removeAttribute('aria-disabled');
        submitting = false;
        refresh();
    }
});

if (AudioContextClass) {
    audioButton.hidden = false;
    audioButton.addEventListener('click', async () => {
        try {
            if (!soundEnabled) {
                audio ??= new AudioContextClass();
                await audio.resume();
            }
            soundEnabled = !soundEnabled;
        } catch {
            soundEnabled = false;
            announce('O navegador não conseguiu ativar o alerta sonoro.', true);
        }
        audioButton.setAttribute('aria-pressed', String(soundEnabled));
    });
}

document.addEventListener('visibilitychange', () => {
    if (document.hidden) {
        clearTimeout(timer);
        controller?.abort();
    } else {
        refresh();
    }
});

const initialClock = board.querySelector('[data-kitchen-time]');
if (initialClock) {
    clockOffset = Number(initialClock.dataset.kitchenTime) * 1000 - Date.now();
    setConnection('Fila conectada');
}
scheduleRefresh();
setInterval(() => { if (!document.hidden) updateElapsed(); }, 15000);
