'use strict';

(() => {
    const key = 'burger-house.aparencia.v1';
    const root = document.documentElement;
    const contrast = matchMedia('(prefers-contrast: more)');
    const motion = matchMedia('(prefers-reduced-motion: reduce)');
    let settings = {};
    let sizeInput, contrastInput, motionInput;

    function read() {
        try {
            const value = JSON.parse(localStorage.getItem(key));
            settings = value && typeof value === 'object' ? value : {};
        } catch { settings = {}; }
    }
    function apply() {
        const size = ['large', 'extra'].includes(settings.size) ? settings.size : 'normal';
        const high = typeof settings.contrast === 'boolean' ? settings.contrast : contrast.matches;
        const reduced = motion.matches || settings.motion === true;
        root.dataset.textSize = size;
        root.toggleAttribute('data-high-contrast', high);
        root.toggleAttribute('data-reduce-motion', reduced);
        if (sizeInput) {
            sizeInput.value = size;
            contrastInput.checked = high;
            motionInput.checked = reduced;
            motionInput.disabled = motion.matches;
            motionInput.closest('label').title = motion.matches ? 'Ativado nas preferências do dispositivo' : '';
        }
    }
    function save() {
        try { localStorage.setItem(key, JSON.stringify(settings)); } catch { /* Apply for this page. */ }
        apply();
    }
    read();
    apply();
    contrast.addEventListener('change', apply);
    motion.addEventListener('change', apply);
    window.addEventListener('pageshow', () => { read(); apply(); });
    window.addEventListener('storage', (event) => {
        if (event.key === key || event.key === null) { read(); apply(); }
    });
    document.addEventListener('DOMContentLoaded', () => {
        const tools = document.querySelector('[data-access-tools]');
        const panel = tools?.querySelector('dialog');
        if (typeof panel?.showModal !== 'function') return;
        sizeInput = panel.querySelector('[data-access-size]');
        contrastInput = panel.querySelector('[data-access-contrast]');
        motionInput = panel.querySelector('[data-access-motion]');
        const feedback = panel.querySelector('[role="status"]');
        apply();
        tools.hidden = false;
        tools.querySelector('.access-trigger').addEventListener('click', () => panel.showModal());
        for (const [input, name] of [[sizeInput, 'size'], [contrastInput, 'contrast'], [motionInput, 'motion']]) {
            input.addEventListener('change', () => {
                settings[name] = input === sizeInput ? input.value : input.checked;
                save();
                feedback.textContent = '';
            });
        }
        panel.querySelector('.access-reset').addEventListener('click', () => {
            settings = {};
            save();
            feedback.textContent = 'Preferências do dispositivo restauradas.';
        });
    });
})();
