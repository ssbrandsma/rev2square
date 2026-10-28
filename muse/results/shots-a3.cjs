// A3 browser validation: account menu, public profile, workspace, join flow.
// Credentials via environment: A3_PASSWORD.
//   docker compose -f compose.dev.yaml exec -T -e A3_PASSWORD=... node node /var/www/muse/results/shots-a3.cjs
const fs = require('fs');
const { chromium } = require('/tmp/pw/node_modules/playwright');

const assert = (cond, msg) => { if (!cond) { throw new Error(`ASSERT: ${msg}`); } };

(async () => {
    const base = 'http://nginx';
    const out = '/var/www/muse/results';
    fs.mkdirSync(out, { recursive: true });
    const slug = 'a3-profile-walkman-20261027';
    const browser = await chromium.launch();
    const errors = [];

    const login = async (email, viewport) => {
        const ctx = await browser.newContext(viewport ? { viewport } : { viewport: { width: 1440, height: 900 } });
        const page = await ctx.newPage();
        page.on('pageerror', (e) => errors.push(`${email}: ${e.message}`));
        await page.goto(`${base}/login`, { waitUntil: 'networkidle' });
        await page.fill('input[name="email"]', email);
        await page.fill('input[name="password"]', process.env.A3_PASSWORD);
        await Promise.all([
            page.waitForURL('**/dashboard', { timeout: 15000 }).catch(() => page.waitForLoadState('networkidle')),
            page.click('button[type="submit"]'),
        ]);
        return { ctx, page };
    };

    const owner = await login('a3-owner@rev2square.test');
    const member = await login('a3-member@rev2square.test');
    const viewer = await login('a3-viewer@rev2square.test');

    // 1. Account menu: keyboard open, Escape closes with focus return.
    await owner.page.goto(`${base}/dashboard`, { waitUntil: 'networkidle' });
    const menuButton = owner.page.locator('button[aria-haspopup="menu"]');
    await menuButton.focus();
    await owner.page.keyboard.press('Enter');
    await owner.page.locator('[role="menu"][aria-label="Account"]').waitFor({ timeout: 5000 });
    assert(await owner.page.getByText('A3 Profile Walkman').count() >= 1, 'menu lists recent project');
    await owner.page.keyboard.press('Escape');
    await owner.page.waitForTimeout(400);
    assert(!(await owner.page.locator('[role="menu"][aria-label="Account"]').isVisible()), 'Escape closes menu');
    assert(await menuButton.evaluate((el) => document.activeElement === el), 'focus returns to menu button');
    console.log('menu keyboard behavior confirmed');

    // Reopen with pointer for the screenshot.
    await menuButton.click();
    await owner.page.waitForTimeout(400);
    await owner.page.screenshot({ path: `${out}/a3-01-menu.png` });
    console.log('saved a3-01-menu.png');

    // 2. Populated public profile (owner viewing member).
    await owner.page.goto(`${base}/u/a3-member`, { waitUntil: 'networkidle' });
    await owner.page.waitForTimeout(800);
    assert(await owner.page.getByText('Capacitor whisperer').count() >= 1, 'bio visible');
    assert(await owner.page.getByText('A3 Profile Walkman').count() >= 1, 'working-on project visible');
    assert(await owner.page.getByText('Belt residue pattern').count() >= 1, 'observation contribution visible');
    assert(await owner.page.getByText('FF/RW torque test').count() >= 1, 'test contribution visible');
    const profileBody = await owner.page.content();
    assert(!profileBody.includes('a3-member@rev2square.test'), 'no email leak on public profile');
    await owner.page.screenshot({ path: `${out}/a3-02-public-profile.png`, fullPage: true });
    console.log('saved a3-02-public-profile.png');

    // 3. Join flow: member joins from workspace, Working on updates.
    await member.page.goto(`${base}/dashboard`, { waitUntil: 'networkidle' });
    const joinForm = member.page.locator('form[action$="/join"]').first();
    assert(await joinForm.count() >= 1, 'join button offered for accepted request');
    await Promise.all([
        member.page.waitForLoadState('networkidle'),
        joinForm.locator('button[type="submit"]').click(),
    ]);
    assert(await member.page.getByText('You joined the project team').count() >= 1, 'join confirmed');
    const teamBadge = member.page.locator('text=Contributor').first();
    assert(await teamBadge.count() >= 1, 'contributor role surfaces');
    console.log('join flow confirmed');

    // 4. Workspace screenshot (member).
    await member.page.goto(`${base}/dashboard`, { waitUntil: 'networkidle' });
    await member.page.waitForTimeout(800);
    await member.page.screenshot({ path: `${out}/a3-03-workspace.png`, fullPage: true });
    console.log('saved a3-03-workspace.png');

    // 5. Profile editing: member updates bio + handle.
    await member.page.goto(`${base}/profile`, { waitUntil: 'networkidle' });
    await member.page.fill('#bio', 'Capacitor whisperer. Recapped twelve boards and counting.');
    await Promise.all([
        member.page.waitForLoadState('networkidle'),
        member.page.click('form[action$="/profile/public"] button[type="submit"]'),
    ]);
    await member.page.goto(`${base}/u/a3-member`, { waitUntil: 'networkidle' });
    assert(await member.page.getByText('twelve boards').count() >= 1, 'edited bio published');
    console.log('profile edit confirmed');
    await member.page.goto(`${base}/profile`, { waitUntil: 'networkidle' });
    await member.page.waitForTimeout(800);
    await member.page.screenshot({ path: `${out}/a3-04-edit-profile.png`, fullPage: true });
    console.log('saved a3-04-edit-profile.png');

    // 6. Avatar upload (GD-generated 64px PNG in shared storage) renders in the menu.
    await member.page.setInputFiles('#avatar', '/var/www/storage/app/a3-avatar.png');
    await Promise.all([
        member.page.waitForLoadState('networkidle'),
        member.page.click('form[action$="/profile/avatar"] button[type="submit"]'),
    ]);
    assert(await member.page.getByText('Avatar updated').count() >= 1, 'avatar upload confirmed');
    await member.page.goto(`${base}/dashboard`, { waitUntil: 'networkidle' });
    const avatarImg = member.page.locator('header img[src*="/avatars/"]').first();
    await avatarImg.waitFor({ timeout: 5000 });
    console.log('avatar renders in header');

    // 7. Unrelated viewer sees the same public profile, no private data.
    await viewer.page.goto(`${base}/u/a3-member`, { waitUntil: 'networkidle' });
    assert(await viewer.page.getByText('twelve boards').count() >= 1, 'viewer sees public bio');
    const viewerBody = await viewer.page.content();
    assert(!viewerBody.includes('a3-member@rev2square.test'), 'viewer sees no email');
    assert(!viewerBody.includes('Happy to document'), 'viewer sees no pending application');
    assert(!viewerBody.includes('A3 Side Radio'), 'viewer sees no saved project');
    console.log('unrelated-viewer privacy confirmed');

    // 8. Draft change: owner shelves the project; public profile drops it at once.
    await owner.page.goto(`${base}/projects/${slug}/edit`, { waitUntil: 'networkidle' });
    await owner.page.locator('select[name="publication_status"]').selectOption('DRAFT');
    await Promise.all([
        owner.page.waitForLoadState('networkidle'),
        owner.page.click('button:has-text("Save changes")'),
    ]);
    await viewer.page.goto(`${base}/u/a3-member`, { waitUntil: 'networkidle' });
    const afterUnpublish = await viewer.page.content();
    assert(!afterUnpublish.includes('A3 Profile Walkman'), 'draft leaves public profile');
    assert(!afterUnpublish.includes('Belt residue pattern'), 'draft contributions leave public profile');
    console.log('unpublish propagation confirmed');

    // 9. Account switching in one session: logout owner, login member.
    await owner.page.goto(`${base}/dashboard`, { waitUntil: 'networkidle' });
    await menuButton.click();
    await owner.page.waitForTimeout(300);
    await Promise.all([
        owner.page.waitForLoadState('networkidle'),
        owner.page.locator('[role="menu"] form[action$="/logout"] button').click(),
    ]);
    assert(!await owner.page.locator('button[aria-haspopup="menu"]').count(), 'menu gone after logout');
    await owner.page.goto(`${base}/login`, { waitUntil: 'networkidle' });
    await owner.page.fill('input[name="email"]', 'a3-member@rev2square.test');
    await owner.page.fill('input[name="password"]', process.env.A3_PASSWORD);
    await Promise.all([
        owner.page.waitForURL('**/dashboard', { timeout: 15000 }).catch(() => owner.page.waitForLoadState('networkidle')),
        owner.page.click('button[type="submit"]'),
    ]);
    assert(await owner.page.locator('button[aria-label="Account menu for A3 Member"]').count() >= 1, 'menu shows switched account');
    console.log('account switching confirmed');

    // 10. Mobile workspace.
    const mob = await login('a3-member@rev2square.test', { width: 390, height: 844 });
    await mob.page.goto(`${base}/dashboard`, { waitUntil: 'networkidle' });
    await mob.page.waitForTimeout(800);
    await mob.page.screenshot({ path: `${out}/a3-05-mobile-workspace.png`, fullPage: true });
    console.log('saved a3-05-mobile-workspace.png');

    await browser.close();

    const fatal = errors.filter((e) => !/favicon/i.test(e));
    if (fatal.length > 0) {
        console.error(`PAGE ERRORS:\n${fatal.join('\n')}`);
        process.exit(2);
    }
    console.log('A3 browser validation passed with no page errors.');
})().catch((e) => { console.error(e); process.exit(1); });
