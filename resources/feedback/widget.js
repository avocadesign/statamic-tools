/*
 * Avoca feedback: the widget, loaded by the loader on the first click. A panel on the right lists the comments on this
 * page or on every page, open or resolved. Add feedback asks for a spot on the page: the comment is pinned to the
 * element clicked, at that point within it, so its numbered pin follows the element when the layout changes. Each
 * comment says in words where it is: the block, from the template comments the kit leaves in the page, and the nearest
 * heading. Everything lives in the loader's shadow root, so the site's styles never reach it and it never reaches them.
 */
(function () {
    'use strict';

    if (window.__avocaFeedback) return;

    var css = [
        ':host{all:initial}',
        '*,*::before,*::after{box-sizing:border-box}',
        '.fb{--ink:#1f2430;--muted:#5d6475;--line:#e3e5ea;--soft:#f5f6f8;--bg:#fff;--accent:#5b5bd6;--accent-ink:#fff;--ok:#1f8a5b;--warn:#b54708;',
        'font:14px/1.45 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;color:var(--ink)}',
        '@media (prefers-color-scheme:dark){.fb{--ink:#eceef2;--muted:#a3a9b6;--line:#343a46;--soft:#232833;--bg:#191d25}}',
        'button{font:inherit;color:inherit;cursor:pointer}',
        'button:focus-visible,textarea:focus-visible,input:focus-visible{outline:2px solid var(--accent);outline-offset:2px}',
        '.panel{position:fixed;top:0;right:0;bottom:0;z-index:2147483001;width:min(400px,100vw);display:flex;flex-direction:column;background:var(--bg);',
        'border-left:1px solid var(--line);box-shadow:-12px 0 32px rgb(0 0 0/.12);transform:translateX(100%);transition:transform .2s ease;visibility:hidden}',
        '.panel.on{transform:none;visibility:visible}',
        '.head{display:flex;align-items:center;justify-content:space-between;padding:14px 16px 10px}',
        '.head h2{margin:0;font-size:16px;font-weight:700}',
        '.icon{display:grid;place-items:center;width:30px;height:30px;border:0;border-radius:6px;background:transparent;color:var(--muted);font-size:20px;line-height:1}',
        '.icon:hover{background:var(--soft);color:var(--ink)}',
        '.filters{display:flex;flex-direction:column;gap:10px;padding:0 16px 12px;border-bottom:1px solid var(--line)}',
        '.seg{display:flex;padding:3px;border-radius:8px;background:var(--soft)}',
        '.seg button{flex:1;padding:5px 8px;border:0;border-radius:6px;background:transparent;color:var(--muted);font-weight:600;font-size:13px}',
        '.seg button[aria-pressed=true]{background:var(--bg);color:var(--ink);box-shadow:0 1px 2px rgb(0 0 0/.12)}',
        '.tabs{display:flex;gap:16px}',
        '.tabs button{padding:4px 0;border:0;border-bottom:2px solid transparent;background:transparent;color:var(--muted);font-weight:600;font-size:13px}',
        '.tabs button[aria-selected=true]{color:var(--ink);border-bottom-color:var(--accent)}',
        '.tabs span{margin-left:4px;color:var(--muted);font-weight:500}',
        '.body{flex:1;overflow:auto;padding:12px 16px;display:flex;flex-direction:column;gap:10px}',
        '.empty{margin:24px 0;color:var(--muted);text-align:center}',
        '.card{border:1px solid var(--line);border-radius:10px;background:var(--bg);padding:10px 12px}',
        '.card:hover,.card.hot{border-color:var(--accent)}',
        '.card-head{display:flex;align-items:flex-start;gap:8px;width:100%;padding:0;border:0;background:transparent;text-align:left}',
        '.num{flex:none;display:grid;place-items:center;min-width:22px;height:22px;padding:0 6px;border-radius:11px;background:var(--accent);color:var(--accent-ink);font-size:12px;font-weight:700}',
        '.resolved .num{background:var(--ok)}',
        '.where{color:var(--muted);font-size:12px;line-height:1.35;padding-top:3px}',
        '.page{display:block;color:var(--accent);font-size:12px}',
        '.meta{display:flex;align-items:center;gap:8px;margin:8px 0 4px;font-size:13px}',
        '.avatar{flex:none;display:grid;place-items:center;width:24px;height:24px;border-radius:12px;color:#fff;font-size:11px;font-weight:700}',
        '.meta time{color:var(--muted);font-size:12px}',
        '.badge{padding:1px 6px;border-radius:9px;background:var(--soft);color:var(--muted);font-size:11px;font-weight:600}',
        '.text{margin:0;white-space:pre-wrap;word-wrap:break-word}',
        '.clamp{display:-webkit-box;-webkit-line-clamp:4;-webkit-box-orient:vertical;overflow:hidden}',
        '.more{margin-top:6px;color:var(--muted);font-size:12px}',
        '.replies{margin:10px 0 0;padding:0 0 0 12px;border-left:2px solid var(--line);display:flex;flex-direction:column;gap:10px}',
        '.replies .meta{margin-top:0}',
        '.actions{display:flex;flex-wrap:wrap;gap:8px;margin-top:10px}',
        '.reply textarea,.composer textarea,.signin input{width:100%;padding:8px 10px;border:1px solid var(--line);border-radius:8px;background:var(--bg);color:var(--ink);font:inherit;resize:vertical}',
        '.reply{margin-top:10px;display:flex;flex-direction:column;gap:8px}',
        '.reply textarea{min-height:60px}',
        '.primary,.ghost{padding:7px 12px;border-radius:8px;font-weight:600;font-size:13px}',
        '.primary{border:1px solid var(--accent);background:var(--accent);color:var(--accent-ink)}',
        '.primary:disabled{opacity:.6;cursor:default}',
        '.ghost{border:1px solid var(--line);background:var(--bg)}',
        '.ghost:hover{background:var(--soft)}',
        '.link{padding:0;border:0;background:none;color:var(--accent);font-size:inherit;text-decoration:underline}',
        '.foot{padding:12px 16px;border-top:1px solid var(--line);display:flex;flex-direction:column;gap:8px}',
        '.foot .primary{width:100%;padding:10px}',
        '.who{margin:0;color:var(--muted);font-size:12px;text-align:center}',
        '.signin{display:flex;flex-direction:column;gap:12px;padding:8px 0}',
        '.signin h3{margin:0;font-size:15px}',
        '.signin p{margin:0;color:var(--muted)}',
        '.signin label{display:flex;flex-direction:column;gap:4px;font-weight:600;font-size:13px}',
        '.error{color:var(--warn);font-size:13px}',
        '.pins{position:fixed;inset:0;z-index:2147482999;pointer-events:none}',
        '.pin{position:fixed;transform:translate(-50%,-50%);pointer-events:auto;display:grid;place-items:center;min-width:26px;height:26px;padding:0 7px;',
        'border:2px solid #fff;border-radius:13px;background:#5b5bd6;color:#fff;font:700 12px/1 system-ui,sans-serif;box-shadow:0 2px 8px rgb(0 0 0/.3)}',
        '.pin.resolved{background:#1f8a5b}',
        '.pin.moved{border-style:dashed}',
        '.pin.hot{transform:translate(-50%,-50%) scale(1.25);z-index:1}',
        '.pin.draft{background:#1f2430}',
        '.mark{position:fixed;z-index:2147482998;pointer-events:none;border:2px solid #5b5bd6;border-radius:4px;background:rgb(91 91 214/.08);display:none}',
        '.banner{position:fixed;top:16px;left:50%;transform:translateX(-50%);z-index:2147483002;display:none;align-items:center;gap:12px;padding:10px 14px;',
        'border-radius:10px;background:#1f2430;color:#fff;box-shadow:0 6px 24px rgb(0 0 0/.25);font-size:13px}',
        '.banner .ghost{background:transparent;color:#fff;border-color:rgb(255 255 255/.35);padding:4px 10px}',
        '.composer{position:fixed;z-index:2147483002;width:300px;display:flex;flex-direction:column;gap:8px;padding:12px;border:1px solid var(--line);',
        'border-radius:12px;background:var(--bg);box-shadow:0 12px 32px rgb(0 0 0/.22)}',
        '.composer textarea{min-height:90px}',
        '.composer .where{padding:0}',
        '.row{display:flex;justify-content:flex-end;gap:8px}',
        '@media (max-width:480px){.composer{left:8px!important;right:8px;width:auto}}'
    ].join('');

    var BREAKPOINTS = [['2xl', 96], ['xl', 80], ['lg', 64], ['md', 48], ['sm', 40]];

    var state = {
        ctx: null,
        session: null,
        page: [],
        list: [],
        scope: 'page',
        status: 'open',
        openId: null,
        hotId: null,
        picking: false,
        draft: null,
        error: null,
        pending: null,
        numbers: {}
    };
    var ui = {};
    var frame = 0;

    /* ---------- helpers ---------- */

    function esc(s) {
        return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    function path() {
        return location.pathname.replace(/\/+$/, '') || '/';
    }

    function clamp(n) {
        return Math.max(0, Math.min(1, n));
    }

    function trimText(s, n) {
        s = String(s || '').replace(/\s+/g, ' ').trim();
        return s.length > n ? s.slice(0, n - 1).trim() + '…' : s;
    }

    function ago(iso) {
        var then = Date.parse(iso);
        if (!then) return '';
        var s = Math.round((then - Date.now()) / 1000);
        var units = [['year', 31536000], ['month', 2592000], ['week', 604800], ['day', 86400], ['hour', 3600], ['minute', 60]];
        var rtf = new Intl.RelativeTimeFormat(undefined, { numeric: 'auto' });
        for (var i = 0; i < units.length; i++) {
            if (Math.abs(s) >= units[i][1]) return rtf.format(Math.round(s / units[i][1]), units[i][0]);
        }
        return 'just now';
    }

    function initials(name) {
        var words = String(name || '').match(/[\p{L}\p{N}][\p{L}\p{N}'’-]*/gu) || ['?'];
        return (words[0][0] + (words.length > 1 ? words[words.length - 1][0] : '')).toUpperCase();
    }

    function hue(name) {
        var h = 0;
        for (var i = 0; i < String(name).length; i++) h = (h * 31 + String(name).charCodeAt(i)) % 360;
        return h;
    }

    function human(handle) {
        var s = String(handle || '').replace(/_/g, ' ');
        return s.charAt(0).toUpperCase() + s.slice(1);
    }

    function breakpoint(width) {
        var root = getComputedStyle(document.documentElement);
        for (var i = 0; i < BREAKPOINTS.length; i++) {
            var value = root.getPropertyValue('--breakpoint-' + BREAKPOINTS[i][0]).trim();
            var px = value ? parseFloat(value) * (/rem$/.test(value) ? 16 : 1) : BREAKPOINTS[i][1] * 16;
            if (width >= px) return BREAKPOINTS[i][0];
        }
        return 'base';
    }

    function api(method, url, body) {
        var headers = { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' };
        if (body) headers['Content-Type'] = 'application/json';
        if (state.session && state.session.token) headers['X-CSRF-TOKEN'] = state.session.token;
        return fetch(state.ctx.base + '/' + url, {
            method: method,
            credentials: 'same-origin',
            headers: headers,
            body: body ? JSON.stringify(body) : undefined
        }).then(function (r) {
            return r.json().catch(function () { return {}; }).then(function (data) {
                if (!r.ok) {
                    var error = new Error(data.message || 'Something went wrong (' + r.status + ').');
                    error.status = r.status;
                    error.data = data;
                    throw error;
                }
                return data;
            });
        });
    }

    /* ---------- where on the page ---------- */

    function unique(selector) {
        try { return document.querySelectorAll(selector).length === 1; } catch (e) { return false; }
    }

    function selectorFor(el) {
        var parts = [];
        for (var node = el; node && node.nodeType === 1 && node !== document.body && node !== document.documentElement; node = node.parentElement) {
            if (node.id && unique('#' + CSS.escape(node.id))) {
                parts.unshift('#' + CSS.escape(node.id));
                return parts.join(' > ');
            }
            var part = node.tagName.toLowerCase();
            var parent = node.parentElement;
            if (parent) {
                var same = Array.prototype.filter.call(parent.children, function (c) { return c.tagName === node.tagName; });
                if (same.length > 1) part += ':nth-of-type(' + (same.indexOf(node) + 1) + ')';
            }
            parts.unshift(part);
        }
        return 'body > ' + parts.join(' > ');
    }

    // The kit's templates open and close with comments naming their file, so the comments still open at an element say
    // which block, text editor set or layout part it is in.
    function templatePlace(el) {
        var walker = document.createTreeWalker(document.body, NodeFilter.SHOW_COMMENT);
        var stack = [];
        var node;
        while ((node = walker.nextNode())) {
            if (!(node.compareDocumentPosition(el) & Node.DOCUMENT_POSITION_FOLLOWING)) break;
            var m = /^\s*(End:\s*)?\/?([\w\-./:]+?)\.antlers\.html\s*$/.exec(node.nodeValue || '');
            if (!m) continue;
            if (m[1]) {
                var at = stack.lastIndexOf(m[2]);
                if (at > -1) stack.splice(at, 1);
            } else {
                stack.push(m[2]);
            }
        }
        var place = { block: null, set: null, part: null };
        for (var i = stack.length - 1; i >= 0; i--) {
            var b = /^page_builder\/_(\w+)$/.exec(stack[i]);
            var s = /^components\/_(\w+)$/.exec(stack[i]);
            var p = /^layout\/_(\w+)$/.exec(stack[i]);
            if (b && !place.block) place.block = b[1];
            if (s && !place.set && !place.block) place.set = s[1];
            if (p && !place.part) place.part = p[1];
        }
        return place;
    }

    function nearestHeading(el) {
        if (/^H[1-6]$/.test(el.tagName)) return trimText(el.textContent, 60);
        var container = el.closest('section, header, footer, article, main') || document.body;
        var headings = container.querySelectorAll('h1, h2, h3, h4, h5, h6');
        var found = null;
        for (var i = 0; i < headings.length; i++) {
            var h = headings[i];
            if (h.contains(el) || h.compareDocumentPosition(el) & Node.DOCUMENT_POSITION_FOLLOWING) found = h;
        }
        found = found || headings[0];
        return found ? trimText(found.textContent, 60) : '';
    }

    function labelFor(el, place) {
        var parts = [];
        if (place.part === 'header') parts.push('Site header');
        if (place.part === 'footer') parts.push('Site footer');
        if (place.set) parts.push(human(place.set) + ' set');
        var heading = nearestHeading(el);
        var own = trimText(el.textContent, 41).replace(/\s*\*$/, '');
        // Short text of its own, like a label or a button, says exactly what was clicked; the heading says where.
        if (own && own.length <= 40 && own !== heading) parts.push('“' + own + '”');
        if (heading) parts.push('near “' + heading + '”');
        return parts.join(', ');
    }

    function anchorFor(el, x, y) {
        var r = el.getBoundingClientRect();
        var doc = document.documentElement;
        var place = templatePlace(el);
        return {
            selector: selectorFor(el),
            x: clamp((x - r.left) / (r.width || 1)),
            y: clamp((y - r.top) / (r.height || 1)),
            page_x: clamp((x + window.scrollX) / Math.max(doc.scrollWidth, 1)),
            page_y: Math.round(y + window.scrollY),
            block: place.block,
            label: labelFor(el, place),
            text: trimText(el.textContent, 120)
        };
    }

    function where(comment) {
        var a = comment.anchor || {};
        var block = a.block_name || (a.block ? human(a.block) : '');
        return [block ? block + ' block' : '', a.label || ''].filter(Boolean).join(', ') || 'On this page';
    }

    function target(comment) {
        var a = comment.anchor || {};
        if (!a.selector) return null;
        try {
            var el = document.querySelector(a.selector);
            return el && el.getClientRects().length ? el : null;
        } catch (e) {
            return null;
        }
    }

    function position(comment) {
        var a = comment.anchor || {};
        var el = target(comment);
        if (el) {
            var r = el.getBoundingClientRect();
            return { x: r.left + (a.x == null ? 0.5 : a.x) * r.width, y: r.top + (a.y == null ? 0.5 : a.y) * r.height, el: el, moved: false };
        }
        if (a.page_y == null) return null;
        return { x: (a.page_x || 0.5) * document.documentElement.scrollWidth - window.scrollX, y: a.page_y - window.scrollY, el: null, moved: true };
    }

    /* ---------- data ---------- */

    function loadSession() {
        return api('GET', 'session').then(function (data) { state.session = data; });
    }

    function loadPage() {
        return api('GET', 'comments?url=' + encodeURIComponent(path())).then(function (data) {
            state.page = data.comments || [];
            var oldest = state.page.slice().sort(function (a, b) { return a.created_at < b.created_at ? -1 : 1; });
            state.numbers = {};
            oldest.forEach(function (c, i) { state.numbers[c.id] = i + 1; });
            state.ctx.setCount(state.page.filter(function (c) { return c.status === 'open'; }).length);
        });
    }

    function loadList() {
        if (state.scope === 'page') {
            state.list = state.page;
            return Promise.resolve();
        }
        return api('GET', 'comments?scope=all').then(function (data) { state.list = data.comments || []; });
    }

    function refresh() {
        return loadPage().then(loadList).then(render).catch(fail);
    }

    function fail(error) {
        if (error && error.status === 401) {
            state.session = state.session || {};
            state.session.viewer = null;
        } else {
            state.error = error && error.message ? error.message : 'Something went wrong.';
        }
        render();
    }

    function replace(comment) {
        [state.page, state.list].forEach(function (list) {
            for (var i = 0; i < list.length; i++) if (list[i].id === comment.id) list[i] = Object.assign({}, list[i], comment, { anchor: Object.assign({}, list[i].anchor, comment.anchor) });
        });
        state.ctx.setCount(state.page.filter(function (c) { return c.status === 'open'; }).length);
    }

    /* ---------- rendering ---------- */

    function build() {
        var root = state.ctx.root;
        var style = document.createElement('style');
        style.textContent = css;
        var wrap = document.createElement('div');
        wrap.className = 'fb';
        wrap.innerHTML =
            '<div class="pins" aria-hidden="false"></div><div class="mark"></div>' +
            '<div class="banner" role="status"><span>Click where your comment belongs</span><button type="button" class="ghost cancel-pick">Cancel</button></div>' +
            '<aside class="panel" role="dialog" aria-label="Feedback" aria-modal="false">' +
            '<div class="head"><h2 tabindex="-1">Feedback</h2><button type="button" class="icon close" aria-label="Close feedback">×</button></div>' +
            '<div class="filters">' +
            '<div class="seg" role="group" aria-label="Pages"><button type="button" data-scope="page">This page</button><button type="button" data-scope="all">All pages</button></div>' +
            '<div class="tabs" role="tablist"><button type="button" role="tab" data-status="open">Open<span></span></button><button type="button" role="tab" data-status="resolved">Resolved<span></span></button></div>' +
            '</div><div class="body"></div><div class="foot"></div></aside>';
        root.appendChild(style);
        root.appendChild(wrap);
        ui.wrap = wrap;
        ui.pins = wrap.querySelector('.pins');
        ui.mark = wrap.querySelector('.mark');
        ui.banner = wrap.querySelector('.banner');
        ui.panel = wrap.querySelector('.panel');
        ui.body = wrap.querySelector('.body');
        ui.foot = wrap.querySelector('.foot');
        ui.heading = wrap.querySelector('.head h2');

        wrap.addEventListener('click', onClick);
        wrap.addEventListener('submit', onSubmit);
        wrap.addEventListener('keydown', onKeydown);
        wrap.addEventListener('mouseover', onHover);
        wrap.addEventListener('mouseout', function (e) { if (e.target.closest && e.target.closest('[data-id]')) hot(null); });
        window.addEventListener('scroll', schedule, { passive: true });
        window.addEventListener('resize', schedule);
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape') escape(); });
        setInterval(function () { if (ui.panel.classList.contains('on')) placePins(); }, 1000);
    }

    function render() {
        var viewer = state.session && state.session.viewer;
        var shown = state.list.filter(function (c) { return c.status === state.status; });

        ui.wrap.querySelectorAll('[data-scope]').forEach(function (b) { b.setAttribute('aria-pressed', String(b.dataset.scope === state.scope)); });
        ui.wrap.querySelectorAll('[data-status]').forEach(function (b) {
            b.setAttribute('aria-selected', String(b.dataset.status === state.status));
            b.querySelector('span').textContent = ' ' + state.list.filter(function (c) { return c.status === b.dataset.status; }).length;
        });

        if (!viewer) {
            ui.body.innerHTML = signInForm();
            ui.foot.innerHTML = '';
        } else {
            ui.body.innerHTML = (state.error ? '<p class="error" role="alert">' + esc(state.error) + '</p>' : '') +
                (shown.length ? shown.map(card).join('') : '<p class="empty">' + (state.status === 'open' ? 'No open feedback ' : 'Nothing resolved ') + (state.scope === 'page' ? 'on this page yet.' : 'yet.') + '</p>');
            ui.foot.innerHTML = '<button type="button" class="primary add">Add feedback</button>' +
                '<p class="who">Commenting as <b>' + esc(viewer.name) + '</b>' + (viewer.staff ? '' : ' · <button type="button" class="link sign-out">Not you?</button>') + '</p>';
        }
        state.error = null;
        placePins();
    }

    function signInForm() {
        var needs = state.session && state.session.needs_password;
        return '<form class="signin" data-form="sign-in" novalidate><h3>Who’s commenting?</h3>' +
            '<p>Your name shows beside your comments' + (needs ? '. The password is the one you were given for this review.' : '.') + '</p>' +
            '<label>Your name<input name="name" autocomplete="name" maxlength="80" required></label>' +
            (needs ? '<label>Password<input name="password" type="password" autocomplete="current-password" required></label>' : '') +
            (state.error ? '<p class="error" role="alert">' + esc(state.error) + '</p>' : '') +
            '<button class="primary">Continue</button></form>';
    }

    function author(person, time) {
        var name = (person && person.name) || 'Someone';
        return '<div class="meta"><span class="avatar" style="background:hsl(' + hue(name) + ' 45% 45%)" aria-hidden="true">' + esc(initials(name)) + '</span>' +
            '<b>' + esc(name) + '</b>' + (person && person.staff ? '<span class="badge">team</span>' : '') + '<time datetime="' + esc(time) + '">' + esc(ago(time)) + '</time></div>';
    }

    function card(c) {
        var open = state.openId === c.id;
        var here = c.url === path();
        var number = here ? state.numbers[c.id] : null;
        var replies = c.replies || [];
        var html = '<article class="card' + (c.status === 'resolved' ? ' resolved' : '') + (state.hotId === c.id ? ' hot' : '') + '" data-id="' + esc(c.id) + '">' +
            '<button type="button" class="card-head toggle" aria-expanded="' + open + '">' +
            (number ? '<span class="num">' + number + '</span>' : '<span class="num">·</span>') +
            '<span class="where">' + esc(where(c)) + (here ? '' : '<span class="page">' + esc(c.url) + '</span>') + '</span></button>' +
            author(c.author, c.created_at) +
            '<p class="text' + (open ? '' : ' clamp') + '">' + esc(c.body) + '</p>';

        if (!open) {
            if (replies.length) html += '<p class="more">' + replies.length + (replies.length === 1 ? ' reply' : ' replies') + '</p>';
            return html + '</article>';
        }

        if (replies.length) {
            html += '<div class="replies">' + replies.map(function (r) { return '<div>' + author(r.author, r.created_at) + '<p class="text">' + esc(r.body) + '</p></div>'; }).join('') + '</div>';
        }
        if (c.status === 'resolved' && c.resolved_by) {
            html += '<p class="more">Resolved by ' + esc(c.resolved_by.name) + ' ' + esc(ago(c.resolved_at)) + '</p>';
        }
        html += '<form class="reply" data-form="reply"><textarea name="body" aria-label="Reply" placeholder="Reply…" required></textarea>' +
            '<div class="actions"><button class="primary">Reply</button>' +
            (c.status === 'open' ? '<button type="button" class="ghost resolve">Resolve</button>' : '<button type="button" class="ghost reopen">Reopen</button>') +
            (here ? '<button type="button" class="ghost show">Show on page</button>' : '<a class="ghost" href="' + esc(c.url + '?feedback=' + c.id) + '">Go to page</a>') +
            '</div></form>';
        return html + '</article>';
    }

    function schedule() {
        if (frame) return;
        frame = requestAnimationFrame(function () { frame = 0; placePins(); });
    }

    function placePins() {
        if (!ui.pins) return;
        var on = ui.panel.classList.contains('on');
        var html = '';
        if (on) {
            state.page.filter(function (c) { return c.status === state.status; }).forEach(function (c) {
                var p = position(c);
                if (!p) return;
                html += '<button type="button" class="pin' + (c.status === 'resolved' ? ' resolved' : '') + (p.moved ? ' moved' : '') + (state.hotId === c.id ? ' hot' : '') +
                    '" data-pin="' + esc(c.id) + '" style="left:' + p.x + 'px;top:' + p.y + 'px" aria-label="Comment ' + state.numbers[c.id] + (p.moved ? ', its element has changed' : '') + '">' +
                    state.numbers[c.id] + '</button>';
            });
        }
        if (state.draft) {
            html += '<span class="pin draft" style="left:' + state.draft.x + 'px;top:' + state.draft.y + 'px">+</span>';
        }
        ui.pins.innerHTML = html;
        mark(state.hotId ? find(state.hotId) : null);
    }

    function find(id) {
        return state.page.concat(state.list).filter(function (c) { return c.id === id; })[0] || null;
    }

    function mark(comment) {
        var el = comment && comment.url === path() ? target(comment) : null;
        if (!el) {
            ui.mark.style.display = 'none';
            return;
        }
        var r = el.getBoundingClientRect();
        Object.assign(ui.mark.style, { display: 'block', left: r.left - 3 + 'px', top: r.top - 3 + 'px', width: r.width + 6 + 'px', height: r.height + 6 + 'px' });
    }

    function hot(id) {
        if (state.hotId === id) return;
        state.hotId = id;
        ui.wrap.querySelectorAll('.card').forEach(function (c) { c.classList.toggle('hot', c.dataset.id === id); });
        placePins();
    }

    /* ---------- picking a spot ---------- */

    function startPicking() {
        state.picking = true;
        ui.panel.classList.remove('on');
        ui.banner.style.display = 'flex';
        document.documentElement.style.setProperty('cursor', 'crosshair', 'important');
        document.addEventListener('mousemove', onPickMove, true);
        document.addEventListener('click', onPick, true);
    }

    function stopPicking() {
        state.picking = false;
        ui.banner.style.display = 'none';
        ui.mark.style.display = 'none';
        document.documentElement.style.removeProperty('cursor');
        document.removeEventListener('mousemove', onPickMove, true);
        document.removeEventListener('click', onPick, true);
    }

    function ours(e) {
        return e.composedPath && e.composedPath().indexOf(state.ctx.root.host) > -1;
    }

    function onPickMove(e) {
        if (ours(e)) {
            ui.mark.style.display = 'none';
            return;
        }
        var r = e.target.getBoundingClientRect();
        Object.assign(ui.mark.style, { display: 'block', left: r.left - 3 + 'px', top: r.top - 3 + 'px', width: r.width + 6 + 'px', height: r.height + 6 + 'px' });
    }

    function onPick(e) {
        if (ours(e)) return;
        e.preventDefault();
        e.stopPropagation();
        var anchor = anchorFor(e.target, e.clientX, e.clientY);
        stopPicking();
        state.draft = { anchor: anchor, x: e.clientX, y: e.clientY, pageY: e.clientY + window.scrollY };
        placePins();
        compose();
    }

    function compose() {
        var d = state.draft;
        var form = document.createElement('form');
        form.className = 'composer';
        form.dataset.form = 'compose';
        form.innerHTML = '<p class="where">' + esc(where({ anchor: d.anchor })) + '</p>' +
            '<textarea name="body" aria-label="Your comment" placeholder="What should change here?" required></textarea>' +
            '<p class="error" role="alert" hidden></p>' +
            '<div class="row"><button type="button" class="ghost cancel-compose">Cancel</button><button class="primary">Post</button></div>';
        var left = Math.min(Math.max(8, d.x + 16), window.innerWidth - 316);
        var top = Math.min(Math.max(8, d.y + 16), window.innerHeight - 220);
        form.style.left = left + 'px';
        form.style.top = top + 'px';
        ui.wrap.appendChild(form);
        ui.composer = form;
        form.querySelector('textarea').focus();
    }

    function closeComposer() {
        if (ui.composer) ui.composer.remove();
        ui.composer = null;
        state.draft = null;
        placePins();
    }

    /* ---------- events ---------- */

    function onHover(e) {
        var card = e.target.closest && e.target.closest('[data-id], [data-pin]');
        if (card) hot(card.dataset.id || card.dataset.pin);
    }

    function onClick(e) {
        var t = e.target;
        var cardEl = t.closest('[data-id]');
        var id = cardEl && cardEl.dataset.id;

        if (t.closest('.close')) return close();
        if (t.closest('.cancel-pick')) { stopPicking(); return openPanel(); }
        if (t.closest('.cancel-compose')) { closeComposer(); return openPanel(); }
        if (t.closest('[data-scope]')) {
            state.scope = t.closest('[data-scope]').dataset.scope;
            return loadList().then(render).catch(fail);
        }
        if (t.closest('[data-status]')) {
            state.status = t.closest('[data-status]').dataset.status;
            return render();
        }
        if (t.closest('.add')) return startPicking();
        if (t.closest('.sign-out')) {
            return api('POST', 'sign-out', {}).then(function () { state.session.viewer = null; render(); }).catch(fail);
        }
        if (t.closest('[data-pin]')) {
            var pinned = t.closest('[data-pin]').dataset.pin;
            state.openId = pinned;
            state.scope = 'page';
            state.list = state.page;
            openPanel();
            return render();
        }
        if (t.closest('.toggle') && id) {
            state.openId = state.openId === id ? null : id;
            return render();
        }
        if (t.closest('.show') && id) return show(find(id));
        if (t.closest('.resolve') && id) return act(api('POST', 'comments/' + id + '/resolve', {}));
        if (t.closest('.reopen') && id) return act(api('POST', 'comments/' + id + '/reopen', {}));
    }

    function act(request) {
        return request.then(function (data) { replace(data.comment); render(); }).catch(fail);
    }

    function onSubmit(e) {
        e.preventDefault();
        var form = e.target;
        var kind = form.dataset.form;
        var data = Object.fromEntries(new FormData(form));
        var button = form.querySelector('.primary');
        if (button) button.disabled = true;

        if (kind === 'sign-in') {
            api('POST', 'sign-in', data).then(function (res) {
                state.session.viewer = res.viewer;
                return loadSession();
            }).then(function () {
                if (state.pending === 'add') {
                    state.pending = null;
                    return startPicking();
                }
                return refresh();
            }).catch(function (error) {
                var errors = error.data && error.data.errors;
                state.error = errors ? Object.keys(errors).map(function (k) { return errors[k][0]; }).join(' ') : error.message;
                render();
            });
            return;
        }

        if (kind === 'reply') {
            var id = form.closest('[data-id]').dataset.id;
            if (!String(data.body || '').trim()) { if (button) button.disabled = false; return; }
            act(api('POST', 'comments/' + id + '/replies', { body: data.body }));
            return;
        }

        if (kind === 'compose') {
            if (!String(data.body || '').trim()) { if (button) button.disabled = false; return; }
            var d = state.draft;
            var width = window.innerWidth;
            api('POST', 'comments', {
                url: path(),
                entry: state.ctx.entry || null,
                title: document.title,
                body: data.body,
                anchor: d.anchor,
                viewport: { width: width, height: window.innerHeight, breakpoint: breakpoint(width) }
            }).then(function (res) {
                closeComposer();
                state.status = 'open';
                state.scope = 'page';
                state.openId = res.comment.id;
                openPanel();
                return refresh();
            }).catch(function (error) {
                if (error.status === 401) {
                    closeComposer();
                    state.session.viewer = null;
                    openPanel();
                    return render();
                }
                var box = form.querySelector('.error');
                box.textContent = error.message;
                box.hidden = false;
                if (button) button.disabled = false;
            });
        }
    }

    function onKeydown(e) {
        if (e.key === 'Enter' && (e.metaKey || e.ctrlKey) && e.target.tagName === 'TEXTAREA') {
            e.preventDefault();
            e.target.form.requestSubmit();
        }
    }

    function escape() {
        if (state.picking) { stopPicking(); return openPanel(); }
        if (ui.composer) { closeComposer(); return openPanel(); }
        if (ui.panel.classList.contains('on')) close();
    }

    function show(comment) {
        var el = comment && target(comment);
        if (el) {
            el.scrollIntoView({ behavior: 'smooth', block: 'center' });
        } else if (comment && comment.anchor && comment.anchor.page_y != null) {
            window.scrollTo({ top: Math.max(0, comment.anchor.page_y - window.innerHeight / 2), behavior: 'smooth' });
        }
        hot(comment ? comment.id : null);
    }

    function openPanel() {
        ui.panel.classList.add('on');
        placePins();
    }

    function close() {
        ui.panel.classList.remove('on');
        state.hotId = null;
        placePins();
    }

    /* ---------- entry point ---------- */

    function open(ctx) {
        var first = !state.ctx;
        state.ctx = ctx;
        if (first) build();
        openPanel();
        ui.heading.focus({ preventScroll: true });

        loadSession().then(loadPage).then(loadList).then(function () {
            if (ctx.focus) {
                var c = find(ctx.focus);
                if (c) {
                    state.status = c.status;
                    state.openId = c.id;
                    render();
                    return show(c);
                }
            }
            render();
        }).catch(function (error) {
            if (error.status === 401) {
                state.session = state.session || { viewer: null };
                state.session.viewer = null;
                return render();
            }
            fail(error);
        });
    }

    window.__avocaFeedback = { open: open };
})();
