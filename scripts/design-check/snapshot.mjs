#!/usr/bin/env node
/*
    Design check snapshots. scripts/design-check.sh runs this from the site's root folder with the site's
    address and pages; run it directly only to do something the shell script doesn't.

    Every block on a site's pages, in their default state:
      node snapshot.mjs --base http://site.test --page / --page /about --out storage/design-check/before

    One block in each of its states:
      node snapshot.mjs --base http://site.test --page / --out storage/design-check/before \
          --block @proof_strip --state default --state "slide2=Alpine.\$data(root.querySelector('[x-data]')).go(1)"

    Options:
      [--widths 390,1440] [--heights 844,900] [--index 0] [--name <folder>]
      [--state 'name=js'] ... [--state 'name=hover:<selector>'] [--reduced] [--scroll top|silent|none|<px>]
      [--pad 24] [--no-png] [--mask-images] [--dpr 1] [--no-repaint] [--headful] [--commit <text>] [--jobs 1]

    Without --block, every page builder block and layout partial on each page is taken: the element that
    follows each <!-- /page_builder/_<handle>.antlers.html --> and <!-- /layout/_<name>.antlers.html -->
    comment, leaving out any inside another. They are written to <out>/<page>/<handle>, and a second one
    of the same block on a page to <handle>-2. --jobs says how many blocks are taken at once (1).

    --block takes @<handle> for a page builder block, @<view path> for any other template, such as
    @layout/_header or @components/_pull_quote, or a CSS selector. A template is found by its comment,
    which no class change can move. With several pages, the block is taken from the first that has it.

    For each width and state it loads the page fresh, puts the block in its state, freezes motion, then
    records the following. Timers of a second or more never fire, so an autoplay stays where it loaded.
      - every computed style of the block, every element in it, and their ::before/::after/::marker,
        keyed by DOM path (child index + tag), never by class, so renaming classes can't hide a change;
      - each element's box relative to the block, and the block's box on the page;
      - a PNG of the block (the union of its descendants' boxes, so overflow such as rising photos is in),
        with everything outside the block hidden.

    A state's js runs in the page as the body of an async function with `root` (the block) in scope.
    A state of hover:<selector> moves the real mouse over that element inside the block instead.
    Selectors in states must not use the classes being converted either: use structure, ARIA or data.
    With no --state, a single state called "default" is taken.
    --scroll silent scrolls to the block but no scroll listener hears it, for a block's state before a
    scroll-triggered reveal; --scroll none leaves the page at the top.
    --reduced emulates prefers-reduced-motion: reduce; its files are named <width>-<state>-reduced.
    --mask-images hides <img> in the PNG only (their src, box and styles are still compared), for when
    Chrome's image resampling is not repeatable on a machine.

    Puppeteer comes from the site's own node_modules, which the starter kit lists; nothing is installed.
    Exit: 0 written, 1 a page or block failed, 2 could not run.
*/
import { createRequire } from 'node:module'
import { pathToFileURL } from 'node:url'
import { existsSync } from 'node:fs'
import { mkdir, readFile, writeFile } from 'node:fs/promises'
import path from 'node:path'

const SITE = process.cwd()
let puppeteer
try {
    const req = createRequire(path.join(SITE, 'package.json'))
    puppeteer = (await import(pathToFileURL(req.resolve('puppeteer')).href)).default
} catch {
    console.error(`No Puppeteer in ${path.join(SITE, 'node_modules')}. The starter kit lists it in package.json: run npm install.`)
    process.exit(2)
}

/* ---------- arguments ---------- */
const argv = process.argv.slice(2)
const opts = { states: [], pages: [] }
for (let i = 0; i < argv.length; i++) {
    const a = argv[i]
    const val = () => {
        if (i + 1 >= argv.length) { console.error(`${a} needs a value`); process.exit(2) }
        return argv[++i]
    }
    if (a === '--base') opts.base = val().replace(/\/+$/, '')
    else if (a === '--page') opts.pages.push(val())
    else if (a === '--block') opts.block = val()
    else if (a === '--out') opts.out = val()
    else if (a === '--name') opts.name = val()
    else if (a === '--commit') opts.commit = val()
    else if (a === '--widths') opts.widths = val().split(',').map(Number)
    else if (a === '--heights') opts.heights = val().split(',').map(Number)
    else if (a === '--index') opts.index = Number(val())
    else if (a === '--state') {
        const s = val()
        const eq = s.indexOf('=')
        opts.states.push(eq < 0 ? { name: s, js: '' } : { name: s.slice(0, eq), js: s.slice(eq + 1) })
    } else if (a === '--reduced') opts.reduced = true
    else if (a === '--scroll') opts.scroll = val()
    else if (a === '--pad') opts.pad = Number(val())
    else if (a === '--no-png') opts.png = false
    else if (a === '--dpr') opts.dpr = Number(val())
    else if (a === '--mask-images') opts.maskImages = true
    else if (a === '--no-repaint') opts.noRepaint = true
    else if (a === '--headful') opts.headful = true
    else if (a === '--jobs') opts.jobs = Number(val())
    else { console.error(`Unknown option ${a}`); process.exit(2) }
}
if (!opts.base || !opts.pages.length || !opts.out) {
    console.error('Need --base, at least one --page, and --out')
    process.exit(2)
}
if (!opts.block && (opts.states.length || opts.index !== undefined || opts.name)) {
    console.error('--state, --index and --name need --block: a state is something one block does')
    process.exit(2)
}
const DEFAULT_HEIGHTS = { 375: 812, 390: 844, 700: 1024, 768: 1024, 1100: 900, 1280: 800, 1440: 900 }
const widths = opts.widths || [390, 1440]
const heights = widths.map((w, i) => opts.heights?.[i] ?? DEFAULT_HEIGHTS[w] ?? 900)
const states = opts.states.length ? opts.states : [{ name: 'default', js: '' }]
const scroll = opts.scroll ?? 'top'
const pad = opts.pad ?? 24
const png = opts.png ?? true
const dpr = opts.dpr ?? 1
const media = opts.reduced ? 'reduce' : 'no-preference'
// Four at a time is about three times quicker, but two runs of the same code agree less often: on Whoosh,
// the Outcomes graphics and a photo in Examples came out different in every pair of runs.
const jobs = Math.max(1, opts.jobs || 1)
// A page with public/hot takes its CSS and scripts from the Vite dev server; two runs should use the same.
const assets = existsSync(path.join(SITE, 'public', 'hot')) ? 'vite dev server' : 'build'

/* ---------- names ---------- */
const pageName = (page) => page.split(/[?#]/)[0].replace(/^\/+|\/+$/g, '').replace(/[^a-zA-Z0-9_-]+/g, '-') || 'home'
const blockName = (selector, index) => {
    const base = selector.startsWith('@')
        ? selector.slice(1).replace(/\/_?/g, '-') // @proof_strip -> proof_strip, @layout/_header -> layout-header
        : selector.replace(/[^a-zA-Z0-9_-]+/g, '_').replace(/^_+|_+$/g, '')
    return index ? `${base}-${index + 1}` : base
}

/* ---------- in-page helpers (serialised into the page) ---------- */

/* Find blocks. A template's output is the element after its own <!-- /<view>.antlers.html --> comment,
   as long as the template rendered something before its <!-- End: ... --> comment. Found without relying
   on any class, so it is the same element before and after the classes change. */
function defineRoot() {
    const firstElement = (comment) => {
        const end = `End: ${comment.textContent.trim()}`
        for (let n = comment.nextSibling; n; n = n.nextSibling) {
            if (n.nodeType === Node.ELEMENT_NODE) return n
            if (n.nodeType === Node.COMMENT_NODE && n.textContent.trim() === end) return null
        }
        return null
    }
    const comments = () => {
        const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_COMMENT)
        const out = []
        while (walker.nextNode()) out.push(walker.currentNode)
        return out
    }
    const rendered = (template) => comments().filter((c) => c.textContent.trim() === template).map(firstElement).filter(Boolean)
    const template = (selector) => {
        const view = selector.slice(1)
        return view.includes('/') ? `/${view.replace(/^\/+/, '')}.antlers.html` : `/page_builder/_${view}.antlers.html`
    }

    window.__snapRoot = (selector, index) => (selector.startsWith('@') ? rendered(template(selector))[index] : document.querySelectorAll(selector)[index])

    // Every page builder block and layout partial on the page, in page order, leaving out any inside another.
    window.__snapBlocks = () => {
        const BLOCK = /^\/(?:page_builder\/_([a-z0-9_]+)|(layout\/_[a-z0-9_]+))\.antlers\.html$/i
        const found = []
        for (const t of new Set(comments().map((c) => c.textContent.trim()))) {
            const m = t.match(BLOCK)
            if (!m) continue
            const selector = `@${m[1] ?? m[2]}`
            rendered(t).forEach((el, index) => found.push({ selector, index, el }))
        }
        found.sort((a, b) => (a.el.compareDocumentPosition(b.el) & Node.DOCUMENT_POSITION_FOLLOWING ? -1 : 1))
        return found.filter((p) => !found.some((q) => q !== p && q.el.contains(p.el))).map(({ selector, index }) => ({ selector, index }))
    }
}

/* Scroll the block into place and load its fonts and images. */
async function place({ selector, index, scroll, pad }) {
    const raf = () => new Promise((r) => requestAnimationFrame(() => r()))
    // Nothing waits forever: what hasn't arrived in 20 seconds fails the block, saying what it was.
    const within = (promise, what) => Promise.race([promise, new Promise((_, no) => window.__snapTimeout(() => no(new Error(`${what} hadn't loaded after 20s`)), 20000))])
    const root = window.__snapRoot(selector, index)
    if (!root) throw new Error(`No ${selector} #${index} on the page`)

    // Every web font, loaded before anything is measured: a font still on its way is drawn in the fallback,
    // and the block would differ from a run where it arrived in time.
    await within(Promise.all(Array.from(document.fonts, (f) => f.load().catch(() => {}))), 'A web font')
    await document.fonts.ready
    const failed = Array.from(document.fonts).filter((f) => f.status === 'error')
    if (failed.length) throw new Error(`The font ${failed.map((f) => `${f.family} ${f.weight} ${f.style}`).join(', ')} didn't load`)

    // A face with font-display: optional that misses its first 100ms is never used on that page, however
    // soon it arrives, so text would be in the site's font on one run and the fallback on the next. Each
    // @font-face is added again from script, loaded and with display: block, which the browser prefers to
    // the stylesheet's copy: blocks are always drawn in the site's own fonts. A resize lets anything that
    // measured text measure it again.
    const rules = []
    const collectRules = (list) => {
        for (const rule of list) {
            if (rule instanceof CSSFontFaceRule) rules.push(rule)
            else if (rule.cssRules) collectRules(rule.cssRules)
        }
    }
    for (const sheet of document.styleSheets) {
        try { collectRules(sheet.cssRules) } catch {} // a stylesheet from another origin can't be read
    }
    const descriptors = { weight: 'font-weight', style: 'font-style', stretch: 'font-stretch', unicodeRange: 'unicode-range', featureSettings: 'font-feature-settings', variationSettings: 'font-variation-settings', ascentOverride: 'ascent-override', descentOverride: 'descent-override', lineGapOverride: 'line-gap-override', sizeAdjust: 'size-adjust' }
    const again = await within(Promise.all(rules.map(async (rule) => {
        const base = rule.parentStyleSheet?.href || location.href
        const src = rule.style.getPropertyValue('src').replace(/url\((['"]?)([^'")]+)\1\)/g, (m, q, url) => `url("${new URL(url, base).href}")`)
        const options = { display: 'block' }
        for (const [key, property] of Object.entries(descriptors)) {
            const value = rule.style.getPropertyValue(property)
            if (value) options[key] = value
        }
        const face = new FontFace(rule.style.getPropertyValue('font-family').trim().replace(/^(['"])(.*)\1$/, '$2'), src, options)
        return face.load().then(() => face, () => null)
    })), 'A web font')
    again.filter(Boolean).forEach((face) => document.fonts.add(face))
    if (again.some(Boolean)) {
        await raf()
        window.dispatchEvent(new Event('resize'))
    }

    // Scroll to a fixed place and fire a scroll, so scroll-triggered reveals run even at y=0.
    // 'top' puts the top of the block, or of anything rising out of it, `pad` below the top of the viewport.
    // 'silent' scrolls to the same place but no scroll listener hears it: the block is in view, and
    // whatever waits for the visitor to scroll (a reveal) is still waiting.
    if (scroll === 'silent') {
        window.addEventListener('scroll', (e) => e.stopImmediatePropagation(), { capture: true })
    }
    if (scroll !== 'none') {
        let top = root.getBoundingClientRect().top
        for (const el of root.querySelectorAll('*')) {
            const r = el.getBoundingClientRect()
            if (r.width && r.height) top = Math.min(top, r.top)
        }
        top += window.scrollY - pad
        const y = scroll === 'top' || scroll === 'silent' ? Math.max(0, Math.floor(top)) : Number(scroll)
        window.scrollTo({ top: y, left: 0, behavior: 'instant' })
        if (scroll !== 'silent') window.dispatchEvent(new Event('scroll'))
    }
    await raf()
    await raf()

    // Lazy images in the block load now, and are decoded before anything is measured.
    root.querySelectorAll('img[loading="lazy"]').forEach((img) => (img.loading = 'eager'))
    await Promise.all(
        Array.from(root.querySelectorAll('img')).map((img) =>
            within(
                (img.complete
                    ? Promise.resolve()
                    : new Promise((r) => {
                          img.addEventListener('load', r, { once: true })
                          img.addEventListener('error', r, { once: true })
                      })
                ).then(() => img.decode?.().catch(() => {})),
                `The image ${img.currentSrc || img.src}`,
            ),
        ),
    )
}

/* Put the block in a state with a script. */
async function act({ selector, index, stateJs }) {
    const root = window.__snapRoot(selector, index)
    // eslint-disable-next-line no-new-func
    await new Function('root', `return (async () => { ${stateJs} })()`)(root)
}

/* Let everything settle, then stop every kind of motion: CSS animations and transitions jump to their
   end (infinite ones to their start), timers and animation frame loops stop, media pauses. The
   transition and animation declarations themselves are left alone, so they are still compared. */
async function freeze({ final, repaintImages }) {
    const realRaf = window.requestAnimationFrame.bind(window)
    const raf = () => new Promise((r) => realRaf(() => r()))
    const sleep = (ms) => new Promise((r) => setTimeout(r, ms))
    await raf()
    await raf()
    await sleep(150)

    const finishAll = () => {
        for (const a of document.getAnimations()) {
            try {
                const t = a.effect?.getComputedTiming?.()
                if (!t || t.iterations === Infinity || t.endTime === Infinity) {
                    a.pause()
                    a.currentTime = 0
                } else {
                    a.finish()
                }
            } catch {
                try { a.pause(); a.currentTime = 0 } catch {}
            }
        }
    }
    finishAll()
    await raf()
    await raf()
    finishAll() // anything started by an animationend / transitionend handler
    if (!final) return null // settled for an action such as a hover, which must land on still elements

    const last = setTimeout(() => {}, 0)
    for (let i = 0; i <= last; i++) {
        clearTimeout(i)
        clearInterval(i)
    }
    window.setTimeout = () => 0
    window.setInterval = () => 0
    window.requestAnimationFrame = () => 0
    // From here nothing the page listens for reaches it: taking the screenshot can resize the viewport
    // or scroll, and a scroll-triggered reveal must not start in the middle of it.
    for (const type of ['scroll', 'resize', 'wheel', 'mousemove', 'mouseover', 'mouseout', 'mouseenter', 'mouseleave', 'pointermove', 'pointerover', 'pointerout', 'pointerenter', 'pointerleave', 'focusin', 'focusout']) {
        window.addEventListener(type, (e) => e.stopImmediatePropagation(), { capture: true })
    }    document.querySelectorAll('video, audio').forEach((m) => { try { m.pause(); m.currentTime = 0 } catch {} })
    finishAll()
    await document.fonts.ready

    // Chrome resamples an image differently the first time it paints it and when it repaints it
    // later, so a block that happened to repaint (a font swap, a class change) would differ by
    // a few levels in every photo. Hide and show every image once, so every run ends on a repaint.
    if (repaintImages) {
        const imgs = Array.from(document.querySelectorAll('img'))
        imgs.forEach((img) => img.style.setProperty('visibility', 'hidden', 'important'))
        await raf()
        await raf()
        imgs.forEach((img) => img.style.removeProperty('visibility'))
        await raf()
        await raf()
        finishAll()
    }

    return { scrollY: window.scrollY, animations: document.getAnimations().length }
}

/* Walk the block and record everything. */
function collect({ selector, index }) {
    const root = window.__snapRoot(selector, index)
    const rootBox = root.getBoundingClientRect()
    const round = (n) => Math.round(n * 100) / 100

    // Colours are compared as the sRGB pixel they paint, so #fff, rgb() and an oklch() token that
    // resolve to the same colour are equal. Raw values are kept as well.
    const canvas = document.createElement('canvas')
    canvas.width = canvas.height = 1
    const ctx = canvas.getContext('2d', { willReadFrequently: true })
    ctx.globalCompositeOperation = 'copy'
    const colourCache = new Map()
    const toRgba = (c) => {
        if (colourCache.has(c)) return colourCache.get(c)
        ctx.fillStyle = 'rgba(0,0,0,0)'
        ctx.fillStyle = c
        ctx.fillRect(0, 0, 1, 1)
        const [r, g, b, a] = ctx.getImageData(0, 0, 1, 1).data
        const out = a === 0 ? 'transparent' : `rgba(${r},${g},${b},${Math.round((a / 255) * 100) / 100})`
        colourCache.set(c, out)
        return out
    }
    const COLOUR = /\b(?:rgba?|hsla?|hwb|lab|lch|oklab|oklch|color)\([^()]*\)|#[0-9a-fA-F]{3,8}\b/g
    const NUMBER = /-?\d*\.\d+/g
    const normalise = (v) => v.replace(COLOUR, toRgba).replace(NUMBER, (n) => String(round(parseFloat(n))))

    const standardProps = Array.from(getComputedStyle(root)).filter((p) => !p.startsWith('--')).sort()

    const styles = (el, pseudo) => {
        const cs = getComputedStyle(el, pseudo)
        const raw = {}
        const norm = {}
        for (const p of standardProps) {
            const v = cs.getPropertyValue(p)
            norm[p] = normalise(v)
            if (norm[p] !== v) raw[p] = v // the raw value is kept only where normalising changed it
        }
        return { raw, norm }
    }

    // Custom properties declared on (or changed at) this element, not just inherited from :root.
    const ownVars = (el) => {
        const cs = getComputedStyle(el)
        const parent = el.parentElement ? getComputedStyle(el.parentElement) : null
        const out = {}
        for (const p of cs) {
            if (!p.startsWith('--') || p.startsWith('--tw-')) continue
            const v = cs.getPropertyValue(p).trim()
            if (!parent || parent.getPropertyValue(p).trim() !== v) out[p] = v
        }
        return out
    }

    const box = (el) => {
        const r = el.getBoundingClientRect()
        return { x: round(r.left - rootBox.left), y: round(r.top - rootBox.top), w: round(r.width), h: round(r.height) }
    }

    const nodes = {}
    const walk = (el, p) => {
        const cs = getComputedStyle(el)
        const entry = {
            tag: el.tagName.toLowerCase(),
            classHint: el.getAttribute('class') || '', // for reading the report only, never matched on
            text: (Array.from(el.childNodes).filter((n) => n.nodeType === 3).map((n) => n.textContent).join(' ').trim().replace(/\s+/g, ' ')).slice(0, 40),
            box: box(el),
            style: styles(el),
            vars: ownVars(el),
        }
        if (el.tagName === 'IMG') entry.img = { currentSrc: el.currentSrc.replace(location.origin, ''), natural: `${el.naturalWidth}x${el.naturalHeight}` }
        const pseudos = {}
        for (const ps of ['::before', '::after']) {
            const pcs = getComputedStyle(el, ps)
            if (pcs.content && pcs.content !== 'none' && pcs.content !== 'normal') pseudos[ps] = styles(el, ps)
        }
        if (cs.display === 'list-item') pseudos['::marker'] = styles(el, '::marker')
        if (el.matches('input, textarea')) pseudos['::placeholder'] = styles(el, '::placeholder')
        if (Object.keys(pseudos).length) entry.pseudos = pseudos
        nodes[p] = entry
        Array.from(el.children).forEach((child, i) => walk(child, `${p}/${i}:${child.tagName.toLowerCase()}`))
    }
    walk(root, `0:${root.tagName.toLowerCase()}`)

    return {
        rootBox: { x: round(rootBox.left + scrollX), y: round(rootBox.top + scrollY), w: round(rootBox.width), h: round(rootBox.height) },
        documentHeight: document.documentElement.scrollHeight,
        nodeCount: Object.keys(nodes).length,
        nodes,
    }
}

/* The area to photograph: the block and anything of it that overflows, in document coordinates. */
function clipArea({ selector, index, pad, maskImages }) {
    const root = window.__snapRoot(selector, index)
    // Nothing moves while the picture is taken. To draw what lies beyond the viewport, Chrome styles the page
    // again, and a transition can start from that (Whoosh's Close cards slid open and shut), to be caught at a
    // different point on every run. The styles have already been recorded.
    const still = document.createElement('style')
    still.textContent = '*, *::before, *::after { transition: none !important }'
    document.head.append(still)
    if (maskImages) root.querySelectorAll('img').forEach((img) => img.style.setProperty('visibility', 'hidden', 'important'))
    let { left, top, right, bottom } = root.getBoundingClientRect()
    for (const el of root.querySelectorAll('*')) {
        const r = el.getBoundingClientRect()
        if (!r.width || !r.height) continue
        left = Math.min(left, r.left); top = Math.min(top, r.top)
        right = Math.max(right, r.right); bottom = Math.max(bottom, r.bottom)
    }
    // Hide everything that is neither the block nor around it, so the margin of the picture shows the page
    // behind the block rather than its neighbours, and a change to one block can't make the next one differ.
    // The top bar goes with them, so it can't cover the block. Nothing moves: hidden things keep their space.
    for (const el of document.querySelectorAll('body *')) {
        if (root.contains(el) || el.contains(root)) continue
        el.style.setProperty('visibility', 'hidden', 'important')
    }
    const x = Math.max(0, Math.floor(left + scrollX - pad))
    const y = Math.max(0, Math.floor(top + scrollY - pad))
    const w = Math.min(document.documentElement.clientWidth, Math.ceil(right + scrollX + pad)) - x
    const h = Math.ceil(bottom + scrollY + pad) - y
    return { x, y, width: w, height: h, viewportH: innerHeight, scrollY }
}

/* ---------- run ---------- */
const started = Date.now()
let browser
try {
    browser = await puppeteer.launch({
        headless: !opts.headful,
        args: ['--hide-scrollbars', '--font-render-hinting=none', '--disable-lcd-text', '--force-color-profile=srgb'],
    })
} catch (e) {
    console.error(`Puppeteer could not start Chrome (${e.message.split('\n')[0]}). npx puppeteer browsers install chrome fetches the one it expects.`)
    process.exit(2)
}
const chrome = await browser.version()

async function open(url, width, height, context = browser.defaultBrowserContext()) {
    // Each page in a window of its own. A tab behind another is hidden and gets no animation frames, so
    // with several jobs its waits for a frame stalled until Chrome happened to bring it to the front.
    const page = await context.newPage({ type: 'window' })
    await page.setViewport({ width, height, deviceScaleFactor: dpr })
    await page.emulateMediaFeatures([{ name: 'prefers-reduced-motion', value: media }])
    const errors = []
    page.on('pageerror', (e) => errors.push(String(e).split('\n')[0]))
    // A timer of a second or more is an autoplay or a delayed change: it never fires, so a carousel is
    // taken on the slide it loads on however long the page takes, and the runs can't drift apart.
    await page.evaluateOnNewDocument((long) => {
        const timeout = window.setTimeout
        const interval = window.setInterval
        window.__snapTimeout = timeout // for this script's own waits
        window.setTimeout = (fn, ms, ...args) => (Number(ms) >= long ? timeout(() => {}, ms) : timeout(fn, ms, ...args))
        window.setInterval = (fn, ms, ...args) => (Number(ms) >= long ? interval(() => {}, ms) : interval(fn, ms, ...args))
    }, 1000)
    try {
        const response = await page.goto(url, { waitUntil: 'load', timeout: 60000 })
        // A not-found page is a design like any other; an error page is not the page.
        if ((response?.status() ?? 0) >= 500) throw new Error(`${url} answered ${response.status()}`)
        await page.evaluate(defineRoot)
    } catch (e) {
        await page.close()
        throw e
    }
    return { page, errors }
}

/* Every width and state of one block, into its own folder. */
async function snapshotBlock({ url, selector, index, out, verbose, context }) {
    await mkdir(out, { recursive: true })
    const meta = { url, block: selector, index, media, widths, heights, states: states.map((s) => s.name), dpr, chrome, assets, runs: [] }
    let nodes = 0
    const pageErrors = new Set()
    for (const [i, width] of widths.entries()) {
        for (const state of states) {
            const t = Date.now()
            const { page, errors } = await open(url, width, heights[i], context)
            try {
                await page.evaluate(place, { selector, index, scroll, pad })
                if (state.js) await page.evaluate(freeze, { final: false })
                if (state.js.startsWith('hover:')) {
                    const at = await page.evaluate(({ selector, index, target }) => {
                        const el = window.__snapRoot(selector, index).querySelector(target)
                        if (!el) throw new Error(`hover: nothing matches ${target} in the block`)
                        const r = el.getBoundingClientRect()
                        return { x: r.left + r.width / 2, y: r.top + r.height / 2 }
                    }, { selector, index, target: state.js.slice(6) })
                    await page.mouse.move(at.x, at.y)
                } else if (state.js) await page.evaluate(act, { selector, index, stateJs: state.js })
                const prep = await page.evaluate(freeze, { final: true, repaintImages: !opts.noRepaint })
                const data = await page.evaluate(collect, { selector, index })
                const name = `${width}-${state.name}${opts.reduced ? '-reduced' : ''}`
                await writeFile(path.join(out, `${name}.json`), JSON.stringify({ width, height: heights[i], state: state.name, media, ...prep, ...data }))
                let clip = null
                if (png) {
                    clip = await page.evaluate(clipArea, { selector, index, pad, maskImages: !!opts.maskImages })
                    const fits = clip.y >= clip.scrollY && clip.y + clip.height <= clip.scrollY + clip.viewportH
                    await page.screenshot({
                        path: path.join(out, `${name}.png`),
                        clip: { x: clip.x, y: clip.y, width: clip.width, height: clip.height },
                        captureBeyondViewport: !fits,
                    })
                    clip.fitsViewport = fits
                }
                meta.runs.push({ name, ms: Date.now() - t, nodes: data.nodeCount, rootBox: data.rootBox, clip, errors })
                nodes = Math.max(nodes, data.nodeCount)
                errors.forEach((e) => pageErrors.add(e))
                if (verbose) console.log(`${name}: ${data.nodeCount} elements, root ${data.rootBox.w}x${data.rootBox.h} at y=${data.rootBox.y}${clip ? `, png ${clip.width}x${clip.height}${clip.fitsViewport ? '' : ' (beyond viewport)'}` : ''}, ${Date.now() - t}ms${errors.length ? `, page errors: ${errors.join(' | ')}` : ''}`)
            } finally {
                await page.close()
            }
        }
    }
    // Several invocations can share a folder (states needing different --scroll or --reduced): keep every run.
    try {
        const prev = JSON.parse(await readFile(path.join(out, 'meta.json'), 'utf8'))
        const names = new Set(meta.runs.map((r) => r.name))
        meta.runs = [...prev.runs.filter((r) => !names.has(r.name)), ...meta.runs]
    } catch {}
    await writeFile(path.join(out, 'meta.json'), JSON.stringify(meta, null, 2))
    return { nodes, pageErrors: [...pageErrors] }
}

/* Run fn over items, at most `jobs` at a time. Each worker has a browser context of its own: Chrome holds
   back a request for a page that another tab in the same context is loading, so four blocks of one page
   would wait for it one after another. */
async function pool(items, jobs, fn) {
    let next = 0
    const worker = async () => {
        const context = await browser.createBrowserContext()
        try {
            while (next < items.length) {
                const i = next++
                await fn(items[i], i, context)
            }
        } finally {
            await context.close()
        }
    }
    await Promise.all(Array.from({ length: Math.min(jobs, items.length) }, worker))
}

const written = []
let failures = 0
try {
    if (opts.block) {
        // The block's page: the one named, or the first of the site's pages that has it.
        const index = opts.index ?? 0
        let page = opts.pages.length === 1 ? opts.pages[0] : null
        for (const p of page ? [] : opts.pages) {
            const { page: tab } = await open(opts.base + p, widths[0], heights[0]).catch(() => ({}))
            if (!tab) continue
            const has = await tab.evaluate(({ selector, index }) => !!window.__snapRoot(selector, index), { selector: opts.block, index })
            await tab.close()
            if (has) { page = p; break }
        }
        if (!page) {
            console.error(`No ${opts.block}${index ? ` #${index}` : ''} on ${opts.pages.join(', ')}`)
            failures++
        } else {
            const name = `${pageName(page)}/${opts.name ?? blockName(opts.block, index)}`
            console.log(`${name}: ${opts.block} on ${opts.base}${page}`)
            await snapshotBlock({ url: opts.base + page, selector: opts.block, index, out: path.join(opts.out, name), verbose: true })
            written.push(name)
        }
    } else {
        // Every page's blocks first, then the blocks, --jobs at a time: each is still a page of its own,
        // loaded fresh, and a page like /site/content takes seconds to render.
        const found = []
        await pool(opts.pages, jobs, async (p, i, context) => {
            try {
                const { page } = await open(opts.base + p, widths[0], heights[0], context)
                found.push({ p, blocks: await page.evaluate(() => window.__snapBlocks()) })
                await page.close()
            } catch (e) {
                console.log(`FAIL ${p}: ${e.message.split('\n')[0]}`)
                failures++
            }
        })
        const todo = []
        for (const p of opts.pages) {
            const blocks = found.find((f) => f.p === p)?.blocks
            if (blocks && !blocks.length) console.log(`     ${p}: no blocks`)
            for (const { selector, index } of blocks ?? []) todo.push({ p, selector, index, name: `${pageName(p)}/${blockName(selector, index)}` })
        }
        // Lines are said in page order, whichever block finishes first; a page's script errors once.
        const lines = []
        const seen = new Map()
        let said = 0
        await pool(todo, jobs, async ({ p, selector, index, name }, i, context) => {
            const t = Date.now()
            try {
                const { nodes, pageErrors } = await snapshotBlock({ url: opts.base + p, selector, index, out: path.join(opts.out, name), context })
                lines[i] = { name, nodes, s: (Date.now() - t) / 1000, pageErrors }
                written.push(name)
            } catch (e) {
                lines[i] = { name, error: e.message.split('\n')[0] }
                failures++
            }
            for (; said < todo.length && lines[said]; said++) {
                const line = lines[said]
                if (line.error) { console.log(`FAIL ${line.name}: ${line.error}`); continue }
                const page = todo[said].p
                if (!seen.has(page)) seen.set(page, new Set())
                const fresh = line.pageErrors.filter((e) => !seen.get(page).has(e))
                fresh.forEach((e) => seen.get(page).add(e))
                console.log(`ok   ${line.name}  ${line.nodes} elements, ${line.s.toFixed(1)}s${fresh.length ? `, page errors: ${fresh.join(' | ')}` : ''}`)
            }
        })
    }
} finally {
    await browser.close()
}

// What the run was, for the comparison to say: earlier blocks written into the same run are kept.
const runFile = path.join(opts.out, 'run.json')
let run = { blocks: [] }
try { run = JSON.parse(await readFile(runFile, 'utf8')) } catch {}
await mkdir(opts.out, { recursive: true })
await writeFile(runFile, JSON.stringify({
    ...run,
    base: opts.base,
    commit: opts.commit ?? run.commit ?? null,
    assets,
    chrome,
    taken: new Date().toISOString(),
    blocks: [...new Set([...(run.blocks ?? []), ...written])],
}, null, 2))
console.log(`${written.length} block${written.length === 1 ? '' : 's'} in ${((Date.now() - started) / 1000).toFixed(1)}s -> ${opts.out}${failures ? `, ${failures} failed` : ''}`)
process.exit(failures ? 1 : 0)
