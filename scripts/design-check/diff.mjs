#!/usr/bin/env node
/*
    Compare one block's two snapshot folders, written by snapshot.mjs. scripts/design-check.sh compare runs
    this for every block in two runs, and for one block's detail when it is named.

      node diff.mjs <before>/<page>/<block> <after>/<page>/<block> [--threshold 0] [--max-pixels 0] [--raw]
          [--show 8] [--keys 40] [--logical] [--diff-dir <dir>]

    Layer 1, computed styles: elements are matched by DOM path (child index + tag), never by class.
      - every standard computed property, compared after colour and decimal normalisation
        (so #fff, rgb(255,255,255) and an oklch() token painting the same pixel are equal);
      - each element's box relative to the block root, and the root's own size;
      - ::before / ::after / ::marker / ::placeholder;
      - img currentSrc (a changed `sizes` picks a different file).
      Reported but not failing: raw-only differences (same pixel, different spelling, with --raw to list them),
      custom properties the block declares (BEM vars replaced by utilities are expected), and the block's
      y position on the page (another block above it may have changed).
    Layer 2, pixels: the block's PNGs, a pixel differs when any channel moves by more than --threshold
      (0 by default: a conversion is proved pixel for pixel); the check fails when more than --max-pixels
      differ. A diff image is written for each pair that differs, into _diff in the after folder.

    Exit: 0 identical, 1 differences, 2 could not compare. No packages: PNGs are decoded with node:zlib.
*/
import { readFile, readdir, writeFile, mkdir } from 'node:fs/promises'
import { inflateSync, deflateSync, crc32 } from 'node:zlib'
import path from 'node:path'

const argv = process.argv.slice(2)
const dirs = []
const opts = { threshold: 0, maxPixels: 0, raw: false, show: 8, keys: 40, logical: false }
for (let i = 0; i < argv.length; i++) {
    const a = argv[i]
    if (a === '--threshold') opts.threshold = Number(argv[++i])
    else if (a === '--max-pixels') opts.maxPixels = Number(argv[++i])
    else if (a === '--raw') opts.raw = true
    else if (a === '--show') opts.show = Number(argv[++i])
    else if (a === '--diff-dir') opts.diffDir = argv[++i]
    else if (a === '--keys') opts.keys = Number(argv[++i])
    else if (a === '--logical') opts.logical = true
    else dirs.push(a)
}
if (dirs.length !== 2) {
    console.error('Usage: node diff.mjs <before-dir> <after-dir> [options]')
    process.exit(2)
}
const [beforeDir, afterDir] = dirs
const diffDir = opts.diffDir || path.join(afterDir, '_diff')

/* ---------- PNG (8-bit RGB/RGBA, non-interlaced: what Chrome writes) ---------- */
function decodePng(buf) {
    if (buf.readUInt32BE(0) !== 0x89504e47) throw new Error('not a PNG')
    let off = 8, width, height, bitDepth, colourType, interlace
    const idat = []
    while (off < buf.length) {
        const len = buf.readUInt32BE(off)
        const type = buf.toString('latin1', off + 4, off + 8)
        const data = buf.subarray(off + 8, off + 8 + len)
        if (type === 'IHDR') {
            width = data.readUInt32BE(0); height = data.readUInt32BE(4)
            bitDepth = data[8]; colourType = data[9]; interlace = data[12]
        } else if (type === 'IDAT') idat.push(data)
        else if (type === 'IEND') break
        off += 12 + len
    }
    if (bitDepth !== 8 || interlace !== 0 || ![2, 6].includes(colourType)) throw new Error(`unsupported PNG (depth ${bitDepth}, type ${colourType}, interlace ${interlace})`)
    const bpp = colourType === 6 ? 4 : 3
    const raw = inflateSync(Buffer.concat(idat))
    const stride = width * bpp
    const px = Buffer.alloc(stride * height)
    for (let y = 0; y < height; y++) {
        const f = raw[y * (stride + 1)]
        const src = y * (stride + 1) + 1
        const dst = y * stride
        for (let x = 0; x < stride; x++) {
            const a = x >= bpp ? px[dst + x - bpp] : 0
            const b = y ? px[dst - stride + x] : 0
            const c = x >= bpp && y ? px[dst - stride + x - bpp] : 0
            let v = raw[src + x]
            if (f === 1) v += a
            else if (f === 2) v += b
            else if (f === 3) v += (a + b) >> 1
            else if (f === 4) { const p = a + b - c, pa = Math.abs(p - a), pb = Math.abs(p - b), pc = Math.abs(p - c); v += pa <= pb && pa <= pc ? a : pb <= pc ? b : c }
            px[dst + x] = v & 255
        }
    }
    const rgba = Buffer.alloc(width * height * 4)
    for (let i = 0, j = 0; i < width * height; i++, j += bpp) {
        rgba[i * 4] = px[j]; rgba[i * 4 + 1] = px[j + 1]; rgba[i * 4 + 2] = px[j + 2]; rgba[i * 4 + 3] = bpp === 4 ? px[j + 3] : 255
    }
    return { width, height, rgba }
}

function encodePng({ width, height, rgba }) {
    const chunk = (type, data) => {
        const head = Buffer.alloc(8); head.writeUInt32BE(data.length, 0); head.write(type, 4, 'latin1')
        const crc = Buffer.alloc(4); crc.writeUInt32BE(crc32(Buffer.concat([head.subarray(4), data])) >>> 0, 0)
        return Buffer.concat([head, data, crc])
    }
    const ihdr = Buffer.alloc(13)
    ihdr.writeUInt32BE(width, 0); ihdr.writeUInt32BE(height, 4); ihdr[8] = 8; ihdr[9] = 6
    const raw = Buffer.alloc((width * 4 + 1) * height)
    for (let y = 0; y < height; y++) rgba.copy(raw, y * (width * 4 + 1) + 1, y * width * 4, (y + 1) * width * 4)
    return Buffer.concat([Buffer.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a]), chunk('IHDR', ihdr), chunk('IDAT', deflateSync(raw)), chunk('IEND', Buffer.alloc(0))])
}

function pixelDiff(a, b) {
    const width = Math.max(a.width, b.width), height = Math.max(a.height, b.height)
    const out = Buffer.alloc(width * height * 4)
    let count = 0, maxDelta = 0
    let box = null
    for (let y = 0; y < height; y++) {
        for (let x = 0; x < width; x++) {
            const o = (y * width + x) * 4
            const inA = x < a.width && y < a.height, inB = x < b.width && y < b.height
            let delta = 255
            let grey = 255
            if (inA && inB) {
                const ia = (y * a.width + x) * 4, ib = (y * b.width + x) * 4
                delta = Math.max(Math.abs(a.rgba[ia] - b.rgba[ib]), Math.abs(a.rgba[ia + 1] - b.rgba[ib + 1]), Math.abs(a.rgba[ia + 2] - b.rgba[ib + 2]), Math.abs(a.rgba[ia + 3] - b.rgba[ib + 3]))
                grey = (b.rgba[ib] * 0.3 + b.rgba[ib + 1] * 0.59 + b.rgba[ib + 2] * 0.11) | 0
            }
            if (delta > opts.threshold) {
                count++
                if (delta < 255 || (inA && inB)) maxDelta = Math.max(maxDelta, delta)
                box = box ? { x0: Math.min(box.x0, x), y0: Math.min(box.y0, y), x1: Math.max(box.x1, x), y1: Math.max(box.y1, y) } : { x0: x, y0: y, x1: x, y1: y }
                out[o] = 255; out[o + 1] = 0; out[o + 2] = 170; out[o + 3] = 255
            } else {
                const g = 200 + ((grey * 55) / 255) | 0 // the after image, faded, for context
                out[o] = g; out[o + 1] = g; out[o + 2] = g; out[o + 3] = 255
            }
        }
    }
    return { count, maxDelta, box, image: { width, height, rgba: out } }
}

/* ---------- computed styles ---------- */
const short = (s, n = 60) => (s.length > n ? s.slice(0, n - 1) + '…' : s)
const label = (p, node) => `${p}${node?.classHint ? ` .${short(node.classHint.trim().split(/\s+/).join('.'), 50)}` : ''}${node?.text ? ` "${short(node.text, 24)}"` : ''}`

function compareSnapshots(A, B) {
    const hard = new Map() // "prop: a -> b" => [paths]
    const soft = { raw: 0, rawExamples: [], vars: [], position: null, docHeight: null }
    const add = (key, where) => { if (!hard.has(key)) hard.set(key, []); hard.get(key).push(where) }

    if (A.rootBox.w !== B.rootBox.w || A.rootBox.h !== B.rootBox.h) add(`block size: ${A.rootBox.w}x${A.rootBox.h} -> ${B.rootBox.w}x${B.rootBox.h}`, 'root')
    if (A.rootBox.y !== B.rootBox.y || A.rootBox.x !== B.rootBox.x) soft.position = `${A.rootBox.x},${A.rootBox.y} -> ${B.rootBox.x},${B.rootBox.y}`
    if (A.documentHeight !== B.documentHeight) soft.docHeight = `${A.documentHeight} -> ${B.documentHeight}`

    const pa = Object.keys(A.nodes), pb = Object.keys(B.nodes)
    const removed = pa.filter((p) => !(p in B.nodes)), added = pb.filter((p) => !(p in A.nodes))
    removed.forEach((p) => add('element removed (structure changed)', label(p, A.nodes[p])))
    added.forEach((p) => add('element added (structure changed)', label(p, B.nodes[p])))

    // Logical properties mirror physical ones in a left-to-right, horizontal page: skip the copies.
    const isLogical = (p) => /(^|-)(block|inline)(-|$)/.test(p) || /^border-(start|end)-(start|end)-radius$/.test(p)
    // Properties that default to currentColor: when they move exactly with `color`, only `color` is reported.
    const FOLLOWS_COLOR = /^(-webkit-text-fill-color|-webkit-text-stroke-color|border-(top|right|bottom|left)-color|caret-color|column-rule-color|row-rule-color|outline-color|text-decoration-color|text-emphasis-color)$/
    const compareStyle = (sa, sb, where) => {
        for (const prop of Object.keys(sa.norm)) {
            if (!opts.logical && isLogical(prop)) continue
            if (FOLLOWS_COLOR.test(prop) && sa.norm[prop] === sa.norm.color && sb.norm[prop] === sb.norm.color) continue
            const va = sa.norm[prop], vb = sb.norm[prop]
            if (vb === undefined) continue // property unknown to the other Chrome
            if (va !== vb) add(`${prop}: ${short(va, 80)} -> ${short(vb, 80)}`, where)
            else if ((sa.raw[prop] ?? va) !== (sb.raw[prop] ?? vb)) {
                soft.raw++
                if (soft.rawExamples.length < 50) soft.rawExamples.push(`${where} ${prop}: ${sa.raw[prop] ?? va} -> ${sb.raw[prop] ?? vb}`)
            }
        }
    }

    for (const p of pa.filter((p) => p in B.nodes)) {
        const a = A.nodes[p], b = B.nodes[p]
        const where = label(p, a)
        for (const k of ['x', 'y', 'w', 'h']) if (a.box[k] !== b.box[k]) add(`box ${k}: ${a.box[k]} -> ${b.box[k]}`, where)
        compareStyle(a.style, b.style, where)
        for (const ps of new Set([...Object.keys(a.pseudos || {}), ...Object.keys(b.pseudos || {})])) {
            const xa = a.pseudos?.[ps], xb = b.pseudos?.[ps]
            if (!xa || !xb) add(`${ps} ${xa ? 'removed' : 'added'}`, where)
            else compareStyle(xa, xb, `${where} ${ps}`)
        }
        if (a.img || b.img) {
            if (a.img?.currentSrc !== b.img?.currentSrc) add(`img currentSrc: ${a.img?.currentSrc} -> ${b.img?.currentSrc}`, where)
        }
        for (const v of new Set([...Object.keys(a.vars), ...Object.keys(b.vars)])) {
            if (a.vars[v] !== b.vars[v]) soft.vars.push(`${p} ${v}: ${a.vars[v] ?? '(none)'} -> ${b.vars[v] ?? '(none)'}`)
        }
    }
    return { hard, soft }
}

/* ---------- run ---------- */
let failed = false
try {
    const [ma, mb] = await Promise.all([beforeDir, afterDir].map(async (d) => JSON.parse(await readFile(path.join(d, 'meta.json'), 'utf8'))))
    if (ma.chrome !== mb.chrome) console.log(`WARNING: taken with different browsers (${ma.chrome} vs ${mb.chrome}); pixels may differ for that reason alone. Retake the baseline.`)
    if (ma.assets && mb.assets && ma.assets !== mb.assets) console.log(`WARNING: one run took its CSS from the ${ma.assets}, the other from the ${mb.assets}. Take both the same way.`)
    if (ma.url !== mb.url || ma.block !== mb.block) console.log(`WARNING: different page or block (${ma.url} ${ma.block} vs ${mb.url} ${mb.block})`)
} catch {}
const files = (await readdir(beforeDir)).filter((f) => f.endsWith('.json') && f !== 'meta.json').sort((x, y) => parseInt(x) - parseInt(y) || x.localeCompare(y))
if (!files.length) { console.error(`No snapshots in ${beforeDir}`); process.exit(2) }

for (const f of files) {
    const name = f.replace(/\.json$/, '')
    let B
    try { B = JSON.parse(await readFile(path.join(afterDir, f), 'utf8')) } catch { console.log(`\n## ${name}: missing in ${afterDir}`); failed = true; continue }
    const A = JSON.parse(await readFile(path.join(beforeDir, f), 'utf8'))
    const { hard, soft } = compareSnapshots(A, B)

    let pixels = null
    try {
        const [ia, ib] = await Promise.all([readFile(path.join(beforeDir, `${name}.png`)), readFile(path.join(afterDir, `${name}.png`))])
        const a = decodePng(ia), b = decodePng(ib)
        const d = pixelDiff(a, b)
        pixels = { size: a.width === b.width && a.height === b.height ? `${a.width}x${a.height}` : `${a.width}x${a.height} -> ${b.width}x${b.height}`, ...d, total: d.image.width * d.image.height }
        if (d.count > opts.maxPixels || a.width !== b.width || a.height !== b.height) {
            await mkdir(diffDir, { recursive: true })
            pixels.file = path.join(diffDir, `${name}.png`)
            await writeFile(pixels.file, encodePng(d.image))
        }
    } catch (e) {
        if (e.code !== 'ENOENT') pixels = { error: e.message }
    }

    const pixelFail = pixels && (pixels.error || pixels.count > opts.maxPixels || pixels.size.includes('->'))
    const ok = !hard.size && !pixelFail
    failed ||= !ok
    console.log(`\n## ${name}  ${ok ? 'SAME' : 'DIFFERENT'}  (${Object.keys(A.nodes).length} elements)`)
    if (pixels?.error) console.log(`  pixels: could not compare (${pixels.error})`)
    else if (pixels) console.log(`  pixels: ${pixels.count} of ${pixels.total} differ (threshold ${opts.threshold}, max channel delta ${pixels.maxDelta})${pixels.box ? `, in x ${pixels.box.x0}-${pixels.box.x1}, y ${pixels.box.y0}-${pixels.box.y1}` : ''}, size ${pixels.size}${pixels.file ? `\n  diff image: ${pixels.file}` : ''}`)
    if (hard.size) {
        const elements = new Set([...hard.values()].flat().map((w) => w.split(' ')[0]))
        console.log(`  computed styles: ${hard.size} distinct differences on ${elements.size} elements`)
    } else console.log('  computed styles: identical')
    for (const [key, where] of [...hard].slice(0, opts.keys)) {
        console.log(`  ${key}`)
        where.slice(0, opts.show).forEach((w) => console.log(`      ${w}`))
        if (where.length > opts.show) console.log(`      … and ${where.length - opts.show} more`)
    }
    if (hard.size > opts.keys) console.log(`  … and ${hard.size - opts.keys} more distinct differences (--keys to show more)`)
    if (soft.position) console.log(`  note: block moved on the page ${soft.position} (a change above it?)`)
    if (soft.docHeight) console.log(`  note: document height ${soft.docHeight}`)
    if (soft.vars.length) {
        console.log(`  note: ${soft.vars.length} custom properties declared in the block changed (not failing):`)
        soft.vars.slice(0, opts.show).forEach((v) => console.log(`      ${v}`))
        if (soft.vars.length > opts.show) console.log(`      … and ${soft.vars.length - opts.show} more`)
    }
    if (soft.raw) {
        console.log(`  note: ${soft.raw} values spelled differently but equal after normalising${opts.raw ? ':' : ' (--raw to list)'}`)
        if (opts.raw) soft.rawExamples.forEach((r) => console.log(`      ${r}`))
    }
}
console.log(failed ? '\nRESULT: DIFFERENT' : '\nRESULT: SAME')
process.exit(failed ? 1 : 0)
