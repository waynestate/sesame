#!/usr/bin/env node
/**
 * Site accessibility gate.
 *
 * Serves the site folder on a local port, opens each page in a real Chromium
 * browser and runs axe-core against it with the WCAG 2.2 AA tag set, in light
 * mode and in dark mode (colorScheme emulation) at a narrow (360 px) and a
 * wide (1280 px) viewport. Each page is analyzed with reduced motion, which
 * is the page's own static, fully visible state, so scroll reveals never hide
 * content from the check.
 *
 * Usage:  node tools/a11y/check.mjs [--root=site] [--page=PAGE]
 *         --page may repeat. PAGE is a path under the root, or a glob with the
 *         characters star, question mark and brackets (double star crosses
 *         folders). A glob that matches nothing is skipped, so one script
 *         covers repos with and without extra folders. A literal page that
 *         does not exist is a tool error.
 * Exit:   0 clean, 1 violations found, 2 tool error.
 */
import { createServer } from "node:http";
import { existsSync, readdirSync } from "node:fs";
import { readFile } from "node:fs/promises";
import path from "node:path";
import process from "node:process";
import { fileURLToPath } from "node:url";

import { chromium } from "playwright";
import AxeBuilder from "@axe-core/playwright";

const TAGS = ["wcag2a", "wcag2aa", "wcag21a", "wcag21aa", "wcag22aa"];
const MODES = ["light", "dark"];
const WIDTHS = [360, 1280];
const HEIGHT = 900;

const MIME = {
  ".css": "text/css; charset=utf-8",
  ".gif": "image/gif",
  ".html": "text/html; charset=utf-8",
  ".ico": "image/x-icon",
  ".jpg": "image/jpeg",
  ".jpeg": "image/jpeg",
  ".js": "text/javascript; charset=utf-8",
  ".json": "application/json; charset=utf-8",
  ".md": "text/markdown; charset=utf-8",
  ".mjs": "text/javascript; charset=utf-8",
  ".png": "image/png",
  ".svg": "image/svg+xml",
  ".txt": "text/plain; charset=utf-8",
  ".woff": "font/woff",
  ".woff2": "font/woff2",
  ".xml": "application/xml; charset=utf-8",
};

function option(name, fallback) {
  const prefix = `--${name}=`;
  const hit = process.argv.slice(2).find((arg) => arg.startsWith(prefix));
  return hit ? hit.slice(prefix.length) : fallback;
}

function options(name) {
  const prefix = `--${name}=`;
  return process.argv.slice(2).filter((arg) => arg.startsWith(prefix)).map((arg) => arg.slice(prefix.length));
}

function isGlob(value) {
  return /[*?[\]]/.test(value);
}

function globSegmentToRegex(segment) {
  let out = "^";
  for (let i = 0; i < segment.length; i++) {
    const ch = segment[i];
    if (ch === "*") out += "[^/]*";
    else if (ch === "?") out += "[^/]";
    else if (ch === "[") {
      const close = segment.indexOf("]", i + 1);
      if (close === -1) {
        out += "\\[";
        continue;
      }
      let cls = segment.slice(i + 1, close);
      if (cls.startsWith("!")) cls = `^${cls.slice(1)}`;
      out += `[${cls}]`;
      i = close;
    } else out += ch.replace(/[.+^${}()|\\]/g, "\\$&");
  }
  return new RegExp(`${out}$`);
}

/** Expand a glob relative to rootDir; ** crosses folders, * and ? stay inside one. */
function expandGlob(rootDir, pattern) {
  const segments = pattern.split("/").filter((s) => s !== "");
  const matches = new Set();
  const walk = (dir, remaining) => {
    if (remaining.length === 0) {
      matches.add(path.relative(rootDir, dir).split(path.sep).join("/"));
      return;
    }
    let entries;
    try {
      entries = readdirSync(dir, { withFileTypes: true });
    } catch {
      return;
    }
    const [head, ...rest] = remaining;
    if (head === "**") {
      walk(dir, rest); // zero folders
      for (const entry of entries) {
        if (entry.isDirectory()) walk(path.join(dir, entry.name), remaining);
      }
      return;
    }
    const regex = globSegmentToRegex(head);
    for (const entry of entries) {
      if (!regex.test(entry.name)) continue;
      const full = path.join(dir, entry.name);
      if (rest.length === 0) {
        if (entry.isFile()) matches.add(path.relative(rootDir, full).split(path.sep).join("/"));
      } else if (entry.isDirectory()) {
        walk(full, rest);
      }
    }
  };
  walk(rootDir, segments);
  return [...matches].sort();
}

/** Resolve every --page value to a flat, ordered, de-duplicated list of pages. */
function resolvePages(rootDir, pageValues) {
  const pages = [];
  const seen = new Set();
  const add = (page) => {
    if (!seen.has(page)) {
      seen.add(page);
      pages.push(page);
    }
  };
  for (const value of pageValues) {
    if (isGlob(value)) {
      for (const page of expandGlob(rootDir, value)) add(page);
    } else {
      add(value);
    }
  }
  return pages;
}

/** Serve rootDir over http on an ephemeral port; "/" maps to index.html when present. */
async function serve(rootDir, fallbackPage) {
  const home = existsSync(path.join(rootDir, "index.html")) ? "index.html" : fallbackPage;
  const server = createServer((req, res) => {
    (async () => {
      try {
        const url = new URL(req.url, "http://127.0.0.1");
        let pathname = decodeURIComponent(url.pathname);
        if (pathname === "/") pathname = `/${home}`;
        const filePath = path.normalize(path.join(rootDir, pathname));
        if (filePath !== rootDir && !filePath.startsWith(rootDir + path.sep)) {
          throw new Error(`outside the site root: ${pathname}`);
        }
        const body = await readFile(filePath);
        const type = MIME[path.extname(filePath).toLowerCase()] || "application/octet-stream";
        res.writeHead(200, { "content-type": type });
        res.end(body);
      } catch {
        res.writeHead(404, { "content-type": "text/plain; charset=utf-8" });
        res.end("not found");
      }
    })();
  });
  await new Promise((resolve, reject) => {
    server.once("error", reject);
    server.listen(0, "127.0.0.1", resolve);
  });
  return { server, base: `http://127.0.0.1:${server.address().port}` };
}

async function main() {
  const repoRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..", "..");
  const rootDir = path.resolve(repoRoot, option("root", "site"));
  const pageValues = options("page");
  if (pageValues.length === 0) pageValues.push(option("page", "index.html"));
  const pages = resolvePages(rootDir, pageValues);
  if (pages.length === 0) throw new Error("no pages to check: every --page glob matched nothing");
  for (const page of pages) {
    if (!isGlob(page) && !existsSync(path.join(rootDir, page))) {
      throw new Error(`page not found under ${path.relative(repoRoot, rootDir) || "."}: ${page}`);
    }
  }

  const { server, base } = await serve(rootDir, pages[0]);
  const browser = await chromium.launch();
  let grandTotal = 0;
  try {
    for (const page of pages) {
      const passes = [];
      for (const scheme of MODES) {
        for (const width of WIDTHS) {
          const context = await browser.newContext({
            colorScheme: scheme,
            reducedMotion: "reduce",
            viewport: { width, height: HEIGHT },
          });
          const tab = await context.newPage();
          await tab.goto(`${base}/${page}`, { waitUntil: "load" });
          await tab.evaluate(() => document.fonts.ready);
          const results = await new AxeBuilder({ page: tab }).withTags(TAGS).analyze();
          passes.push({ scheme, width, violations: results.violations });
          await context.close();
        }
      }
      const total = passes.reduce((sum, pass) => sum + pass.violations.length, 0);
      grandTotal += total;
      console.log(`${page}: ${total} violation${total === 1 ? "" : "s"}`);
      for (const pass of passes) {
        if (pass.violations.length === 0) continue;
        console.log(`  ${pass.scheme} ${pass.width}px:`);
        for (const violation of pass.violations) {
          console.log(`    ${violation.id} (${violation.impact}): ${violation.help}`);
          for (const node of violation.nodes) {
            console.log(`      ${node.target.join(" ")}`);
          }
        }
      }
    }
  } finally {
    await browser.close();
    server.close();
  }
  console.log(`checked ${pages.length} page${pages.length === 1 ? "" : "s"}, ${grandTotal} violation${grandTotal === 1 ? "" : "s"}`);
  process.exit(grandTotal === 0 ? 0 : 1);
}

main().catch((err) => {
  console.error(`tool error: ${err && err.message ? err.message : err}`);
  process.exit(2);
});
