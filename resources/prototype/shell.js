/* ---------- Shell ---------- */

const $ = (s, r = document) => r.querySelector(s);
const $$ = (s, r = document) => Array.from(r.querySelectorAll(s));

const STORE = `${PROJECT.key}-prototype-v1`;
const state = Object.assign({
    view: 'wireframes', route: '/', frames: 'both', pins: true, rail: 'notes',
    journey: JOURNEYS[0]?.id, step: -1, modes: { desktop: 'pinned', mobile: 'swipe' }, entity: null,
    sync: true, notes: false, side: false, railTab: 'notes', fbList: 'page', fbShow: 'open', fbScope: 'version',
    opts: {}, optsOpen: false
}, (() => { try { return JSON.parse(localStorage.getItem(STORE) || '{}'); } catch (e) { return {}; } })());
state.step = -1;
// Page notes, journeys and page options start collapsed on every visit, whatever was open last time.
state.notes = false;
state.side = false;
state.optsOpen = false;
state.entity = null;  // the content model opens with nothing selected
// Which tabs this viewer has. The server says, from PROTOTYPE_CONTENT_MODEL and who is viewing: the content model is
// the team's unless it's shared. Opened as a file or an Artifact, everything shows.
const VIEWS = Object.assign({ model: true, model_team_only: false }, window.PROTOTYPE_VIEWS);
// A view saved by an older prototype, or one this viewer doesn't have, opens the pages instead.
if (!['wireframes', 'sitemap', 'model'].includes(state.view) || (state.view === 'model' && !VIEWS.model)) state.view = 'wireframes';
function save() {
    try { localStorage.setItem(STORE, JSON.stringify({ view: state.view, route: state.route, frames: state.frames, pins: state.pins, modes: state.modes, entity: state.entity, journey: state.journey, sync: state.sync, railTab: state.railTab, fbList: state.fbList, fbShow: state.fbShow, fbScope: state.fbScope, opts: state.opts, welcomed: state.welcomed })); } catch (e) { /* storage unavailable */ }
}
const pagesFor = id => ROUTES.filter(r => (NOTES[r.key]?.fed || []).includes(id));

/* ---------- Frames ---------- */

const FRAME_DOC = '<!doctype html><html lang="en" class="antialiased"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><link rel="icon" href="data:,">'
    + '<script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@' + (window.PROTOTYPE_TAILWIND || '4.3.3') + '/dist/index.global.js"><\/script>'
    + '<style type="text/tailwindcss">' + document.getElementById('frame-css').textContent + '</style>'
    + '</head><body class="flex flex-col min-h-screen bg-white wf-annotate"><div id="app" class="flex flex-col min-h-screen"></div>'
    + '<script>' + document.getElementById('frame-js').textContent + '<\/script>'
    + '</body></html>';

const FR = {
    desktop: { w: 1440, h: 900, s: 1, ready: false, el: null },
    mobile: { w: 390, h: 844, s: 1, ready: false, el: null }
};

// Other devices a site adds, such as a tablet: DEVICES in data.js. None by default. Each shows on its own, never in
// Both, and comments made on it are kept for it.
const EXTRA = (typeof DEVICES === 'undefined' ? [] : DEVICES).filter(d => d && /^[a-z][a-z0-9-]{0,19}$/.test(d.id)
    && !['both', 'desktop', 'mobile'].includes(d.id) && d.width > 0 && d.height > 0);
EXTRA.forEach(d => { FR[d.id] = { w: d.width, h: d.height, s: 1, ready: false, el: null, extra: true }; });

function makeFrames() {
    EXTRA.forEach(d => {
        $('#frames').insertAdjacentHTML('beforeend', `<div class="device" id="dev-${d.id}" hidden>
            <div class="device-label"><button type="button" class="device-name" aria-expanded="false">${esc(d.label || d.id)} <span class="dims" id="lab-${d.id}"></span></button><span class="zoom" id="zoom-${d.id}"></span></div>
            <div class="browser"><div class="viewport" id="vp-${d.id}"></div></div>
        </div>`);
        $('#frame-pick').insertAdjacentHTML('beforeend', `<button type="button" data-frames="${d.id}" aria-pressed="false">${esc(d.label || d.id)}</button>`);
    });
    for (const name of Object.keys(FR)) {
        const f = document.createElement('iframe');
        f.name = name;
        f.title = `${DEVICE[name] || name} prototype`;
        f.srcdoc = FRAME_DOC;
        FR[name].el = f;
        $(`#vp-${name}`).appendChild(f);
    }
}

let pendingHighlight = null;
function renderFrame(name, highlight) {
    const f = FR[name];
    if (!f.ready) return;
    const pr = parseRoute(state.route);
    // option(id), in pages.js, reads the choices showing in this frame while its page is drawn.
    const options = optionValues(pr.key, name);
    window.OPTION_NOW = options;
    const html = (PAGES[pr.key] || PAGES[ROUTES[0].key])(pr.slug);
    window.OPTION_NOW = null;
    f.el.contentWindow.postMessage({
        type: 'render', html, title: pr.r.title, options,
        mode: modeFor(pr.key, name), annotate: true,
        phase: pr.params.phase ? Number(pr.params.phase) : null,
        highlight: highlight || null, sync: state.sync
    }, '*');
}
function tellFrames(msg) {
    for (const name of Object.keys(FR)) if (FR[name].ready) FR[name].el.contentWindow.postMessage(msg, '*');
}

function navigate(route, opts = {}) {
    state.route = route;
    pendingHighlight = opts.highlight || null;
    save();
    for (const name of Object.keys(FR)) renderFrame(name, opts.highlight);
    if (fb.picking) fbPick(false);
    if (fb.draft && fb.draft.page !== parseRoute(route).key) fb.draft = null;
    renderOptions();
    fbSendPins();
    const pr = parseRoute(route);
    $('#page-title').textContent = pr.r.title;
    $('#page-path').textContent = pr.path;
    closeSidebar();
    renderSidebar();
    requestAnimationFrame(layoutFrames);
    if (state.view !== 'wireframes' && !opts.stay) setView('wireframes');
    else renderRail();
}

window.addEventListener('message', e => {
    const name = Object.keys(FR).find(n => FR[n].el && FR[n].el.contentWindow === e.source);
    if (!name) return;
    const m = e.data || {};
    if (m.source !== 'wf-frame') return;
    if (m.type === 'ready') { FR[name].ready = true; renderFrame(name, pendingHighlight); fbSendPins(); if (fb.picking) tellFrames({ type: 'pick', on: true }); }
    else if (m.type === 'nav') {
        if (state.step >= 0) {
            const next = activeJourney().steps[state.step + 1];
            if (next && parseRoute(next.route).path === parseRoute(m.route).path) { goStep(state.step + 1); return; }
            endJourney(true);
            toast('You’ve left the journey to look around. Pick it up again from the list on the left.');
        }
        navigate(m.route);
    }
    else if (m.type === 'cpin') fbOpen(m.id);
    else if (m.type === 'picked') fbPicked(name, m.anchor);
    else if (m.type === 'moved') fbMoved(name, m.id, m.anchor);
    else if (m.type === 'pick-cancel') fbPick(false);
    else if (m.type === 'external') toast('Links to other websites are switched off in the prototype.');
    else if (m.type === 'inert') toast(`“${m.label}” is a placeholder in the prototype.`);
    else if (m.type === 'download') toast(`Downloads aren’t served in the prototype (${m.href}).`);
    else if (m.type === 'scroll' && state.sync && state.view === 'wireframes') {
        const other = name === 'desktop' ? 'mobile' : 'desktop';
        if (FR[other].ready && !$(`#dev-${other}`).hidden) FR[other].el.contentWindow.postMessage({ type: 'syncScroll', tl: m.tl, idx: m.idx, frac: m.frac, top: m.top }, '*');
    }
});

function layoutFrames() {
    const area = $('#frames-wrap');
    const narrow = window.innerWidth <= 1000;
    // A device the site has since taken out shows both.
    let show = state.frames === 'both' || FR[state.frames] ? state.frames : 'both';
    // Both frames need room for the phone at 55% and the laptop at 30%.
    if (show === 'both' && (narrow || area.clientWidth - 32 < 390 * 0.55 + 44 + 1440 * 0.3)) show = 'mobile';
    // Frames show just the page, with no browser bar. On its own the desktop fills the stage, edge to edge at full size.
    area.classList.toggle('solo-desktop', show === 'desktop');
    const W = area.clientWidth - (show === 'desktop' ? 0 : 32);
    const H = area.clientHeight - (show === 'desktop' ? 0 : 32);
    Object.keys(FR).forEach(name => { $(`#dev-${name}`).hidden = !(show === name || (show === 'both' && !FR[name].extra)); });
    // During a journey, fade the other device, but only while both are on screen.
    const jd = state.step >= 0 && show === 'both' ? activeJourney().device : null;
    $('#dev-desktop').classList.toggle('dim', jd === 'mobile');
    $('#dev-mobile').classList.toggle('dim', jd === 'desktop');

    const label = 26, bar = 0, phoneChrome = 2;
    const D = FR.desktop, M = FR.mobile;
    if (FR[show] && FR[show].extra) {
        // Another device, on its own: as large as fits, up to its full size.
        const E = FR[show];
        E.s = narrow ? Math.min(1, W / E.w) : Math.min(1, Math.max(0.3, Math.min(W / E.w, (H - label - 2) / E.h)));
    } else if (show === 'desktop') {
        D.w = Math.max(320, Math.floor(W));
        D.h = Math.max(320, Math.floor(narrow ? window.innerHeight - area.getBoundingClientRect().top : H));
        D.s = 1;
    } else if (!narrow) {
        M.w = 390; M.h = 844;
        const fitH = (H - label - phoneChrome) / M.h;
        M.s = show === 'both'
            ? Math.max(0.55, Math.min(0.8, fitH, (W * 0.4 - 44) / M.w))
            : Math.min(1, Math.max(0.5, fitH));
        const mobileW = show === 'both' ? M.w * M.s + 20 + 24 : 0;
        // A 1440 by 900 laptop, as large as fits beside the phone and within the height.
        D.w = 1440;
        D.h = 900;
        D.s = Math.min(1, Math.max(0.3, Math.min((W - mobileW) / D.w, (H - label - bar - 2) / D.h)));
    } else {
        M.w = 390; M.h = 780;
        M.s = Math.min(1, (W - 20) / M.w);
        D.w = 1440; D.s = Math.min(1, W / D.w); D.h = 900;
    }
    for (const name of Object.keys(FR)) {
        const f = FR[name];
        if (!f.el) continue;
        f.el.style.width = f.w + 'px';
        f.el.style.height = f.h + 'px';
        f.el.style.transform = `scale(${f.s})`;
        const vp = $(`#vp-${name}`);
        vp.style.width = Math.round(f.w * f.s) + 'px';
        vp.style.height = Math.round(f.h * f.s) + 'px';
        $(`#lab-${name}`).textContent = `${f.w} × ${f.h}`;
        $(`#zoom-${name}`).textContent = `${Math.round(f.s * 100)}%`;
        if (f.ready && f.sentZoom !== f.s) { f.sentZoom = f.s; f.el.contentWindow.postMessage({ type: 'zoom', zoom: f.s }, '*'); }
    }
    if (FB.on) fbAddButton();
}

/* ---------- Top bar ---------- */

function setView(v) {
    state.view = v;
    save();
    $$('#views [data-view]').forEach(b => b.setAttribute('aria-selected', String(b.dataset.view === v)));
    $$('.view').forEach(s => { s.hidden = s.id !== `view-${v}`; });
    if (v === 'sitemap') renderSitemap();
    if (v === 'model') renderModel();
    renderRail();
    if (v === 'wireframes') requestAnimationFrame(layoutFrames);
}

let toastTimer;
function toast(msg) {
    const t = $('#toast');
    t.textContent = msg;
    t.hidden = false;
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => { t.hidden = true; }, 3200);
}

/* ---------- Suggested ---------- */

// What we suggest rather than what's agreed: a page, a field or a part of the content model. Agreeing it happens in
// the feedback, as a decision on the page.
const isOpen = suggested => !!suggested;
const confirmBadge = () => '<span class="badge sugg">Suggested</span>';

/* ---------- Rail ---------- */

function renderRail() {
    const body = $('#rail-body');
    const pr = parseRoute(state.route);
    const tab = railTab(pr);
    // On the pages, the panel stays out of sight until the Notes or Comments button opens it, and a page with nothing
    // for it has none. The other views always show their panel.
    const off = state.view === 'wireframes' && (!state.notes || !pageHasNotes(pr));
    // The Sitemap has its whole width: what its panel said is on the page, below the tree.
    $('.body').classList.toggle('rail-off', off || state.view === 'sitemap');
    const open = state.view === 'wireframes' && !off;
    $('#notes-btn').hidden = !pageHasOwnNotes(pr);
    $('#notes-btn').setAttribute('aria-expanded', String(open && tab === 'notes'));
    $('#fb-comment').setAttribute('aria-expanded', String(open && tab === 'comments'));
    if (state.view !== 'wireframes' || state.step < 0) $$('.device').forEach(d => d.classList.remove('dim'));
    if (state.view === 'wireframes') body.innerHTML = off ? '' : notesRail();
    else if (state.view === 'model') body.innerHTML = modelRail();
    else body.innerHTML = '';
    requestAnimationFrame(fbAddButton);
}

// The panel's tab: Notes or Comments, or whichever of them the page has.
const railTab = pr => !FB.on ? 'notes' : !pageHasOwnNotes(pr) ? 'comments' : state.railTab === 'comments' ? 'comments' : 'notes';

// The Notes and Comments buttons: each opens the panel on its tab, or closes it when it's already showing.
function togglePanel(tab) {
    if (fb.picking) fbPick(false);
    if (state.notes && railTab(parseRoute(state.route)) === tab) { setNotes(false); return; }
    state.railTab = tab;
    if (tab === 'comments') state.fbList = 'page';
    setNotes(true);
    if (tab === 'comments') fbRefresh();
}

// Whether a page has notes of its own. The header and footer are described once, on the Sitemap tab, never on each page.
function pageHasOwnNotes(pr) {
    const n = Object.assign({ purpose: '', aud: [], content: [], tech: [], fed: [] }, NOTES[pr.key]);
    return Boolean(n.purpose || n.aud.length || n.content.length || n.tech.length || (VIEWS.model && n.fed.length));
}
// The panel shows for a page with notes, and for every page with feedback on, for its comments.
const pageHasNotes = pr => FB.on || pageHasOwnNotes(pr);

// The panel: with feedback on, a Notes tab and a Comments tab, or just the one when the page has no notes.
function notesRail() {
    const pr = parseRoute(state.route);
    const notes = pageHasOwnNotes(pr);
    const tab = railTab(pr);
    const open = FB.on && fbSigned() ? fbOpenOn(pr.key) : 0;
    const head = FB.on && notes
        ? `<div class="seg rail-tabs" role="tablist" aria-label="Notes and comments">
            <button type="button" role="tab" data-rail-tab="notes" aria-selected="${tab === 'notes'}">Notes</button>
            <button type="button" role="tab" data-rail-tab="comments" aria-selected="${tab === 'comments'}">Comments${open ? `<span class="count">${open}</span>` : ''}</button>
        </div>`
        : `<h2 class="side-h">${FB.on ? 'Comments' : 'Page notes'}</h2>`;
    return `<div class="rail-top">${head}<button type="button" class="icon-btn tiny" data-notes="close" aria-label="Close the panel" title="Close the panel">${ICON.close}</button></div>
        ${tab === 'notes' ? pageNotes(pr) : fbPanel(pr)}`;
}

// A page's content as displayed: each part in order, with a few words on what it shows.
function contentList(items) {
    return `<ol class="content-list">${items.map(c => Array.isArray(c) ? c : [String(c), '']).map(([part, what]) => `<li>${esc(part)}${what ? `<span>${esc(what)}</span>` : ''}</li>`).join('')}</ol>`;
}

// Page notes: what the page is for, what's on it, and how it's built where the page doesn't show it.
function pageNotes(pr) {
    const n = Object.assign({ purpose: '', aud: [], content: [], tech: [], fed: [] }, NOTES[pr.key]);
    const r = pr.r;
    // Technical notes read as plain sentences; the content model links say where content comes from.
    const sentences = list => `<div class="tech">${list.map(c => `<p>${esc(c)}</p>`).join('')}</div>`;
    // Each section opens with the notes; any can be folded away.
    const fold = (title, count, body) => `<details class="fold" open><summary>${title}${count ? ` <span class="fold-n">${count}</span>` : ''}</summary><div>${body}</div></details>`;
    const aud = n.aud.filter(a => AUD[a]);
    const fed = !VIEWS.model ? '' : n.fed.map(id => { const e = MODEL.find(m => m.id === id); return e ? `<button type="button" class="chip-btn" data-entity="${id}">${esc(e.name)} <span class="muted">${esc(e.kind.toLowerCase())}</span></button>` : ''; }).join('');
    return `<div class="rail-head">
            <h2>${esc(r.title)}</h2>
            ${aud.length ? `<div class="badges">${aud.map(a => `<span class="badge aud" title="${esc(AUD[a][1])}">${AUD[a][0]}</span>`).join('')}</div>` : ''}
            ${n.purpose ? `<p class="purpose">${esc(n.purpose)}</p>` : ''}
        </div>
        <div class="folds">
            ${n.content.length ? fold('On this page', n.content.length, contentList(n.content)) : ''}
            ${n.tech.length || fed ? fold('Technical notes', '', `
                ${n.tech.length ? sentences(n.tech) : ''}
                ${fed ? `<div><p class="fold-h">Content comes from</p><div class="chips">${fed}</div></div>` : ''}`) : ''}
        </div>`;
}

const ICON = {
    mobile: '<svg width="14" height="16" viewBox="0 0 14 16" aria-hidden="true"><rect x="2.5" y="1" width="9" height="14" rx="2" fill="none" stroke="currentColor" stroke-width="1.4"/><path d="M6 12.5h2" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/></svg>',
    desktop: '<svg width="16" height="16" viewBox="0 0 16 16" aria-hidden="true"><rect x="1.5" y="2" width="13" height="9" rx="1.5" fill="none" stroke="currentColor" stroke-width="1.4"/><path d="M6 14h4M8 11v3" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/></svg>',
    tablet: '<svg width="16" height="16" viewBox="0 0 16 16" aria-hidden="true"><rect x="2.5" y="1.5" width="11" height="13" rx="1.8" fill="none" stroke="currentColor" stroke-width="1.4"/><path d="M7 12.2h2" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/></svg>',
    panel: '<svg width="16" height="16" viewBox="0 0 16 16" aria-hidden="true"><rect x="1.5" y="2.5" width="13" height="11" rx="2" fill="none" stroke="currentColor" stroke-width="1.4"/><path d="M6 2.5v11" stroke="currentColor" stroke-width="1.4"/></svg>',
    close: '<svg width="14" height="14" viewBox="0 0 16 16" aria-hidden="true"><path d="M3.5 3.5l9 9M12.5 3.5l-9 9" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>',
    more: '<svg width="16" height="16" viewBox="0 0 16 16" aria-hidden="true"><circle cx="3.5" cy="8" r="1.4" fill="currentColor"/><circle cx="8" cy="8" r="1.4" fill="currentColor"/><circle cx="12.5" cy="8" r="1.4" fill="currentColor"/></svg>',
    chev: '<svg width="12" height="12" viewBox="0 0 12 12" aria-hidden="true"><path d="M3 4.5l3 3 3-3" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>'
};
const DEVICE = { mobile: 'Mobile', desktop: 'Desktop' };
EXTRA.forEach(d => { DEVICE[d.id] = d.label || d.id; });
const activeJourney = () => JOURNEYS.find(x => x.id === state.journey) || JOURNEYS[0];

const narrowMQ = matchMedia('(max-width: 1000px)');
const sideCollapsed = () => state.side === false && !narrowMQ.matches;

function renderSidebar() {
    const live = state.step >= 0;
    const collapsed = sideCollapsed();
    $('.body').classList.toggle('side-off', collapsed);
    $('#sidebar').classList.toggle('mini', collapsed);
    if (collapsed) {
        $('#side-scroll').innerHTML = `<button type="button" class="fold-tab" data-side="open" aria-label="Show journeys" title="Show journeys">${ICON.panel}<span class="fold-tab-label">Journeys</span></button>`;
        return;
    }
    // Each journey is numbered, says who takes it, and shows its device as a small icon on the right.
    const journeys = JOURNEYS.map((j, i) => {
        const on = live && j.id === state.journey;
        const open = on && state.jopen !== false;
        return `<li>
            <button type="button" class="jbtn" data-journey="${j.id}" aria-pressed="${on}"${on ? ` aria-expanded="${open}"` : ''}>
                <span class="jnum">${i + 1}</span>
                <span><b>${esc(j.name)}</b><small>${on && !open ? `Step ${state.step + 1} of ${j.steps.length}: ${esc(j.steps[state.step].t)}` : esc(j.who)}</small></span>
                <span class="jdevice" title="${DEVICE[j.device] || ''}">${ICON[j.device] || ''}<span class="vh">, on ${(DEVICE[j.device] || '').toLowerCase()}</span></span>
                ${on ? `<span class="jchev">${ICON.chev}</span>` : ''}
            </button>
            ${open ? `<div class="jpanel">
                <dl class="jmeta"><dt>Arrives</dt><dd>${esc(j.arrives)}</dd><dt>Wants to</dt><dd>${esc(j.goal)}</dd><dt>Success</dt><dd>${esc(withArticle(j.measure.toLowerCase(), true))}, counted in Plausible</dd></dl>
                <ol class="steps">${j.steps.map((st, i) => `<li class="${i < state.step ? 'done' : ''}" ${i === state.step ? 'aria-current="step"' : ''}><button type="button" data-step="${i}"><span class="n">${i < state.step ? '✓' : i + 1}</span><span>${esc(st.t)}</span></button>${i === state.step ? `<p class="step-desc">${esc(st.d)}</p>` : ''}</li>`).join('')}</ol>
                <div class="jnav">
                    <button type="button" class="jb-btn" data-jstep="-1" ${state.step === 0 ? 'disabled' : ''}>← Back</button>
                    <button type="button" class="jb-btn primary" data-jstep="1">${state.step === j.steps.length - 1 ? 'Finish' : 'Next →'}</button>
                    <button type="button" class="jend" data-journey-end>End journey</button>
                </div>
            </div>` : ''}
        </li>`;
    }).join('');
    $('#side-scroll').innerHTML = `
        <section>
            <div class="side-hrow"><h2 class="side-h">Follow a journey</h2><button type="button" class="icon-btn tiny side-collapse" data-side="close" aria-label="Collapse the journeys panel" title="Collapse the journeys panel">${ICON.panel}</button></div>
            <p class="side-help">${JOURNEYS.length ? 'See the site the way a particular visitor would, one step at a time.' : 'Journeys appear here once they’re planned.'}</p>
            ${JOURNEYS.length ? `<ul class="jlist">${journeys}</ul>` : ''}
        </section>`;
    const nav = $('#side-scroll .jnav');
    if (nav) nav.scrollIntoView({ block: 'nearest' });
}

function setSide(open) {
    state.side = open;
    save();
    renderSidebar();
    if (state.view === 'wireframes') requestAnimationFrame(layoutFrames);
}
narrowMQ.addEventListener('change', renderSidebar);

function goStep(i) {
    const j = activeJourney();
    const s = j.steps[i];
    if (!s) return;
    if (state.view !== 'wireframes') setView('wireframes');
    state.step = i;
    if (state.frames !== 'both' && state.frames !== j.device) setFrames(j.device);
    if (parseRoute(s.route).path === parseRoute(state.route).path && !s.phase) {
        state.route = s.route;
        tellFrames({ type: 'highlight', sel: s.sel });
        renderSidebar();
        renderRail();
        requestAnimationFrame(layoutFrames);
    } else {
        navigate(s.route, { highlight: s.sel });
    }
}

function endJourney(quiet) {
    if (state.step < 0) return;
    state.step = -1;
    if (state.framesBefore) { setFrames(state.framesBefore); state.framesBefore = null; }
    $$('.device').forEach(d => d.classList.remove('dim'));
    if (!quiet) { renderSidebar(); requestAnimationFrame(layoutFrames); }
}

// "a share", "an enquiry".
function withArticle(word, capital) {
    const article = /^[aeiou]/i.test(word) ? 'an' : 'a';
    return `${capital ? article[0].toUpperCase() + article.slice(1) : article} ${word}`;
}

function finishJourney() {
    const j = activeJourney();
    endJourney();
    toast(`Journey complete. On the live site this is counted as ${withArticle(j.measure.toLowerCase())} in Plausible.`);
}

/* ---------- Help and the narrow-screen drawer ---------- */

let helpReturn = null;
function openHelp() {
    helpReturn = document.activeElement;
    $('#help').hidden = false;
    $('#help .help-card').focus();
}
function closeHelp() {
    $('#help').hidden = true;
    if (!state.welcomed) { state.welcomed = true; save(); }
    if (helpReturn && helpReturn.focus) helpReturn.focus();
}
function openSidebar() { $('#sidebar').classList.add('open'); $('#scrim').hidden = false; $('#nav-toggle').setAttribute('aria-expanded', 'true'); }
function closeSidebar() { $('#sidebar').classList.remove('open'); $('#scrim').hidden = true; $('#nav-toggle').setAttribute('aria-expanded', 'false'); }

/* ---------- Sitemap ---------- */

function smNode(n, top) {
    const cls = ['node', top ? 'top' : '', isOpen(n.s) ? 's' : '', n.count ? 'c' : ''].join(' ');
    const tags = [
        n.nav === 'main' ? 'main menu' : '', n.nav === 'footer' || n.foot ? 'footer' : '',
        n.count ? `${n.count} ${n.coll ? n.coll.toLowerCase() + ' entries' : 'entries'}` : ''
    ].filter(Boolean);
    const inner = `<span class="nt">${esc(n.t)}</span><span class="nu">${esc(n.u).replace(/([/.])/g, '$1<wbr>')}</span>${n.note ? `<span class="muted" style="font-size:12px">${esc(n.note)}</span>` : ''}${tags.length ? `<span class="nm">${tags.map(t => `<span class="tag">${esc(t)}</span>`).join('')}</span>` : ''}`;
    const r = n.k ? ROUTES.find(x => x.key === n.k) : null;
    return r ? `<button type="button" class="${cls}" data-go="${sampleRoute(r)}">${inner}</button>` : `<div class="${cls}">${inner}</div>`;
}
function smKids(kids) {
    return `<ul class="sm-kids">${kids.map(k => `<li>${smNode(k)}${k.kids ? smKids(k.kids) : ''}</li>`).join('')}</ul>`;
}
function renderSitemap() {
    $('#view-sitemap').innerHTML = `<div class="canvas">
        <div class="canvas-head">
            <div><h2>Sitemap</h2><p>The pages the site will have. ${esc(PROJECT.sitemapIntro || 'Those with a dashed outline are our suggestions: say what you think in the comments on the page.')} Select a page to open it.</p></div>
            <div class="legend">
                <span><i class="sw"></i>Agreed</span>
                <span><i class="sw s"></i>Suggested</span>
                <span><i class="sw c"></i>Collection entries</span>
            </div>
        </div>
        <div class="scroll-x"><div class="sm" style="--cols:${Math.max(1, SITEMAP.length)}">
            <div class="sm-root">${smNode(SITEMAP_ROOT, true)}</div>
            <div class="sm-cols${SITEMAP.length === 1 ? ' one' : ''}">${SITEMAP.map(c => `<div class="sm-col">${smNode(c, true)}${c.kids ? smKids(c.kids) : ''}</div>`).join('')}</div>
        </div></div>
        ${sitemapInfo()}
    </div>`;
    sitemapFade();
}

// While the sitemap runs off the right edge, it fades there to say so, and gains room on the right so its last
// column scrolls clear of the fade.
function sitemapFade() {
    const sx = $('#view-sitemap .scroll-x');
    if (!sx) return;
    if (!sx.dataset.fade) {
        sx.dataset.fade = '1';
        sx.addEventListener('scroll', sitemapFade, { passive: true });
    }
    sx.classList.toggle('overflows', sx.scrollWidth > sx.clientWidth + 1 + (sx.classList.contains('overflows') ? 56 : 0));
    sx.classList.toggle('fade-right', sx.scrollWidth - sx.clientWidth - sx.scrollLeft > 1);
}
// Below the tree, in columns: the menus, the header and footer as displayed, where the source documents go, and what
// changed since the proposal. SITEWIDE.consider is SITEWIDE.content's name before v0.1.38.
function sitemapInfo() {
    const footer = SITEMAP.flatMap(c => [c, ...(c.kids || [])]).filter(c => c.nav === 'footer' || c.foot);
    const parts = SITEWIDE.content || SITEWIDE.consider || [];
    const tech = SITEWIDE.tech || [];
    return `<div class="sm-info">
        <section><h3>Menus</h3>
            <div class="navs">
                <div><p style="font-weight:600;margin-bottom:6px">Main</p><ol>${NAV.map(n => `<li>${esc(n.t)}${n.kids ? ' ▾' : ''}</li>`).join('')}</ol></div>
                <div><p style="font-weight:600;margin-bottom:6px">Footer</p><ol>${(FOOTER_NAV.length ? FOOTER_NAV.map(l => l[0]) : footer.map(c => c.t)).map(t => `<li>${esc(t)}</li>`).join('')}</ol></div>
            </div>
        </section>
        ${parts.length || tech.length ? `<section><h3>Header and footer</h3>${parts.length ? contentList(parts) : ''}${tech.length ? `<div class="tech">${tech.map(t => `<p>${esc(t)}</p>`).join('')}</div>` : ''}</section>` : ''}
        ${SOURCE_MAP.length ? `<section><h3>Where the source documents go</h3>${sourceMap()}</section>` : ''}
        ${CHANGES.length ? `<section><h3>What changed since the proposal</h3>${changesList()}</section>` : ''}
    </div>`;
}
function sourceMap() {
    return `<ul class="changes">${SOURCE_MAP.map(r => `<li><b>${esc(r[0])}</b><span>${esc(r[1])}</span>${r[2] ? `<button type="button" class="chip-btn" data-go="${r[2]}" style="grid-row:1/span 2">Open</button>` : ''}</li>`).join('')}</ul>`;
}
function sourceChecks() {
    return `<ul class="changes">${SOURCE_CHECKS.map(c => `<li><b>${esc(c[0])}</b><span>${esc(c[1])}</span></li>`).join('')}</ul>`;
}
function changesList() {
    return `<ul class="changes">${CHANGES.map(c => `<li><b>${esc(c[0])}</b><span>${esc(c[1])}</span></li>`).join('')}</ul>`;
}

/* ---------- Content model ---------- */

// The handle, and for anything with pages its slug, with any note about the slug on a line of its own in italics.
// Without a slug (a route of 'none'), the note stands in for it.
function entMeta(e) {
    const route = String(e.route || '');
    const [, slug = '', note = route] = route.match(/^(\/\S*)\s*(.*)$/) || [];
    const aside = note.replace(/^\((.*)\)$/, '$1');
    if (!route) return `<span class="ent-m">Handle: <code>${esc(e.handle)}</code></span>`;
    return `<span class="ent-m">Handle: <code>${esc(e.handle)}</code></span>`
        + `<span class="ent-m">Slug: ${slug ? `<code>${esc(slug)}</code>` : `<em>${esc(aside)}</em>`}</span>`
        + (slug && aside ? `<span class="ent-m"><em>${esc(aside)}</em></span>` : '');
}

// An icon for each kind of content, as the control panel draws them: a stack for a collection, a tag for a
// taxonomy, a form, a globe for globals, lines for a navigation and a picture for an asset library.
const KIND_ICON = {
    collection: '<rect x="2.5" y="5" width="11" height="8.5" rx="1.5"/><path d="M4.5 2.5h7"/>',
    taxonomy: '<path d="M2.5 3.5v4.1l6.4 6.4 5.1-5.1-6.4-6.4H3.5a1 1 0 0 0-1 1Z"/><circle cx="5.6" cy="5.6" r="1"/>',
    form: '<rect x="3" y="2.5" width="10" height="11" rx="1.5"/><path d="M5.5 6h5M5.5 8.5h5M5.5 11h3"/>',
    globals: '<circle cx="8" cy="8" r="5.5"/><path d="M2.5 8h11M8 2.5c1.8 1.6 2.6 3.4 2.6 5.5S9.8 11.9 8 13.5M8 2.5C6.2 4.1 5.4 5.9 5.4 8s.8 3.9 2.6 5.5"/>',
    navigation: '<path d="M3 4h10M3 8h10M3 12h6"/>',
    assets: '<rect x="2.5" y="3" width="11" height="10" rx="1.5"/><circle cx="6" cy="6.5" r="1.2"/><path d="m2.5 11.5 3.5-3.5 3 3 2-2 2.5 2.5"/>'
};
function kindIcon(kind) {
    const k = String(kind).toLowerCase().replace(/^global$/, 'globals');
    return KIND_ICON[k] ? `<svg width="13" height="13" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${KIND_ICON[k]}</svg>` : '';
}

// The key to the field marks, both to confirm, shown under the fields only when there are marks to explain.
function fieldKey(e) {
    const key = [];
    if (e.fields.some(f => f[2] === 's')) key.push('<span><span class="mono" style="color:var(--sugg-line)">+ field</span>to add</span>');
    if (e.fields.some(f => f[2] === 'x')) key.push('<span><span class="mono" style="color:var(--drop);text-decoration:line-through">field</span>to remove</span>');
    return key.length ? `<p class="legend field-key"><span>Suggested:</span>${key.join('')}</p>` : '';
}

// Pages always comes first, so it leads on a phone too, where the cards stack in this order.
const modelOrder = () => [...MODEL].sort((a, b) => (b.handle === 'pages') - (a.handle === 'pages'));

// Navigation and asset libraries sit in sections of their own below the diagram.
const MODEL_SECTIONS = [['Navigation', 'Navigation'], ['Asset libraries', 'Assets']];

function renderModel() {
    const diagram = modelOrder().filter(e => !MODEL_SECTIONS.some(([, kind]) => e.kind === kind));
    const card = e => `<button type="button" class="ent${isOpen(e.status === 'suggested') ? ' s' : ''}"${e.area ? ` style="grid-area:${e.area}"` : ''} data-ent="${e.id}" aria-pressed="${state.entity === e.id}">
        <span class="ent-h">
            <span class="ent-k"><span class="kind">${kindIcon(e.kind)}${e.kind}</span><span>${esc(e.count)}</span></span>
            <span class="ent-n">${esc(e.name)}</span>
            ${entMeta(e)}
        </span>
    </button>`;
    $('#view-model').innerHTML = `<div class="canvas">
        <div class="canvas-head">
            <div><h2>Content model</h2><p>The collections, taxonomies, forms and globals behind the site, with the fields we suggest adding. Select one to see where it appears and what it depends on.</p></div>
            <div class="legend">
                <span><i class="sw"></i>Agreed</span>
                <span><i class="sw s"></i>Suggested</span>
            </div>
        </div>
        ${diagram.length ? `<div class="model" id="model" style="grid-template-areas:${esc(MODEL_LAYOUT)}"><svg aria-hidden="true" id="model-svg"></svg>${diagram.map(card).join('')}</div>` : ''}
        ${MODEL_SECTIONS.map(([title, kind]) => { const items = modelOrder().filter(e => e.kind === kind); return items.length ? `<section class="model-sec"><h3>${title}</h3><div class="model-row">${items.map(card).join('')}</div></section>` : ''; }).join('')}
        ${MODEL.length ? '' : '<p class="note-line">No content model yet.</p>'}
    </div>`;
    requestAnimationFrame(drawRels);
}

function drawRels() {
    const sel = state.entity;
    const related = new Set([sel]);
    RELS.forEach(([a, b]) => { if (a === sel) related.add(b); if (b === sel) related.add(a); });
    $$('#view-model .ent').forEach(el => { el.classList.toggle('dim', !!sel && !related.has(el.dataset.ent)); el.setAttribute('aria-pressed', String(el.dataset.ent === sel)); });
    const wrap = $('#model'), svg = $('#model-svg');
    if (!wrap || !svg || getComputedStyle(svg).display === 'none') return;
    const box = wrap.getBoundingClientRect();
    const rect = id => { const r = $(`[data-ent="${id}"]`, wrap).getBoundingClientRect(); return { l: r.left - box.left, t: r.top - box.top, r: r.right - box.left, b: r.bottom - box.top, cx: (r.left + r.right) / 2 - box.left, cy: (r.top + r.bottom) / 2 - box.top }; };
    let out = '';
    RELS.filter(([a, b]) => $(`[data-ent="${a}"]`, wrap) && $(`[data-ent="${b}"]`, wrap)).forEach(([a, b, label, sug]) => {
        const A = rect(a), B = rect(b);
        const dx = B.cx - A.cx, dy = B.cy - A.cy;
        let x1, y1, x2, y2, d;
        const sameRow = Math.abs(dy) < Math.min(A.b - A.t, B.b - B.t) / 2 && Math.abs(A.t - B.t) < 40;
        if (sameRow || (Math.abs(dx) > Math.abs(dy) * 1.6)) {
            x1 = dx > 0 ? A.r : A.l; x2 = dx > 0 ? B.l : B.r;
            y1 = Math.min(A.b - 20, Math.max(A.t + 20, (B.t + B.b) / 2));
            y2 = Math.min(B.b - 20, Math.max(B.t + 20, y1));
            const mx = (x1 + x2) / 2;
            d = `M${x1},${y1} C${mx},${y1} ${mx},${y2} ${x2},${y2}`;
        } else {
            y1 = dy > 0 ? A.b : A.t; y2 = dy > 0 ? B.t : B.b;
            x1 = Math.abs(dx) < 40 ? A.cx : (dx > 0 ? A.l + (A.r - A.l) * 0.8 : A.l + (A.r - A.l) * 0.2);
            x2 = Math.abs(dx) < 40 ? B.cx : (dx > 0 ? B.l + (B.r - B.l) * 0.25 : B.l + (B.r - B.l) * 0.75);
            const my = (y1 + y2) / 2;
            d = `M${x1},${y1} C${x1},${my} ${x2},${my} ${x2},${y2}`;
        }
        const on = sel === a || sel === b;
        out += `<path d="${d}" class="${sug ? 's' : ''} ${on ? 'on' : ''}"/>`;
        out += `<text x="${(x1 + x2) / 2}" y="${(y1 + y2) / 2 + 4}" text-anchor="middle" class="${on ? 'on' : ''}">${esc(label)}</text>`;
    });
    svg.innerHTML = out;
}

// A relationship as a sentence: a taxonomy reads "Stories uses the Story type taxonomy".
function relSentence(r, a, b) {
    return r[2] === 'taxonomy' ? `${esc(a.name)} uses the ${esc(b.name)} taxonomy` : `${esc(a.name)} ${esc(r[2])} ${esc(b.name)}`;
}

// With nothing selected, the panel says what the model holds.
function modelOverview() {
    const plural = { Collection: 'Collections', Taxonomy: 'Taxonomies', Form: 'Forms', Global: 'Globals', Globals: 'Globals', Navigation: 'Navigation menus', Assets: 'Asset libraries' };
    const counts = {};
    MODEL.forEach(m => { const k = plural[m.kind] || m.kind; counts[k] = (counts[k] || 0) + 1; });
    return `
        <div class="rail-head"><h2>Content model</h2><p class="purpose" style="font-size:14px">${MODEL.length ? 'Select an item to see its fields, where it appears and what it connects to. Select it again, or press Escape, to clear.' : 'Nothing here yet.'}</p></div>
        ${MODEL.length ? `<section><h3>What it holds</h3><div class="counts">${Object.entries(counts).map(([k, n]) => `<span>${esc(k)}</span><b>${n}</b>`).join('')}</div></section>` : ''}
        ${CHANGES.length ? `<details class="fold"><summary>What changed since the proposal</summary><div>${changesList()}</div></details>` : ''}
        ${SOURCE_CHECKS.length ? `<details class="fold"><summary>Checked against the source documents</summary><div>${sourceChecks()}</div></details>` : ''}`;
}

function clearEntity() {
    state.entity = null;
    save();
    drawRels();
    renderRail();
}

function modelRail() {
    const e = MODEL.find(m => m.id === state.entity);
    if (!e) return modelOverview();
    const rels = RELS.filter(r => r[0] === e.id || r[1] === e.id);
    const pages = pagesFor(e.id);
    return `
        <div class="rail-head">
            <div class="rail-kind"><p class="kind">${kindIcon(e.kind)}${e.kind}</p><button type="button" class="icon-btn tiny" data-ent-clear aria-label="Clear selection" title="Clear selection">${ICON.close}</button></div>
            <h2>${esc(e.name)}</h2>
            <div class="ent-meta">${entMeta(e)}</div>
            <div class="badges">
                ${isOpen(e.status === 'suggested') ? confirmBadge() : '<span class="badge">Agreed</span>'}
                ${e.count ? `<span class="badge">${esc(e.count)}${/^[~\d]/.test(e.count) ? ({ Collection: ' entries', Taxonomy: ' terms' }[e.kind] || '') : ''}</span>` : ''}
            </div>
        </div>
        <section><h3>Fields</h3>${e.fields.length ? '' : '<p class="note-line">No fields of its own.</p>'}<table class="ftable">${e.fields.map(f => `<tr class="${f[2] || ''}"><td>${f[2] === 's' ? '+ ' : ''}${esc(f[0])}</td><td>${esc(f[1])}</td></tr>`).join('')}</table>${fieldKey(e)}
            ${e.terms ? `<p class="note-line" style="margin-top:10px">${esc(e.terms)}</p>` : ''}</section>
        ${rels.length ? `<section><h3>Relationships</h3><ul class="bullets">${rels.map(r => { const other = MODEL.find(m => m.id === (r[0] === e.id ? r[1] : r[0])); return `<li>${r[0] === e.id ? relSentence(r, e, other) : relSentence(r, other, e)}${r[3] ? ' (suggested)' : ''}</li>`; }).join('')}</ul></section>` : ''}
        ${pages.length ? `<section><h3>Appears on</h3><div class="chips">${pages.map(r => `<button type="button" class="chip-btn" data-go="${sampleRoute(r)}">${esc(r.title)}</button>`).join('')}</div></section>` : ''}
        ${CHANGES.length ? `<details class="fold"><summary>What changed since the proposal</summary><div>${changesList()}</div></details>` : ''}
        ${SOURCE_CHECKS.length ? `<details class="fold"><summary>Checked against the source documents</summary><div>${sourceChecks()}</div></details>` : ''}`;
}

/* ---------- Feedback ---------- */

// Comments on the prototype, kept by the site with the feedback on its pages. Anyone signed in can comment, reply and
// resolve. The team (a control panel login, or a reviewer marked team) can also raise a comment as a decision and
// record what was decided. A decision carries from one version to the next, so it shows whichever version raised it.
const FB = Object.assign({ on: false, base: '' }, window.PROTOTYPE_FEEDBACK);
const fb = { session: null, comments: [], num: new Map(), draft: null, open: null, deciding: null, picking: false, hover: null, error: '', errorFor: null };

const fbSigned = () => !!(fb.session && fb.session.viewer);
const fbStaff = () => fbSigned() && !!fb.session.viewer.staff;
const fbIsDecided = c => !!(c.decision && c.decision.state === 'decided');
// A page's comments: this version's, and decisions from any version.
const fbPage = key => fb.comments.filter(c => c.page === key && (c.version === VERSION.id || c.decision));
// Done is green, whether decided or resolved; a decision still to make is amber; an open comment is graphite.
const fbKind = c => fbIsDecided(c) ? 'decided' : c.status === 'resolved' ? 'resolved' : c.decision ? 'decide' : 'comment';
const fbToDecide = c => c.decision && !fbIsDecided(c) && c.status === 'open';
// Done, for a reviewer, is one thing: a decision made or a comment resolved.
const fbIsDone = c => fbIsDecided(c) || c.status === 'resolved';
// Open on a page: comments still open, and decisions still to make.
const fbOpenOn = key => fbPage(key).filter(c => c.status === 'open' && !fbIsDecided(c)).length;
const fbVersionLabel = id => (VERSIONS.find(v => v.id === id) || {}).label || `Version ${id}`;
const fbDate = iso => {
    const d = new Date(iso);
    return isNaN(d) ? '' : `${d.toLocaleDateString('en-NZ', { day: 'numeric', month: 'short' })}, ${d.toLocaleTimeString('en-NZ', { hour: 'numeric', minute: '2-digit' })}`;
};

function fbApi(method, path, body) {
    const headers = { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' };
    if (body) headers['Content-Type'] = 'application/json';
    if (fb.session && fb.session.token) headers['X-CSRF-TOKEN'] = fb.session.token;
    return fetch(`${FB.base}/${path}`, { method, credentials: 'same-origin', headers, body: body ? JSON.stringify(body) : undefined })
        .then(r => r.json().catch(() => ({})).then(data => {
            if (r.ok) return data;
            const first = data.errors ? Object.values(data.errors).flat()[0] : null;
            const err = new Error(first || data.message || `Something went wrong (${r.status}).`);
            err.status = r.status;
            throw err;
        }));
}

function fbStart() {
    if (!FB.on) return;
    $('#fb-comment').hidden = false;
    $('#pins-toggle').hidden = false;
    $('#help-comments').hidden = false;
    fbApi('GET', 'session')
        .then(s => { fb.session = s; return fbSigned() ? fbLoad() : fbRefresh(); })
        .catch(err => { fb.session = { viewer: null }; fb.error = err.message; fb.errorFor = 'signin'; fbRefresh(); });
}

// A link from the team's digest, ?comment=<id>, opens that comment on its page, once.
let fbLink = new URLSearchParams(location.search).get('comment');

function fbLoad() {
    return fbApi('GET', 'comments?context=prototype')
        .then(d => {
            fb.comments = d.comments || [];
            fbRefresh();
            if (fbLink && fb.comments.some(c => c.id === fbLink)) {
                const id = fbLink;
                fbLink = null;
                history.replaceState(null, '', location.pathname);
                fbShow(id);
            }
        })
        .catch(err => { if (err.status === 401 && fb.session) fb.session.viewer = null; fbRefresh(); });
}

// Each page numbers its comments from the oldest, the same in the panel, on the pins and in the Feedback tab.
function fbIndex() {
    fb.num = new Map();
    const pages = new Set(fb.comments.map(c => c.page));
    pages.forEach(key => fbPage(key).sort((a, b) => String(a.created_at).localeCompare(String(b.created_at))).forEach((c, i) => fb.num.set(c.id, i + 1)));
}

function fbFiltered(show, scope) {
    return fb.comments.filter(c => scope === 'all' || c.version === VERSION.id || c.decision).filter(c => {
        if (show === 'open') return c.status === 'open' && !fbIsDecided(c);
        if (show === 'decide') return fbToDecide(c);
        if (show === 'done') return fbIsDone(c);
        return true;
    });
}

// Re-renders whatever shows feedback, keeping anything half typed.
function fbRefresh() {
    fbIndex();
    const open = fbSigned() ? fbFiltered('open', 'version').length : 0;
    $('#fb-badge').textContent = open ? String(open) : '';
    $('#fb-badge').hidden = !open;
    $('#fb-comment').setAttribute('aria-label', open ? `Comments, ${open} open` : 'Comments');
    const kept = {};
    $$('[data-fb-input]').forEach(i => { if (i.value) kept[i.dataset.fbInput] = i.value; });
    const focused = document.activeElement && document.activeElement.dataset ? document.activeElement.dataset.fbInput : null;
    renderRail();
    $$('[data-fb-input]').forEach(i => { if (kept[i.dataset.fbInput] != null) i.value = kept[i.dataset.fbInput]; });
    if (focused && $(`[data-fb-input="${focused}"]`)) $(`[data-fb-input="${focused}"]`).focus();
    if (state.view === 'wireframes') requestAnimationFrame(layoutFrames);
    fbSendPins();
}

function fbViewer() {
    const v = fb.session && fb.session.viewer;
    if (!v) return;
    $('#viewer').innerHTML = `<b title="Signed in as ${esc(v.name)}">${esc(v.name)}</b><a href="/prototype/sign-out">Sign out</a>`;
    $('#viewer').hidden = false;
}

// The pins for the page on show. Each frame gets those it can place: by element in either frame, and by spot on the
// page only in the frame the comment was made in, where the spot means the same thing.
function fbSendPins() {
    if (!FB.on) return;
    const pr = parseRoute(state.route);
    const list = fbPage(pr.key).filter(c => c.status === 'open' || c.decision || c.id === fb.open);
    for (const name of Object.keys(FR)) {
        if (!FR[name].ready) continue;
        const items = list.map(c => {
            const a = c.anchor || {};
            const here = c.frame === name;
            return { id: c.id, n: fb.num.get(c.id) || '•', kind: fbKind(c), selector: a.selector, x: a.x, y: a.y, page_x: here ? a.page_x : null, page_y: here ? a.page_y : null, label: `Comment ${fb.num.get(c.id) || ''} from ${(c.author || {}).name || 'someone'}` };
        });
        if (fb.draft && fb.draft.page === pr.key && fb.draft.frame === name) {
            const a = fb.draft.anchor;
            items.push({ id: 'draft', n: '+', kind: 'draft', selector: a.selector, x: a.x, y: a.y, page_x: a.page_x, page_y: a.page_y, label: 'Your new comment' });
        }
        FR[name].el.contentWindow.postMessage({ type: 'cpins', items, show: state.pins, hot: fb.open, zoom: FR[name].s }, '*');
    }
}

/* Choosing a spot */

// The panel on its Comments tab, for this page.
function fbToComments() {
    state.notes = true;
    state.railTab = 'comments';
    state.fbList = 'page';
    save();
}

function fbStartComment() {
    if (!fbSigned()) { fbToComments(); fbRefresh(); fbFocus('[data-fb-form="signin"] input'); return; }
    fbPick(!fb.picking);
}



function fbPick(on) {
    fb.picking = on;
    tellFrames({ type: 'pick', on });
    fbAddButton();
}

// Add comment floats at the foot of the frames, in yellow, while the Comments panel is open. While a spot is being
// chosen it says so, and a click on it stops.
function fbAddButton() {
    const b = $('#fb-add');
    const pr = parseRoute(state.route);
    const show = FB.on && fbSigned() && state.view === 'wireframes' && state.notes && pageHasNotes(pr) && railTab(pr) === 'comments';
    b.hidden = !show;
    b.setAttribute('aria-pressed', String(fb.picking));
    b.innerHTML = fb.picking ? 'Click where your comment belongs<span class="float-cancel">Cancel</span>' : `<svg width="14" height="14" viewBox="0 0 16 16" aria-hidden="true"><path d="M8 3v10M3 8h10" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>Add comment`;
    if (!show) return;
    const r = $('#frames-wrap').getBoundingClientRect();
    b.style.left = `${r.left + r.width / 2}px`;
}

function fbPicked(frame, anchor) {
    fbPick(false);
    const pr = parseRoute(state.route);
    fb.draft = { page: pr.key, route: state.route, frame, anchor: anchor || {} };
    fb.open = null;
    fb.error = '';
    fbToComments();
    fbRefresh();
    fbFocus('[data-fb-input="new"]');
}

// A pin dragged to a new spot. A new comment's pin just moves; a comment's is saved, and goes back if that fails.
function fbMoved(frame, id, anchor) {
    if (id === 'draft') {
        if (fb.draft) { fb.draft.anchor = anchor || {}; fb.draft.frame = frame; }
        fbSendPins();
        return;
    }
    const c = fb.comments.find(x => x.id === id);
    if (!c || !fbSigned()) { fbSendPins(); return; }
    const before = { anchor: c.anchor, frame: c.frame };
    c.anchor = fbAnchor(anchor || {});
    c.frame = frame;
    fbSendPins();
    fbApi('POST', `comments/${id}/anchor`, { anchor: c.anchor, frame })
        .then(res => { fbPut(res.comment); fbSendPins(); })
        .catch(err => { Object.assign(c, before); fbSendPins(); toast(`The pin couldn’t be moved. ${err.message}`); });
}

function fbFocus(sel) {
    requestAnimationFrame(() => { const el = $(sel); if (el) { el.scrollIntoView({ block: 'nearest' }); el.focus(); } });
}

/* Opening a thread */

function fbOpen(id) {
    if (!fb.comments.some(c => c.id === id)) return;
    fb.open = id;
    fb.deciding = null;
    fbToComments();
    fbRefresh();
    tellFrames({ type: 'cpin-hot', id });
    requestAnimationFrame(() => { const el = $(`#rail [data-fb="${id}"]`); if (el) el.scrollIntoView({ block: 'nearest' }); });
}

// Opening a comment reveals its pin, scrolling the frames only when it's out of sight; one on another page takes you
// there.
function fbToggle(id) {
    const c = fb.comments.find(x => x.id === id);
    if (fb.open !== id && c && (c.page !== parseRoute(state.route).key || state.view !== 'wireframes')) { fbShow(id); return; }
    fb.open = fb.open === id ? null : id;
    fb.deciding = null;
    fbRefresh();
    tellFrames({ type: 'cpin-hot', id: fb.open });
    if (fb.open) tellFrames({ type: 'cpin-show', id: fb.open, soft: true });
}

function fbMenus() {
    $$('.fbc-menu').forEach(m => { m.hidden = true; });
    $$('[data-fb-menu]').forEach(b => b.setAttribute('aria-expanded', 'false'));
}

// Takes you to the comment on its page, from the panel or the Feedback tab.
function fbShow(id) {
    const c = fb.comments.find(x => x.id === id);
    if (!c) return;
    const there = parseRoute(state.route).key === c.page && state.view === 'wireframes';
    fb.open = id;
    state.notes = true;
    state.railTab = 'comments';
    if (!there) {
        endJourney(true);
        const r = ROUTES.find(x => x.key === c.page);
        navigate(c.route || (r ? sampleRoute(r) : '/'));
    }
    fbRefresh();
    setTimeout(() => tellFrames({ type: 'cpin-show', id }), there ? 0 : 600);
    requestAnimationFrame(() => { const el = $(`#rail [data-fb="${id}"]`); if (el) el.scrollIntoView({ block: 'nearest' }); });
}

/* Writing */

function fbPut(comment) {
    const i = fb.comments.findIndex(c => c.id === comment.id);
    if (i >= 0) fb.comments[i] = comment; else fb.comments.unshift(comment);
}

// Only the parts of the spot the server keeps, within its limits.
function fbAnchor(a) {
    const unit = v => typeof v === 'number' && isFinite(v) ? Math.max(0, Math.min(1, v)) : null;
    return {
        selector: typeof a.selector === 'string' ? a.selector.slice(0, 1000) : null,
        x: unit(a.x), y: unit(a.y), page_x: unit(a.page_x),
        page_y: typeof a.page_y === 'number' && isFinite(a.page_y) ? Math.max(0, Math.round(a.page_y)) : null,
        label: typeof a.label === 'string' ? a.label.slice(0, 300) : null,
        text: typeof a.text === 'string' ? a.text.slice(0, 300) : null
    };
}

function fbRun(request, form, key) {
    const buttons = form ? $$('button', form) : [];
    buttons.forEach(b => { b.disabled = true; });
    fb.error = '';
    return request
        .then(() => { fb.errorFor = null; })
        .catch(err => { fb.error = err.message; fb.errorFor = key; })
        .then(() => { buttons.forEach(b => { b.disabled = false; }); fbRefresh(); });
}

function fbClear(key) {
    const el = $(`[data-fb-input="${key}"]`);
    if (el) el.value = '';
}

function fbSubmit(f) {
    const kind = f.dataset.fbForm, id = f.dataset.id;
    const val = sel => ((f.querySelector(sel) || {}).value || '').trim();
    if (kind === 'signin') {
        const pass = f.querySelector('[name="password"]');
        return fbRun(fbApi('POST', 'sign-in', { name: val('[name="name"]') || null, email: val('[name="email"]') || null, password: pass ? pass.value : null })
            .then(() => fbApi('GET', 'session'))
            .then(s => { fb.session = s; fbViewer(); return fbLoad(); }), f, 'signin');
    }
    if (kind === 'new') {
        const body = val('textarea');
        if (!body || !fb.draft) return;
        const d = fb.draft;
        const pr = parseRoute(d.route);
        return fbRun(fbApi('POST', 'comments', {
            context: 'prototype', version: VERSION.id, page: d.page, route: d.route, frame: d.frame,
            url: `/prototype/${VERSION.id}${pr.path === '/' ? '' : pr.path}`, title: pr.r.title, body,
            anchor: fbAnchor(d.anchor),
            options: optionsShowing(d.page, d.frame),
            viewport: { width: FR[d.frame].w, height: FR[d.frame].h, breakpoint: d.frame },
            decision: !!(f.querySelector('[name="decision"]') || {}).checked
        }).then(res => { fb.draft = null; fbPut(res.comment); fb.open = res.comment.id; fbClear('new'); }), f, 'new');
    }
    if (kind === 'reply') {
        const body = val('textarea');
        if (!body) return;
        return fbRun(fbApi('POST', `comments/${id}/replies`, { body }).then(res => { fbPut(res.comment); fbClear(`reply-${id}`); }), f, id);
    }
    if (kind === 'outcome') {
        const outcome = val('textarea');
        if (!outcome) return;
        return fbRun(fbApi('POST', `comments/${id}/decision`, { state: 'decided', outcome }).then(res => { fb.deciding = null; fbPut(res.comment); fbClear(`outcome-${id}`); }), f, id);
    }
}

function fbAct(act, id) {
    const post = (path, body) => fbRun(fbApi('POST', `comments/${id}/${path}`, body).then(res => fbPut(res.comment)), null, id);
    if (act === 'comment') fbStartComment();
    else if (act === 'cancel-new') { fb.draft = null; fb.error = ''; fbRefresh(); }
    else if (act === 'resolve') post('resolve');
    else if (act === 'reopen') {
        const c = fb.comments.find(x => x.id === id) || {};
        const steps = [];
        if (fbIsDecided(c)) steps.push(() => fbApi('POST', `comments/${id}/decision`, { state: 'open' }));
        if (c.status === 'resolved') steps.push(() => fbApi('POST', `comments/${id}/reopen`, {}));
        fbRun(steps.reduce((p, step) => p.then(step).then(res => fbPut(res.comment)), Promise.resolve()), null, id);
    }
    else if (act === 'raise') post('decision', { state: 'open' });
    else if (act === 'drop') post('decision', { state: 'none' });
    else if (act === 'decide') { fb.deciding = id; fbRefresh(); fbFocus(`[data-fb-input="outcome-${id}"]`); }
    else if (act === 'cancel-decide') { fb.deciding = null; fbRefresh(); }
}

function fbClick(t) {
    if (t.id === 'fb-comment') { togglePanel('comments'); return true; }
    if (t.id === 'fb-add') { fbStartComment(); return true; }
    if (t.hasAttribute('data-fb-menu')) {
        const menu = t.nextElementSibling;
        const open = menu.hidden;
        fbMenus();
        menu.hidden = !open;
        t.setAttribute('aria-expanded', String(open));
        return true;
    }
    if (t.dataset.fbToggle) { fbToggle(t.dataset.fbToggle); return true; }
    if (t.dataset.fbAct) { fbAct(t.dataset.fbAct, t.dataset.id); return true; }
    if (t.dataset.fbFilter) { state.fbShow = t.dataset.fbFilter; save(); renderRail(); return true; }
    if (t.dataset.fbList) { state.fbList = t.dataset.fbList; save(); renderRail(); $('#rail').scrollTop = 0; return true; }
    if (t.dataset.railTab) { state.railTab = t.dataset.railTab; save(); renderRail(); return true; }
    return false;
}

/* Drawing */

const fbError = key => fb.error && fb.errorFor === key ? `<p class="fb-error" role="alert">${esc(fb.error)}</p>` : '';

function fbSignIn() {
    const s = fb.session || {};
    return `<form class="fb-form" data-fb-form="signin">
        <p class="fb-lead">Say who you are to see and leave comments.</p>
        ${s.needs_email
            ? '<input class="fb-input" type="email" name="email" placeholder="Your email address" aria-label="Your email address" autocomplete="email" required>'
            : '<input class="fb-input" name="name" placeholder="Your name" aria-label="Your name" autocomplete="name" maxlength="80" required>'}
        ${s.needs_password ? '<input class="fb-input" type="password" name="password" placeholder="Password" aria-label="Password" autocomplete="current-password" required>' : ''}
        ${fbError('signin')}
        <div class="fb-row"><button type="submit" class="btn primary">Continue</button></div>
    </form>`;
}

function fbCompose() {
    return `<form class="fb-form fb-compose" data-fb-form="new">
        <p class="fb-where"><span class="fbc-n fbc-n--draft">+</span>New comment, at the + on the page</p>
        <textarea class="fb-input" data-fb-input="new" rows="3" maxlength="5000" placeholder="What would you change, or what do you think?" aria-label="Your comment" required></textarea>
        ${fbStaff() ? '<label class="toggle"><input type="checkbox" name="decision"> Make it a decision</label>' : ''}
        ${fbError('new')}
        <div class="fb-row"><button type="submit" class="btn primary">Post</button><button type="button" class="btn" data-fb-act="cancel-new">Cancel</button></div>
    </form>`;
}

// Only a decision is labelled, on the card's top right corner: amber while it's to make, green with a tick once done.
function fbState(c) {
    if (!c.decision) return '';
    return `<span class="fbc-state ${fbIsDone(c) ? 'done' : 'decide'}">${fbIsDone(c) ? '✓ ' : ''}Decision</span>`;
}

function fbCard(c) {
    const open = fb.open === c.id;
    const kind = fbKind(c);
    const n = fb.num.get(c.id);
    const d = c.decision;
    const author = (c.author || {}).name || 'Someone';
    const replies = c.replies || [];
    return `<article class="fbc fbc--${kind}${open ? ' is-open' : ''}" data-fb="${c.id}">
        <button type="button" class="fbc-head" data-fb-toggle="${c.id}" aria-expanded="${open}" aria-label="${open ? 'Close' : 'Open'} comment ${n || ''} from ${esc(author)}">
            <span class="fbc-n fbc-n--${kind}">${n || '•'}</span>
            <span class="fbc-meta"><b>${esc(author)}</b><span>${fbDate(c.created_at)}${c.version !== VERSION.id ? ` · ${esc(fbVersionLabel(c.version))}` : ''}</span></span>
            ${fbState(c)}
            <span class="fbc-chev" aria-hidden="true">${ICON.chev}</span>
        </button>
        <div class="fbc-main">
            <p class="fbc-body${open ? '' : ' clamp'}">${esc(c.body)}</p>
            ${c.options && typeof c.options === 'object' && Object.keys(c.options).length ? `<p class="fbc-opts">With ${Object.entries(c.options).map(([k, v]) => `${esc(k)}: ${esc(v)}`).join(' · ')}</p>` : ''}
            ${fbIsDecided(c) ? `<div class="fbc-outcome"><b>Decided</b><p>${esc(d.outcome)}</p><small>${esc((d.decided_by || {}).name || '')}${d.decided_at ? ` · ${fbDate(d.decided_at)}` : ''}</small></div>` : ''}
            ${!open && replies.length ? `<p class="fbc-more">${replies.length} ${replies.length === 1 ? 'reply' : 'replies'}</p>` : ''}
            ${open ? fbThread(c) : ''}
        </div>
    </article>`;
}

// What can be done with a comment: the one next step as a button, anything else the team can do in a menu.
function fbActions(c) {
    const staff = fbStaff();
    const more = [];
    let next = null;
    if (fbIsDecided(c)) { if (staff) next = ['reopen', 'Reopen']; }
    else if (c.status === 'resolved') next = ['reopen', 'Reopen'];
    else if (c.decision) { if (staff) { next = ['decide', 'Record decision']; more.push(['resolve', 'Mark done']); } }
    else next = ['resolve', 'Mark done'];
    if (staff) more.push(c.decision ? ['drop', 'Make a comment'] : ['raise', 'Make it a decision']);
    return { next, more };
}

function fbThread(c) {
    const replies = (c.replies || []).map(r => `<li><p class="fbc-meta"><b>${esc((r.author || {}).name || 'Someone')}</b><span>${fbDate(r.created_at)}</span></p><p class="fbc-body">${esc(r.body)}</p></li>`).join('');
    const { next, more } = fbActions(c);
    const acts = fb.deciding === c.id ? '' : `${next ? `<button type="button" class="btn small" data-fb-act="${next[0]}" data-id="${c.id}">${next[1]}</button>` : ''}
        ${more.length ? `<span class="fbc-more"><button type="button" class="icon-btn tiny" data-fb-menu aria-expanded="false" aria-label="More actions" title="More actions">${ICON.more}</button>
            <span class="fbc-menu" hidden>${more.map(([act, label]) => `<button type="button" data-fb-act="${act}" data-id="${c.id}">${label}</button>`).join('')}</span></span>` : ''}`;
    return `${c.decision && c.decision.who ? `<p class="fbc-who">Who decides: ${esc(c.decision.who)}</p>` : ''}
        ${replies ? `<ul class="fbc-replies">${replies}</ul>` : ''}
        ${fb.deciding === c.id ? `<form class="fb-form" data-fb-form="outcome" data-id="${c.id}">
            <textarea class="fb-input" data-fb-input="outcome-${c.id}" rows="2" maxlength="5000" placeholder="What was decided" aria-label="What was decided" required></textarea>
            <div class="fb-row"><button type="submit" class="btn small primary">Record decision</button><button type="button" class="link-btn" data-fb-act="cancel-decide" data-id="${c.id}">Cancel</button></div>
        </form>` : ''}
        <form class="fb-form fb-reply" data-fb-form="reply" data-id="${c.id}">
            <textarea class="fb-input" data-fb-input="reply-${c.id}" rows="1" maxlength="5000" placeholder="Reply" aria-label="Reply" required></textarea>
            <div class="fbc-bar"><button type="submit" class="btn small fb-send">Reply</button><span class="fbc-acts">${acts}</span></div>
        </form>
        ${fbError(c.id)}`;
}

// Comments: this page's, or all the comments on the prototype. Signing in comes first.
function fbPanel(pr) {
    if (!fb.session) return '<p class="note-line">Loading the comments…</p>';
    if (!fbSigned()) return `<section class="fb-section">${fbSignIn()}</section>`;
    return (state.fbList === 'all' ? fbAll() : fbSection(pr)) + fbFoot();
}

// The pins switch, pinned to the foot of the panel over whatever scrolls beneath it.
function fbFoot() {
    return `<div class="fb-foot"><label class="toggle"><input type="checkbox" data-fb-pins${state.pins ? ' checked' : ''}> Show comment pins</label></div>`;
}

// This page or all feedback, and the Comment button.
function fbScopeBar() {
    const all = state.fbList === 'all';
    const open = fbFiltered('open', 'version').length;
    return `<div class="fb-h">
        <p class="fb-scope">${all ? '<button type="button" class="link-btn" data-fb-list="page">This page</button>' : '<b>This page</b>'}<span aria-hidden="true">·</span>${all ? '<b>All comments</b>' : `<button type="button" class="link-btn" data-fb-list="all">All comments${open ? ` (${open} open)` : ''}</button>`}</p>
    </div>`;
}

// The page's comments: decisions to make, then open comments, with everything done folded away.
function fbSection(pr) {
    const list = fbPage(pr.key).sort((a, b) => (fb.num.get(a.id) || 0) - (fb.num.get(b.id) || 0));
    const toDecide = list.filter(fbToDecide);
    const open = list.filter(c => !c.decision && c.status === 'open');
    const done = list.filter(fbIsDone);
    return `<section class="fb-section">
        ${fbScopeBar()}
        ${fb.draft && fb.draft.page === pr.key ? fbCompose() : ''}
        ${!list.length && !fb.draft ? '<p class="note-line">No comments on this page yet. Press Comment, then click the spot you mean on either page.</p>' : ''}
        ${[...toDecide, ...open].map(c => fbCard(c)).join('')}
        ${done.length ? `<details class="fold fb-resolved"${done.some(c => c.id === fb.open) ? ' open' : ''}><summary>Done <span class="fold-n">${done.length}</span></summary><div>${done.map(c => fbCard(c)).join('')}</div></details>` : ''}
    </section>`;
}

const FB_FILTERS = [['open', 'Open'], ['decide', 'Decisions']];
const FB_EMPTY = { open: 'No comments yet.', decide: 'No decisions yet.' };

// All the feedback on the prototype, page by page, filtered to what needs doing. Decisions from earlier versions are
// always in it; their other comments only when asked for.
// Each page's comments open or to decide, with those done folded away beneath, as on the page.
function fbAll() {
    const show = state.fbShow === 'decide' ? 'decide' : 'open';
    const scope = state.fbScope === 'all' ? 'all' : 'version';
    const byNum = (a, b) => (fb.num.get(a.id) || 0) - (fb.num.get(b.id) || 0);
    const list = fbFiltered(show, scope);
    // Done beneath: every comment done, or with Decisions, the decisions made.
    const done = fbFiltered('done', scope).filter(c => show === 'open' || c.decision);
    const pages = [...list, ...done].map(c => c.page);
    const keys = [...ROUTES.map(r => r.key), ...new Set(pages.filter(k => !ROUTES.some(r => r.key === k)))];
    const groups = keys.map(key => [key, list.filter(c => c.page === key).sort(byNum), done.filter(c => c.page === key).sort(byNum)]).filter(([, cs, ds]) => cs.length || ds.length);
    const title = key => (ROUTES.find(r => r.key === key) || {}).title || key;
    const route = key => { const r = ROUTES.find(x => x.key === key); return r ? sampleRoute(r) : null; };
    const here = parseRoute(state.route).key;
    return `<section class="fb-section">
        ${fbScopeBar()}
        <div class="fb-filters" role="group" aria-label="Show">${FB_FILTERS.map(([k, label]) => { const n = fbFiltered(k, scope).length; return `<button type="button" class="chip-btn" data-fb-filter="${k}" aria-pressed="${show === k}">${label}${n ? ` <span class="muted">${n}</span>` : ''}</button>`; }).join('')}</div>
        ${VERSIONS.length > 1 ? `<label class="toggle"><input type="checkbox" id="fb-versions"${scope === 'all' ? ' checked' : ''}> Comments on earlier versions too</label>` : ''}
        ${groups.length ? groups.map(([key, cs, ds]) => `<div class="fb-group">
            <div class="fb-group-h"><h3>${key !== here && route(key) ? `<button type="button" class="fb-page" data-go="${esc(route(key))}">${esc(title(key))}<span aria-hidden="true">→</span></button>` : esc(title(key))}</h3>${key === here ? '<span class="fb-here">Showing</span>' : ''}</div>
            ${cs.map(c => fbCard(c)).join('')}
            ${ds.length ? `<details class="fold fb-resolved"${ds.some(c => c.id === fb.open) ? ' open' : ''}><summary>Done <span class="fold-n">${ds.length}</span></summary><div>${ds.map(c => fbCard(c)).join('')}</div></details>` : ''}
        </div>`).join('') : `<p class="note-line">${FB_EMPTY[show]}</p>`}
    </section>`;
}

// Comment pins: the same switch in the Comments tab and in Display options.
function setPins(on) {
    state.pins = on;
    save();
    $('#pins').checked = on;
    $$('[data-fb-pins]').forEach(i => { i.checked = on; });
    fbSendPins();
}
document.addEventListener('change', e => {
    if (e.target.matches('[data-fb-pins]')) { setPins(e.target.checked); return; }
    if (e.target.id !== 'fb-versions') return;
    state.fbScope = e.target.checked ? 'all' : 'version';
    save();
    renderRail();
});
document.addEventListener('submit', e => {
    const f = e.target.closest('[data-fb-form]');
    if (!f) return;
    e.preventDefault();
    fbSubmit(f);
});
// A folded card opens from anywhere on it.
document.addEventListener('click', e => {
    const card = e.target.closest('.fbc:not(.is-open)');
    if (card && !e.target.closest('button, a, textarea, input, label, summary')) fbToggle(card.dataset.fb);
});
// Pointing at a comment in the panel lights up its pin.
$('#rail').addEventListener('mouseover', e => {
    const card = e.target.closest('[data-fb]');
    const id = card ? card.dataset.fb : null;
    if (id === fb.hover) return;
    fb.hover = id;
    tellFrames({ type: 'cpin-hot', id: id || fb.open });
});
$('#rail').addEventListener('mouseleave', () => { fb.hover = null; tellFrames({ type: 'cpin-hot', id: fb.open }); });
// Coming back to the tab picks up comments made since.
document.addEventListener('visibilitychange', () => { if (!document.hidden && fbSigned()) fbLoad(); });

/* ---------- Page options ---------- */

// Ways a page can be shown, for reviewers to compare before choosing: OPTIONS in data.js, by page. Each switches both
// frames, or the one its frame names; one marked mode sets the timeline's layout. The bar above the frames shows them
// on pages that have any, folded to one line of what's showing until opened, and a comment records which were showing.
const PAGE_OPTIONS = typeof OPTIONS === 'undefined' ? {} : OPTIONS;
const pageOptions = key => (PAGE_OPTIONS[key] || []).filter(o => o && o.id && Array.isArray(o.choices) && o.choices.length);
const optsOpen = () => state.optsOpen === true;

function optionValue(key, o) {
    const v = ((state.opts || {})[key] || {})[o.id];
    return o.choices.some(c => c[0] === v) ? v : (o.default !== undefined ? o.default : o.choices[0][0]);
}

const choiceLabel = (o, v) => (o.choices.find(c => c[0] === v) || [v, v])[1];

// The choices that apply in one frame, by option id.
function optionValues(key, frame) {
    const values = {};
    pageOptions(key).filter(o => !o.frame || o.frame === frame).forEach(o => { values[o.id] = optionValue(key, o); });
    return values;
}

// The timeline's layout in a frame: its page's mode option, or the frame's own default.
function modeFor(key, frame) {
    const o = pageOptions(key).find(x => x.mode && (!x.frame || x.frame === frame));
    return o ? optionValue(key, o) : state.modes[frame];
}

// What was showing when a comment was made, in words, for the comment to keep.
function optionsShowing(key, frame) {
    const showing = {};
    pageOptions(key).filter(o => !o.frame || o.frame === frame).forEach(o => { showing[String(o.label).slice(0, 100)] = String(choiceLabel(o, optionValue(key, o))).slice(0, 100); });
    return Object.keys(showing).length ? showing : null;
}

function setOption(id, value) {
    const key = parseRoute(state.route).key;
    const o = pageOptions(key).find(x => x.id === id);
    if (!o) return;
    state.opts = state.opts || {};
    state.opts[key] = Object.assign({}, state.opts[key], { [id]: value });
    save();
    renderOptions();
    for (const name of Object.keys(FR)) {
        if (o.frame && o.frame !== name) continue;
        // A layout keeps the reader's place; anything else draws the page again.
        if (o.mode && FR[name].ready) FR[name].el.contentWindow.postMessage({ type: 'mode', mode: value }, '*');
        else renderFrame(name);
    }
    fbSendPins();
}

function renderOptions() {
    const bar = $('#opts-bar');
    const key = parseRoute(state.route).key;
    const opts = pageOptions(key);
    bar.hidden = !opts.length;
    if (!opts.length) { bar.innerHTML = ''; return; }
    const open = optsOpen();
    const summary = opts.map(o => `${o.label}: ${choiceLabel(o, optionValue(key, o))}`).join('  ·  ');
    bar.innerHTML = `<button type="button" class="opts-head" data-opts-toggle aria-expanded="${open}">
            <span class="opts-title">Options on this page</span>${open ? '' : `<span class="opts-sum">${esc(summary)}</span>`}<span class="opts-act">${open ? 'Close' : 'Show options'}</span>
        </button>
        ${open ? `<div class="opts-body">
            ${opts.map(o => `<div class="opt"><span class="opt-l">${esc(o.label)}</span>
                <div class="seg" role="group" aria-label="${esc(o.label)}">${o.choices.map(([v, l]) => `<button type="button" data-opt="${esc(o.id)}" data-value="${esc(v)}" aria-pressed="${optionValue(key, o) === v}">${esc(l)}</button>`).join('')}</div></div>`).join('')}
        </div>` : ''}`;
    if (state.view === 'wireframes') requestAnimationFrame(layoutFrames);
}

/* ---------- Events ---------- */

document.addEventListener('click', e => {
    if (!e.target.closest('.version')) versionMenu(false);
    // A click on the diagram's empty space clears the selection.
    if (state.view === 'model' && state.entity && e.target.closest('#view-model') && !e.target.closest('.ent, button, a, summary')) { clearEntity(); return; }
    if (!e.target.closest('.display')) displayMenu(false);
    if (!e.target.closest('.fbc-more')) fbMenus();
    const t = e.target.closest('button');
    if (!t) return;
    if (fbClick(t, e)) return;
    if (t.id === 'version-btn') { versionMenu($('#version-menu').hidden); return; }
    if (t.id === 'display-btn') { displayMenu($('#display-menu').hidden); return; }
    if (t.id === 'notes-btn') { togglePanel('notes'); return; }
    // A frame's name shows its size; pointing at it does too.
    if (t.classList.contains('device-name')) { const on = t.getAttribute('aria-expanded') !== 'true'; t.setAttribute('aria-expanded', String(on)); t.parentElement.classList.toggle('show-dims', on); return; }
    if (t.id === 'help-open') { openHelp(); return; }
    if (t.id === 'help-close' || t.id === 'help-done') { closeHelp(); return; }
    if (t.id === 'nav-toggle') { $('#sidebar').classList.contains('open') ? closeSidebar() : openSidebar(); return; }
    if (t.hasAttribute('data-journey-end')) { endJourney(); return; }
    if (t.dataset.view) { if (t.dataset.view === 'model') state.entity = null; setView(t.dataset.view); return; }
    if (t.dataset.frames) { setFrames(t.dataset.frames); return; }
    if (t.dataset.go) { endJourney(true); navigate(t.dataset.go); return; }
    if (t.dataset.entity && VIEWS.model) { state.entity = t.dataset.entity; setView('model'); return; }
    // Selecting an item again clears it.
    if (t.dataset.ent) { state.entity = state.entity === t.dataset.ent ? null : t.dataset.ent; save(); drawRels(); renderRail(); return; }
    if (t.hasAttribute('data-ent-clear')) { clearEntity(); return; }
    if (t.dataset.side) { setSide(t.dataset.side === 'open'); return; }
    if (t.dataset.notes) { setNotes(t.dataset.notes === 'open'); return; }
    if (t.dataset.journey) {
        // The active journey's row opens and closes its steps.
        if (state.step >= 0 && state.journey === t.dataset.journey) { state.jopen = state.jopen === false; renderSidebar(); return; }
        state.journey = t.dataset.journey; state.jopen = true; save();
        if (!state.framesBefore) state.framesBefore = state.frames;
        setFrames(activeJourney().device);
        goStep(0); return;
    }
    if (t.dataset.step) { goStep(Number(t.dataset.step)); return; }
    if (t.dataset.jstep) {
        const next = state.step + Number(t.dataset.jstep);
        if (next >= activeJourney().steps.length) finishJourney(); else goStep(Math.max(0, next));
        return;
    }
    if (t.hasAttribute('data-opts-toggle')) { state.optsOpen = !optsOpen(); save(); renderOptions(); return; }
    if (t.dataset.opt) { setOption(t.dataset.opt, t.dataset.value); return; }
});

$('#scrim').addEventListener('click', closeSidebar);
$('#help').addEventListener('click', e => { if (e.target.id === 'help') closeHelp(); });
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') {
        versionMenu(false);
        displayMenu(false);
        if (fb.picking) fbPick(false);
        else if (!$('#help').hidden) closeHelp();
        else if (state.view === 'model' && state.entity) clearEntity();
        else closeSidebar();
        return;
    }
    if (state.step < 0 || !$('#help').hidden || e.target.closest('input, select, textarea, [contenteditable]')) return;
    if (e.key === 'ArrowRight') { e.preventDefault(); const next = state.step + 1; if (next >= activeJourney().steps.length) finishJourney(); else goStep(next); }
    if (e.key === 'ArrowLeft' && state.step > 0) { e.preventDefault(); goStep(state.step - 1); }
});

/* ---------- Versions ---------- */

// VERSION is this build's own, from its version.json. Served by the site, PROTOTYPE_VERSIONS lists every version
// with its address; opened as a file or an Artifact, there is only this one.
const VERSIONS = window.PROTOTYPE_VERSIONS || [Object.assign({ url: null }, VERSION)];
const longDate = d => d ? new Date(`${d}T00:00:00`).toLocaleDateString('en-NZ', { day: 'numeric', month: 'long', year: 'numeric' }) : '';

function renderVersion() {
    const menu = VERSIONS.length > 1 || VERSIONS.some(v => v.notes && v.notes.length);
    const b = $('#version-btn');
    b.innerHTML = `${esc(VERSION.label)}${menu ? '<span aria-hidden="true">▾</span>' : ''}`;
    b.disabled = !menu;
    b.title = longDate(VERSION.date);
    $('#version-menu').innerHTML = `<p class="vm-h">Versions</p><ul>${[...VERSIONS].reverse().map(v => {
        const on = v.id === VERSION.id;
        const inner = `<span class="vm-t"><b>${esc(v.label)}</b>${on ? '<span class="vm-on">Viewing</span>' : ''}</span>${v.date ? `<span class="vm-d">${longDate(v.date)}</span>` : ''}${v.notes && v.notes.length ? `<ul class="vm-n">${v.notes.map(n => `<li>${esc(n)}</li>`).join('')}</ul>` : ''}`;
        return `<li>${on || !v.url ? `<div class="vm-i${on ? ' on' : ''}">${inner}</div>` : `<a class="vm-i" href="${esc(v.url)}">${inner}</a>`}</li>`;
    }).join('')}</ul>`;
}
function versionMenu(open) {
    const m = $('#version-menu');
    if (!m || $('#version-btn').disabled) return;
    m.hidden = !open;
    $('#version-btn').setAttribute('aria-expanded', String(open));
}

function displayMenu(open) {
    const m = $('#display-menu');
    if (!m) return;
    m.hidden = !open;
    $('#display-btn').setAttribute('aria-expanded', String(open));
}

function setFrames(f) {
    state.frames = f;
    save();
    $$('#frame-pick [data-frames]').forEach(b => b.setAttribute('aria-pressed', String(b.dataset.frames === f)));
    // The button says what's showing: Display: Both, Desktop, Mobile or another device the site adds.
    $('#display-label').textContent = `Display: ${f !== 'both' && DEVICE[f] ? DEVICE[f] : 'Both'}`;
    layoutFrames();
}

// A click inside a frame never reaches this page, but it takes the focus from it, so open menus close then too.
window.addEventListener('blur', () => { displayMenu(false); versionMenu(false); fbMenus(); });

$('#sync').addEventListener('change', e => {
    state.sync = e.target.checked;
    save();
    tellFrames({ type: 'sync', on: state.sync });
});

function setNotes(on) {
    state.notes = on;
    save();
    renderRail();
    if (state.view === 'wireframes') requestAnimationFrame(layoutFrames);
    if (on) $('#rail').scrollTop = 0;
}

$('#pins').addEventListener('change', e => setPins(e.target.checked));

/* ---------- Start ---------- */

// The site's name is APP_NAME from .env, unless data.js names it (a name still in [brackets] is a placeholder).
const SITE_NAME = PROJECT.name && !/^\[.*\]$/.test(PROJECT.name) ? PROJECT.name : (window.PROTOTYPE_SITE || PROJECT.name || 'Website');
document.title = `${VERSION.label} · ${SITE_NAME} prototype`;
$('#brand-name').textContent = SITE_NAME;
$('#brand-sub').textContent = PROJECT.subtitle;
$('#prepared').textContent = PROJECT.prepared;
if (!AGENCY.url) { $('.agency').removeAttribute('href'); $('.agency').removeAttribute('target'); }
// Who signed in on the site's sign-in page. Opened as a file or an Artifact, there is no one.
const viewer = window.PROTOTYPE_VIEWER;
if (viewer && viewer.name) {
    $('#viewer').innerHTML = `<b title="Signed in as ${esc(viewer.name)}">${esc(viewer.name)}</b><a href="/prototype/sign-out">Sign out</a>`;
    $('#viewer').hidden = false;
}
$('#sync').checked = state.sync;
$('#pins').checked = state.pins;
const modelTab = $('[data-view="model"]');
modelTab.hidden = !VIEWS.model;
// The team sees the content model before anyone else does, and the tab says so.
if (VIEWS.model_team_only) { modelTab.insertAdjacentHTML('beforeend', '<span class="team-only">Team</span>'); modelTab.title = 'Only the team sees this. PROTOTYPE_CONTENT_MODEL=everyone shares it.'; }
renderVersion();
fbStart();
renderSidebar();
makeFrames();
setFrames(state.frames);
new ResizeObserver(() => { if (state.view === 'wireframes') layoutFrames(); if (state.view === 'model') drawRels(); if (state.view === 'sitemap') sitemapFade(); }).observe($('#stage'));
new ResizeObserver(() => { if (state.view === 'wireframes') layoutFrames(); }).observe($('#frames-wrap'));
const startView = state.view;
navigate(state.route || '/', { stay: true });
setView(startView);
if (!state.welcomed) openHelp();
