// A2 browser validation: discussion + chat + presence across two sessions.
// Credentials via environment: A2_PASSWORD.
//   docker compose -f compose.dev.yaml exec -T -e A2_PASSWORD=... node node /var/www/muse/results/shots-a2.cjs
const fs = require('fs');
const { chromium } = require('/tmp/pw/node_modules/playwright');

const assert = (cond, msg) => { if (!cond) { throw new Error(`ASSERT: ${msg}`); } };

(async () => {
    const base = 'http://nginx';
    const out = '/var/www/muse/results';
    fs.mkdirSync(out, { recursive: true });
    const slug = 'a2-discuss-o-tron-20261027';
    const stamp = Date.now().toString(36);
    const browser = await chromium.launch();
    const errors = [];

    const login = async (email) => {
        const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 } });
        const page = await ctx.newPage();
        page.on('pageerror', (e) => errors.push(`${email}: ${e.message}`));
        await page.goto(`${base}/login`, { waitUntil: 'networkidle' });
        await page.fill('input[name="email"]', email);
        await page.fill('input[name="password"]', process.env.A2_PASSWORD);
        await Promise.all([
            page.waitForURL('**/dashboard', { timeout: 15000 }).catch(() => page.waitForLoadState('networkidle')),
            page.click('button[type="submit"]'),
        ]);
        return { ctx, page };
    };

    const shot = async (page, name, url) => {
        await page.goto(url, { waitUntil: 'networkidle' });
        await page.waitForTimeout(1500);
        await page.screenshot({ path: `${out}/${name}`, fullPage: true });
        console.log(`saved ${name}`);
    };

    const owner = await login('a2-owner@rev2square.test');
    const member = await login('a2-member@rev2square.test');

    // 1-2. Discussion index + thread, both sessions.
    await shot(member.page, 'a2-01-discussion-index.png', `${base}/projects/${slug}/discussion`);
    assert(await member.page.getByText('C14 and C22').count() >= 1, 'thread listed on index');
    await shot(owner.page, 'a2-02-thread.png', `${base}/projects/${slug}/discussion/1`);
    assert(await owner.page.getByText('measured C14 at 47uF').count() >= 1, 'reply visible in thread');

    // 3. Member replies; owner sees it after reload (cross-session write/read).
    const replyMarker = `reply-marker-${stamp}`;
    await member.page.goto(`${base}/projects/${slug}/discussion/1`, { waitUntil: 'networkidle' });
    await member.page.fill('#reply-body', `Confirming the fix works on my board. ${replyMarker}`);
    await Promise.all([
        member.page.waitForLoadState('networkidle'),
        member.page.click('button:has-text("Post reply")'),
    ]);
    assert(await member.page.getByText(replyMarker).count() >= 1, 'member sees own reply');
    await owner.page.goto(`${base}/projects/${slug}/discussion/1`, { waitUntil: 'networkidle' });
    assert(await owner.page.getByText(replyMarker).count() >= 1, 'owner sees member reply after reload');

    // 4. Realtime chat: both in the project room, member sends, owner receives without reload.
    const chatUrl = `${base}/projects/${slug}/chat`;
    await owner.page.goto(chatUrl, { waitUntil: 'networkidle' });
    await member.page.goto(chatUrl, { waitUntil: 'networkidle' });
    await owner.page.waitForSelector('[data-presence-list] li, [data-presence-state]', { timeout: 10000 });
    await member.page.waitForTimeout(2500); // let both sockets join
    const chatMarker = `chat-marker-${stamp}`;
    await member.page.fill('#chat-body', `Hello from the member session. ${chatMarker}`);
    await member.page.click('form[data-chat-form] button[type="submit"]');
    await owner.page.getByText(chatMarker).waitFor({ timeout: 15000 });
    console.log('realtime delivery confirmed (no reload)');
    await owner.page.waitForTimeout(800);
    await owner.page.screenshot({ path: `${out}/a2-03-chat-realtime.png`, fullPage: true });
    console.log('saved a2-03-chat-realtime.png');

    // 5. Typing indicator across sessions.
    await member.page.fill('#chat-body', 'typing something…');
    await owner.page.getByText(/is typing|are typing/).first().waitFor({ timeout: 10000 });
    console.log('typing indicator confirmed');
    await owner.page.screenshot({ path: `${out}/a2-04-chat-typing.png` });
    console.log('saved a2-04-chat-typing.png');
    await member.page.fill('#chat-body', '');

    // 6. Presence roster lists both opted-in members in both sessions.
    for (const [who, pg] of [['owner', owner.page], ['member', member.page]]) {
        const roster = pg.locator('[data-presence-roster]');
        await roster.getByText('A2 Owner').first().waitFor({ timeout: 10000 });
        await roster.getByText('A2 Member').first().waitFor({ timeout: 10000 });
        console.log(`roster lists both members (${who})`);
    }

    // 7. Member opts out of presence via profile; owner roster drops them.
    await member.page.goto(`${base}/profile`, { waitUntil: 'networkidle' });
    const presenceBox = member.page.locator('input[name="show_presence"]');
    assert(await presenceBox.isChecked(), 'member starts opted in');
    await presenceBox.uncheck();
    // Keep notification boxes as-is; the form posts explicit values.
    for (const name of ['notify_discussion_replies', 'notify_mentions', 'notify_project_updates']) {
        const box = member.page.locator(`input[name="${name}"]`);
        if (!(await box.isChecked())) { await box.check(); }
    }
    await Promise.all([
        member.page.waitForLoadState('networkidle'),
        member.page.click('form[action$="/profile/preferences"] button[type="submit"]'),
    ]);
    await owner.page.goto(chatUrl, { waitUntil: 'networkidle' });
    await owner.page.waitForTimeout(2500);
    const ownerRoster = await owner.page.locator('[data-presence-roster]').innerText();
    assert(!ownerRoster.includes('A2 Member'), 'opted-out member leaves owner roster');
    assert(ownerRoster.includes('A2 Owner'), 'owner still listed');
    console.log('presence opt-out confirmed');
    // Opt back in for the remaining checks.
    await member.page.goto(`${base}/profile`, { waitUntil: 'networkidle' });
    await member.page.locator('input[name="show_presence"]').check();
    await Promise.all([
        member.page.waitForLoadState('networkidle'),
        member.page.click('form[action$="/profile/preferences"] button[type="submit"]'),
    ]);

    // 8. Launcher unread: owner idles on dashboard while member sends.
    await owner.page.goto(`${base}/dashboard`, { waitUntil: 'networkidle' });
    await member.page.goto(chatUrl, { waitUntil: 'networkidle' });
    await member.page.waitForTimeout(2000);
    const unreadMarker = `unread-marker-${stamp}`;
    await member.page.fill('#chat-body', `Ping for the launcher. ${unreadMarker}`);
    await member.page.click('form[data-chat-form] button[type="submit"]');
    await member.page.getByText(unreadMarker).waitFor({ timeout: 10000 });
    await owner.page.locator('[data-launcher-dot]:visible').first().waitFor({ timeout: 30000 });
    console.log('launcher unread dot confirmed');
    await owner.page.click('[data-launcher-toggle]');
    await owner.page.waitForTimeout(800);
    await owner.page.screenshot({ path: `${out}/a2-05-launcher-unread.png` });
    console.log('saved a2-05-launcher-unread.png');

    // 9. Updates feed.
    await shot(member.page, 'a2-06-updates.png', `${base}/projects/${slug}/updates`);
    assert(await member.page.getByText('Boards arrived').count() >= 1, 'update listed');

    // 10. Member reports the owner's reply; owner sees the moderation queue.
    await member.page.goto(`${base}/projects/${slug}/discussion/1`, { waitUntil: 'networkidle' });
    const reportDetails = member.page.locator('details:has-text("Report")').first();
    await reportDetails.locator('summary').click();
    await reportDetails.locator('input[name="reason"]').fill(`Suspicious claim ${stamp}`);
    await Promise.all([
        member.page.waitForLoadState('networkidle'),
        reportDetails.locator('button:has-text("Send report")').click(),
    ]);
    await shot(owner.page, 'a2-07-moderation.png', `${base}/projects/${slug}/moderation`);
    assert(await owner.page.getByText(`Suspicious claim ${stamp}`).count() >= 1, 'report lands in queue');

    // 11. /online roster with both opted in.
    await owner.page.goto(chatUrl, { waitUntil: 'networkidle' }); // rejoin presence after opt-out cycle
    await owner.page.waitForTimeout(2000);
    await member.page.goto(chatUrl, { waitUntil: 'networkidle' });
    await member.page.waitForTimeout(2000);
    await shot(owner.page, 'a2-08-online.png', `${base}/online`);
    assert(await owner.page.getByText('A2 Owner').count() >= 1, 'owner on /online');

    // 12. Mobile chat.
    const mob = await browser.newContext({ viewport: { width: 390, height: 844 } });
    const mpage = await mob.newPage();
    mpage.on('pageerror', (e) => errors.push(`mobile: ${e.message}`));
    await mpage.goto(`${base}/login`, { waitUntil: 'networkidle' });
    await mpage.fill('input[name="email"]', 'a2-member@rev2square.test');
    await mpage.fill('input[name="password"]', process.env.A2_PASSWORD);
    await Promise.all([
        mpage.waitForURL('**/dashboard', { timeout: 15000 }).catch(() => mpage.waitForLoadState('networkidle')),
        mpage.click('button[type="submit"]'),
    ]);
    await shot(mpage, 'a2-09-mobile-chat.png', chatUrl);

    await browser.close();

    const fatal = errors.filter((e) => !/favicon/i.test(e));
    if (fatal.length > 0) {
        console.error(`PAGE ERRORS:\n${fatal.join('\n')}`);
        process.exit(2);
    }
    console.log('A2 browser validation passed with no page errors.');
})().catch((e) => { console.error(e); process.exit(1); });
