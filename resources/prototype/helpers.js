/* ---------- Helpers for drawing pages ----------
   From the add-on, for every site's prototype. They are function declarations, so a site's pages.js can
   redefine one to suit its design.
   ---------- */

/* ---------- Site markup, rendered into both frames ----------
   The site's own Tailwind classes and tokens, so a page reads like the kit's blocks.
   Each page is a function in PAGES that returns markup; ROUTES gives it a URL. Mark anything a journey step
   points at with data-j="…". */

function esc(s) { return String(s ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); }
function decNum(id) { return id.replace('D', ''); }

// Numbered decision markers came from decisions written into data.js. Decisions are comments now, raised by the team
// and pinned where they were made, so a page that still calls these draws nothing.
function pin() { return ''; }
function toConfirm() { return ''; }
// The choice showing for one of the page's options (OPTIONS in data.js), for a page that draws itself differently for
// each: ${option('chapters') === 'years' ? yearsTimeline() : shiftsTimeline()}. Undefined outside a frame's drawing.
function option(id) { return window.OPTION_NOW ? window.OPTION_NOW[id] : undefined; }
function flag(text) { return `<span class="wf-flag">${esc(text)}</span>`; }
function img(label, ratio = '3/2', cls = '') {
    return `<div class="wf-img ${cls}" style="aspect-ratio:${ratio}"><span>${esc(label)}</span></div>`;
}
function gw(word, text) {
    const g = GLOSSARY.find(x => x.word === word) || { word, def: '' };
    return `<span class="glossary-word"><button type="button" class="glossary-word__trigger" aria-expanded="false">${esc(text || g.word)}</button><span class="glossary-word__panel" role="tooltip"><span class="glossary-word__term">${esc(g.word)}${g.type ? ` <span class="glossary-word__type">${esc(g.type)}</span>` : ''}</span>${g.also ? `<span class="glossary-word__alternatives">Also: ${esc(g.also)}</span>` : ''}<span>${esc(g.def)}</span></span></span>`;
}
function btn(label, href, style = 'primary') {
    if (style === 'inline') return `<a class="btn-inline" href="${href}"><span>${esc(label)}</span></a>`;
    return `<a class="btn btn-${style}" href="${href}"><span>${esc(label)}</span></a>`;
}
function crumbs(items) {
    return `<nav class="breadcrumbs" aria-label="Breadcrumb">${items.map((c, i) => i < items.length - 1 ? `<a href="${c[1]}">${esc(c[0])}</a><span aria-hidden="true">/</span>` : `<span aria-current="page">${esc(c[0])}</span>`).join('')}</nav>`;
}
function sectionHead(h, sub, extra = '') {
    return `<header class="span-content flex flex-col gap-2 mb-8">
        <h2>${h}${extra}</h2>${sub ? `<p class="text-gray-600 max-w-[60ch]">${sub}</p>` : ''}
    </header>`;
}
function statTiles(items, cls = 'md:grid-cols-4') {
    return `<ul class="grid gap-6 grid-cols-2 ${cls}">${items.map(s => `<li class="flex flex-col gap-2 border-t-2 border-current pt-4"><span class="stat">${s[0]}</span><span class="text-sm">${s[1]}</span>${s[2] ? `<span class="text-xs opacity-70">Source: ${s[2]}</span>` : ''}</li>`).join('')}</ul>`;
}
function shareRow(pins = '') {
    return `<div class="flex flex-wrap items-center gap-2" data-j="share">
        <span class="text-xs font-bold mr-1">Share</span>
        <a class="chip lg:hidden" href="#share">Share…</a><a class="chip" href="#share">Copy link</a><a class="chip" href="#share">Facebook</a><a class="chip" href="#share">LinkedIn</a><a class="chip" href="#share">Email</a>
        ${pins}
    </div>`;
}

function pageHeader({ crumbs: c, eyebrow, title, lede, actions = '', aside = '', badge = '', pins = '' }) {
    return `<section class="fluid-grid pt-8 md:pt-14 relative" data-j="page-header">
        <div class="span-content ${aside ? 'lg:col-start-[content-start] lg:col-span-7' : 'lg:col-start-[content-start] lg:col-span-9'} flex flex-col gap-4">
            ${badge ? flag(badge) : ''}
            ${c ? crumbs(c) : ''}
            ${eyebrow ? `<p class="eyebrow">${eyebrow}</p>` : ''}
            <h1>${title}${pins}</h1>
            ${lede ? `<p class="lede max-w-[40ch]">${lede}</p>` : ''}
            ${actions ? `<div class="flex flex-wrap gap-3 mt-2">${actions}</div>` : ''}
        </div>
        ${aside ? `<div class="span-content mt-8 lg:mt-0 lg:col-start-[col-9] lg:col-span-4 lg:row-start-1 self-end">${aside}</div>` : ''}
    </section>`;
}

/* ---------- A page still to be drawn ---------- */
// Until the prototype is drawn, every page says so rather than guessing at its content. Replace these with
// pages built from page(key, inner), the helpers above, and the site's own markup.
function coming(title) {
    return `<main id="content" class="fluid-grid flex-1 content-center py-24" data-j="coming">
        <div class="span-content flex flex-col items-center gap-4 text-center">
            <p class="eyebrow">${esc(title)}</p>
            <h1>The prototype is coming</h1>
            <p class="lede max-w-[40ch]">This page will be drawn here once its content is planned.</p>
        </div>
    </main>`;
}

/* ---------- Routes ---------- */

function sampleRoute(r) {
    return r.sample ? r.path.replace(':slug', r.sample) : r.path;
}

function parseRoute(route) {
    const [path, qs] = String(route || '/').split('?');
    const params = Object.fromEntries(new URLSearchParams(qs || ''));
    const segs = path.split('/').filter(Boolean);
    for (const r of ROUTES) {
        const rs = r.path.split('/').filter(Boolean);
        if (rs.length !== segs.length) continue;
        let slug = null, ok = true;
        rs.forEach((s, i) => { if (s === ':slug') slug = segs[i]; else if (s !== segs[i]) ok = false; });
        if (ok) return { r, key: r.key, slug, params, path };
    }
    return { r: ROUTES[0], key: ROUTES[0].key, slug: null, params, path: ROUTES[0].path };
}
