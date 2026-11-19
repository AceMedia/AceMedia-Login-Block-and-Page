/* Step-specific verification shared by core, WooCommerce and block login flows. */
(() => {
    const config = window.aceLoginVerification || { steps: [] };
    const states = new WeakMap();
    let loading;
    function api() {
        if (window.turnstile?.render) return Promise.resolve(window.turnstile);
        if (!loading) loading = new Promise((resolve, reject) => {
            const existing = document.querySelector('script[src*="challenges.cloudflare.com/turnstile/v0/api.js"]');
            if (!existing) {
                const script = document.createElement('script');
                script.src = 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit';
                script.async = true;
                script.onerror = () => reject(new Error(config.unavailable));
                document.head.append(script);
            }
            let checks = 0;
            const timer = setInterval(() => {
                if (window.turnstile?.render) { clearInterval(timer); resolve(window.turnstile); }
                else if (++checks >= 120) { clearInterval(timer); reject(new Error(config.unavailable)); }
            }, 250);
        });
        return loading;
    }
    function field(form, name, value) {
        let input = form.querySelector(`input[name="${name}"]`);
        if (!input) { input = document.createElement('input'); input.type = 'hidden'; input.name = name; form.append(input); }
        input.value = value;
        return input;
    }
    function widget(form, step) {
        let el = form.querySelector(`[data-ace-turnstile-step="${step}"]`);
        if (!el && config.steps.includes(step)) {
            el = document.createElement('div');
            el.className = 'ace-login-turnstile';
            Object.assign(el.dataset, { aceTurnstileStep: step, sitekey: config.siteKey, action: `ace_${step}`, field: `ace_turnstile_${step}` });
            form.append(el);
        }
        return el;
    }
    async function activate(form, step) {
        const el = widget(form, step);
        if (!el) return null;
        el.hidden = false;
        if (states.has(el)) return states.get(el);
        const state = { token: '', waiters: [], id: undefined };
        states.set(el, state);
        const message = document.createElement('p');
        message.className = 'description';
        message.setAttribute('role', 'status');
        message.textContent = config.prompt;
        el.append(message);
        const target = document.createElement('div'); el.prepend(target);
        const clear = () => { state.token = ''; field(form, el.dataset.field, ''); };
        state.clear = clear;
        try {
            const turnstile = await api();
            state.id = turnstile.render(target, {
                sitekey: el.dataset.sitekey, action: el.dataset.action,
                size: form.clientWidth < 320 ? 'compact' : 'normal',
                'response-field': false,
                callback: token => {
                    state.token = token; field(form, el.dataset.field, token); message.textContent = config.complete;
                    state.waiters.splice(0).forEach(waiter => waiter.resolve(token));
                },
                'expired-callback': () => { clear(); message.textContent = config.expired; },
                'timeout-callback': clear,
                'error-callback': () => { clear(); message.textContent = config.unavailable; state.waiters.splice(0).forEach(waiter => waiter.reject(new Error(config.unavailable))); },
            });
        } catch (error) { states.delete(el); message.textContent = config.unavailable; throw error; }
        return state;
    }
    async function ensure(form, step) {
        if (!form || !config.steps.includes(step)) return '';
        const state = await activate(form, step);
        if (!state) throw new Error(config.unavailable);
        if (state.token) return state.token;
        const el = widget(form, step);
        el.style.outline = '2px solid currentColor';
        el.style.outlineOffset = '5px';
        el.scrollIntoView({ block: 'nearest', behavior: 'auto' });
        return new Promise((resolve, reject) => {
            const timer = setTimeout(() => { state.waiters = state.waiters.filter(item => item !== waiter); reject(new Error(config.prompt)); }, 120000);
            const waiter = { resolve: value => { clearTimeout(timer); el.style.outline = ''; resolve(value); }, reject: error => { clearTimeout(timer); reject(error); } };
            state.waiters.push(waiter);
        });
    }
    function reset(form, step) {
        const el = widget(form, step), state = el && states.get(el);
        if (!state) return;
        state.clear();
        if (state.id !== undefined) window.turnstile?.reset(state.id);
    }
    function showError(form, error) {
        let status = form.querySelector('.ace-login-verification-error');
        if (!status) { status = document.createElement('p'); status.className = 'ace-login-verification-error'; status.setAttribute('role', 'alert'); form.append(status); }
        status.textContent = error.message || config.unavailable;
    }
    async function request(url, options, form) {
        form ||= document.querySelector('#loginform, .wp-block-login-form form, form.woocommerce-form-login');
        const path = new URL(url, location.href).pathname;
        const step = path.endsWith('/check-2fa') ? 'login' : path.endsWith('/passkeys/login-options') ? 'passkey' : '';
        if (!step || !config.steps.includes(step)) return fetch(url, options);
        const token = await ensure(form, step);
        const body = { ...JSON.parse(options.body || '{}'), [`ace_turnstile_${step}`]: token };
        let response;
        try { response = await fetch(url, { ...options, body: JSON.stringify(body) }); }
        catch (error) { reset(form, step); throw error; }
        if (response.ok && step === 'login') {
            const data = await response.clone().json();
            if (data.turnstileReceipt) field(form, 'ace_turnstile_receipt', data.turnstileReceipt);
        }
        reset(form, step);
        return response;
    }
    async function submit(form) {
        try {
            const receipt = form.querySelector('[name="ace_turnstile_receipt"]')?.value;
            if (!receipt) await ensure(form, 'login');
            if (form.querySelector('[name="two_factor_state"]')?.value === 'verification') await ensure(form, 'two_factor');
            HTMLFormElement.prototype.submit.call(form);
        } catch (error) { showError(form, error); }
    }
    window.AceLoginTurnstile = { request, submit, activate, ensure, reset };
    document.addEventListener('DOMContentLoaded', () => {
        const forms = new Set([...document.querySelectorAll('.ace-login-turnstile')].map(el => el.closest('form')).filter(Boolean));
        forms.forEach(form => {
            form.querySelectorAll('.ace-login-turnstile:not([data-lazy])').forEach(el => activate(form, el.dataset.aceTurnstileStep).catch(error => showError(form, error)));
            form.addEventListener('input', event => {
                if (!['log', 'pwd', 'username', 'password'].includes(event.target.name)) return;
                const receipt = form.querySelector('[name="ace_turnstile_receipt"]');
                if (receipt?.value) { receipt.value = ''; reset(form, 'login'); }
            });
            form.addEventListener('submit', event => {
                const pending = [...form.querySelectorAll('.ace-login-turnstile:not([hidden])')].find(el => !states.get(el)?.token && !(el.dataset.aceTurnstileStep === 'login' && form.querySelector('[name="ace_turnstile_receipt"]')?.value));
                if (!pending) return;
                event.preventDefault(); event.stopImmediatePropagation();
                if (form.dataset.aceVerificationWaiting) return;
                form.dataset.aceVerificationWaiting = '1';
                const submitter = event.submitter;
                ensure(form, pending.dataset.aceTurnstileStep).then(() => { delete form.dataset.aceVerificationWaiting; form.requestSubmit(submitter || undefined); }).catch(error => { delete form.dataset.aceVerificationWaiting; showError(form, error); });
            }, true);
        });
    });
})();
