// A4 after-shots: same pages as the A1–A3 evidence, new visual identity.
// Credentials via environment: A1_PASSWORD, A2_PASSWORD, A3_PASSWORD.
//   docker compose -f compose.dev.yaml exec -T -e ... node node /var/www/muse/results/shots-a4.cjs
const { chromium } = require('/tmp/pw/node_modules/playwright');

(async () => {
    const base = 'http://nginx';
    const out = '/var/www/muse/results';
    const browser = await chromium.launch();
    const errors = [];

    const login = async (email, password, viewport) => {
        const ctx = await browser.newContext(viewport ? { viewport } : { viewport: { width: 1440, height: 900 } });
        const page = await ctx.newPage();
        page.on('pageerror', (e) => errors.push(`${email}: ${e.message}`));
        await page.goto(`${base}/login`, { waitUntil: 'networkidle' });
        await page.fill('input[name="email"]', email);
        await page.fill('input[name="password"]', password);
        await Promise.all([
            page.waitForURL('**/dashboard', { timeout: 15000 }).catch(() => page.waitForLoadState('networkidle')),
            page.click('button[type="submit"]'),
        ]);
        return { ctx, page };
    };

    const shot = async (page, name, url, opts = {}) => {
        await page.goto(url, { waitUntil: 'networkidle' });
        await page.waitForTimeout(1500);
        // Confirm the self-hosted font actually applied (no Figtree fallback).
        const family = await page.evaluate(() => getComputedStyle(document.body).fontFamily);
        if (!/Source Sans 3/i.test(family)) {
            throw new Error(`ASSERT: Source Sans 3 not applied on ${url}: ${family}`);
        }
        await page.screenshot({ path: `${out}/${name}`, fullPage: true, ...opts });
        console.log(`saved ${name} (${family.split(',')[0]})`);
    };

    const a1 = await login('a1-owner@rev2square.test', process.env.A1_PASSWORD);
    const a3 = await login('a3-member@rev2square.test', process.env.A3_PASSWORD);

    const anon = await browser.newContext({ viewport: { width: 1440, height: 900 } });
    const anonPage = await anon.newPage();
    anonPage.on('pageerror', (e) => errors.push(`anon: ${e.message}`));
    await shot(anonPage, 'a4-after-home.png', `${base}/`);

    await shot(a1.page, 'a4-after-project.png', `${base}/projects/a1-community-walkman-20261027`);
    await shot(a1.page, 'a4-after-discussion.png', `${base}/projects/a2-discuss-o-tron-20261027/discussion/1`);
    await shot(a3.page, 'a4-after-profile.png', `${base}/u/a3-member`);
    await shot(a3.page, 'a4-after-dashboard.png', `${base}/dashboard`);

    const mob = await browser.newContext({ viewport: { width: 390, height: 844 } });
    const mpage = await mob.newPage();
    mpage.on('pageerror', (e) => errors.push(`mobile: ${e.message}`));
    await shot(mpage, 'a4-after-home-mobile.png', `${base}/`);

    const mobAuth = await login('a3-member@rev2square.test', process.env.A3_PASSWORD, { width: 390, height: 844 });
    await shot(mobAuth.page, 'a4-after-project-mobile.png', `${base}/projects/a1-community-walkman-20261027`);

    await browser.close();

    const fatal = errors.filter((e) => !/favicon/i.test(e));
    if (fatal.length > 0) {
        console.error(`PAGE ERRORS:\n${fatal.join('\n')}`);
        process.exit(2);
    }
    console.log('A4 after-shots captured with no page errors.');
})().catch((e) => { console.error(e); process.exit(1); });
