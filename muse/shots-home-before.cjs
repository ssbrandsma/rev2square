const { chromium } = require('/tmp/pw/node_modules/playwright');
(async () => {
    const browser = await chromium.launch();
    const page = await (await browser.newContext({ viewport: { width: 1440, height: 900 } })).newPage();
    await page.goto('http://nginx/', { waitUntil: 'networkidle' });
    await page.waitForTimeout(1200);
    await page.screenshot({ path: '/var/www/muse/results/a4-before-home.png', fullPage: true });
    const mpage = await (await browser.newContext({ viewport: { width: 390, height: 844 } })).newPage();
    await mpage.goto('http://nginx/', { waitUntil: 'networkidle' });
    await mpage.waitForTimeout(1200);
    await mpage.screenshot({ path: '/var/www/muse/results/a4-before-home-mobile.png', fullPage: true });
    await browser.close();
    console.log('before shots saved');
})().catch((e) => { console.error(e); process.exit(1); });
