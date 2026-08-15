#!/usr/bin/env node
/**
 * Syntax-check the inline <script> blocks of every HTML page in the repo.
 *
 * WHY THIS EXISTS. The portals carry thousands of lines of inline JavaScript —
 * the student portal alone is larger than most files in assets/js — and CI's
 * `node --check` step only ever looked at assets/js. A syntax error in a
 * portal's inline script shipped GREEN: the page loads, the IIFE throws at
 * parse time, and the portal renders an empty shell. Nothing in the pipeline
 * had an opinion about it.
 *
 *   node .github/scripts/check-inline-js.cjs [file.html ...]
 *
 * With no arguments it walks the repo. Blocks with a `src` are skipped (those
 * files are checked directly by the assets/js step), as are non-JS types like
 * application/ld+json and text/template.
 *
 * Line numbers are reported against the HTML FILE, not the extracted blob, so
 * a failure points at somewhere you can actually go and look.
 */
'use strict';
const fs = require('fs');
const path = require('path');
const vm = require('vm');

const ROOT = path.resolve(__dirname, '..', '..');
const SKIP_DIRS = new Set(['.git', 'node_modules', 'vendor', 'storage']);

function walk(dir, out) {
  for (const e of fs.readdirSync(dir, { withFileTypes: true })) {
    if (e.isDirectory()) {
      if (SKIP_DIRS.has(e.name)) continue;
      walk(path.join(dir, e.name), out);
    } else if (e.name.endsWith('.html')) {
      out.push(path.join(dir, e.name));
    }
  }
  return out;
}

// A script block is JS unless its type says otherwise. Bare `type` values like
// application/ld+json and text/template are DATA — parsing them as JS would
// invent failures that are not bugs.
const JS_TYPES = new Set(['', 'text/javascript', 'application/javascript', 'module']);

function blocks(html) {
  const out = [];
  const re = /<script\b([^>]*)>([\s\S]*?)<\/script\s*>/gi;
  let m;
  while ((m = re.exec(html)) !== null) {
    const attrs = m[1] || '';
    if (/\bsrc\s*=/i.test(attrs)) continue; // external file — checked elsewhere
    const type = (attrs.match(/\btype\s*=\s*["']?([^"'\s>]*)/i) || ['', ''])[1].toLowerCase();
    if (!JS_TYPES.has(type)) continue;
    // Line number of the block's first line of code, 1-indexed.
    const line = html.slice(0, m.index + m[0].indexOf('>') + 1).split('\n').length;
    out.push({ code: m[2], line, module: type === 'module' });
  }
  return out;
}

const files = process.argv.length > 2
  ? process.argv.slice(2).map((f) => path.resolve(f))
  : walk(ROOT, []).sort();

let checked = 0, failed = 0;
for (const file of files) {
  // Repo-relative when it is in the repo; absolute otherwise, so an ad-hoc
  // check of a file elsewhere doesn't print a ladder of '../'.
  const r = path.relative(ROOT, file);
  const rel = r.startsWith('..') ? file : r;
  const html = fs.readFileSync(file, 'utf8');
  const bs = blocks(html);
  if (!bs.length) continue;
  for (const b of bs) {
    checked++;
    try {
      // new vm.Script parses without executing — exactly `node --check`, but
      // for a string. Modules need the sourceType hint or `import` is a
      // syntax error in an otherwise valid block.
      new vm.Script(b.code, { filename: rel, ...(b.module ? { importModuleDynamically: () => {} } : {}) });
    } catch (err) {
      failed++;
      // vm reports the line within the block; add the block's offset in the file.
      const inBlock = (String(err.stack || '').match(/^[^\n]*:(\d+)\n/m) || [])[1];
      const at = inBlock ? b.line + parseInt(inBlock, 10) - 1 : b.line;
      console.error(`✗ ${rel}:${at} — ${err.message}`);
    }
  }
}

if (failed) {
  console.error(`\n❌ ${failed} of ${checked} inline script block(s) failed to parse`);
  process.exit(1);
}
console.log(`✅ ${checked} inline script block(s) parse cleanly across ${files.length} HTML file(s)`);
