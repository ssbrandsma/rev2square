// Final combined journey: create -> import (live) -> evidence -> discussion ->
// chat -> profile/workspace. Import status is polled from outside (tinker);
// this script only drives the browser half before and after the import.
// Usage: node shots-final.cjs <phase>   (phase: create | after)
const { chromium } = require('/tmp/pw/node_modules/playwright');

const assert = (cond, msg) => { if (!cond) { throw new Error(`ASSERT: ${msg}`); } };
const phase = process.argv[2] || 'create';

(async () => {
    const base = 'http://nginx';
    const out = '/var/www/muse/results';
    const slug = 'final-journey-rig';
    const browser = await chromium.launch();
    const errors = [];
    const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 } });
    const page = await ctx.newPage();
    page.on('pageerror', (e) => errors.push(e.message));
    await page.goto(`${base}/login`, { waitUntil: 'networkidle' });
    await page.fill('input[name="email"]', 'fj-owner@rev2square.test');
    await page.fill('input[name="password"]', process.env.FJ_PASSWORD);
    await Promise.all([
        page.waitForURL('**/dashboard', { timeout: 15000 }).catch(() => page.waitForLoadState('networkidle')),
        page.click('button[type="submit"]'),
    ]);

    if (phase === 'create') {
        await page.goto(`${base}/projects/create`, { waitUntil: 'networkidle' });
        // Fill only if the project does not exist yet (rerun-safe).
        await page.goto(`${base}/dashboard`, { waitUntil: 'networkidle' });
        if (!await page.getByText('Final Journey Rig').count()) {
            await page.goto(`${base}/projects/create`, { waitUntil: 'networkidle' });
            await page.fill('#title', 'Final Journey Rig');
            await page.fill('#pitch', 'Combined revival + community validation rig.');
            await page.fill('#body', 'A project that walks the whole journey: import, evidence, discussion, chat, profile.');
            await Promise.all([
                page.waitForLoadState('networkidle'),
                page.click('button:has-text("Create project")'),
            ]);
        }
        await page.goto(`${base}/projects/${slug}/evidence`, { waitUntil: 'networkidle' });
        await page.waitForTimeout(1500);
        // Start the import unless evidence is already ready/importing.
        const input = page.locator('#repo-url');
        if (await input.count() > 0) {
            await input.fill('https://github.com/gesellix/bose-soundtouch');
            await page.click('button:has-text("Import")');
            await page.waitForTimeout(4000);
            console.log('import started');
        } else {
            console.log('no import input found; check state manually');
        }
        await page.screenshot({ path: `${out}/final-01-evidence-importing.png`, fullPage: true });
        console.log('saved final-01-evidence-importing.png');
    } else {
        // After import: publish flow + community features on the same project.
        await page.goto(`${base}/projects/${slug}`, { waitUntil: 'networkidle' });
        await page.waitForTimeout(1200);
        await page.screenshot({ path: `${out}/final-02-project.png`, fullPage: true });
        console.log('saved final-02-project.png');

        // Discussion thread on the revival project.
        await page.goto(`${base}/projects/${slug}/discussion`, { waitUntil: 'networkidle' });
        await page.fill('textarea[name="body"]', 'Import finished — I reviewed the evidence list and the entry points look right. Starting the teardown notes next.');
        await Promise.all([
            page.waitForLoadState('networkidle'),
            page.click('button:has-text("Post discussion")'),
        ]);
        assert(await page.getByText('teardown notes next').count() >= 1, 'journey thread posted');

        // Chat message in the project room.
        await page.goto(`${base}/projects/${slug}/chat`, { waitUntil: 'networkidle' });
        await page.waitForTimeout(2500);
        await page.fill('#chat-body', 'Journey check-in: discussion, chat, and evidence all live on one project.');
        await page.click('form[data-chat-form] button[type="submit"]');
        await page.getByText('Journey check-in').waitFor({ timeout: 10000 });
        await page.screenshot({ path: `${out}/final-03-chat.png`, fullPage: true });
        console.log('saved final-03-chat.png');

        // Workspace reflects the new project + work.
        await page.goto(`${base}/dashboard`, { waitUntil: 'networkidle' });
        await page.waitForTimeout(800);
        assert(await page.getByText('Final Journey Rig').count() >= 1, 'project in workspace');
        await page.screenshot({ path: `${out}/final-04-workspace.png`, fullPage: true });
        console.log('saved final-04-workspace.png');
    }

    await browser.close();
    const fatal = errors.filter((e) => !/favicon/i.test(e));
    if (fatal.length > 0) {
        console.error(`PAGE ERRORS:\n${fatal.join('\n')}`);
        process.exit(2);
    }
    console.log(`phase ${phase} done with no page errors.`);
})().catch((e) => { console.error(e); process.exit(1); });
