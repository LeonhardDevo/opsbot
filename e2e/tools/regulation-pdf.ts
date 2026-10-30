import { readFileSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import { pathToFileURL } from 'node:url';
import { chromium } from '@playwright/test';
import { E2E_DIR } from '../support/config.ts';

const ROOT = join(E2E_DIR, '..');
const PACK = join(ROOT, 'apps', 'web', 'resources', 'starter-packs', 'cleaning');
const FONTS = join(ROOT, 'apps', 'web', 'node_modules', '@fontsource-variable', 'onest', 'files');
const OUTPUT = join(E2E_DIR, 'data', 'regulation.pdf');

const escapeHtml = (value: string): string => value.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');

export function markdownToHtml(markdown: string): string {
  const html: string[] = [];
  let list: string[] = [];
  let paragraph: string[] = [];
  const flushParagraph = () => {
    if (paragraph.length) html.push(`<p>${escapeHtml(paragraph.join(' '))}</p>`);
    paragraph = [];
  };
  const flushList = () => {
    if (list.length) html.push(`<ol>${list.map((item) => `<li>${escapeHtml(item)}</li>`).join('')}</ol>`);
    list = [];
  };
  for (const raw of markdown.split(/\r?\n/)) {
    const line = raw.trim();
    const heading = /^(#{1,3})\s+(.*)$/.exec(line);
    const item = /^\d+\.\s+(.*)$/.exec(line);
    if (!line) {
      flushParagraph();
      flushList();
    } else if (heading) {
      flushParagraph();
      flushList();
      html.push(`<h${heading[1]!.length}>${escapeHtml(heading[2]!)}</h${heading[1]!.length}>`);
    } else if (item) {
      flushParagraph();
      list.push(item[1]!);
    } else if (list.length) {
      list[list.length - 1] += ` ${line}`;
    } else {
      paragraph.push(line);
    }
  }
  flushParagraph();
  flushList();
  return html.join('\n');
}

function fontFace(file: string, range: string): string {
  return `@font-face{font-family:Onest;font-weight:100 900;src:url('${pathToFileURL(join(FONTS, file)).href}') format('woff2');unicode-range:${range};}`;
}

export function regulationHtml(): string {
  const pages = ['en', 'ru'].map((locale) => readFileSync(join(PACK, locale, 'damage-property.md'), 'utf8'));
  const css = [
    fontFace('onest-latin-wght-normal.woff2', 'U+0000-00FF,U+2000-206F,U+2116'),
    fontFace('onest-cyrillic-wght-normal.woff2', 'U+0400-045F,U+0490-0491,U+04B0-04B1'),
    'body{font-family:Onest,sans-serif;font-size:12pt;line-height:1.45;color:#111;margin:0}',
    'section{padding:18mm 20mm;break-after:page}',
    'section:last-child{break-after:auto}',
    'h1{font-size:18pt}h2{font-size:14pt;margin-top:14pt}',
  ].join('\n');
  return `<!doctype html><html><head><meta charset="utf-8"><style>${css}</style></head><body>${pages
    .map((markdown) => `<section>${markdownToHtml(markdown)}</section>`)
    .join('')}</body></html>`;
}

export async function buildRegulationPdf(output = OUTPUT): Promise<string> {
  const browser = await chromium.launch();
  try {
    const page = await browser.newPage();
    const htmlPath = join(E2E_DIR, 'test-results', 'regulation.html');
    writeFileSync(htmlPath, regulationHtml(), 'utf8');
    await page.goto(pathToFileURL(htmlPath).href);
    await page.evaluate(() => document.fonts.ready);
    writeFileSync(output, await page.pdf({ format: 'A4', printBackground: false, tagged: true }));
    return output;
  } finally {
    await browser.close();
  }
}

if (import.meta.url === pathToFileURL(process.argv[1] ?? '').href) {
  console.log(await buildRegulationPdf());
}
