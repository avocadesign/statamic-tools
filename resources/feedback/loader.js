/*
 * Avoca feedback: the loader. The only script feedback adds to a page while it is on. When the site lists its
 * reviewers, only their emails can sign in, so the Comments tab shows on every page. Without a list it shows nothing to
 * a visitor who isn't reviewing: the tab appears for a browser that has signed in to review before, or when the
 * address asks for it, with ?review on the link reviewers are sent, or ?feedback=<id> for one comment. For a browser that has
 * reviewed before, the widget starts once the page has settled, so the pins and the number of open comments show
 * without a click; otherwise it loads on the first click.
 */
(function () {
    'use strict';

    var script = document.currentScript;
    if (!script || window.__avocaFeedbackLoader) return;
    window.__avocaFeedbackLoader = true;

    var params = new URLSearchParams(location.search);
    var named = params.get('feedback');
    var invited = params.has('review');
    var known = false;
    try { known = localStorage.getItem('avoca-feedback') === '1'; } catch (e) { /* storage unavailable */ }
    var open = script.getAttribute('data-feedback-open') === '1';
    if (!known && !named && !invited && !open) return;

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
        '.tab:hover{background:#2c3342}.tab:focus-visible{outline:2px solid #1f2430;outline-offset:2px}' +
        '.label{writing-mode:vertical-rl;transform:rotate(180deg)}' +
        '.count{min-width:20px;height:20px;padding:0 5px;border-radius:10px;background:#fff;color:#1f2430;font-size:11px;line-height:20px;text-align:center;box-sizing:border-box}' +
        '.count[hidden]{display:none}' +
        '</style>' +
        '<button class="tab" type="button" aria-label="Comments"><span class="count" hidden></span><span class="label">Comments</span></button>';

    var tab = root.querySelector('.tab');
    var count = root.querySelector('.count');
    var loading = null;

    function setCount(open) {
        count.textContent = open > 99 ? '99+' : String(open);
        count.hidden = !open;
        tab.setAttribute('aria-label', 'Comments' + (open ? ', ' + open + ' open' : ''));
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

    // Remembers that this browser reviews, so the tab is there next time without ?review.
    function remember(on) {
        try {
            if (on) localStorage.setItem('avoca-feedback', '1');
            else localStorage.removeItem('avoca-feedback');
        } catch (e) { /* storage unavailable */ }
    }

    function ctx(focus) {
        return { base: base, entry: entry, root: root, setCount: setCount, remember: remember, focus: focus || null };
    }

    function open(focus) {
        return widget().then(function (app) { app.open(ctx(focus)); });
    }

    tab.addEventListener('click', function () { open(); });

    document.body.appendChild(host);

    if (named || invited) {
        open(named);
        return;
    }

    // The widget waits until the page has settled, so it never competes with the page's own requests.
    var later = window.requestIdleCallback || function (fn) { return setTimeout(fn, 1500); };
    later(function () {
        widget().then(function (app) { app.start(ctx()); }).catch(function () {});
    });
})();
