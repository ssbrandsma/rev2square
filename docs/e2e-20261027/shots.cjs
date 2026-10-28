// Captures the R2S E2E validation screenshots. Credentials via environment:
// E2E_EMAIL, E2E_PASSWORD, DRAFT_REPORT_ID. Run from the node container:
//   E2E_EMAIL=... E2E_PASSWORD=... DRAFT_REPORT_ID=4 node docs/e2e-20261027/shots.cjs
const fs = require('fs');
const { chromium } = require('/tmp/pw/node_modules/playwright');

(async () => {
    const base = 'http://nginx';
    const out = '/var/www/docs/e2e-20261027';
    fs.mkdirSync(out, { recursive: true });
    const browser = await chromium.launch();
    const page = await browser.newPage({ viewport: { width: 1440, height: 900 } });

    await page.goto(`${base}/login`, { waitUntil: 'networkidle' });
    await page.fill('input[name="email"]', process.env.E2E_EMAIL);
    await page.fill('input[name="password"]', process.env.E2E_PASSWORD);
    await Promise.all([
        page.waitForURL('**/dashboard', { timeout: 15000 }).catch(() => page.waitForLoadState('networkidle')),
        page.click('button[type="submit"]'),
    ]);

    const shot = async (name, url) => {
        await page.goto(url, { waitUntil: 'networkidle' });
        await page.waitForTimeout(1800);
        await page.screenshot({ path: `${out}/${name}`, fullPage: true });
        console.log(`saved ${name}`);
    };

    // Project 5 import is dispatched just before this script: this lands mid-import.
    await shot('01-evidence-importing.png', `${base}/projects/e2e-bose-import-shot2-20261027/evidence`);
    await shot('02-evidence-ready.png', `${base}/projects/e2e-bose-local-20261027/evidence`);
    await shot('03-report-draft.png', `${base}/projects/e2e-bose-local-20261027/reports/${process.env.DRAFT_REPORT_ID}`);
    await shot('04-report-approved.png', `${base}/projects/e2e-bose-local-20261027/reports/3`);
    await shot('05-project-published.png', `${base}/projects/e2e-bose-local-20261027`);

    await browser.close();
})().catch((e) => { console.error(e); process.exit(1); });
