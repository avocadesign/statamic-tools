/*
 * Avoca feedback: the widget, which works as the prototype's comments do. A panel on the right lists this page's
 * comments, or all the feedback on the site filtered to open, to decide or done. Add comment asks for a spot on the
 * page: the comment is pinned to the element clicked, at that point within it, so its numbered pin follows the element
 * when the layout changes, and a pin that covers something can be dragged to another spot. Pins are graphite while
 * open, amber for a decision to make and green once done. The team can raise a comment as a decision and record what
 * was decided. For a browser that has reviewed before, the loader starts it once the page is idle, so pins and the
 * count show without opening anything. Everything lives in the loader's shadow root, so the site's styles never reach
 * it and it never reaches them.
 */
(function () {
    'use strict';

    if (window.__avocaFeedback) return;

    var css = [
        ':host{all:initial}',
        '*,*::before,*::after{box-sizing:border-box}',
        '.fb{--ink:#1f2430;--muted:#5d6475;--line:#e3e5ea;--line-2:#cfd3da;--soft:#f4f5f7;--bg:#fff;--accent:#1f2430;--accent-ink:#fff;',
        '--note:#3d4452;--note-ink:#fff;--amber:#f2b632;--amber-ink:#1b1400;--green:#47cb50;--green-ink:#12151b;--green-text:#1d7a34;--warn:#b54708;',
        'font:14px/1.45 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;color:var(--ink)}',
        '@media (prefers-color-scheme:dark){.fb{--ink:#eceef2;--muted:#a3a9b6;--line:#2b313c;--line-2:#343a46;--soft:#232833;--bg:#191d25;--accent:#eceef2;--accent-ink:#191d25;--green-text:#76dd7e;--warn:#f08b78}}',
        'button{font:inherit;color:inherit;cursor:pointer}',
        'button:focus-visible,a:focus-visible,summary:focus-visible{outline:2px solid var(--accent);outline-offset:2px}',
        // The heading takes focus when the panel opens so a screen reader starts there; it needs no ring. Text fields
        // show focus with their border, like any form field.
        '[tabindex="-1"]:focus{outline:none}',
        'textarea:focus,input:focus{outline:none;border-color:var(--ink);box-shadow:0 0 0 1px var(--ink)}',
        '.panel{position:fixed;top:0;right:0;bottom:0;z-index:2147483001;width:min(400px,100vw);display:flex;flex-direction:column;background:var(--bg);',
        'border-left:1px solid var(--line);box-shadow:-12px 0 32px rgb(0 0 0/.12);transform:translateX(100%);transition:transform .2s ease;visibility:hidden}',
        '.panel.on{transform:none;visibility:visible}',
        '.head{display:flex;align-items:center;justify-content:space-between;padding:14px 16px 6px}',
        '.head h2{margin:0;font-size:16px;font-weight:700}',
        '.icon{display:grid;place-items:center;width:30px;height:30px;border:0;border-radius:6px;background:transparent;color:var(--muted)}',
        '.icon:hover{background:var(--soft);color:var(--ink)}',
        '.bar{display:flex;flex-direction:column;gap:10px;padding:4px 16px 12px;border-bottom:1px solid var(--line)}',
        '.bar:empty{display:none}',
        '.scope{display:flex;align-items:center;justify-content:space-between;gap:8px}',
        '.scope p{margin:0;display:flex;flex-wrap:wrap;align-items:baseline;gap:6px;font-size:13px;color:var(--muted)}',
        '.scope b{color:var(--ink)}',
        '.scope .add{flex:none;white-space:nowrap}',
        '.toggle{display:inline-flex;align-items:center;gap:7px;font-size:13px;color:var(--ink);user-select:none}',
        '.toggle input{accent-color:var(--accent);width:15px;height:15px;margin:0}',
        '.chips{display:flex;flex-wrap:wrap;gap:6px}',
        '.chip{padding:3px 10px;border:1px solid var(--line-2);border-radius:999px;background:var(--bg);font-size:12px}',
        '.chip span{color:var(--muted)}',
        '.chip[aria-pressed=true]{background:var(--accent);border-color:var(--accent);color:var(--accent-ink)}',
        '.chip[aria-pressed=true] span{color:inherit;opacity:.7}',
        '.body{flex:1;overflow:auto;padding:12px 16px;display:flex;flex-direction:column;gap:10px}',
        '.empty{margin:16px 0;color:var(--muted)}',
        '.card{border:1px solid var(--line);border-radius:10px;background:var(--bg)}',
        '.card:not(.is-open){cursor:pointer}',
        '.card:hover,.card.hot{border-color:var(--line-2)}',
        '.card.is-open{background:var(--soft);border-color:var(--line-2)}',
        '.card--decide{border-left:3px solid var(--amber)}',
        '.card--done{border-left:3px solid var(--green)}',
        '.card-head{display:grid;grid-template-columns:auto minmax(0,1fr) auto auto;gap:10px;align-items:center;width:100%;padding:10px 12px 4px;border:0;background:transparent;text-align:left}',
        '.num{flex:none;display:inline-grid;place-items:center;min-width:22px;height:22px;padding:0 6px;border-radius:11px;background:var(--note);color:var(--note-ink);font:700 11px/1 ui-monospace,SFMono-Regular,Menlo,monospace}',
        '.num--decide{background:var(--amber);color:var(--amber-ink)}',
        '.num--done{background:var(--green);color:var(--green-ink)}',
        '.num--draft{background:var(--accent);color:var(--accent-ink)}',
        '.by{display:flex;flex-wrap:wrap;align-items:baseline;gap:2px 8px;min-width:0;font-size:12px;color:var(--muted)}',
        '.by b{font-size:13px;color:var(--ink)}',
        '.by time{white-space:nowrap}',
        '.who-decides{margin:0;font-size:12px;color:var(--muted)}',
        // Open and close: the chevron points down on a closed comment and up on an open one.
        '.chev{display:grid;place-items:center;width:20px;height:20px;color:var(--muted)}',
        '.chev svg{transition:transform .2s}',
        '.card-head[aria-expanded=true] .chev svg{transform:rotate(180deg)}',
        '.card-head:hover .chev{color:var(--ink)}',
        '.badge{padding:0 6px;border-radius:9px;background:var(--soft);color:var(--muted);font-size:11px;font-weight:600}',
        '.state{padding:1px 8px;border:1px solid var(--line-2);border-radius:999px;font-size:11px;font-weight:600;white-space:nowrap;color:var(--muted)}',
        '.state.decide{border-color:var(--amber);color:var(--warn)}',
        '.state.done{border-color:var(--green);color:var(--green-text)}',
        '.card-main{display:flex;flex-direction:column;gap:8px;padding:0 12px 12px 44px}',
        '.text{margin:0;white-space:pre-wrap;overflow-wrap:anywhere}',
        '.clamp{display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;overflow:hidden}',
        '.more{margin:0;color:var(--muted);font-size:12px}',
        '.outcome{padding:8px 10px;border:1px solid var(--green);border-radius:8px;display:flex;flex-direction:column;gap:2px}',
        '.outcome b{font-size:11px;text-transform:uppercase;letter-spacing:.08em;color:var(--green-text)}',
        '.outcome p{margin:0;white-space:pre-wrap;overflow-wrap:anywhere}',
        '.outcome small{color:var(--muted);font-size:11.5px}',
        '.replies{margin:0;padding:0 0 0 12px;list-style:none;border-left:2px solid var(--line-2);display:flex;flex-direction:column;gap:10px}',
        '.replies .by{margin-bottom:2px}',
        // An open comment's foot: a slim reply box, its Reply button once something is typed, the next step and a menu.
        '.form.reply textarea{min-height:34px;field-sizing:content;max-height:200px;resize:none}',
        '.foot-bar{display:flex;align-items:center;gap:8px}',
        '.reply:has(textarea:placeholder-shown) .send{display:none}',
        '.acts{display:flex;align-items:center;gap:4px;margin-left:auto}',
        '.more-wrap{position:relative}',
        '.menu{position:absolute;z-index:2;right:0;top:calc(100% + 4px);display:flex;flex-direction:column;min-width:170px;padding:4px;border:1px solid var(--line-2);border-radius:10px;background:var(--bg);box-shadow:0 8px 24px rgb(0 0 0/.18)}',
        '.menu[hidden]{display:none}',
        '.menu button{border:0;background:none;text-align:left;padding:7px 10px;border-radius:6px;font-size:13px;color:var(--ink);white-space:nowrap}',
        '.menu button:hover{background:var(--soft)}',
        '.icon.small{width:26px;height:26px}',
        '.form{display:flex;flex-direction:column;gap:8px}',
        '.form textarea,.form input[type=email],.form input[name=name],.form input[type=password]{width:100%;padding:8px 10px;border:1px solid var(--line-2);border-radius:8px;background:var(--bg);color:var(--ink);font:inherit;resize:vertical}',
        '.form textarea{min-height:60px}',
        '.compose{padding:12px;border:1px solid var(--line-2);border-radius:10px;background:var(--soft)}',
        '.compose textarea{min-height:84px}',
        '.where{margin:0;display:flex;align-items:center;gap:8px;font-size:12px;color:var(--muted)}',
        '.row{display:flex;flex-wrap:wrap;align-items:center;gap:8px}',
        '.primary,.ghost{padding:7px 12px;border-radius:8px;font-weight:600;font-size:13px;text-decoration:none}',
        '.primary{border:1px solid var(--accent);background:var(--accent);color:var(--accent-ink)}',
        '.primary:disabled,.ghost:disabled{opacity:.6;cursor:default}',
        '.ghost{border:1px solid var(--line-2);background:var(--bg);color:var(--ink)}',
        '.ghost:hover{background:var(--soft)}',
        '.ghost.small{padding:4px 10px;font-size:12.5px}',
        '.ghost[aria-pressed=true]{background:var(--accent);border-color:var(--accent);color:var(--accent-ink)}',
        '.link{padding:0;border:0;background:none;color:var(--ink);font-size:12.5px;font-weight:600;text-decoration:underline;text-decoration-color:var(--line-2);text-underline-offset:3px}',
        '.link:hover{text-decoration-color:currentColor}',
        '.fold>summary{cursor:pointer;list-style:none;display:flex;align-items:center;gap:8px;font-weight:600;padding:4px 0}',
        '.fold>summary::-webkit-details-marker{display:none}',
        '.fold>summary::before{content:"›";color:var(--muted);transition:transform .2s}',
        '.fold[open]>summary::before{transform:rotate(90deg)}',
        '.fold>summary span{font:500 11px ui-monospace,monospace;color:var(--muted)}',
        '.fold>div{margin-top:8px;display:flex;flex-direction:column;gap:8px}',
        '.group{display:flex;flex-direction:column;gap:8px;padding-top:10px;border-top:1px solid var(--line)}',
        '.group-h{display:flex;align-items:baseline;justify-content:space-between;gap:12px}',
        '.group-h h3{margin:0;font-size:14px;font-weight:600;overflow-wrap:anywhere}',
        '.group-h span{color:var(--muted);font-size:12px;white-space:nowrap}',
        '.foot{padding:10px 16px;border-top:1px solid var(--line)}',
        '.foot:empty{display:none}',
        '.who{margin:0;color:var(--muted);font-size:12px;text-align:center}',
        '.signin{display:flex;flex-direction:column;gap:12px;padding:8px 0}',
        '.signin h3{margin:0;font-size:15px}',
        '.signin p{margin:0;color:var(--muted)}',
        '.signin label{display:flex;flex-direction:column;gap:4px;font-weight:600;font-size:13px}',
        '.error{margin:0;color:var(--warn);font-size:13px}',
        '.pins{position:fixed;inset:0;z-index:2147482999;pointer-events:none}',
        '.pin{position:fixed;transform:translate(-50%,-50%);pointer-events:auto;display:grid;place-items:center;min-width:26px;height:26px;padding:0 6px;margin:0;',
        'border:0;border-radius:13px;background:var(--note);color:var(--note-ink);font:700 12px/1 ui-monospace,SFMono-Regular,Menlo,monospace;',
        'box-shadow:0 0 0 2px #fff,0 2px 8px rgb(0 0 0/.3);cursor:grab;touch-action:none;transition:transform .15s}',
        '.pin--decide{background:var(--amber);color:var(--amber-ink)}',
        '.pin--done{background:var(--green);color:var(--green-ink)}',
        '.pin--draft{background:#12151b;color:#fff}',
        // Its element has gone since: the pin sits where the comment was made, dashed.
        '.pin.moved{outline:2px dashed #fff;outline-offset:-5px}',
        '.pin:hover,.pin.hot{transform:translate(-50%,-50%) scale(1.18);z-index:1}',
        '.pin.dragging{cursor:grabbing;transition:none;transform:translate(-50%,-50%) scale(1.18)}',
        '.mark{position:fixed;z-index:2147482998;pointer-events:none;border:2px solid #12151b;border-radius:4px;box-shadow:0 0 0 2px rgb(255 255 255/.8);display:none}',
        '.mark.picking{background:rgb(18 21 27/.06)}',
        '.banner{position:fixed;top:16px;left:50%;transform:translateX(-50%);z-index:2147483002;display:none;align-items:center;gap:12px;padding:10px 14px;',
        'border-radius:10px;background:#1f2430;color:#fff;box-shadow:0 6px 24px rgb(0 0 0/.25);font-size:13px}',
        '.banner .ghost{background:transparent;color:#fff;border-color:rgb(255 255 255/.35);padding:4px 10px}',
        '@media (prefers-reduced-motion:reduce){.panel,.pin{transition:none}}'
    ].join('');

    var BREAKPOINTS = [['2xl', 96], ['xl', 80], ['lg', 64], ['md', 48], ['sm', 40]];
    var FILTERS = [['open', 'Open'], ['decide', 'To decide'], ['done', 'Done'], ['all', 'All']];
    var EMPTY = { open: 'Nothing open.', decide: 'No decisions waiting.', done: 'Nothing done yet.', all: 'No comments yet.' };
    var PINS_KEY = 'avoca-feedback-pins';

    var state = {
        ctx: null,
        session: null,
        loading: null,
        all: [],
        numbers: {},
        panel: false,
        scope: 'page',
        show: 'open',
        openId: null,
        hoverId: null,
        deciding: null,
        picking: false,
        draft: null,
        error: null,
        errorFor: null,
        pins: true
    };
    var ui = {};
    var frame = 0;
    var drag = null;
    var dragged = 0;

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
                    var errors = data.errors;
                    var first = errors ? errors[Object.keys(errors)[0]][0] : null;
                    var error = new Error(first || data.message || 'Something went wrong (' + r.status + ').');
                    error.status = r.status;
                    error.data = data;
                    throw error;
                }
                return data;
            });
        });
    }

    function readPins() {
        try { return localStorage.getItem(PINS_KEY) !== '0'; } catch (e) { return true; }
    }

    function savePins(on) {
        try { localStorage.setItem(PINS_KEY, on ? '1' : '0'); } catch (e) { /* storage unavailable */ }
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

    // Kept with the comment for avoca:feedback, which says in words where each comment is. The panel shows the pin.
    function labelFor(el, place) {
        var parts = [];
        if (place.part === 'header') parts.push('Site header');
        if (place.part === 'footer') parts.push('Site footer');
        if (place.set) parts.push(human(place.set) + ' set');
        var heading = nearestHeading(el);
        var own = trimText(el.textContent, 41).replace(/\s*\*$/, '');
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

    function target(anchor) {
        var a = anchor || {};
        if (!a.selector) return null;
        try {
            var el = document.querySelector(a.selector);
            return el && el.getClientRects().length ? el : null;
        } catch (e) {
            return null;
        }
    }

    function position(anchor) {
        var a = anchor || {};
        var el = target(a);
        if (el) {
            var r = el.getBoundingClientRect();
            return { x: r.left + (a.x == null ? 0.5 : a.x) * r.width, y: r.top + (a.y == null ? 0.5 : a.y) * r.height, moved: false };
        }
        if (a.page_y == null) return null;
        return { x: (a.page_x == null ? 0.5 : a.page_x) * document.documentElement.scrollWidth - window.scrollX, y: a.page_y - window.scrollY, moved: true };
    }

    // The page's element under a point, looking past the pin being dragged and anything of the widget's.
    function under(pin, x, y) {
        pin.style.visibility = 'hidden';
        var el = document.elementFromPoint(x, y);
        pin.style.visibility = '';
        if (!el || el === state.ctx.root.host || el === document.documentElement) return null;
        return el === document.body ? null : el;
    }

    /* ---------- comments ---------- */

    function decided(c) { return !!(c.decision && c.decision.state === 'decided'); }
    // Done, for a reviewer, is one thing: a decision made or a comment resolved.
    function done(c) { return decided(c) || c.status === 'resolved'; }
    function toDecide(c) { return !!c.decision && !decided(c) && c.status === 'open'; }
    function isOpen(c) { return c.status === 'open' && !decided(c); }
    function kind(c) { return done(c) ? 'done' : c.decision ? 'decide' : 'comment'; }
    function onPage(c) { return c.url === path(); }
    function signed() { return !!(state.session && state.session.viewer); }
    function staff() { return signed() && !!state.session.viewer.staff; }
    function byNumber(a, b) { return (state.numbers[a.id] || 0) - (state.numbers[b.id] || 0); }
    function find(id) { return state.all.filter(function (c) { return c.id === id; })[0] || null; }

    function filtered(show) {
        return state.all.filter(function (c) {
            if (show === 'open') return isOpen(c);
            if (show === 'decide') return toDecide(c);
            if (show === 'done') return done(c);
            return true;
        });
    }

    /* ---------- data ---------- */

    function loadSession() {
        return api('GET', 'session').then(function (data) {
            state.session = data;
            if (data.viewer) state.ctx.remember(true);
        });
    }

    // Every comment on the site, in one request: each page numbers its own from the oldest.
    function loadComments() {
        return api('GET', 'comments?scope=all').then(function (data) {
            state.all = data.comments || [];
            index();
        });
    }

    function index() {
        var pages = {};
        state.all.slice().sort(function (a, b) { return a.created_at < b.created_at ? -1 : 1; }).forEach(function (c) {
            pages[c.url] = (pages[c.url] || 0) + 1;
            state.numbers[c.id] = pages[c.url];
        });
        state.ctx.setCount(state.all.filter(isOpen).length);
    }

    // The session, then the comments when someone is signed in. Started once, however it's asked for.
    function load() {
        if (!state.loading) {
            state.loading = loadSession().then(function () {
                return signed() ? loadComments() : null;
            }).catch(function (error) {
                state.loading = null;
                throw error;
            });
        }
        return state.loading;
    }

    function put(comment) {
        var found = false;
        state.all = state.all.map(function (c) {
            if (c.id !== comment.id) return c;
            found = true;
            return comment;
        });
        if (!found) state.all.unshift(comment);
        index();
    }

    function fail(error, key) {
        if (error && error.status === 401) {
            state.session = state.session || {};
            state.session.viewer = null;
        } else {
            state.error = error && error.message ? error.message : 'Something went wrong.';
            state.errorFor = key || 'panel';
        }
        render();
    }

    /* ---------- the panel ---------- */

    function build() {
        state.pins = readPins();
        var root = state.ctx.root;
        var style = document.createElement('style');
        style.textContent = css;
        var wrap = document.createElement('div');
        wrap.className = 'fb';
        wrap.innerHTML =
            '<div class="pins"></div><div class="mark"></div>' +
            '<div class="banner" role="status"><span>Click where your comment belongs</span><button type="button" class="ghost cancel-pick">Cancel</button></div>' +
            '<aside class="panel" role="dialog" aria-label="Comments" aria-modal="false">' +
            '<div class="head"><h2 tabindex="-1">Comments</h2><button type="button" class="icon close" aria-label="Close comments">' +
            '<svg width="14" height="14" viewBox="0 0 16 16" aria-hidden="true"><path d="M3.5 3.5l9 9M12.5 3.5l-9 9" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg></button></div>' +
            '<div class="bar"></div><div class="body"></div><div class="foot"></div></aside>';
        root.appendChild(style);
        root.appendChild(wrap);
        ui.wrap = wrap;
        ui.pins = wrap.querySelector('.pins');
        ui.mark = wrap.querySelector('.mark');
        ui.banner = wrap.querySelector('.banner');
        ui.panel = wrap.querySelector('.panel');
        ui.bar = wrap.querySelector('.bar');
        ui.body = wrap.querySelector('.body');
        ui.foot = wrap.querySelector('.foot');
        ui.heading = wrap.querySelector('.head h2');

        wrap.addEventListener('click', onClick);
        wrap.addEventListener('submit', onSubmit);
        wrap.addEventListener('change', onChange);
        wrap.addEventListener('keydown', onKeydown);
        wrap.addEventListener('mouseover', onHover);
        wrap.addEventListener('mouseout', function (e) {
            if (e.target.closest && e.target.closest('[data-id]') && !(e.relatedTarget && e.relatedTarget.closest && e.relatedTarget.closest('[data-id]'))) hover(null);
        });
        ui.pins.addEventListener('pointerdown', onPinDown);
        ui.pins.addEventListener('pointermove', onPinMove);
        ui.pins.addEventListener('pointerup', function (e) { endDrag(e, false); });
        ui.pins.addEventListener('pointercancel', function (e) { endDrag(e, true); });
        window.addEventListener('scroll', schedule, { passive: true });
        window.addEventListener('resize', schedule);
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape') escape(); });
        // Pins follow their elements as the page changes: images loading, menus opening.
        setInterval(placePins, 1000);
    }

    // Re-renders the panel, keeping anything half typed.
    function render() {
        if (!ui.wrap) return;
        var kept = {};
        ui.wrap.querySelectorAll('[data-keep]').forEach(function (i) { if (i.value) kept[i.dataset.keep] = i.value; });
        var active = state.ctx.root.activeElement;
        var focused = active && active.dataset ? active.dataset.keep : null;

        ui.panel.classList.toggle('on', state.panel);
        var viewer = state.session && state.session.viewer;
        if (!state.session) {
            ui.bar.innerHTML = '';
            ui.body.innerHTML = '<p class="empty">Loading the comments…</p>';
            ui.foot.innerHTML = '';
        } else if (!viewer) {
            ui.bar.innerHTML = '';
            ui.body.innerHTML = signInForm();
            ui.foot.innerHTML = '';
        } else {
            ui.bar.innerHTML = scopeBar();
            ui.body.innerHTML = (state.errorFor === 'panel' ? errorLine() : '') + (state.scope === 'all' ? allList() : pageList());
            ui.foot.innerHTML = '<p class="who">Commenting as <b>' + esc(viewer.name) + '</b>' + (viewer.staff ? ' <span class="badge">team</span>' : '') +
                ' · <button type="button" class="link sign-out">Sign out</button></p>';
        }

        ui.wrap.querySelectorAll('[data-keep]').forEach(function (i) { if (kept[i.dataset.keep] != null) i.value = kept[i.dataset.keep]; });
        if (focused) {
            var again = ui.wrap.querySelector('[data-keep="' + focused + '"]');
            if (again) again.focus();
        }
        placePins();
    }

    function errorLine() {
        return '<p class="error" role="alert">' + esc(state.error) + '</p>';
    }

    function errorFor(key) {
        return state.error && state.errorFor === key ? errorLine() : '';
    }

    function signInForm() {
        var needs = state.session && state.session.needs_password;
        var listed = state.session && state.session.needs_email;
        return '<form class="form signin" data-form="sign-in" novalidate><h3>Who’s commenting?</h3>' +
            '<p>' + (listed ? 'Use the email address you were invited with' : 'Your name shows beside your comments') +
            (needs ? '. The password is the one you were given for this review.' : '.') + '</p>' +
            (listed
                ? '<label>Email address<input name="email" type="email" autocomplete="email" maxlength="254" required></label>'
                : '<label>Your name<input name="name" autocomplete="name" maxlength="80" required></label>') +
            (needs ? '<label>Password<input name="password" type="password" autocomplete="current-password" required></label>' : '') +
            errorFor('sign-in') +
            '<div class="row"><button class="primary">Continue</button></div></form>';
    }

    // This page or all feedback, Add comment, and the pins switch; all feedback adds its filters.
    function scopeBar() {
        var all = state.scope === 'all';
        var open = state.all.filter(isOpen).length;
        return '<div class="scope"><p>' +
            (all ? '<button type="button" class="link" data-scope="page">This page</button>' : '<b>This page</b>') + '<span aria-hidden="true">·</span>' +
            (all ? '<b>All feedback</b>' : '<button type="button" class="link" data-scope="all">All feedback' + (open ? ' (' + open + ' open)' : '') + '</button>') +
            '</p><button type="button" class="ghost small add" aria-pressed="' + state.picking + '">Add comment</button></div>' +
            '<label class="toggle"><input type="checkbox" class="pins-toggle"' + (state.pins ? ' checked' : '') + '> Show comment pins</label>' +
            (all ? '<div class="chips" role="group" aria-label="Show">' + FILTERS.map(function (f) {
                var n = filtered(f[0]).length;
                return '<button type="button" class="chip" data-show="' + f[0] + '" aria-pressed="' + (state.show === f[0]) + '">' + f[1] + (n ? ' <span>' + n + '</span>' : '') + '</button>';
            }).join('') + '</div>' : '');
    }

    // This page's comments: decisions to make, then open comments, with everything done folded away.
    function pageList() {
        var list = state.all.filter(onPage).sort(byNumber);
        var finished = list.filter(done);
        var html = state.draft ? composer() : '';
        if (!list.length && !state.draft) html += '<p class="empty">No comments on this page yet. Press Add comment, then click the spot you mean.</p>';
        html += list.filter(toDecide).concat(list.filter(function (c) { return !c.decision && c.status === 'open'; })).map(card).join('');
        if (finished.length) {
            var opened = finished.some(function (c) { return c.id === state.openId; });
            html += '<details class="fold"' + (opened ? ' open' : '') + '><summary>Done <span>' + finished.length + '</span></summary><div>' + finished.map(card).join('') + '</div></details>';
        }
        return html;
    }

    // All the feedback on the site, page by page, this page first.
    function allList() {
        var list = filtered(state.show);
        if (!list.length) return '<p class="empty">' + EMPTY[state.show] + '</p>';
        var groups = {};
        var order = [];
        list.forEach(function (c) {
            if (!groups[c.url]) { groups[c.url] = []; order.push(c.url); }
            groups[c.url].push(c);
        });
        order.sort(function (a, b) { return (b === path()) - (a === path()); });
        return order.map(function (url) {
            var cs = groups[url].sort(byNumber);
            var title = cs[0].title || url;
            return '<div class="group"><div class="group-h"><h3>' + esc(title) + '</h3>' +
                (url === path() ? '<span>This page</span>' : '<a class="link" href="' + esc(url) + '">Go to page</a>') + '</div>' +
                cs.map(card).join('') + '</div>';
        }).join('');
    }

    function composer() {
        return '<form class="form compose" data-form="compose"><p class="where"><span class="num num--draft">+</span>New comment, at the + on the page</p>' +
            '<textarea name="body" data-keep="new" maxlength="5000" placeholder="What would you change, or what do you think?" aria-label="Your comment" required></textarea>' +
            (staff() ? '<label class="toggle"><input type="checkbox" name="decision"> Make it a decision</label>' : '') +
            errorFor('new') +
            '<div class="row"><button class="primary">Post</button><button type="button" class="ghost cancel-compose">Cancel</button></div></form>';
    }

    // A comment is labelled only Comment or Decision; the colour says where it stands, with a tick once it's done.
    function stateBadge(c) {
        return '<span class="state ' + (done(c) ? 'done' : c.decision ? 'decide' : 'comment') + '">' + (done(c) ? '✓ ' : '') + (c.decision ? 'Decision' : 'Comment') + '</span>';
    }

    function by(person, time) {
        var name = (person && person.name) || 'Someone';
        return '<span class="by"><b>' + esc(name) + '</b>' + (person && person.staff ? '<span class="badge">team</span>' : '') +
            '<time datetime="' + esc(time) + '">' + esc(ago(time)) + '</time></span>';
    }

    function card(c) {
        var open = state.openId === c.id;
        var k = kind(c);
        var n = onPage(c) || state.scope === 'all' ? state.numbers[c.id] : null;
        var d = c.decision;
        var replies = c.replies || [];
        return '<article class="card card--' + k + (open ? ' is-open' : '') + (hotId() === c.id ? ' hot' : '') + '" data-id="' + esc(c.id) + '">' +
            '<button type="button" class="card-head toggle" aria-expanded="' + open + '" aria-label="' + (open ? 'Close' : 'Open') + ' comment ' + (n || '') + ' from ' + esc((c.author || {}).name || 'someone') + '">' +
            '<span class="num num--' + k + '">' + (n || '·') + '</span>' + by(c.author, c.created_at) + stateBadge(c) +
            '<span class="chev" aria-hidden="true"><svg width="12" height="12" viewBox="0 0 12 12"><path d="M3 4.5l3 3 3-3" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg></span></button>' +
            '<div class="card-main"><p class="text' + (open ? '' : ' clamp') + '">' + esc(c.body) + '</p>' +
            (decided(c) ? '<div class="outcome"><b>Decided</b><p>' + esc(d.outcome) + '</p><small>' + esc((d.decided_by || {}).name || '') + (d.decided_at ? ' · ' + esc(ago(d.decided_at)) : '') + '</small></div>' : '') +
            (!open && replies.length ? '<p class="more">' + replies.length + (replies.length === 1 ? ' reply' : ' replies') + '</p>' : '') +
            (open ? thread(c) : '') +
            '</div></article>';
    }

    // What can be done with a comment: the one next step as a button, anything else the team can do in a menu.
    function actions(c) {
        var more = [];
        var next = null;
        if (decided(c)) { if (staff()) next = ['reopen', 'Reopen']; }
        else if (c.status === 'resolved') next = ['reopen', 'Reopen'];
        else if (c.decision) { if (staff()) { next = ['decide', 'Record decision']; more.push(['resolve', 'Mark done']); } }
        else next = ['resolve', 'Mark done'];
        if (staff()) more.push(c.decision ? ['drop', 'Remove decision'] : ['raise', 'Make it a decision']);
        return { next: next, more: more };
    }

    function thread(c) {
        var id = esc(c.id);
        var replies = (c.replies || []).map(function (r) { return '<li>' + by(r.author, r.created_at) + '<p class="text">' + esc(r.body) + '</p></li>'; }).join('');
        var a = actions(c);
        var acts = state.deciding === c.id ? '' :
            (onPage(c) ? '' : '<a class="ghost small" href="' + esc(c.url + '?feedback=' + c.id) + '">Go to page</a>') +
            (a.next ? '<button type="button" class="ghost small" data-act="' + a.next[0] + '">' + a.next[1] + '</button>' : '') +
            (a.more.length ? '<span class="more-wrap"><button type="button" class="icon small menu-toggle" aria-expanded="false" aria-label="More actions" title="More actions">' +
                '<svg width="16" height="16" viewBox="0 0 16 16" aria-hidden="true"><circle cx="3.5" cy="8" r="1.4" fill="currentColor"/><circle cx="8" cy="8" r="1.4" fill="currentColor"/><circle cx="12.5" cy="8" r="1.4" fill="currentColor"/></svg></button>' +
                '<span class="menu" hidden>' + a.more.map(function (m) { return '<button type="button" data-act="' + m[0] + '">' + m[1] + '</button>'; }).join('') + '</span></span>' : '');
        return (c.decision && c.decision.who ? '<p class="who-decides">Who decides: ' + esc(c.decision.who) + '</p>' : '') +
            (replies ? '<ul class="replies">' + replies + '</ul>' : '') +
            (state.deciding === c.id ? '<form class="form" data-form="outcome"><textarea name="outcome" data-keep="outcome-' + id + '" maxlength="5000" aria-label="What was decided" placeholder="What was decided" required></textarea>' +
                '<div class="row"><button class="primary">Record decision</button><button type="button" class="link" data-act="cancel-decide">Cancel</button></div></form>' : '') +
            '<form class="form reply" data-form="reply"><textarea name="body" data-keep="reply-' + id + '" rows="1" maxlength="5000" aria-label="Reply" placeholder="Reply" required></textarea>' +
            '<div class="foot-bar"><button class="ghost small send">Reply</button><span class="acts">' + acts + '</span></div></form>' +
            errorFor(c.id);
    }

    function menus(except) {
        ui.wrap.querySelectorAll('.menu').forEach(function (m) { if (m !== except) m.hidden = true; });
        ui.wrap.querySelectorAll('.menu-toggle').forEach(function (b) { if (b.nextElementSibling !== except) b.setAttribute('aria-expanded', 'false'); });
    }

    /* ---------- pins ---------- */

    // Pointing at a comment, or having it open, lights up its pin, and shows it even with pins switched off.
    function hotId() {
        return state.hoverId || (state.panel ? state.openId : null);
    }

    function schedule() {
        if (frame) return;
        frame = requestAnimationFrame(function () { frame = 0; placePins(); });
    }

    function placePins() {
        if (!ui.pins || drag || !signed()) {
            if (ui.pins && !signed()) ui.pins.innerHTML = '';
            return;
        }
        var hot = hotId();
        var list = state.all.filter(function (c) { return onPage(c) && (c.status === 'open' || c.decision || c.id === state.openId); });
        if (!state.pins) list = list.filter(function (c) { return c.id === hot; });
        var html = '';
        list.forEach(function (c) {
            var p = position(c.anchor);
            if (!p) return;
            html += '<button type="button" class="pin pin--' + kind(c) + (p.moved ? ' moved' : '') + (hot === c.id ? ' hot' : '') +
                '" data-pin="' + esc(c.id) + '" style="left:' + p.x + 'px;top:' + p.y + 'px" aria-label="Comment ' + state.numbers[c.id] + ' from ' +
                esc((c.author || {}).name || 'someone') + (p.moved ? ', its element has changed' : '') + '">' + state.numbers[c.id] + '</button>';
        });
        if (state.draft) {
            var dp = position(state.draft.anchor);
            if (dp) html += '<button type="button" class="pin pin--draft hot" data-pin="draft" style="left:' + dp.x + 'px;top:' + dp.y + 'px" aria-label="Your new comment">+</button>';
        }
        ui.pins.innerHTML = html;
        if (!state.picking) mark(hot ? target((find(hot) || {}).anchor) : null);
    }

    function mark(el, picking) {
        ui.mark.classList.toggle('picking', !!picking);
        if (!el) {
            ui.mark.style.display = 'none';
            return;
        }
        var r = el.getBoundingClientRect();
        Object.assign(ui.mark.style, { display: 'block', left: r.left - 3 + 'px', top: r.top - 3 + 'px', width: r.width + 6 + 'px', height: r.height + 6 + 'px' });
    }

    function hover(id) {
        if (state.hoverId === id) return;
        state.hoverId = id;
        var hot = hotId();
        ui.wrap.querySelectorAll('.card').forEach(function (c) { c.classList.toggle('hot', c.dataset.id === hot); });
        placePins();
    }

    // Dragging a pin: past a few pixels it follows the pointer, outlining the element it would land on, and dropping it
    // pins the comment there. A press that doesn't move is a click, which opens the comment.
    function onPinDown(e) {
        var pin = e.target.closest('[data-pin]');
        if (!pin || state.picking || e.button !== 0) return;
        drag = { pin: pin, id: pin.dataset.pin, x: e.clientX, y: e.clientY, moved: false, el: null };
        try { pin.setPointerCapture(e.pointerId); } catch (err) { /* the pointer has gone */ }
    }

    function onPinMove(e) {
        if (!drag) return;
        if (!drag.moved && Math.abs(e.clientX - drag.x) + Math.abs(e.clientY - drag.y) < 5) return;
        drag.moved = true;
        drag.pin.classList.add('dragging');
        drag.pin.style.left = e.clientX + 'px';
        drag.pin.style.top = e.clientY + 'px';
        drag.el = under(drag.pin, e.clientX, e.clientY) || drag.el;
        mark(drag.el);
    }

    function endDrag(e, cancelled) {
        if (!drag) return;
        var d = drag;
        drag = null;
        if (!d.moved) return;
        dragged = Date.now();
        var el = cancelled ? null : (under(d.pin, e.clientX, e.clientY) || d.el);
        if (!el) { placePins(); return; }
        var anchor = anchorFor(el, e.clientX, e.clientY);
        if (d.id === 'draft') {
            if (state.draft) state.draft.anchor = anchor;
            placePins();
            return;
        }
        var c = find(d.id);
        if (!c) { placePins(); return; }
        var before = c.anchor;
        c.anchor = anchor;
        placePins();
        api('POST', 'comments/' + c.id + '/anchor', { anchor: anchor })
            .then(function (data) { put(data.comment); render(); })
            .catch(function (error) { c.anchor = before; fail(error, c.id); });
    }

    /* ---------- choosing a spot ---------- */

    function startPicking() {
        state.picking = true;
        state.panel = false;
        render();
        ui.banner.style.display = 'flex';
        document.documentElement.style.setProperty('cursor', 'crosshair', 'important');
        document.addEventListener('mousemove', onPickMove, true);
        document.addEventListener('click', onPick, true);
    }

    function stopPicking() {
        state.picking = false;
        ui.banner.style.display = 'none';
        mark(null);
        document.documentElement.style.removeProperty('cursor');
        document.removeEventListener('mousemove', onPickMove, true);
        document.removeEventListener('click', onPick, true);
    }

    function ours(e) {
        return e.composedPath && e.composedPath().indexOf(state.ctx.root.host) > -1;
    }

    function onPickMove(e) {
        mark(ours(e) ? null : e.target, true);
    }

    function onPick(e) {
        if (ours(e)) return;
        e.preventDefault();
        e.stopPropagation();
        var anchor = anchorFor(e.target, e.clientX, e.clientY);
        stopPicking();
        state.draft = { anchor: anchor };
        state.openId = null;
        state.scope = 'page';
        state.error = null;
        openPanel();
        var box = ui.wrap.querySelector('[data-keep="new"]');
        if (box) box.focus();
    }

    /* ---------- events ---------- */

    function onHover(e) {
        var el = e.target.closest && e.target.closest('[data-id], [data-pin]');
        if (el) hover(el.dataset.id || (el.dataset.pin !== 'draft' ? el.dataset.pin : null));
    }

    function onChange(e) {
        if (e.target.classList.contains('pins-toggle')) {
            state.pins = e.target.checked;
            savePins(state.pins);
            placePins();
        }
    }

    function onClick(e) {
        var t = e.target;
        var cardEl = t.closest('[data-id]');
        var id = cardEl && cardEl.dataset.id;

        if (!t.closest('.more-wrap')) menus(null);
        var toggleMenu = t.closest('.menu-toggle');
        if (toggleMenu) {
            var menu = toggleMenu.nextElementSibling;
            menus(menu);
            menu.hidden = !menu.hidden;
            toggleMenu.setAttribute('aria-expanded', String(!menu.hidden));
            return;
        }
        if (t.closest('.close')) return close();
        if (t.closest('.cancel-pick')) { stopPicking(); return openPanel(); }
        if (t.closest('.cancel-compose')) { state.draft = null; state.error = null; return render(); }
        if (t.closest('[data-scope]')) {
            state.scope = t.closest('[data-scope]').dataset.scope;
            render();
            ui.body.scrollTop = 0;
            return;
        }
        if (t.closest('[data-show]')) {
            state.show = t.closest('[data-show]').dataset.show;
            return render();
        }
        if (t.closest('.add')) {
            if (state.picking) { stopPicking(); return openPanel(); }
            return startPicking();
        }
        if (t.closest('.sign-out')) {
            return api('POST', 'sign-out', {}).then(function () {
                state.ctx.remember(false);
                state.loading = null;
                return load();
            }).then(render).catch(fail);
        }
        var pin = t.closest('[data-pin]');
        if (pin) {
            if (Date.now() - dragged < 400 || pin.dataset.pin === 'draft') return;
            state.openId = pin.dataset.pin;
            state.deciding = null;
            state.scope = 'page';
            openPanel();
            var opened = ui.wrap.querySelector('.card.is-open');
            if (opened) opened.scrollIntoView({ block: 'nearest' });
            return;
        }
        var act = t.closest('[data-act]');
        if (act && id) return action(act.dataset.act, find(id));
        if (t.closest('.toggle') && id) return toggle(id);
        // A folded card opens from anywhere on it.
        if (cardEl && !cardEl.classList.contains('is-open') && !t.closest('a, button, textarea, input, label, summary')) return toggle(id);
    }

    function toggle(id) {
        state.openId = state.openId === id ? null : id;
        state.deciding = null;
        render();
        var c = state.openId ? find(state.openId) : null;
        if (c && onPage(c)) show(c, true);
    }

    function action(act, c) {
        if (!c) return;
        var post = function (path, body) {
            return api('POST', 'comments/' + c.id + '/' + path, body || {})
                .then(function (data) { put(data.comment); state.error = null; render(); })
                .catch(function (error) { fail(error, c.id); });
        };
        if (act === 'resolve') return post('resolve');
        // Reopening reopens whatever is done: a decision made, a comment resolved, or both.
        if (act === 'reopen') {
            var steps = [];
            if (decided(c)) steps.push(function () { return api('POST', 'comments/' + c.id + '/decision', { state: 'open' }); });
            if (c.status === 'resolved') steps.push(function () { return api('POST', 'comments/' + c.id + '/reopen', {}); });
            return steps.reduce(function (p, step) { return p.then(step).then(function (data) { put(data.comment); }); }, Promise.resolve())
                .then(function () { state.error = null; render(); })
                .catch(function (error) { fail(error, c.id); });
        }
        if (act === 'raise') return post('decision', { state: 'open' });
        if (act === 'drop') return post('decision', { state: 'none' });
        if (act === 'decide') {
            state.deciding = c.id;
            render();
            var box = ui.wrap.querySelector('[data-keep="outcome-' + c.id + '"]');
            if (box) box.focus();
            return;
        }
        if (act === 'cancel-decide') { state.deciding = null; render(); }
    }

    function onSubmit(e) {
        e.preventDefault();
        var form = e.target;
        var kind = form.dataset.form;
        var data = Object.fromEntries(new FormData(form));
        var buttons = form.querySelectorAll('button');
        var busy = function (on) { buttons.forEach(function (b) { b.disabled = on; }); };
        var cardEl = form.closest('[data-id]');
        var id = cardEl && cardEl.dataset.id;
        var clear = function (key) { var box = ui.wrap.querySelector('[data-keep="' + key + '"]'); if (box) box.value = ''; };
        busy(true);

        if (kind === 'sign-in') {
            api('POST', 'sign-in', data).then(function () {
                state.loading = null;
                state.error = null;
                return load();
            }).then(render).catch(function (error) { fail(error, 'sign-in'); });
            return;
        }

        if (kind === 'reply') {
            if (!String(data.body || '').trim()) return busy(false);
            api('POST', 'comments/' + id + '/replies', { body: data.body })
                .then(function (res) { clear('reply-' + id); put(res.comment); state.error = null; render(); })
                .catch(function (error) { busy(false); fail(error, id); });
            return;
        }

        if (kind === 'outcome') {
            if (!String(data.outcome || '').trim()) return busy(false);
            api('POST', 'comments/' + id + '/decision', { state: 'decided', outcome: data.outcome })
                .then(function (res) { clear('outcome-' + id); state.deciding = null; put(res.comment); state.error = null; render(); })
                .catch(function (error) { busy(false); fail(error, id); });
            return;
        }

        if (kind === 'compose') {
            if (!String(data.body || '').trim() || !state.draft) return busy(false);
            var width = window.innerWidth;
            api('POST', 'comments', {
                url: path(),
                entry: state.ctx.entry || null,
                title: document.title,
                body: data.body,
                anchor: state.draft.anchor,
                viewport: { width: width, height: window.innerHeight, breakpoint: breakpoint(width) },
                decision: data.decision === 'on'
            }).then(function (res) {
                clear('new');
                state.draft = null;
                state.error = null;
                state.openId = res.comment.id;
                put(res.comment);
                render();
            }).catch(function (error) {
                busy(false);
                fail(error, 'new');
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
        if (state.panel) close();
    }

    // Scrolls to a comment's spot; a soft show leaves the page where it is when the spot is already in sight.
    function show(comment, soft) {
        var el = comment && target(comment.anchor);
        if (el && soft) {
            var r = el.getBoundingClientRect();
            if (r.bottom > 0 && r.top < window.innerHeight) return;
        }
        if (el) {
            el.scrollIntoView({ behavior: 'smooth', block: 'center' });
        } else if (comment && comment.anchor && comment.anchor.page_y != null) {
            window.scrollTo({ top: Math.max(0, comment.anchor.page_y - window.innerHeight / 2), behavior: 'smooth' });
        }
    }

    function openPanel() {
        state.panel = true;
        render();
    }

    function close() {
        state.panel = false;
        state.hoverId = null;
        render();
    }

    /* ---------- entry points ---------- */

    function init(ctx) {
        state.ctx = ctx;
        if (!ui.wrap) build();
    }

    // A reviewer's page, once it has settled: the pins and the count, with the panel shut.
    function start(ctx) {
        init(ctx);
        return load().then(render).catch(function () { /* the tab still opens it */ });
    }

    // The tab, or a link for one comment: the panel open, on that comment when there is one.
    function open(ctx) {
        init(ctx);
        state.panel = true;
        render();
        ui.heading.focus({ preventScroll: true });
        load().then(function () {
            var c = ctx.focus ? find(ctx.focus) : null;
            if (c) {
                state.openId = c.id;
                state.scope = onPage(c) ? 'page' : 'all';
                if (!onPage(c)) state.show = 'all';
            }
            render();
            if (c && onPage(c)) show(c);
        }).catch(function (error) { fail(error); });
    }

    window.__avocaFeedback = { open: open, start: start };
})();
