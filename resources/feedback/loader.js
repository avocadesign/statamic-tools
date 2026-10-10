/*
 * Avoca feedback: the loader. The only script feedback adds to a page while it is switched on: a Feedback tab on the
 * right edge, with the number of open comments on this page once the page has settled. The widget itself loads on the
 * first click, or straight away when the address names a comment (?feedback=<id>).
 */
(function () {
    'use strict';

    var script = document.currentScript;
    if (!script || window.__avocaFeedbackLoader) return;
    window.__avocaFeedbackLoader = true;

    var base = script.getAttribute('data-feedback');
    var version = script.getAttribute('data-feedback-version') || '';
    var entry = script.getAttribute('data-feedback-entry') || '';

    var host = document.createElement('div');
    host.id = 'avoca-feedback';
    host.setAttribute('data-avoca-feedback', '');
    var root = host.attachShadow({ mode: 'open' });
    root.innerHTML =
        '<style>' +
        ':host{all:initial}' +
        '.tab{position:fixed;top:50%;right:0;transform:translateY(-50%);z-index:2147483000;display:flex;flex-direction:column;align-items:center;gap:8px;' +
        'padding:14px 8px;border:0;border-radius:8px 0 0 8px;background:#1f2430;color:#fff;cursor:pointer;box-shadow:0 4px 16px rgb(0 0 0/.18);' +
        'font:600 13px/1 system-ui,-apple-system,"Segoe UI",sans-serif;letter-spacing:.02em}' +
        '.tab:hover{background:#2c3342}.tab:focus-visible{outline:2px solid #5b5bd6;outline-offset:2px}' +
        '.label{writing-mode:vertical-rl;transform:rotate(180deg)}' +
        '.count{min-width:20px;height:20px;padding:0 5px;border-radius:10px;background:#5b5bd6;color:#fff;font-size:11px;line-height:20px;text-align:center;box-sizing:border-box}' +
        '.count[hidden]{display:none}' +
        '</style>' +
        '<button class="tab" type="button" aria-label="Feedback on this page"><span class="count" hidden></span><span class="label">Feedback</span></button>';

    var tab = root.querySelector('.tab');
    var count = root.querySelector('.count');
    var loading = null;

    function path() {
        return location.pathname.replace(/\/+$/, '') || '/';
    }

    function setCount(open) {
        count.textContent = open > 99 ? '99+' : String(open);
        count.hidden = !open;
        tab.setAttribute('aria-label', 'Feedback on this page' + (open ? ', ' + open + ' open' : ''));
    }

    function widget() {
        if (!loading) {
            loading = new Promise(function (resolve, reject) {
                var s = document.createElement('script');
                s.src = base + '/widget.js?v=' + version;
                s.onload = function () { resolve(window.__avocaFeedback); };
                s.onerror = reject;
                document.head.appendChild(s);
            });
        }
        return loading;
    }

    function open(focus) {
        return widget().then(function (app) {
            app.open({ base: base, entry: entry, root: root, setCount: setCount, focus: focus || null });
        });
    }

    tab.addEventListener('click', function () { open(); });

    document.body.appendChild(host);

    var named = new URLSearchParams(location.search).get('feedback');
    if (named) {
        open(named);
        return;
    }

    // The count waits until the page has settled, so it never competes with the page's own requests.
    var later = window.requestIdleCallback || function (fn) { return setTimeout(fn, 1500); };
    later(function () {
        fetch(base + '/count?url=' + encodeURIComponent(path()), { credentials: 'same-origin', headers: { Accept: 'application/json' } })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (data) { if (data) setCount(data.open || 0); })
            .catch(function () {});
    });
})();
