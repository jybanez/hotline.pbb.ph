import { readFile } from 'node:fs/promises';
import { chromium } from 'playwright';

const [, , inputPath, outputPath] = process.argv;

if (!inputPath || !outputPath) {
  console.error('Usage: node bin/render-sitrep-pdf.mjs <input.html> <output.pdf>');
  process.exit(2);
}

const html = await readFile(inputPath, 'utf8');
const browser = await chromium.launch({ headless: true });
const printReport = process.env.SITREP_PRINT_REPORT || 'Report';
const printedAt = process.env.SITREP_PRINTED_AT || 'Printed';
const printedBy = process.env.SITREP_PRINTED_BY || 'By Unknown user';

const runningTemplateStyles = `
  <style>
    body {
      margin: 0;
      background: #07121a;
    }
    .sitrep-print-running {
      align-items: center;
      box-sizing: border-box;
      color: #c8d7e4;
      display: flex;
      font-family: "Segoe UI", Arial, sans-serif;
      font-size: 7px;
      gap: 12px;
      justify-content: space-between;
      margin: 0 32px;
      padding: 0;
      width: calc(100% - 64px);
      -webkit-print-color-adjust: exact;
      print-color-adjust: exact;
    }
    .sitrep-print-running.is-header {
      padding-top: 14px;
    }
    .sitrep-print-running.is-footer {
      padding-bottom: 14px;
    }
    .sitrep-print-running span {
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
    }
  </style>
`;

try {
  const page = await browser.newPage({
    viewport: {
      width: 1280,
      height: 1600,
      deviceScaleFactor: 1,
    },
  });

  await page.emulateMedia({ media: 'screen' });
  await page.setContent(html, {
    waitUntil: 'load',
  });
  await page.pdf({
    path: outputPath,
    format: 'A4',
    displayHeaderFooter: true,
    headerTemplate: `
      ${runningTemplateStyles}
      <div class="sitrep-print-running is-header">
        <span>${escapeHtml(printReport)}</span>
        <span>Page <span class="pageNumber"></span>/<span class="totalPages"></span></span>
      </div>
    `,
    footerTemplate: `
      ${runningTemplateStyles}
      <div class="sitrep-print-running is-footer">
        <span>${escapeHtml(printedAt)}</span>
        <span>${escapeHtml(printedBy)}</span>
      </div>
    `,
    margin: {
      top: '54px',
      right: '32px',
      bottom: '54px',
      left: '32px',
    },
    printBackground: true,
    preferCSSPageSize: false,
  });
} finally {
  await browser.close();
}

function escapeHtml(value) {
  return String(value ?? '')
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&#039;');
}
