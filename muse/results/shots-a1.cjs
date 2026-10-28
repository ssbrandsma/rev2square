// A1 browser validation screenshots. Credentials via environment:
// A1_PASSWORD, A1_TASK_OPEN, A1_OFFER, A1_REPORT.
//   docker compose -f compose.dev.yaml exec -T node node /var/www/muse/results/shots-a1.cjs
const fs = require('fs');
const { chromium } = require('/tmp/pw/node_modules/playwright');

(async () => {
    const base = 'http://nginx';
    const out = '/var/www/muse/results';
    fs.mkdirSync(out, { recursive: true });
    const slug = 'a1-community-walkman-20261027';
    const browser = await chromium.launch();

    const login = async (email) => {
        const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 } });
        const page = await ctx.newPage();
        await page.goto(`${base}/login`, { waitUntil: 'networkidle' });
        await page.fill('input[name="email"]', email);
        await page.fill('input[name="password"]', process.env.A1_PASSWORD);
        await Promise.all([
            page.waitForURL('**/dashboard', { timeout: 15000 }).catch(() => page.waitForLoadState('networkidle')),
            page.click('button[type="submit"]'),
        ]);
        return { ctx, page };
    };

    const shot = async (page, name, url, opts = {}) => {
        await page.goto(url, { waitUntil: 'networkidle' });
        await page.waitForTimeout(1500);
        await page.screenshot({ path: `${out}/${name}`, fullPage: true, ...opts });
        console.log(`saved ${name}`);
    };

    const owner = await login('a1-owner@rev2square.test');
    const contrib = await login('a1-contrib@rev2square.test');

    await shot(contrib.page, 'a1-01-project.png', `${base}/projects/${slug}`);
    await shot(owner.page, 'a1-02-task-detail.png', `${base}/projects/${slug}/tasks/${process.env.A1_TASK_OPEN}`);
    await shot(contrib.page, 'a1-03-offer-thread.png', `${base}/projects/${slug}/offers/${process.env.A1_OFFER}`);
    await shot(owner.page, 'a1-04-inbox.png', `${base}/inbox`);
    await shot(owner.page, 'a1-05-team.png', `${base}/projects/${slug}/team`);
    await shot(contrib.page, 'a1-06-task-directory.png', `${base}/tasks?skill=documentation&hardware=no&status=OPEN`);
    await shot(contrib.page, 'a1-07-test-report.png', `${base}/projects/${slug}/test-reports/${process.env.A1_REPORT}`);
    await shot(owner.page, 'a1-08-evidence-humans.png', `${base}/projects/${slug}/evidence`);

    const mob = await browser.newContext({ viewport: { width: 390, height: 844 } });
    const mpage = await mob.newPage();
    await mpage.goto(`${base}/login`, { waitUntil: 'networkidle' });
    await mpage.fill('input[name="email"]', 'a1-contrib@rev2square.test');
    await mpage.fill('input[name="password"]', process.env.A1_PASSWORD);
    await Promise.all([
        mpage.waitForURL('**/dashboard', { timeout: 15000 }).catch(() => mpage.waitForLoadState('networkidle')),
        mpage.click('button[type="submit"]'),
    ]);
    await shot(mpage, 'a1-09-mobile-project.png', `${base}/projects/${slug}`);

    await browser.close();
})().catch((e) => { console.error(e); process.exit(1); });
