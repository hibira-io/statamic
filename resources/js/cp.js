// Hibira for the Statamic control panel.
//
// On an entry's edit page this adds a small dock in the corner: who else
// has the entry open, a warning when one of them saves it, comments that
// arrive live, and a QR code of the live preview link for other devices.
//
// Plain DOM on purpose: the same file works in Statamic 5 (Vue 2) and
// Statamic 6 (Vue 3, Inertia).

import Pusher from 'pusher-js';
import qrcode from 'qrcode-generator';
import css from './cp.css';

const SCRIPT = document.currentScript;
const BASE = SCRIPT ? SCRIPT.src.replace(/cp\.js(\?.*)?$/, '') : null;
const ENTRY_PATH = /\/collections\/[^/]+\/entries\/([A-Za-z0-9_-]{1,100})\/?$/;
const NOT_ENTRIES = ['create', 'actions', 'reorder'];

let configPromise = null;
let config = null;
let pusher = null;
let page = null;

// ---------------------------------------------------------------------------
// Helpers

function t(key, replace = {}) {
    let text = (config && config.strings && config.strings[key]) || key;
    for (const [name, value] of Object.entries(replace)) {
        text = text.split(':' + name).join(String(value));
    }
    return text;
}

function h(tag, attrs = {}, ...children) {
    const el = document.createElement(tag);
    for (const [name, value] of Object.entries(attrs)) {
        if (value === null || value === undefined || value === false) continue;
        if (name === 'class') el.className = value;
        else if (name.startsWith('on')) el.addEventListener(name.slice(2), value);
        else el.setAttribute(name, value === true ? '' : value);
    }
    for (const child of children.flat()) {
        if (child === null || child === undefined || child === false) continue;
        el.append(child instanceof Node ? child : document.createTextNode(String(child)));
    }
    return el;
}

const ICONS = {
    comment: '<path d="M21 12a8 8 0 0 1-11.6 7.1L4 20l1-4.6A8 8 0 1 1 21 12Z"/>',
    phone: '<rect x="7" y="2.5" width="10" height="19" rx="2"/><path d="M11 18.5h2"/>',
    close: '<path d="M6 6l12 12M18 6 6 18"/>',
    check: '<path d="m5 12.5 4.5 4.5L19 7.5"/>',
    undo: '<path d="M9 14 4 9l5-5"/><path d="M4 9h10a6 6 0 0 1 0 12h-3"/>',
    trash: '<path d="M4 7h16M10 11v6M14 11v6M6 7l1 13h10l1-13M9 7V4h6v3"/>',
    send: '<path d="M5 12h14M13 6l6 6-6 6"/>',
    copy: '<rect x="9" y="9" width="11" height="11" rx="2"/><path d="M5 15V5a1 1 0 0 1 1-1h10"/>',
    open: '<path d="M14 4h6v6M20 4l-9 9M18 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h5"/>',
    warn: '<path d="M12 3 2 20h20L12 3Z"/><path d="M12 10v4M12 17h.01"/>',
};

function icon(name, size = 18) {
    const span = document.createElement('span');
    span.innerHTML = `<svg width="${size}" height="${size}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${ICONS[name]}</svg>`;
    return span.firstChild;
}

function iconButton(name, label, onclick, extra = '') {
    return h('button', { type: 'button', class: 'hibira-st-icon ' + extra, 'aria-label': label, 'data-tip': label, onclick }, icon(name));
}

function avatar(member) {
    const el = h('span', { class: 'hibira-st-avatar', style: `background:${member.color || '#6b7280'}` });
    if (member.avatar) {
        const img = h('img', { src: member.avatar, alt: '' });
        img.addEventListener('error', () => img.replaceWith(document.createTextNode(member.initials || '?')));
        el.append(img);
    } else {
        el.append(member.initials || '?');
    }
    return el;
}

function relativeTime(iso) {
    const seconds = Math.round((new Date(iso).getTime() - Date.now()) / 1000);
    try {
        const rtf = new Intl.RelativeTimeFormat(config.locale, { numeric: 'auto' });
        const steps = [[60, 'second'], [3600, 'minute', 60], [86400, 'hour', 3600], [604800, 'day', 86400]];
        if (Math.abs(seconds) < 45) return rtf.format(0, 'second');
        for (const [limit, unit, divisor = 1] of steps) {
            if (Math.abs(seconds) < limit) return rtf.format(Math.round(seconds / divisor), unit);
        }
        return new Date(iso).toLocaleDateString(config.locale, { month: 'short', day: 'numeric', year: 'numeric' });
    } catch (e) {
        return new Date(iso).toLocaleString();
    }
}

function isDark() {
    const root = document.documentElement;
    if (root.classList.contains('dark')) return true;
    if (root.classList.contains('light')) return false;
    const meta = document.querySelector('meta[name="color-scheme"]');
    const scheme = meta ? meta.getAttribute('content') : 'auto';
    if (scheme === 'dark') return true;
    if (scheme === 'light') return false;
    return window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
}

async function api(url, { method = 'GET', body } = {}) {
    const headers = { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' };
    if (config && config.csrf) headers['X-CSRF-TOKEN'] = config.csrf;
    if (pusher && pusher.connection.socket_id) headers['X-Socket-ID'] = pusher.connection.socket_id;
    if (body !== undefined) headers['Content-Type'] = 'application/json';

    const response = await fetch(url, {
        method,
        headers,
        credentials: 'same-origin',
        body: body === undefined ? undefined : JSON.stringify(body),
    });
    if (!response.ok) throw new Error('HTTP ' + response.status);
    return response.json();
}

function route(name, entry, comment) {
    return config.routes[name]
        .replace('__ENTRY__', encodeURIComponent(entry))
        .replace('__COMMENT__', encodeURIComponent(comment || ''));
}

// ---------------------------------------------------------------------------
// Connection

function loadConfig() {
    if (!configPromise) {
        configPromise = api(BASE + 'config')
            .then((data) => (config = data))
            .catch(() => (config = { enabled: false }));
    }
    return configPromise;
}

function connect() {
    if (pusher) return pusher;
    const c = config.connection;
    pusher = new Pusher(c.key, {
        cluster: 'hkg',
        wsHost: c.host,
        wsPort: c.port,
        wssPort: c.port,
        forceTLS: c.tls,
        enabledTransports: ['ws', 'wss'],
        disableStats: true,
        channelAuthorization: {
            customHandler: ({ socketId, channelName }, callback) => {
                api(config.routes.auth, { method: 'POST', body: { socket_id: socketId, channel_name: channelName } })
                    .then((data) => callback(null, data))
                    .catch((error) => callback(error, null));
            },
        },
    });
    pusher.connection.bind('state_change', ({ current }) => page && page.setState(current));
    return pusher;
}

// ---------------------------------------------------------------------------
// One entry's edit page

class EntryPage {
    constructor(entryId) {
        this.entryId = entryId;
        this.channelName = 'presence-hibira-statamic.entry.' + entryId;
        this.comments = [];
        this.loaded = false;
        this.loadFailed = false;
        this.loading = null;
        this.reloadAfter = false;
        this.showResolved = false;
        this.open = null;
        this.unread = false;
        this.subscribed = false;
        this.confirming = null;

        this.build();
        this.subscribe();
        if (config.features.comments) this.loadComments();
    }

    // -- Layout ---------------------------------------------------------------

    build() {
        const features = config.features;

        this.avatars = h('span', { class: 'hibira-st-avatars' });
        this.label = h('span', { class: 'hibira-st-label' });
        this.people = h('span', { class: 'hibira-st-people', hidden: true }, this.avatars, this.label);
        this.separator = h('span', { class: 'hibira-st-sep', hidden: true });
        this.status = h('span', { class: 'hibira-st-status', 'data-state': 'connecting' });

        this.commentsButton = features.comments
            ? iconButton('comment', t('comments'), () => this.toggle('comments'))
            : null;
        if (this.commentsButton) {
            this.count = h('span', { class: 'hibira-st-count' });
            this.commentsButton.append(this.count);
            this.commentsButton.setAttribute('aria-expanded', 'false');
        }

        this.previewButton = features.livePreview
            ? iconButton('phone', t('preview'), () => this.toggle('preview'))
            : null;
        if (this.previewButton) this.previewButton.setAttribute('aria-expanded', 'false');

        this.dock = h('div', { class: 'hibira-st-dock' }, this.people, this.separator, this.commentsButton, this.previewButton, this.status);
        this.banner = h('div', { class: 'hibira-st-banner', role: 'status', hidden: true });
        this.card = h('div', { class: 'hibira-st-card', hidden: true });
        this.root = h('div', { class: 'hibira-st' }, this.banner, this.card, this.dock);

        this.applyTheme();
        this.themeObserver = new MutationObserver(() => this.applyTheme());
        this.themeObserver.observe(document.documentElement, { attributes: true, attributeFilter: ['class'] });

        this.onKey = (event) => {
            if (event.key === 'Escape' && this.open) this.toggle(null);
        };
        document.addEventListener('keydown', this.onKey);
        document.body.append(this.root);
    }

    applyTheme() {
        this.root.classList.toggle('hibira-st-dark', isDark());
    }

    setState(state) {
        this.status.dataset.state = state;
        const label = state === 'connected' ? 'Hibira' : t('reconnecting');
        this.status.setAttribute('title', label);
    }

    toggle(name) {
        this.open = this.open === name ? null : name;
        if (this.commentsButton) this.commentsButton.setAttribute('aria-expanded', String(this.open === 'comments'));
        if (this.previewButton) this.previewButton.setAttribute('aria-expanded', String(this.open === 'preview'));
        this.card.hidden = !this.open;

        if (this.open === 'comments') {
            this.unread = false;
            this.renderCount();
            this.renderComments();
            setTimeout(() => this.textarea.focus(), 0);
        } else if (this.open === 'preview') {
            this.renderPreview();
        }
    }

    head(title, ...actions) {
        return h('div', { class: 'hibira-st-head' },
            h('span', {}, title),
            h('span', { class: 'hibira-st-head-actions' }, ...actions, iconButton('close', t('close'), () => this.toggle(null), 'hibira-st-sm')));
    }

    // -- Presence --------------------------------------------------------------

    subscribe() {
        this.setState(connect().connection.state);
        this.channel = pusher.subscribe(this.channelName);

        this.channel.bind('pusher:subscription_succeeded', () => {
            // Also after a reconnect: fetch what was missed.
            if (this.subscribed && config.features.comments) this.loadComments();
            this.subscribed = true;
            this.renderPeople();
        });
        this.channel.bind('pusher:member_added', () => this.renderPeople());
        this.channel.bind('pusher:member_removed', () => this.renderPeople());
        this.channel.bind('saved', (data) => this.onSaved(data));
        this.channel.bind('comment', (data) => this.onRemoteComment(data));
    }

    others() {
        const others = [];
        if (!this.channel || !this.channel.members) return others;
        this.channel.members.each((member) => {
            if (String(member.id) !== String(config.user.id)) others.push({ id: member.id, ...member.info });
        });
        return others;
    }

    renderPeople() {
        if (!config.features.presence) return;
        const others = this.others();
        this.people.hidden = others.length === 0;
        this.separator.hidden = others.length === 0 || (!this.commentsButton && !this.previewButton);

        this.avatars.replaceChildren(...others.slice(0, 3).map((member) => {
            const el = avatar(member);
            el.setAttribute('data-tip', member.name);
            el.setAttribute('aria-label', member.name);
            return el;
        }));
        if (others.length > 3) {
            this.avatars.append(h('span', { class: 'hibira-st-avatar hibira-st-more' }, '+' + (others.length - 3)));
        }

        this.label.textContent = others.length === 0
            ? ''
            : others.length === 1
                ? t('here_one', { name: others[0].name })
                : t('here_many', { name: others[0].name, count: others.length - 1 });
    }

    onSaved(data) {
        if (!config.features.presence) return;
        if (data && data.by && String(data.by.id) === String(config.user.id)) return;

        const text = data && data.by ? t('saved_by', { name: data.by.name }) : t('saved_elsewhere');
        this.banner.replaceChildren(
            h('span', { style: 'flex:none;margin-top:1px' }, icon('warn', 18)),
            h('div', { class: 'hibira-st-banner-text' },
                text,
                h('div', { class: 'hibira-st-row' },
                    h('button', { type: 'button', class: 'hibira-st-button', onclick: () => location.reload() }, t('reload')),
                    h('button', { type: 'button', class: 'hibira-st-button', onclick: () => (this.banner.hidden = true) }, t('dismiss')))),
        );
        this.banner.hidden = false;
    }

    // -- Comments --------------------------------------------------------------

    // Fetches the comments. Calls made while a fetch runs are folded into
    // one more fetch after it, so a burst of events costs two requests.
    loadComments() {
        if (this.loading) {
            this.reloadAfter = true;
            return this.loading;
        }

        this.loading = (async () => {
            try {
                const data = await api(route('comments', this.entryId));
                const known = new Set(this.comments.map((c) => c.id));
                this.comments = data.comments || [];
                if (this.loaded && this.open !== 'comments' && this.comments.some((c) => !known.has(c.id) && String(c.author.id) !== String(config.user.id))) {
                    this.unread = true;
                }
                this.loaded = true;
                this.loadFailed = false;
            } catch (e) {
                // Shown in the card with a retry button; a reconnect also tries again.
                this.loadFailed = true;
            } finally {
                this.loading = null;
            }
            this.renderCount();
            if (this.open === 'comments') this.renderComments();
            if (this.reloadAfter) {
                this.reloadAfter = false;
                this.loadComments();
            }
        })();

        return this.loading;
    }

    // Events from the others carry only the comment ID, so a long comment
    // never meets the message size limit: fetch the comments again.
    onRemoteComment({ action, id }) {
        if (action === 'deleted') {
            this.onComment({ action, comment: { id } });
        } else {
            this.loadComments();
        }
    }

    onComment({ action, comment }) {
        if (action === 'added') {
            if (!this.comments.some((c) => c.id === comment.id)) this.comments.push(comment);
        } else if (action === 'updated') {
            this.comments = this.comments.map((c) => (c.id === comment.id ? comment : c));
        } else if (action === 'deleted') {
            this.comments = this.comments.filter((c) => c.id !== comment.id);
        }
        this.renderCount();
        if (this.open === 'comments') this.renderComments();
    }

    open_() {
        return this.comments.filter((c) => !c.resolved_at);
    }

    renderCount() {
        if (!this.commentsButton) return;
        const count = this.open_().length;
        this.count.textContent = count ? String(count) : '';
        this.commentsButton.classList.toggle('hibira-st-new', this.unread);
        const label = count ? t('comments_count', { count }) : t('comments');
        this.commentsButton.setAttribute('aria-label', label);
        this.commentsButton.setAttribute('data-tip', this.unread ? label + ' •' : label);
    }

    renderComments() {
        if (!this.compose || !this.card.contains(this.compose)) this.buildCommentsCard();

        const resolved = this.comments.filter((c) => c.resolved_at);
        const visible = this.showResolved ? this.comments : this.open_();

        this.resolvedToggle.hidden = resolved.length === 0;
        this.resolvedToggle.textContent = this.showResolved ? t('hide_resolved') : t('show_resolved', { count: resolved.length });

        const list = this.list;
        const atEnd = list.scrollTop + list.clientHeight >= list.scrollHeight - 8;
        const scrollTop = list.scrollTop;

        let placeholder;
        if (this.loadFailed && !this.loaded) {
            placeholder = h('div', { class: 'hibira-st-empty' },
                h('div', {}, t('load_failed')),
                h('button', { type: 'button', class: 'hibira-st-text-button', onclick: () => this.retryLoad() }, t('retry')));
        } else {
            placeholder = h('div', { class: 'hibira-st-empty', 'aria-busy': this.loaded ? 'false' : 'true' }, this.loaded ? t('empty') : '…');
        }

        list.replaceChildren(...(visible.length ? visible.map((comment) => this.renderComment(comment)) : [placeholder]));

        list.scrollTop = atEnd ? list.scrollHeight : scrollTop;
    }

    retryLoad() {
        this.loadFailed = false;
        this.renderComments();
        this.loadComments();
    }

    clearError() {
        if (this.error) this.error.textContent = '';
    }

    buildCommentsCard() {
        this.resolvedToggle = h('button', {
            type: 'button',
            class: 'hibira-st-text-button',
            onclick: () => {
                this.showResolved = !this.showResolved;
                this.renderComments();
            },
        });
        this.list = h('div', { class: 'hibira-st-list' });
        this.textarea = h('textarea', {
            rows: 1,
            placeholder: t('placeholder'),
            'aria-label': t('placeholder'),
            maxlength: config.maxLength,
            oninput: () => {
                this.clearError();
                this.resize();
            },
            onkeydown: (event) => {
                if (event.key === 'Enter' && (event.metaKey || event.ctrlKey)) {
                    event.preventDefault();
                    this.send();
                }
            },
        });
        this.sendButton = h('button', {
            type: 'button',
            class: 'hibira-st-send',
            'aria-label': t('send'),
            'data-tip': t('send_hint'),
            onclick: () => this.send(),
        }, icon('send', 18));
        this.error = h('div', { class: 'hibira-st-error', role: 'alert' });
        this.compose = h('div', { class: 'hibira-st-compose' }, this.textarea, this.sendButton);

        this.card.replaceChildren(this.head(t('comments'), this.resolvedToggle), this.list, this.compose, this.error);
        this.resize();
    }

    renderComment(comment) {
        const mine = String(comment.author.id) === String(config.user.id);
        const canDelete = mine || config.user.super;

        const actions = h('span', { class: 'hibira-st-comment-actions' },
            comment.resolved_at
                ? iconButton('undo', t('reopen'), () => this.resolve(comment, false), 'hibira-st-sm')
                : iconButton('check', t('resolve'), () => this.resolve(comment, true), 'hibira-st-sm'),
            canDelete ? iconButton('trash', t('delete'), () => {
                this.confirming = comment.id;
                this.renderComments();
            }, 'hibira-st-sm') : null);

        const confirm = this.confirming === comment.id
            ? h('div', { class: 'hibira-st-confirm' },
                h('span', {}, t('delete_confirm')),
                h('button', { type: 'button', class: 'hibira-st-text-button hibira-st-danger', onclick: () => this.remove(comment) }, t('delete')),
                h('button', {
                    type: 'button',
                    class: 'hibira-st-text-button',
                    onclick: () => {
                        this.confirming = null;
                        this.renderComments();
                    },
                }, t('dismiss')))
            : null;

        return h('div', { class: 'hibira-st-comment' + (comment.resolved_at ? ' hibira-st-resolved' : '') },
            avatar(comment.author),
            h('div', { class: 'hibira-st-comment-main' },
                h('div', { class: 'hibira-st-comment-meta' },
                    h('span', { class: 'hibira-st-comment-name' }, mine ? t('you') : comment.author.name),
                    h('time', { class: 'hibira-st-comment-time', datetime: comment.created_at, title: new Date(comment.created_at).toLocaleString(config.locale) }, relativeTime(comment.created_at)),
                    actions),
                h('div', { class: 'hibira-st-comment-body' }, comment.body),
                comment.resolved_by ? h('div', { class: 'hibira-st-comment-note' }, t('resolved_by', { name: comment.resolved_by.name })) : null,
                confirm));
    }

    resize() {
        if (!this.textarea) return;
        this.textarea.style.height = 'auto';
        this.textarea.style.height = Math.min(this.textarea.scrollHeight + 2, 160) + 'px';
        this.sendButton.disabled = this.textarea.value.trim() === '';
    }

    async send() {
        const body = this.textarea.value.trim();
        if (!body || this.sending) return;
        if (body.length > config.maxLength) {
            this.error.textContent = t('too_long', { max: config.maxLength });
            return;
        }

        this.sending = true;
        this.sendButton.disabled = true;
        try {
            const { comment } = await api(route('comments', this.entryId), { method: 'POST', body: { body } });
            this.textarea.value = '';
            this.clearError();
            this.onComment({ action: 'added', comment });
            if (this.list) this.list.scrollTop = this.list.scrollHeight;
        } catch (e) {
            this.error.textContent = t('failed');
        } finally {
            this.sending = false;
            this.resize();
        }
    }

    async resolve(comment, resolved) {
        try {
            const data = await api(route('resolve', this.entryId, comment.id), { method: 'POST', body: { resolved } });
            this.clearError();
            this.onComment({ action: 'updated', comment: data.comment });
        } catch (e) {
            this.error.textContent = t('failed');
        }
    }

    async remove(comment) {
        this.confirming = null;
        try {
            await api(route('comment', this.entryId, comment.id), { method: 'DELETE' });
            this.clearError();
            this.onComment({ action: 'deleted', comment: { id: comment.id } });
        } catch (e) {
            this.renderComments();
            this.error.textContent = t('failed');
        }
    }

    // -- Live preview on another device ----------------------------------------

    async renderPreview() {
        const body = h('div', { class: 'hibira-st-preview' }, h('div', { class: 'hibira-st-hint' }, '…'));
        this.card.replaceChildren(this.head(t('preview')), body);

        let url = null;
        try {
            ({ url } = await api(route('previewLink', this.entryId)));
        } catch (e) {
            url = null;
        }
        if (this.open !== 'preview') return;

        if (!url) {
            body.replaceChildren(h('div', { class: 'hibira-st-hint' }, t('preview_none')));
            return;
        }

        const qr = qrcode(0, 'M');
        qr.addData(url);
        qr.make();
        const code = h('div', { class: 'hibira-st-qr', role: 'img', 'aria-label': url });
        code.innerHTML = qr.createSvgTag({ cellSize: 4, margin: 0, scalable: true });

        const copy = h('button', {
            type: 'button',
            class: 'hibira-st-button',
            onclick: async () => {
                try {
                    await navigator.clipboard.writeText(url);
                    copy.lastChild.textContent = t('copied');
                    setTimeout(() => (copy.lastChild.textContent = t('copy')), 1500);
                } catch (e) {
                    window.getSelection().selectAllChildren(link);
                }
            },
        }, icon('copy', 16), h('span', {}, t('copy')));

        const link = h('div', { class: 'hibira-st-link', title: url }, url);

        body.replaceChildren(
            code,
            h('div', { class: 'hibira-st-hint' }, t('preview_scan')),
            link,
            h('div', { class: 'hibira-st-row' },
                copy,
                h('a', { class: 'hibira-st-button', href: url, target: '_blank', rel: 'noopener' }, icon('open', 16), h('span', {}, t('open')))),
        );
    }

    // -------------------------------------------------------------------------

    destroy() {
        if (pusher) pusher.unsubscribe(this.channelName);
        this.themeObserver.disconnect();
        document.removeEventListener('keydown', this.onKey);
        this.root.remove();
    }
}

// ---------------------------------------------------------------------------
// Follow the control panel's navigation (Statamic 6 is a single page app)

function entryIdFromLocation() {
    const match = location.pathname.match(ENTRY_PATH);
    return match && !NOT_ENTRIES.includes(match[1]) ? match[1] : null;
}

async function sync() {
    const entryId = entryIdFromLocation();
    if (page && page.entryId === entryId) return;

    if (page) {
        page.destroy();
        page = null;
    }
    if (!entryId) return;

    await loadConfig();
    const { features } = config;
    if (!config.enabled || !(features.presence || features.comments || features.livePreview)) return;
    if (entryIdFromLocation() !== entryId || page) return;

    page = new EntryPage(entryId);
}

function start() {
    if (!BASE || window.HibiraStatamic) return;
    window.HibiraStatamic = { get page() { return page; } };

    const style = document.createElement('style');
    style.textContent = css;
    document.head.append(style);

    for (const method of ['pushState', 'replaceState']) {
        const original = history[method];
        history[method] = function (...args) {
            const result = original.apply(this, args);
            setTimeout(sync, 0);
            return result;
        };
    }
    window.addEventListener('popstate', () => setTimeout(sync, 0));
    setInterval(sync, 1500);
    sync();
}

if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start);
else start();
