#!/usr/bin/env node
/* ============================================================================
   SESAME README art kit.

   Cuts the README images from this repo's own static front page (site/), its
   brand files and its source, and writes them under docs/readme/. Nothing is
   drawn freehand: the hero and the gallery are screenshots of the real page,
   the cast is the marks resized (WayneUI composed on its green tile), the
   badges read their facts from composer.json and the driver factory, and the
   key duplicator is the site's own cas key, cut along its own bitting, with
   every cut labelled by a sign-in step read out of src/.

   Run from any folder (every path resolves from this file's own location):

     npm --prefix scripts/readme-art ci
     npx --prefix scripts/readme-art playwright install chromium
     node scripts/readme-art/build.mjs

   The page is opened as a file:// URL: no server, no network. Keys cut while
   you wait, and the shop never phones out.
   ========================================================================== */

import { chromium } from "playwright";
import { makeBadge } from "badge-maker";
import sharp from "sharp";
import { existsSync, mkdirSync, readFileSync, rmSync, writeFileSync } from "node:fs";
import path from "node:path";
import { fileURLToPath, pathToFileURL } from "node:url";

/* ------------------------------------------------------------- paths, limits */

const scriptDir = path.dirname(fileURLToPath(import.meta.url));
const repoRoot = path.resolve(scriptDir, "..", "..");
const siteDir = path.join(repoRoot, "site");
const srcDir = path.join(repoRoot, "src");
const outDir = path.join(repoRoot, "docs", "readme");
const castDir = path.join(outDir, "cast");

const MAX_BYTES = 300 * 1024; // every file in docs/readme/ stays at or under this
const FORBIDDEN = /GitLab|gitlab\.example|citadel/i; // internal names never ship in art

// The shop's own palette, from site/assets/sesame.css (:root).
const C = {
  green: "#0C5449",
  greenDeep: "#08352F",
  gold: "#FFCC33",
  goldLight: "#FFE38A",
  cream: "#F4F1EA",
  slate: "#314842",
  ink: "#1D2B27",
  brass: "#D6A73C",
  brassDark: "#8A6A1E",
  capText: "#E9E4D8",
  arm: "#7F8A86",
};

const outputs = [];

function record(rel, buf) {
  const abs = path.join(outDir, rel);
  mkdirSync(path.dirname(abs), { recursive: true });
  writeFileSync(abs, buf);
  outputs.push({ rel, bytes: buf.length });
  console.log(`  ${rel}  ${buf.length} bytes`);
}

function fail(msg) {
  console.error(`FAIL: ${msg}`);
  process.exit(1);
}

function read(rel) {
  const abs = path.join(repoRoot, rel);
  if (!existsSync(abs)) fail(`missing source ${rel}`);
  return readFileSync(abs, "utf8");
}

function esc(s) {
  return s.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;");
}

function assertCleanText(text, what) {
  if (FORBIDDEN.test(text)) fail(`forbidden text matched ${FORBIDDEN} in ${what}`);
}

/* ------------------------------------------------------------- 1. cast strip */

// One 96x96 PNG per stack part (shown at 48 px in the README). The marks are
// copied and resized only, never redrawn. WayneUI is composed instead: its
// reverse mark (white frame, gold tab) set on a Wayne State green tile, so it
// reads as a sibling of the other thirteen.
const CAST = [
  "creed",
  "proctor",
  "sesame",
  "helmet",
  "sourdough",
  "squire",
  "preflight",
  "pinstripe",
  "booster",
  "rollcall",
  "drawbridge",
  "advisor",
  "weld",
];

function castSource(name) {
  // SESAME's own mark is not in assets/marks/ (the site shows it from brand/).
  if (name === "sesame") return path.join(siteDir, "brand", "sesame-avatar-256.png");
  return path.join(siteDir, "assets", "marks", `${name}.png`);
}

// The WayneUI tile: the reverse mark, traced from WayneUI's approved artwork,
// set on a green rounded tile. The traced paths are placed unchanged: no edits
// to any d= value, fill, or the mark's own viewBox. The visible ink spans
// 1546 x 1551 of the 1980 x 1989 viewBox and is centred in it, so this scale
// makes the mark 352 px tall on the 512 tile (about 69%), matching the square
// marks WELD and SESAME.
function wayneuiTileSvg() {
  const raw = readFileSync(path.join(scriptDir, "src", "wayneui-mark-reverse.svg"), "utf8");
  const open = raw.indexOf("<svg");
  const openEnd = raw.indexOf(">", open) + 1;
  const close = raw.lastIndexOf("</svg>");
  if (open < 0 || openEnd < 2 || close < openEnd) fail("wayneui-mark-reverse.svg: no <svg> element");
  // Everything between the opening tag and the final </svg>, verbatim (the
  // file's own line endings included, so the paths stay byte-identical).
  const inner = raw.slice(openEnd, close).replace(/^\r?\n/, "");
  return `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512" width="512" height="512" role="img" aria-label="WayneUI">
  <rect width="512" height="512" rx="80" ry="80" fill="#0C5449"/>
  <svg x="31.3" y="30.3" width="449.4" height="451.4" viewBox="0 0 1980 1989">
${inner}</svg>
</svg>
`;
}

async function buildCast() {
  console.log("cast:");
  const tile = Buffer.from(wayneuiTileSvg(), "utf8");
  record("cast/wayneui.svg", tile);
  const tilePng = await sharp(tile, { density: 300 })
    .resize(96, 96, { fit: "contain", background: { r: 0, g: 0, b: 0, alpha: 0 } })
    .png({ palette: true, quality: 85, compressionLevel: 9 })
    .toBuffer();
  record("cast/wayneui.png", tilePng);
  const stale = path.join(castDir, "wayneui-dark.png");
  if (existsSync(stale)) rmSync(stale);
  for (const name of CAST) {
    const src = castSource(name);
    if (!existsSync(src)) fail(`cast needs ${path.relative(repoRoot, src)}`);
    const png = await sharp(readFileSync(src))
      .resize(96, 96, { fit: "contain", background: { r: 0, g: 0, b: 0, alpha: 0 } })
      .png({ palette: true, quality: 85, compressionLevel: 9 })
      .toBuffer();
    record(`cast/${name}.png`, png);
  }
}

/* ---------------------------------------------------------------- 2. badges */

// Facts are read, never typed: php and the licence from composer.json, and
// the drivers from the match arms in DriverFactory, so a new key on the
// pegboard shows up here on the next run. A badge that lies is a key that
// doesn't turn.
function buildBadges() {
  console.log("badges:");
  const composer = JSON.parse(read("composer.json"));
  const factory = read("src/DriverFactory.php");
  const drivers = [...factory.matchAll(/^\s*'([a-z0-9]+)'\s*=>\s*new\s+\w+Driver\(/gm)].map((m) => m[1]);
  if (drivers.length === 0) fail("no drivers found in src/DriverFactory.php");
  const defs = [
    { key: "php", label: "php", message: composer.require?.php },
    { key: "license", label: "licence", message: composer.license },
    { key: "drivers", label: "drivers", message: drivers.join(" · ") },
  ];
  for (const d of defs) {
    if (!d.message) fail(`no fact to badge for "${d.key}"`);
    const svg = makeBadge({
      label: d.label,
      message: d.message,
      labelColor: C.slate,
      color: C.gold,
      style: "flat-square",
    });
    record(`badges/${d.key}.svg`, svg);
  }
}

/* ------------------------------------------------------------- 3. the page */

async function openPage(browser) {
  const context = await browser.newContext({
    viewport: { width: 1280, height: 900 },
    deviceScaleFactor: 2,
    reducedMotion: "reduce", // every key already cut, the door already open
  });
  // file:// only: refuse anything that tries to leave the machine.
  await context.route(/^(https?|wss?):/, (route) => route.abort());
  const page = await context.newPage();
  await page.goto(pathToFileURL(path.join(siteDir, "index.html")).href, { waitUntil: "load" });
  await page.evaluate(() => document.fonts.ready);
  return { context, page };
}

async function assertClean(page, selector, what) {
  assertCleanText(await page.locator(selector).innerText(), what);
}

/* ------------------------------------------------------------------ 4. hero */

async function buildHero(page) {
  console.log("hero:");
  await page.addStyleTag({ content: ".shop-head, .toast { display: none !important; }" });
  await assertClean(page, ".hero", "hero");
  const png = await page.locator(".hero").screenshot();
  const jpg = await sharp(png).resize({ width: 1280 }).jpeg({ quality: 82, mozjpeg: true }).toBuffer();
  record("hero.jpg", jpg);
}

/* ----------------------------------------------------- 5. the key duplicator */

// What the duplicator needs from the page: the cas key exactly as the site
// defines it (the #key-cas symbol), the machine's own plate label, the .env
// line on the lock, and who walks in at the end.
async function readShop(page) {
  return page.evaluate(() => {
    const sym = document.querySelector("symbol#key-cas");
    if (!sym) return null;
    const bow = sym.querySelector(".k-body circle");
    const shoulder = sym.querySelector(".k-body rect");
    const blade = sym.querySelector(".k-body path");
    const hole = sym.querySelector(".k-hole");
    const groove = sym.querySelector(".k-groove");
    const stamp = sym.querySelector(".k-stamp");
    const num = (el, a) => Number(el.getAttribute(a));
    return {
      bow: { cx: num(bow, "cx"), cy: num(bow, "cy"), r: num(bow, "r") },
      shoulder: { x: num(shoulder, "x"), y: num(shoulder, "y"), w: num(shoulder, "width"), h: num(shoulder, "height"), rx: num(shoulder, "rx") },
      blade: blade.getAttribute("d"),
      hole: { cx: num(hole, "cx"), cy: num(hole, "cy"), r: num(hole, "r") },
      groove: groove.getAttribute("d"),
      stamp: stamp.textContent.trim(),
      plate: document.querySelector("#duplicator .m-label").textContent.trim(),
      cap: document.querySelector("#duplicator .m-cap").textContent.trim(),
      env: document.querySelector("[data-readout-line]").textContent.trim(),
      who: document.querySelector(".door__who").textContent.trim(),
      whoIn: document.querySelector(".door__in").textContent.trim(),
    };
  });
}

// Collapse a source file so a phrase that wraps across comment lines still
// matches: "the user must go\n     * to the identity provider" reads as one.
function flat(text) {
  return text.replace(/\s*\r?\n\s*(?:\*|\/\/)?\s*/g, " ");
}

function quote(file, phrase) {
  if (!flat(read(file)).includes(phrase)) fail(`caption "${phrase}" is not in ${file} any more`);
  return phrase;
}

function grab(file, re, what) {
  const m = read(file).match(re);
  if (!m) fail(`could not find ${what} in ${file}`);
  return m[1];
}

// The sign-in, step by step, out of src/. Each route is read from the code
// that serves or calls it; each caption is a phrase quoted from the source,
// the README or the front page, and the build fails if the phrase is gone.
function signInSteps() {
  const provider = "src/Laravel/SesameServiceProvider.php";
  const cas = "src/Drivers/CasDriver.php";

  const loginRoute = grab(provider, /\$router->get\('([^']+)'/, "the login route");
  const logoutRoute = grab(provider, /\$router->post\('([^']+)'/, "the logout route");
  // The CAS server's path comes from the README's own cas example; only the
  // path is used, never the host.
  const basePath = new URL(grab("README.md", /'base_url' => '([^']+)'/, "the cas base_url example")).pathname;
  const casLogin = grab(cas, /\$this->base \. '(\/login)\?'/, "the CAS login path");
  const serviceParam = grab(cas, /\$query = \['(service)' =>/, "the service parameter");
  const ticketPrefix = grab(cas, /preg_match\('\/\^(ST-)/, "the service-ticket prefix");
  const validate = grab(cas, /'3\.0' \? '[^']+' : '([^']+)'/, "the CAS 2.0 validate path");
  const casLogout = grab(cas, /\$this->base \. '(\/logout)\?/, "the CAS logout path");
  const resolver = grab("src/UserResolver.php", /function (findOrImportUser)\(Identity /, "the resolver");
  if (!read("src/Sesame.php").includes("$this->session->regenerate();")) fail("Sesame::login no longer regenerates the session");

  return [
    { route: `GET ${loginRoute}`, caption: quote("README.md", "A guest is sent to the login route") },
    { route: `${basePath}${casLogin}?${serviceParam}=…`, caption: quote("src/Driver.php", "the user must go to the identity provider") },
    { route: `?ticket=${ticketPrefix}…`, caption: quote(cas, "Only ST- service tickets are accepted.") },
    { route: `${basePath}${validate}`, caption: quote("site/index.html", "SESAME validates it once") },
    { route: `${resolver}(Identity)`, caption: quote("site/index.html", "A new session ID at login") },
    { route: `POST ${logoutRoute} → ${basePath}${casLogout}`, caption: quote("site/index.html", "Logout shuts the door.") },
  ];
}

// "M98,30 L102,30 ... Z" into points.
function parsePoly(d) {
  const pts = [...d.matchAll(/[ML]\s*(-?[\d.]+)\s*,\s*(-?[\d.]+)/g)].map((m) => ({ x: Number(m[1]), y: Number(m[2]) }));
  if (pts.length < 4 || !/Z\s*$/.test(d.trim())) fail("the cas key blade is not a closed M/L polygon any more");
  return pts;
}

function buildDuplicatorSvg(shop, steps, fonts) {
  const W = 1400;
  const H = 500;
  const font = "Lato, 'Segoe UI', Helvetica, Arial, sans-serif";
  const mono = "ui-monospace, SFMono-Regular, Menlo, Consolas, 'Liberation Mono', monospace";
  const sign = "Bungee, Lato, 'Arial Black', sans-serif";

  // The key: the bow and shoulder at a true scale, the blade stretched along
  // its length only, so the five cuts sit far enough apart to label. Depths
  // keep the site's proportions.
  const s = 2.4;
  const ox = 66;
  const keyTopY = 300;
  const pts = parsePoly(shop.blade);
  const top = pts[0].y; // the uncut edge of the blade
  const oy = keyTopY - top * s;
  const bladeX0 = pts[0].x;
  const tipX = Math.max(...pts.map((p) => p.x));
  const bx0 = ox + bladeX0 * s;
  const sx = (W - 72 - bx0) / (tipX - bladeX0);
  const X = (x) => +(x >= bladeX0 ? bx0 + (x - bladeX0) * sx : ox + x * s).toFixed(1);
  const Y = (y) => +(oy + y * s).toFixed(1);

  // The top edge, from the shoulder to the tip: what the tracer rides.
  const tipIndex = pts.findIndex((p) => p.x === tipX);
  const edge = pts.slice(0, tipIndex + 1);
  // Cuts: runs of points below the uncut edge.
  const cuts = [];
  for (let i = 1; i < edge.length - 1; i++) {
    if (edge[i].y > top && edge[i - 1].y === top) {
      let j = i;
      while (edge[j + 1].y > top) j++;
      cuts.push({ from: i - 1, to: j + 1, x: (edge[i].x + edge[j].x) / 2, depth: edge[i].y });
      i = j;
    }
  }
  if (cuts.length !== steps.length - 1) fail(`the cas key has ${cuts.length} cuts for ${steps.length - 1} sign-in steps`);
  // The last step (logout) rides the tip, where the key goes in first.
  const shoulderEnd = edge[cuts[cuts.length - 1].to];
  const marks = [
    ...cuts.map((c) => ({ x: X(c.x), y: Y(top), at: c.to })),
    { x: X((shoulderEnd.x + tipX) / 2), y: Y((shoulderEnd.y + edge[tipIndex].y) / 2), at: tipIndex },
  ];

  // Tracer timing: constant speed along the edge, start to tip.
  const T0 = 6;
  const T1 = 80;
  const lens = [0];
  for (let i = 1; i < edge.length; i++) {
    const dx = X(edge[i].x) - X(edge[i - 1].x);
    const dy = Y(edge[i].y) - Y(edge[i - 1].y);
    lens.push(lens[i - 1] + Math.hypot(dx, dy));
  }
  const total = lens[lens.length - 1];
  const pct = (i) => +(T0 + ((T1 - T0) * lens[i]) / total).toFixed(2);
  const tracerFrames = edge
    .map((p, i) => `      ${pct(i)}% { transform: translate(${X(p.x)}px, ${Y(p.y)}px); }`)
    .join("\n");
  const clipFrames = edge.map((p, i) => `      ${pct(i)}% { transform: translateX(${X(p.x)}px); }`).join("\n");

  const parked = { x: X(edge[tipIndex].x), y: Y(edge[tipIndex].y) };

  // Polygons in card space.
  const poly = (list) => "M" + list.map((p) => `${X(p.x)},${Y(p.y)}`).join(" L") + " Z";
  const cutBlade = poly(pts);
  const blankBlade = poly(pts.filter((p) => p.y === top || p.x >= shoulderEnd.x || p.y > edge[tipIndex].y));
  const cutEdge = "M" + edge.map((p) => `${X(p.x)},${Y(p.y)}`).join(" L");
  const groove = (() => {
    const m = shop.groove.match(/M\s*([\d.]+)\s*,\s*([\d.]+)\s*H\s*([\d.]+)/);
    if (!m) fail("the cas key groove is not an M..H line any more");
    return `M${X(+m[1])},${Y(+m[2])} H${X(+m[3])}`;
  })();
  const b = shop.bow;
  const sh = shop.shoulder;
  const ho = shop.hole;

  // Labels on two tiers above the key, alternating, so neighbours never
  // touch. Each one waits for its cut.
  const tiers = [
    { route: 120, caption: 142, lead: 152 },
    { route: 188, caption: 210, lead: 220 },
  ];
  const labelFrames = [];
  const labels = steps
    .map((st, i) => {
      const m = marks[i];
      const t = tiers[i % 2];
      const show = Math.min(pct(m.at) + 0.6, T1 + 1);
      labelFrames.push(`    .l${i} { animation: l${i} 10s linear infinite; }
    @keyframes l${i} { 0%, ${pct(m.at).toFixed(2)}% { opacity: 0; } ${show.toFixed(2)}%, 91% { opacity: 1; } 96%, 100% { opacity: 0; } }`);
      return `  <g class="lab l${i}">
    <path class="lead" d="M${m.x},${t.lead} V${m.y - 8}"/>
    <circle class="nail" cx="${m.x}" cy="${m.y - 8}" r="3"/>
    <text class="route" x="${m.x}" y="${t.route}" text-anchor="middle">${esc(st.route)}</text>
    <text class="cap" x="${m.x}" y="${t.caption}" text-anchor="middle">${esc(st.caption)}</text>
  </g>`;
    })
    .join("\n");

  const desc =
    `SESAME's key duplicator traces the cas key from the front page and cuts its five notches and tip, ` +
    `one sign-in step each: ${steps.map((st) => `${st.route} (${st.caption.replace(/\.$/, "")})`).join("; ")}. ` +
    `Then ${shop.who} ${shop.whoIn}`;

  const fontFace = fonts
    .map((f) => `    @font-face { font-family: "${f.family}"; font-weight: ${f.weight}; src: url(data:font/woff2;base64,${f.b64}) format("woff2"); }`)
    .join("\n");

  const pillX = 1092;
  const pillW = 252;

  return `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ${W} ${H}" role="img" aria-labelledby="dup-title dup-desc">
  <title id="dup-title">SESAME's key duplicator: one cut per sign-in step</title>
  <desc id="dup-desc">${esc(desc)}</desc>
  <style>
${fontFace}
    .plate { font-family: ${sign}; font-size: 19px; fill: ${C.gold}; letter-spacing: .5px; }
    .mcap { font-family: ${mono}; font-size: 16px; fill: ${C.capText}; }
    .route { font-family: ${mono}; font-size: 17px; font-weight: 700; fill: ${C.gold}; }
    .cap { font-family: ${font}; font-size: 15px; fill: ${C.capText}; }
    .lead { stroke: ${C.gold}; stroke-width: 1.5; stroke-dasharray: 2 4; opacity: .7; fill: none; }
    .nail { fill: ${C.gold}; }
    .k-body { fill: ${C.brass}; stroke: ${C.brassDark}; stroke-width: 3; stroke-linejoin: round; }
    .k-hole { fill: ${C.slate}; stroke: ${C.brassDark}; stroke-width: 3; }
    .k-groove { stroke: ${C.brassDark}; stroke-width: 2.5; opacity: .45; fill: none; }
    .k-stamp { font-family: ${sign}; font-size: 26px; fill: ${C.brassDark}; opacity: .85; }
    .fresh { fill: none; stroke: ${C.goldLight}; stroke-width: 3; stroke-linejoin: round; stroke-linecap: round; }
    .arm { fill: ${C.arm}; }
    .stylus { fill: ${C.gold}; stroke: ${C.brassDark}; stroke-width: 2; stroke-linejoin: round; }
    .slot { fill: #16211E; stroke: #4E6862; stroke-width: 2; }
    .env-k { font-family: ${mono}; font-size: 13px; fill: ${C.capText}; opacity: .8; }
    .env { font-family: ${mono}; font-size: 18px; font-weight: 700; fill: ${C.gold}; }
    .pill { fill: ${C.gold}; }
    .who { font-family: ${mono}; font-size: 20px; font-weight: 700; fill: ${C.greenDeep}; }
    .who-in { font-family: ${sign}; font-size: 16px; fill: ${C.greenDeep}; }

    /* The finished key is the resting state: with no animation (or reduced
       motion) every cut is made, every label is up, and the tracer is parked
       at the tip. */
    .tracer { transform: translate(${parked.x}px, ${parked.y}px); }
    .clip-cut { transform: translateX(${W}px); }
    .clip-blank { transform: translateX(${W}px); }

    .tracer { animation: trace 10s linear infinite; }
    .clip-cut { animation: reveal 10s linear infinite; }
    .clip-blank { animation: reveal 10s linear infinite; }
    .layer-cut { animation: cutfade 10s linear infinite; }
    .layer-blank { animation: blankfade 10s linear infinite; }
    .done { animation: done 10s linear infinite; }
    @keyframes trace {
      0% { transform: translate(${X(edge[0].x)}px, ${Y(edge[0].y)}px); }
${tracerFrames}
      91% { transform: translate(${parked.x}px, ${parked.y}px); }
      99%, 100% { transform: translate(${X(edge[0].x)}px, ${Y(edge[0].y)}px); }
    }
    @keyframes reveal {
      0% { transform: translateX(${X(edge[0].x)}px); }
${clipFrames}
      100% { transform: translateX(${W}px); }
    }
    @keyframes cutfade { 0%, 91% { opacity: 1; } 96%, 100% { opacity: 0; } }
    @keyframes blankfade { 0% { opacity: 0; } 4%, 100% { opacity: 1; } }
    @keyframes done { 0%, ${T1}% { opacity: 0; } ${T1 + 2}%, 91% { opacity: 1; } 96%, 100% { opacity: 0; } }
${labelFrames.join("\n")}

    @media (prefers-reduced-motion: reduce) {
      .tracer, .clip-cut, .clip-blank, .layer-cut, .layer-blank, .done, .lab { animation: none !important; }
      .lab, .done, .layer-cut { opacity: 1; }
    }
  </style>
  <defs>
    <clipPath id="cut-so-far"><rect class="clip-cut" x="${-W}" y="0" width="${W + 1.5}" height="${H}"/></clipPath>
    <clipPath id="blade-cut"><path d="${cutBlade}"/></clipPath>
    <clipPath id="blade-blank"><path d="${blankBlade}"/></clipPath>
    <clipPath id="not-yet"><rect class="clip-blank" x="0" y="0" width="${W}" height="${H}"/></clipPath>
  </defs>

  <rect x="0" y="0" width="${W}" height="${H}" rx="24" fill="${C.slate}"/>
  <text class="plate" x="56" y="62">${esc(shop.plate)}</text>
  <rect x="56" y="80" width="${W - 112}" height="6" rx="3" fill="${C.ink}"/>

${labels}

  <g class="key">
    <circle class="k-body" cx="${X(b.cx)}" cy="${Y(b.cy)}" r="${+(b.r * s).toFixed(1)}"/>
    <rect class="k-body" x="${X(sh.x)}" y="${Y(sh.y)}" width="${+(sh.w * s).toFixed(1)}" height="${+(sh.h * s).toFixed(1)}" rx="${+(sh.rx * s).toFixed(1)}"/>
    <g class="layer-blank"><g clip-path="url(#not-yet)">
      <path class="k-body" d="${blankBlade}"/>
      <path class="k-groove" clip-path="url(#blade-blank)" d="${groove}"/>
    </g></g>
    <g class="layer-cut" clip-path="url(#cut-so-far)">
      <path class="k-body" d="${cutBlade}"/>
      <path class="k-groove" clip-path="url(#blade-cut)" d="${groove}"/>
      <path class="fresh" d="${cutEdge}"/>
    </g>
    <circle class="k-hole" cx="${X(ho.cx)}" cy="${Y(ho.cy)}" r="${+(ho.r * s).toFixed(1)}"/>
    <text class="k-stamp" x="${X(56)}" y="${Y(49) + 4}" text-anchor="middle">${esc(shop.stamp)}</text>
  </g>

  <g class="tracer">
    <rect class="arm" x="-4" y="-62" width="8" height="40" rx="2"/>
    <path class="stylus" d="M-12,-24 L12,-24 L0,0 Z"/>
  </g>

  <text class="mcap" x="${X(bladeX0) + 4}" y="456">${esc(shop.cap)}</text>
  <rect class="slot" x="${pillX - 324}" y="428" width="304" height="44" rx="8"/>
  <text class="env-k" x="${pillX - 308}" y="455">.env</text>
  <text class="env" x="${pillX - 264}" y="457">${esc(shop.env)}</text>
  <g class="done">
    <rect class="pill" x="${pillX}" y="428" width="${pillW}" height="44" rx="22"/>
    <text x="${pillX + pillW / 2}" y="457" text-anchor="middle"><tspan class="who">${esc(shop.who)}</tspan><tspan class="who-in" dx="10">${esc(shop.whoIn)}</tspan></text>
  </g>
</svg>
`;
}

function fontB64(rel) {
  return readFileSync(path.join(siteDir, rel)).toString("base64");
}

async function buildDuplicator(page) {
  console.log("duplicator:");
  const shop = await readShop(page);
  if (!shop) fail("site/index.html has no #key-cas symbol");
  const steps = signInSteps();
  assertCleanText(JSON.stringify({ shop, steps }), "duplicator text");
  // The page's own faces, embedded so the plate and captions look the same
  // everywhere (Bungee is OFL, Lato is OFL; both ship with the site).
  const fonts = [
    { family: "Bungee", weight: 400, b64: fontB64("assets/fonts/bungee-latin-400-normal.woff2") },
    { family: "Lato", weight: 400, b64: fontB64("assets/wayne-ui/fonts/lato-400-latin.woff2") },
  ];
  const svg = buildDuplicatorSvg(shop, steps, fonts);
  assertCleanText(svg.replace(/data:[^)"]+/g, ""), "duplicator.svg");
  record("duplicator.svg", Buffer.from(svg, "utf8"));
}

/* -------------------------------------------------------------- 6. gallery */

// A contact sheet of three existing phone crops: the counter, the pegboard,
// the duplicator. (The key-ring crop is left out on purpose: it names an
// internal platform.)
async function buildGallery(page) {
  console.log("gallery:");
  const crops = ["mobile-02-hero.png", "mobile-03-keys.png", "mobile-04-duplicator.png"];
  for (const c of crops) {
    if (!existsSync(path.join(siteDir, "screenshots", "sections", c))) fail(`missing crop ${c}`);
  }
  const html = `<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<style>
  html, body { margin: 0; padding: 0; }
  .sheet { width: fit-content; background: ${C.slate}; padding: 40px; display: flex; gap: 32px; }
  img {
    box-sizing: border-box;
    display: block;
    width: 360px;
    height: 640px;
    object-fit: cover;
    object-position: top;
    border-radius: 14px;
    border: 1px solid rgba(214, 167, 60, .55);
  }
</style>
</head>
<body>
<div class="sheet">
${crops.map((c) => `  <img src="${pathToFileURL(path.join(siteDir, "screenshots", "sections", c)).href}" alt="">`).join("\n")}
</div>
</body>
</html>`;
  await page.setContent(html, { waitUntil: "load" });
  await assertClean(page, ".sheet", "gallery");
  const png = await page.locator(".sheet").screenshot();
  const jpg = await sharp(png).resize({ width: 1280 }).jpeg({ quality: 82, mozjpeg: true }).toBuffer();
  record("gallery.jpg", jpg);
}

/* ---------------------------------------------------------------- 7. board */

async function buildBoard() {
  console.log("board:");
  const src = path.join(siteDir, "brand", "sesame-board.png");
  if (!existsSync(src)) fail("site/brand/sesame-board.png is missing");
  let q = 82;
  let jpg;
  do {
    jpg = await sharp(src).resize({ width: 1400 }).jpeg({ quality: q, mozjpeg: true }).toBuffer();
    q -= 6;
  } while (jpg.length > MAX_BYTES && q >= 60);
  record("board.jpg", jpg);
}

/* -------------------------------------------------------------------- main */

async function main() {
  await buildCast();
  buildBadges();
  await buildBoard();

  const browser = await chromium.launch();
  try {
    const { context, page } = await openPage(browser);
    await buildDuplicator(page); // before the hero hides anything
    await buildHero(page);
    await buildGallery(page);
    await context.close();
  } finally {
    await browser.close();
  }

  let over = false;
  for (const { rel, bytes } of outputs) {
    if (bytes > MAX_BYTES) {
      console.error(`FAIL: ${rel} is ${bytes} bytes (limit ${MAX_BYTES})`);
      over = true;
    }
  }
  if (over) process.exit(1);
  console.log(`done: ${outputs.length} files, all at or under ${MAX_BYTES} bytes`);
}

main().catch((err) => {
  console.error(err);
  process.exit(1);
});
