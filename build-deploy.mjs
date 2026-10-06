// Assembles deploy/ (and deploy.zip) — exactly the files that belong in Hostinger's
// public_html, and nothing else. Everything else in the repo (briefs, the logo pack,
// original speaker photos, dev tooling) deliberately stays out: anything uploaded is
// publicly readable at 360webinaruaoa.online/<name>.
import fs from 'fs';
import path from 'path';
import { execFileSync } from 'child_process';

const OUT = 'deploy';

const PAGES = ['index.html'];

// served as-is, referenced from the head or the manifest rather than the body
const ROOT_FILES = [
  '.htaccess',
  'send-form.php',
  'favicon.ico', 'apple-touch-icon.png',
  'icon-192.png', 'icon-512.png',
  'site.webmanifest', 'robots.txt', 'sitemap.xml',
];

// never leaves the machine: retired art
const EXCLUDE = ['img/yankiv-old.jpg', 'img/.DS_Store'];

fs.rmSync(OUT, { recursive: true, force: true });
fs.rmSync(OUT + '.zip', { force: true });

const copy = (src, dest) => {
  fs.mkdirSync(path.dirname(dest), { recursive: true });
  fs.copyFileSync(src, dest);
};

let count = 0, bytes = 0;
const add = (rel) => {
  if (!fs.existsSync(rel)) throw new Error(`missing: ${rel}`);
  copy(rel, path.join(OUT, rel));
  count++; bytes += fs.statSync(rel).size;
};

for (const p of [...PAGES, ...ROOT_FILES]) add(p);

const walk = (dir) => {
  for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
    const rel = path.join(dir, entry.name);
    if (EXCLUDE.includes(rel) || entry.name.startsWith('.')) continue;
    if (entry.isDirectory()) walk(rel);
    else add(rel);
  }
};
walk('img');

// --- verify: every local asset the pages ask for is in the bundle ---------------
const shipped = new Set();
const collect = (dir) => {
  for (const e of fs.readdirSync(dir, { withFileTypes: true })) {
    const rel = path.join(dir, e.name);
    e.isDirectory() ? collect(rel) : shipped.add(path.relative(OUT, rel));
  }
};
collect(OUT);

const missing = new Set();
for (const page of PAGES) {
  const html = fs.readFileSync(page, 'utf8');
  for (const m of html.matchAll(/(?:src|href|action)="([^"#:?]+\.[a-z0-9]{2,11})"/gi)) {
    const asset = m[1].replace(/^\.?\//, '');
    if (!shipped.has(asset)) missing.add(`${page} -> ${asset}`);
  }
}
const manifest = JSON.parse(fs.readFileSync('site.webmanifest', 'utf8'));
for (const icon of manifest.icons || []) if (!shipped.has(icon.src)) missing.add(`site.webmanifest -> ${icon.src}`);

console.log(`${OUT}/  ${count} files, ${(bytes / 1024 / 1024).toFixed(1)} MB`);
if (missing.size) {
  console.log('\nreferenced but NOT bundled:');
  for (const m of missing) console.log('  ' + m);
  process.exit(1);
}
console.log('every referenced asset is present');

try {
  execFileSync('zip', ['-qr', path.join('..', OUT + '.zip'), '.'], { cwd: OUT });
  console.log(`${OUT}.zip  ready to upload`);
} catch {
  console.log(`(zip not available — upload the contents of ${OUT}/ instead)`);
}
console.log('\nstill to create by hand on the server: public_html/config.php');
