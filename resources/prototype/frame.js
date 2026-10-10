(function () {
    var app = document.getElementById('app');
    var FRAME = window.name || 'frame';
    var reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    var tl = null;

    function post(msg) {
        msg.source = 'wf-frame';
        msg.frame = FRAME;
        parent.postMessage(msg, '*');
    }
    function all(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }

    /* Bracketed text is copy the content team supplies: shade it so it reads as a placeholder. */
    function markFills(root) {
        var walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT, {
            acceptNode: function (node) {
                if (!node.nodeValue || node.nodeValue.indexOf('[') === -1) return NodeFilter.FILTER_REJECT;
                var p = node.parentElement;
                if (!p || p.closest('script, style, textarea, option, .wf-fill, .wf-pin, .wf-flag')) return NodeFilter.FILTER_REJECT;
                return NodeFilter.FILTER_ACCEPT;
            }
        });
        var nodes = [];
        while (walker.nextNode()) nodes.push(walker.currentNode);
        nodes.forEach(function (node) {
            var text = node.nodeValue, re = /\[[^\[\]]{1,260}\]/g, last = 0, m, found = false;
            var frag = document.createDocumentFragment();
            while ((m = re.exec(text))) {
                found = true;
                if (m.index > last) frag.appendChild(document.createTextNode(text.slice(last, m.index)));
                var span = document.createElement('span');
                span.className = 'wf-fill';
                span.textContent = m[0];
                frag.appendChild(span);
                last = m.index + m[0].length;
            }
            if (!found) return;
            if (last < text.length) frag.appendChild(document.createTextNode(text.slice(last)));
            node.parentNode.replaceChild(frag, node);
        });
    }

    function highlight(sel) {
        var el = document.querySelector(sel);
        if (!el) return;
        el.scrollIntoView({ block: 'center', behavior: reduced ? 'auto' : 'smooth' });
        el.classList.remove('wf-pulse');
        void el.offsetWidth;
        el.classList.add('wf-pulse');
        setTimeout(function () { el.classList.remove('wf-pulse'); }, 3600);
    }

    /* ---------- Glossary words: the site's tooltip, without Alpine ---------- */

    function glossary(word, open) {
        var trigger = word.querySelector('.glossary-word__trigger');
        var panel = word.querySelector('.glossary-word__panel');
        if (!trigger || !panel) return;
        if (open && !panel.classList.contains('is-open')) word._openedAt = Date.now();
        trigger.setAttribute('aria-expanded', String(open));
        panel.classList.toggle('is-open', open);
        if (!open) return;
        panel.style.setProperty('--glossary-shift', '0px');
        var box = panel.getBoundingClientRect(), shift = 0, margin = 16;
        if (box.left < margin) shift = margin - box.left;
        else if (box.right > window.innerWidth - margin) shift = window.innerWidth - margin - box.right;
        panel.style.setProperty('--glossary-shift', Math.round(shift) + 'px');
    }
    document.addEventListener('mouseover', function (e) {
        var w = e.target.closest && e.target.closest('.glossary-word');
        if (w && !w.contains(e.relatedTarget)) glossary(w, true);
    });
    document.addEventListener('mouseout', function (e) {
        var w = e.target.closest && e.target.closest('.glossary-word');
        if (w && !w.contains(e.relatedTarget)) glossary(w, false);
    });
    document.addEventListener('focusin', function (e) {
        var w = e.target.closest && e.target.closest('.glossary-word');
        if (w) glossary(w, true);
    });
    document.addEventListener('focusout', function (e) {
        var w = e.target.closest && e.target.closest('.glossary-word');
        if (w) glossary(w, false);
    });

    /* ---------- The timeline ---------- */
    /* One ordered list of phase cards and events, shown three ways:
       pinned   - vertical scroll drives the list sideways while it is held on screen (the desktop proposal)
       swipe    - a native sideways scroller with snap points (touch)
       vertical - a plain stacked list (fallback, and a mobile option) */

    var HINTS = { pinned: 'Scroll to move', swipe: 'Swipe or use the arrows', vertical: 'Scroll down' };

    function Timeline(root) {
        var self = this;
        this.root = root;
        this.bar = root.querySelector('[data-tl-bar]');
        this.pin = root.querySelector('[data-tl-pin]');
        this.vp = root.querySelector('[data-tl-viewport]');
        this.track = root.querySelector('[data-tl-track]');
        this.items = all(':scope > li', this.track);
        this.phases = this.items.filter(function (el) { return el.classList.contains('tl-phase'); });
        this.btns = all('[data-tl-go]', root);
        this.total = this.items.filter(function (el) { return el.dataset.type; }).length;
        this.filters = [];
        this.dist = 0;
        this.active = -1;

        this.onScroll = function () { self.update(); };
        this.onResize = function () { self.measure(); };
        this.onMode = function (e) { self.setMode(e.detail); };
        this.onGo = function (e) { self.go(e.detail - 1); };
        this.onClick = function (e) {
            var t = e.target.closest('button');
            if (!t || !root.contains(t)) return;
            if (t.dataset.tlGo) self.go(Number(t.dataset.tlGo));
            else if (t.dataset.tlStep) self.step(Number(t.dataset.tlStep));
            else if (t.hasAttribute('data-tl-filters-toggle')) {
                var panel = root.querySelector('[data-tl-filters]');
                panel.hidden = !panel.hidden;
                t.setAttribute('aria-expanded', String(!panel.hidden));
                self.measure();
            } else if (t.dataset.tlFilter) {
                var i = self.filters.indexOf(t.dataset.tlFilter);
                if (i === -1) self.filters.push(t.dataset.tlFilter); else self.filters.splice(i, 1);
                self.applyFilters();
            } else if (t.hasAttribute('data-tl-clear')) {
                self.filters = [];
                self.applyFilters();
            }
        };
        this.onKey = function (e) {
            if (e.key === 'ArrowRight') { e.preventDefault(); self.step(1); }
            if (e.key === 'ArrowLeft') { e.preventDefault(); self.step(-1); }
        };

        window.addEventListener('scroll', this.onScroll, { passive: true });
        window.addEventListener('resize', this.onResize);
        window.addEventListener('tl-mode', this.onMode);
        window.addEventListener('tl-go', this.onGo);
        this.vp.addEventListener('scroll', this.onScroll, { passive: true });
        this.vp.addEventListener('keydown', this.onKey);
        root.addEventListener('click', this.onClick);
        /* The Tailwind runtime styles new markup a moment after it lands, so measure whenever sizes settle. */
        this.ro = new ResizeObserver(function () { self.measure(); });
        this.ro.observe(this.track);
        this.ro.observe(this.bar);

        this.applyFilters(true);
        this.setMode(document.documentElement.dataset.tlMode || 'swipe', true);

        var start = window.__tlStart;
        window.__tlStart = null;
        if (start) setTimeout(function () { self.go(start - 1, true); }, 250);
    }

    Timeline.prototype = {
        destroy: function () {
            window.removeEventListener('scroll', this.onScroll);
            window.removeEventListener('resize', this.onResize);
            window.removeEventListener('tl-mode', this.onMode);
            window.removeEventListener('tl-go', this.onGo);
            this.ro.disconnect();
        },

        setMode: function (mode, first) {
            var self = this, keep = Math.max(0, this.active);
            this.mode = mode;
            this.root.dataset.mode = mode;
            var hint = this.root.querySelector('[data-tl-hint]');
            if (hint) hint.textContent = HINTS[mode];
            requestAnimationFrame(function () {
                self.measure();
                if (!first) self.go(keep, true);
            });
        },

        applyFilters: function (quiet) {
            var f = this.filters, shown = 0;
            this.items.forEach(function (el) {
                if (!el.dataset.type) return;
                el.hidden = f.length > 0 && f.indexOf(el.dataset.type) === -1;
                if (!el.hidden) shown++;
            });
            /* A note may tuck in beside a card (and a card beside a note), but never beside its own kind. */
            var prev = null;
            this.items.forEach(function (el) {
                el.removeAttribute('data-overlap');
                if (el.hidden) return;
                if (el.classList.contains('tl-phase')) { prev = null; return; }
                var side = el.classList.contains('is-above') ? 'above' : 'below';
                if (prev && prev !== side) el.setAttribute('data-overlap', '');
                prev = side;
            });
            all('[data-tl-filter]', this.root).forEach(function (b) { b.setAttribute('aria-pressed', String(f.indexOf(b.dataset.tlFilter) !== -1)); });
            this.root.querySelector('[data-tl-clear]').hidden = !f.length;
            this.root.querySelector('[data-tl-fcount]').textContent = f.length ? '(' + f.length + ')' : '';
            this.root.querySelector('[data-tl-count]').textContent = shown === this.total ? this.total + ' moments' : shown + ' of ' + this.total + ' moments';
            if (!quiet) this.measure();
        },

        barH: function () { return this.bar.offsetHeight; },
        pad: function () { return parseFloat(getComputedStyle(this.track).paddingLeft) || 0; },

        measure: function () {
            var bar = this.barH();
            this.root.style.setProperty('--tl-bar', bar + 'px');
            if (this.mode === 'pinned') {
                var vh = window.innerHeight - bar;
                this.vp.style.top = bar + 'px';
                this.vp.style.height = vh + 'px';
                this.dist = Math.max(0, this.track.scrollWidth - this.vp.clientWidth);
                this.pin.style.height = (this.dist + vh) + 'px';
            } else {
                this.pin.style.height = '';
                this.vp.style.top = '';
                this.vp.style.height = '';
                this.track.style.transform = '';
                this.dist = 0;
            }
            this.update();
        },

        offset: function () {
            if (this.mode === 'pinned') {
                var top = this.pin.getBoundingClientRect().top;
                return Math.min(this.dist, Math.max(0, this.barH() - top));
            }
            if (this.mode === 'swipe') return this.vp.scrollLeft;
            return 0;
        },

        update: function () {
            var progress = 0, act = 0, i;
            if (this.mode === 'pinned') {
                var x = this.offset();
                this.track.style.transform = 'translate3d(' + (-x) + 'px,0,0)';
                progress = this.dist ? x / this.dist : 0;
            } else if (this.mode === 'swipe') {
                var max = this.vp.scrollWidth - this.vp.clientWidth;
                progress = max > 0 ? this.vp.scrollLeft / max : 0;
            } else {
                var r = this.track.getBoundingClientRect();
                var span = r.height - window.innerHeight + this.barH();
                progress = span > 0 ? Math.min(1, Math.max(0, (this.barH() - r.top) / span)) : 0;
            }
            if (this.mode === 'vertical') {
                var line = this.barH() + 80;
                for (i = 0; i < this.phases.length; i++) if (this.phases[i].getBoundingClientRect().top <= line) act = i;
            } else {
                var probe = this.offset() + this.vp.clientWidth * 0.35, pad = this.pad();
                for (i = 0; i < this.phases.length; i++) if (this.phases[i].offsetLeft - pad <= probe) act = i;
            }
            this.root.querySelector('[data-tl-progress]').style.width = Math.round(progress * 100) + '%';
            if (act !== this.active) {
                this.active = act;
                this.btns.forEach(function (b, n) { b.setAttribute('aria-current', String(n === act)); });
                var cur = this.btns[act];
                if (cur) cur.parentNode.scrollTo({ left: cur.offsetLeft - 16, behavior: 'auto' });
            }
        },

        scrollToItem: function (el, instant) {
            var behavior = instant || reduced ? 'auto' : 'smooth';
            var bar = this.barH();
            var rootTop = this.root.getBoundingClientRect().top + window.scrollY;
            if (this.mode === 'pinned') {
                var pinTop = this.pin.getBoundingClientRect().top + window.scrollY;
                var x = Math.min(this.dist, Math.max(0, el.offsetLeft - this.pad()));
                window.scrollTo({ top: pinTop - bar + x + 1, behavior: behavior });
            } else if (this.mode === 'swipe') {
                if (this.root.getBoundingClientRect().top > 0) window.scrollTo({ top: rootTop, behavior: behavior });
                this.vp.scrollTo({ left: el.offsetLeft - this.pad(), behavior: behavior });
            } else {
                window.scrollTo({ top: el.getBoundingClientRect().top + window.scrollY - bar - 8, behavior: behavior });
            }
        },

        go: function (i, instant) {
            var el = this.phases[Math.max(0, Math.min(this.phases.length - 1, i))];
            if (el) this.scrollToItem(el, instant);
        },

        /* Scroll sync: is the reader inside the timeline, and which item is at its leading edge? */
        engaged: function (fromViewport) {
            if (fromViewport) return true;
            if (this.mode === 'pinned') {
                var r = this.pin.getBoundingClientRect();
                return r.top <= this.barH() + 1 && r.bottom >= window.innerHeight - 1;
            }
            if (this.mode === 'vertical') {
                var t = this.track.getBoundingClientRect();
                return t.top <= this.barH() && t.bottom > window.innerHeight / 2;
            }
            return false;
        },

        currentIndex: function () {
            var i, el;
            if (this.mode === 'vertical') {
                var line = this.barH() + 12;
                for (i = 0; i < this.items.length; i++) { el = this.items[i]; if (!el.hidden && el.getBoundingClientRect().bottom > line) return i; }
                return 0;
            }
            var x = this.offset(), pad = this.pad();
            for (i = 0; i < this.items.length; i++) { el = this.items[i]; if (!el.hidden && el.offsetLeft - pad + el.offsetWidth / 2 >= x) return i; }
            return 0;
        },

        syncTo: function (i) {
            var el = null, n;
            for (n = i; n < this.items.length && !el; n++) if (!this.items[n].hidden) el = this.items[n];
            if (!el) return;
            if (this.mode === 'swipe') {
                var r = this.root.getBoundingClientRect();
                if (r.top > window.innerHeight * 0.4 || r.bottom < window.innerHeight * 0.4) window.scrollTo(0, r.top + window.scrollY);
                this.vp.scrollLeft = el.offsetLeft - this.pad();
            } else {
                this.scrollToItem(el, true);
            }
        },

        step: function (dir) {
            var vis = this.items.filter(function (el) { return el.offsetParent !== null; }), target;
            if (this.mode === 'vertical') {
                var line = this.barH() + 12;
                target = dir > 0
                    ? vis.find(function (el) { return el.getBoundingClientRect().top > line + 4; })
                    : vis.slice().reverse().find(function (el) { return el.getBoundingClientRect().top < line - 4; });
            } else {
                var x = this.offset(), pad = this.pad();
                target = dir > 0
                    ? vis.find(function (el) { return el.offsetLeft - pad > x + 4; })
                    : vis.slice().reverse().find(function (el) { return el.offsetLeft - pad < x - 4; });
            }
            if (target) this.scrollToItem(target);
        }
    };

    /* ---------- In-page filters (Voices, Resources) ---------- */

    function initFilter(sec) {
        var f = {}, q = '';
        var items = all('[data-item]', sec);
        var count = sec.querySelector('[data-fcount]');
        function apply() {
            var n = 0;
            items.forEach(function (el) {
                var ok = true, k;
                for (k in f) if (f[k] && (' ' + (el.dataset[k] || '') + ' ').indexOf(' ' + f[k] + ' ') === -1) ok = false;
                if (q && el.textContent.toLowerCase().indexOf(q) === -1) ok = false;
                el.hidden = !ok;
                if (ok) n++;
            });
            if (count) count.textContent = n + ' ' + count.dataset.fcount;
            all('button[data-f]', sec).forEach(function (b) { b.setAttribute('aria-pressed', String((f[b.dataset.f] || '') === b.dataset.v)); });
        }
        sec.addEventListener('change', function (e) { var s = e.target.closest('select[data-f]'); if (s) { f[s.dataset.f] = s.value; apply(); } });
        sec.addEventListener('input', function (e) { if (e.target.matches('[data-q]')) { q = e.target.value.trim().toLowerCase(); apply(); } });
        sec.addEventListener('click', function (e) { var b = e.target.closest('button[data-f]'); if (b) { f[b.dataset.f] = b.dataset.v; apply(); } });
        apply();
    }

    function initPage() {
        if (tl) { tl.destroy(); tl = null; }
        var root = app.querySelector('[data-tl]');
        if (root) tl = new Timeline(root);
        all('[data-filter]', app).forEach(initFilter);
    }

    /* ---------- Scroll sync between the desktop and mobile frames ---------- */
    /* Both frames render the same markup, so a block is found by its position in the page. The timeline is
       matched by item instead, because pinned, swipe and stacked modes scroll in different directions. */

    var syncOn = true, quietUntil = 0, pending = false, pendingFromTl = false;
    function quiet(ms) { quietUntil = Date.now() + ms; }
    function anchors() { return all('header, main > *, main > * > *, footer', app); }

    function syncOut(fromTl) {
        if (!syncOn || Date.now() < quietUntil) return;
        pendingFromTl = pendingFromTl || !!fromTl;
        if (pending) return;
        pending = true;
        requestAnimationFrame(function () {
            pending = false;
            var viaTl = pendingFromTl;
            pendingFromTl = false;
            if (Date.now() < quietUntil) return;
            if (tl && tl.engaged(viaTl)) { post({ type: 'scroll', tl: tl.currentIndex() }); return; }
            var list = anchors(), best = -1, frac = 0;
            for (var i = 0; i < list.length; i++) {
                var r = list[i].getBoundingClientRect();
                if (r.top <= 1 && r.bottom > 1 && r.height > 0) { best = i; frac = -r.top / r.height; }
            }
            post({ type: 'scroll', idx: best, frac: frac, top: window.scrollY <= 0 });
        });
    }
    window.addEventListener('scroll', function () { syncOut(false); }, { passive: true });
    document.addEventListener('scroll', function (e) { if (e.target.matches && e.target.matches('[data-tl-viewport]')) syncOut(true); }, { capture: true, passive: true });

    function syncIn(m) {
        quiet(250);
        if (m.tl != null && tl) { tl.syncTo(m.tl); return; }
        if (m.top || m.idx < 0) { window.scrollTo(0, 0); return; }
        var el = anchors()[m.idx];
        if (!el) return;
        var r = el.getBoundingClientRect();
        window.scrollTo(0, r.top + window.scrollY + m.frac * r.height);
    }

    /* ---------- Messages from the wireframe shell ---------- */

    window.addEventListener('message', function (e) {
        if (e.source !== parent) return;
        var m = e.data || {};
        if (m.type === 'render') {
            window.__tlStart = m.phase || null;
            if ('sync' in m) syncOn = !!m.sync;
            document.documentElement.dataset.tlMode = m.mode || 'swipe';
            // Each page option's choice, for the site's CSS or the page's script: data-option-chapters="years".
            Array.prototype.slice.call(document.documentElement.attributes).forEach(function (a) {
                if (a.name.indexOf('data-option-') === 0) document.documentElement.removeAttribute(a.name);
            });
            Object.keys(m.options || {}).forEach(function (id) {
                if (/^[a-z0-9-]+$/i.test(id)) document.documentElement.setAttribute('data-option-' + id, String(m.options[id]));
            });
            document.body.classList.toggle('wf-annotate', !!m.annotate);
            app.innerHTML = m.html;
            markFills(app);
            quiet(900);
            window.scrollTo(0, 0);
            document.title = m.title || 'Wireframe';
            initPage();
            if (m.highlight) setTimeout(function () { quiet(1200); highlight(m.highlight); }, 500);
        } else if (m.type === 'annotate') {
            document.body.classList.toggle('wf-annotate', !!m.on);
        } else if (m.type === 'mode') {
            quiet(600);
            document.documentElement.dataset.tlMode = m.mode;
            window.dispatchEvent(new CustomEvent('tl-mode', { detail: m.mode }));
        } else if (m.type === 'highlight') {
            quiet(1200);
            highlight(m.sel);
        } else if (m.type === 'syncScroll') {
            syncIn(m);
        } else if (m.type === 'sync') {
            syncOn = !!m.on;
        } else if (m.type === 'tlgo') {
            window.dispatchEvent(new CustomEvent('tl-go', { detail: m.phase }));
        }
    });

    /* ---------- Clicks: links, menus, markers ---------- */

    document.addEventListener('click', function (e) {
        var pin = e.target.closest('.wf-pin');
        if (pin) {
            e.preventDefault();
            e.stopPropagation();
            post({ type: 'pin', id: pin.dataset.pin });
            return;
        }

        var gw = e.target.closest('.glossary-word__trigger');
        if (gw) {
            var word = gw.closest('.glossary-word');
            var justOpened = Date.now() - (word._openedAt || 0) < 400;
            glossary(word, justOpened || gw.getAttribute('aria-expanded') !== 'true');
            return;
        }

        var ddToggle = e.target.closest('[data-dd-toggle]');
        all('[data-dd-menu]').forEach(function (menu) {
            if (!ddToggle || !menu.parentNode.contains(ddToggle)) {
                menu.hidden = true;
                var t = menu.parentNode.querySelector('[data-dd-toggle]');
                if (t) t.setAttribute('aria-expanded', 'false');
            }
        });
        if (ddToggle) {
            e.preventDefault();
            var menu = ddToggle.parentNode.querySelector('[data-dd-menu]');
            menu.hidden = !menu.hidden;
            ddToggle.setAttribute('aria-expanded', String(!menu.hidden));
            return;
        }

        var mnav = e.target.closest('[data-mnav-toggle]');
        if (mnav) {
            var root = mnav.closest('[data-mnav]') || mnav.parentNode;
            var panel = root.querySelector('[data-mnav-panel]');
            panel.hidden = !panel.hidden;
            root.querySelectorAll('[data-mnav-toggle]').forEach(function (b) { b.setAttribute('aria-expanded', String(!panel.hidden)); });
            // A text button says what it does next; an icon button swaps its icon in CSS.
            if (!mnav.children.length) mnav.textContent = panel.hidden ? 'Menu' : 'Close';
            return;
        }

        var a = e.target.closest('a[href]');
        if (!a) return;
        var href = a.getAttribute('href');
        if (href.indexOf('#/') === 0) {
            e.preventDefault();
            post({ type: 'nav', route: href.slice(1) });
        } else if (href.indexOf('/downloads/') === 0) {
            e.preventDefault();
            post({ type: 'download', href: href });
        } else if (href.charAt(0) === '#') {
            e.preventDefault();
            var t = href.length > 1 && document.getElementById(href.slice(1));
            if (t) { if (t.tagName === 'DETAILS') t.open = true; t.scrollIntoView({ behavior: reduced ? 'auto' : 'smooth', block: 'start' }); }
            else if (href.length > 1) post({ type: 'inert', label: (a.textContent || '').trim() });
        } else {
            e.preventDefault();
            post({ type: 'external', href: href });
        }
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            all('.glossary-word').forEach(function (w) { glossary(w, false); });
            all('[data-dd-menu]').forEach(function (m) { m.hidden = true; });
        }
        var pin = e.target.closest && e.target.closest('.wf-pin');
        if (pin && (e.key === 'Enter' || e.key === ' ')) {
            e.preventDefault();
            post({ type: 'pin', id: pin.dataset.pin });
        }
    });

    document.addEventListener('submit', function (e) {
        e.preventDefault();
        var note = e.target.querySelector('[data-sent]');
        if (note) note.hidden = false;
    });


    /* ---------- Comments: pins on the page, and choosing a spot for a new one ----------
       The shell sends the comments for this page; each pin sits on its element, at the spot within it, so it moves
       with the element. A comment whose element has gone sits where it was on the page, dashed. A pin can be dragged
       to another spot when it covers something. */

    var cpins = { items: [], show: true, hot: null, zoom: 1 };
    var pinLayer = null, outline = null, picking = false, drag = null, dragged = 0;

    function layer() {
        if (!pinLayer || !pinLayer.isConnected) {
            pinLayer = document.createElement('div');
            pinLayer.className = 'wf-cpins';
            pinLayer.setAttribute('aria-label', 'Comments');
            document.body.appendChild(pinLayer);
        }
        // The frame is drawn scaled down; the pins scale back up, so they're the same size on screen in both frames.
        pinLayer.style.setProperty('--wf-zoom', String(cpins.zoom || 1));
        return pinLayer;
    }

    function find(sel) {
        if (!sel) return null;
        try { var el = app.querySelector(sel); return el && el.getClientRects().length ? el : null; } catch (e) { return null; }
    }

    function placePins() {
        // A pin being dragged stays where the pointer has it.
        if (drag) return;
        var root = layer();
        root.innerHTML = '';
        // With pins switched off, the one being pointed at in the panel still shows, and a new comment's.
        var items = cpins.show ? cpins.items : cpins.items.filter(function (c) { return c.id === cpins.hot || c.id === 'draft'; });
        root.hidden = !items.length;
        var docW = document.documentElement.scrollWidth;
        items.forEach(function (c) {
            var el = find(c.selector), x, y;
            if (el) {
                var r = el.getBoundingClientRect();
                x = r.left + window.scrollX + (c.x == null ? 0.5 : c.x) * r.width;
                y = r.top + window.scrollY + (c.y == null ? 0.5 : c.y) * r.height;
            } else if (c.page_y != null) {
                x = (c.page_x == null ? 0.5 : c.page_x) * docW;
                y = c.page_y;
            } else {
                return;
            }
            var b = document.createElement('button');
            b.type = 'button';
            b.className = 'wf-cpin wf-cpin--' + c.kind + (el ? '' : ' wf-cpin--moved') + (cpins.hot === c.id ? ' is-hot' : '');
            b.dataset.cpin = c.id;
            b.style.left = x + 'px';
            b.style.top = y + 'px';
            b.textContent = c.n;
            b.setAttribute('aria-label', c.label || ('Comment ' + c.n));
            root.appendChild(b);
        });
    }

    function markEl(el) {
        if (!outline) {
            outline = document.createElement('div');
            outline.className = 'wf-pick-outline';
            document.body.appendChild(outline);
        }
        if (!el) { outline.hidden = true; return; }
        var r = el.getBoundingClientRect();
        outline.hidden = false;
        outline.style.left = (r.left + window.scrollX - 3) + 'px';
        outline.style.top = (r.top + window.scrollY - 3) + 'px';
        outline.style.width = (r.width + 6) + 'px';
        outline.style.height = (r.height + 6) + 'px';
    }

    function trimText(s, n) {
        s = String(s || '').replace(/\s+/g, ' ').trim();
        return s.length > n ? s.slice(0, n - 1).trim() + '…' : s;
    }

    // A path to the element from the page's own root, which the next render of the same page rebuilds the same.
    function selectorFor(el) {
        var parts = [];
        for (var node = el; node && node !== app && node.nodeType === 1; node = node.parentElement) {
            var part = node.tagName.toLowerCase();
            var parent = node.parentElement;
            if (parent) {
                var same = Array.prototype.filter.call(parent.children, function (c) { return c.tagName === node.tagName; });
                if (same.length > 1) part += ':nth-of-type(' + (same.indexOf(node) + 1) + ')';
            }
            parts.unshift(part);
        }
        return ':scope > ' + parts.join(' > ');
    }

    function labelFor(el) {
        var parts = [];
        var j = el.closest('[data-j]');
        if (j && j.dataset.j === 'header') parts.push('Site header');
        if (j && j.dataset.j === 'footer') parts.push('Site footer');
        var heading = '';
        if (/^H[1-6]$/.test(el.tagName)) heading = trimText(el.textContent, 60);
        else {
            var box = el.closest('section, header, footer, article, main') || app;
            var hs = box.querySelectorAll('h1, h2, h3, h4, h5, h6'), found = null;
            for (var i = 0; i < hs.length; i++) if (hs[i].contains(el) || (hs[i].compareDocumentPosition(el) & Node.DOCUMENT_POSITION_FOLLOWING)) found = hs[i];
            found = found || hs[0];
            if (found) heading = trimText(found.textContent, 60);
        }
        var own = trimText(el.textContent, 41);
        if (own && own.length <= 40 && own !== heading) parts.push('“' + own + '”');
        if (heading) parts.push('near “' + heading + '”');
        return parts.join(', ') || 'On the page';
    }

    // Where a click or a drop landed: the element under it, the spot within it and on the page, and its name in words.
    function anchorAt(el, clientX, clientY) {
        var r = el.getBoundingClientRect();
        var docW = Math.max(document.documentElement.scrollWidth, 1);
        return {
            selector: selectorFor(el),
            x: Math.max(0, Math.min(1, (clientX - r.left) / (r.width || 1))),
            y: Math.max(0, Math.min(1, (clientY - r.top) / (r.height || 1))),
            page_x: Math.max(0, Math.min(1, (clientX + window.scrollX) / docW)),
            page_y: Math.round(clientY + window.scrollY),
            label: labelFor(el),
            text: trimText(el.textContent, 120)
        };
    }

    // The page's element under a point, looking past the pin being dragged.
    function under(pin, x, y) {
        pin.style.visibility = 'hidden';
        var el = document.elementFromPoint(x, y);
        pin.style.visibility = '';
        if (!el || (el.closest && el.closest('.wf-cpins'))) return null;
        return el === document.documentElement || el === document.body ? app : el;
    }

    function pickOn(on) {
        picking = on;
        document.documentElement.classList.toggle('wf-picking', on);
        if (!on) markEl(null);
    }

    document.addEventListener('mousemove', function (e) {
        if (!picking) return;
        if (e.target.closest && e.target.closest('.wf-cpins')) { markEl(null); return; }
        markEl(e.target === document.documentElement || e.target === document.body ? null : e.target);
    }, true);

    document.addEventListener('click', function (e) {
        var pinBtn = e.target.closest && e.target.closest('[data-cpin]');
        // The click that ends a drag opens nothing.
        if (pinBtn && Date.now() - dragged < 400) {
            e.preventDefault();
            e.stopImmediatePropagation();
            return;
        }
        if (picking) {
            e.preventDefault();
            e.stopImmediatePropagation();
            if (pinBtn) return;
            var el = e.target === document.documentElement || e.target === document.body ? app : e.target;
            post({ type: 'picked', anchor: anchorAt(el, e.clientX, e.clientY) });
            pickOn(false);
            return;
        }
        if (pinBtn) {
            e.preventDefault();
            e.stopImmediatePropagation();
            post({ type: 'cpin', id: pinBtn.dataset.cpin });
        }
    }, true);

    document.addEventListener('keydown', function (e) {
        if (picking && e.key === 'Escape') { pickOn(false); post({ type: 'pick-cancel' }); }
    });

    // Dragging a pin: past a few pixels it follows the pointer, outlining the element it would land on, and dropping it
    // tells the shell the new spot. A press that doesn't move is a click, which opens the comment.
    document.addEventListener('pointerdown', function (e) {
        var pin = e.target.closest && e.target.closest('[data-cpin]');
        if (!pin || picking || e.button !== 0) return;
        drag = { pin: pin, id: pin.dataset.cpin, x: e.clientX, y: e.clientY, moved: false, el: null };
        try { pin.setPointerCapture(e.pointerId); } catch (err) { /* the pointer has gone */ }
    }, true);

    document.addEventListener('pointermove', function (e) {
        if (!drag) return;
        if (!drag.moved && Math.abs(e.clientX - drag.x) + Math.abs(e.clientY - drag.y) < 5) return;
        drag.moved = true;
        drag.pin.classList.add('is-dragging');
        drag.pin.style.left = (e.clientX + window.scrollX) + 'px';
        drag.pin.style.top = (e.clientY + window.scrollY) + 'px';
        drag.el = under(drag.pin, e.clientX, e.clientY) || drag.el;
        markEl(drag.el);
    }, true);

    function endDrag(e, cancelled) {
        if (!drag) return;
        var d = drag;
        drag = null;
        if (!d.moved) return;
        dragged = Date.now();
        markEl(null);
        var el = cancelled ? null : (under(d.pin, e.clientX, e.clientY) || d.el);
        if (el) post({ type: 'moved', id: d.id, anchor: anchorAt(el, e.clientX, e.clientY) });
        else placePins();
    }
    document.addEventListener('pointerup', function (e) { endDrag(e, false); }, true);
    document.addEventListener('pointercancel', function (e) { endDrag(e, true); }, true);

    window.addEventListener('resize', placePins);
    setInterval(function () { if (cpins.items.length && cpins.show) placePins(); }, 1200);

    window.addEventListener('message', function (e) {
        if (e.source !== parent) return;
        var m = e.data || {};
        if (m.type === 'cpins') {
            cpins.items = m.items || [];
            cpins.show = m.show !== false;
            cpins.hot = m.hot || null;
            if (m.zoom) cpins.zoom = m.zoom;
            placePins();
        } else if (m.type === 'zoom') {
            cpins.zoom = m.zoom || 1;
            layer();
        } else if (m.type === 'cpin-hot') {
            cpins.hot = m.id || null;
            placePins();
            var hotItem = cpins.items.filter(function (c) { return c.id === m.id; })[0];
            markEl(hotItem ? find(hotItem.selector) : null);
        } else if (m.type === 'cpin-show') {
            var item = cpins.items.filter(function (c) { return c.id === m.id; })[0];
            var target = item && find(item.selector);
            // A soft show leaves the page where it is when the pin is already in sight.
            var seen = target && (function (r) { return r.bottom > 0 && r.top < window.innerHeight; })(target.getBoundingClientRect());
            if (m.soft && seen) { /* already in sight */ }
            else if (target) target.scrollIntoView({ behavior: reduced ? 'auto' : 'smooth', block: 'center' });
            else if (item && item.page_y != null) window.scrollTo({ top: Math.max(0, item.page_y - window.innerHeight / 2), behavior: reduced ? 'auto' : 'smooth' });
            cpins.hot = m.id;
            placePins();
        } else if (m.type === 'pick') {
            pickOn(!!m.on);
        } else if (m.type === 'render') {
            // The page has been replaced: clear the old outline, and put the pins back once it has laid out.
            if (!picking) markEl(null);
            setTimeout(placePins, 60);
        }
    });

    post({ type: 'ready' });
})();
