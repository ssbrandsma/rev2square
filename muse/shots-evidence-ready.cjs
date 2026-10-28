const { chromium } = require('/tmp/pw/node_modules/playwright');
(async () => {
    const browser = await chromium.launch();
    const page = await (await browser.newContext({ viewport: { width: 1440, height: 900 } })).newPage();
    await page.goto('http://nginx/login', { waitUntil: 'networkidle' });
    await page.fill('input[name="email"]', 'fj-owner@rev2square.test');
    await page.fill('input[name="password"]', process.env.FJ_PASSWORD);
    await Promise.all([
        page.waitForURL('**/dashboard', { timeout: 15000 }).catch(() => page.waitForLoadState('networkidle')),
        page.click('button[type="submit"]'),
    ]);
    await page.goto('http://nginx/projects/final-journey-rig/evidence', { waitUntil: 'networkidle' });
    await page.waitForTimeout(2000);
    const body = await page.content();
    if (!/613b40e|Ready|evidence sources/i.test(body)) throw new Error('evidence not showing ready state');
    await page.screenshot({ path: '/var/www/muse/results/final-05-evidence-ready.png', fullPage: true });
    await browser.close();
    console.log('saved final-05-evidence-ready.png');
})().catch((e) => { console.error(e); process.exit(1); });
