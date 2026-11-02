// P1-1: author-controlled strings must never pass through HTML parsing.
// Exercises the real buildMessageNode with adversarial payloads and a
// fake document that records every innerHTML assignment.
import assert from 'node:assert/strict';
import { buildMessageNode } from '../../resources/js/chat-render.js';
import { FakeDocument, findAll } from './fake-dom.mjs';

const EVIL_NAME = 'x" onpointerenter="alert(1)';
const EVIL_URL = 'https://example.test/"onmouseover="alert(1)';
const EVIL_INITIALS = '><img src=x onerror=alert(1)>';
const ACTIONS = {
    retract_url: 'https://example.test/chat/7/retract',
    report_url: 'https://example.test/chat/7/report',
    remove_url: 'https://example.test/chat/7/remove',
};

function payload(overrides = {}) {
    return {
        id: 7,
        room: 'lounge',
        client_key: 'k1',
        body_html: '<p>Hello <a href="https://example.test">link</a></p>',
        author: {
            id: 9,
            name: EVIL_NAME,
            initials: EVIL_INITIALS,
            avatar_url: null,
            profile_url: 'https://example.test/u/evil',
            is_maintainer: true,
            ...(overrides.author ?? {}),
        },
        created_at: '2026-11-02T07:00:00+00:00',
        created_human: '2 hours ago',
        ...overrides,
    };
}

function allAttrs(root) {
    const pairs = [];
    for (const node of findAll(root, () => true)) {
        for (const [name, value] of Object.entries(node.attrs ?? {})) {
            pairs.push([node.tagName, name, value]);
        }
    }
    return pairs;
}

// 1. No untrusted string reaches innerHTML: exactly one assignment, the
// server-sanitized body, verbatim.
{
    const doc = new FakeDocument();
    const data = payload();
    buildMessageNode(doc, data, { id: 3 });
    assert.equal(doc.innerHtmlCalls.length, 1);
    assert.equal(doc.innerHtmlCalls[0].html, data.body_html);
    assert.ok(!doc.innerHtmlCalls[0].html.includes(EVIL_NAME));
    console.log('PASS innerHTML carries only the sanitized body');
}

// 2. The adversarial name survives verbatim as text/attribute values, and
// creates no new attributes.
{
    const doc = new FakeDocument();
    const node = buildMessageNode(doc, payload(), { id: 3 });

    const links = findAll(node, (n) => n.tagName === 'A');
    assert.equal(links.length, 2);
    assert.equal(links[0].getAttribute('aria-label'), `${EVIL_NAME}’s profile`);
    assert.equal(links[1].textContent, EVIL_NAME);

    for (const [tag, name] of allAttrs(node)) {
        assert.ok(!name.toLowerCase().startsWith('on'), `event attribute on ${tag}: ${name}`);
    }
    // The payload may only surface verbatim inside aria-label; every other
    // attribute must be free of attacker-controlled text.
    const tainted = allAttrs(node).filter(([, , v]) => v.includes('onpointerenter'));
    assert.deepEqual(tainted.map(([, n]) => n), ['aria-label']);
    console.log('PASS adversarial name stays inert text');
}

// 3. Malicious URLs cannot break out of attributes either.
{
    const doc = new FakeDocument();
    const node = buildMessageNode(
        doc,
        payload({ author: { avatar_url: EVIL_URL, profile_url: EVIL_URL } }),
        { id: 3 },
    );
    assert.equal(doc.innerHtmlCalls.length, 1);
    for (const [tag, name] of allAttrs(node)) {
        assert.ok(!name.toLowerCase().startsWith('on'), `event attribute on ${tag}: ${name}`);
    }
    const imgs = findAll(node, (n) => n.tagName === 'IMG');
    assert.equal(imgs.length, 1);
    assert.equal(imgs[0].getAttribute('src'), EVIL_URL);
    console.log('PASS adversarial URLs stay inert attribute values');
}

// 4. Structural parity: identity, maintainer badge, timestamp, fallback.
{
    const doc = new FakeDocument();
    const node = buildMessageNode(doc, payload(), { id: 3 });
    assert.equal(node.getAttribute('data-chat-message'), '');
    assert.equal(node.getAttribute('data-message-id'), '7');
    assert.equal(node.id, 'message-7');

    const times = findAll(node, (n) => n.tagName === 'TIME');
    assert.equal(times.length, 1);
    assert.equal(times[0].getAttribute('datetime'), '2026-11-02T07:00:00+00:00');
    assert.equal(times[0].textContent, '2 hours ago');

    const badge = findAll(node, (n) => n.textContent === 'Maintainer');
    assert.equal(badge.length, 1);

    // Initials fallback for others uses the emerald treatment…
    const fallback = findAll(node, (n) => n.tagName === 'SPAN' && n.textContent === EVIL_INITIALS);
    assert.equal(fallback.length, 1);
    assert.ok(fallback[0].className.includes('bg-emerald-700'));

    // …and the zinc treatment for the viewer's own messages.
    const own = buildMessageNode(new FakeDocument(), payload(), { id: 9 });
    const ownFallback = findAll(own, (n) => n.tagName === 'SPAN' && n.textContent === EVIL_INITIALS);
    assert.equal(ownFallback.length, 1);
    assert.ok(ownFallback[0].className.includes('bg-zinc-700'));

    // No badge without the flag.
    const plain = buildMessageNode(new FakeDocument(), payload({ author: { is_maintainer: false } }), { id: 3 });
    assert.equal(findAll(plain, (n) => n.textContent === 'Maintainer').length, 0);
    console.log('PASS structure, badge, timestamp, avatar fallback');
}

// 5. Own messages offer Retract with method spoofing and the CSRF token.
{
    const doc = new FakeDocument();
    const node = buildMessageNode(doc, payload({ actions: ACTIONS }), { id: 9, csrf: 'tok', canModerate: false, roomKind: 'project' });

    const retract = findAll(node, (n) => n.tagName === 'BUTTON' && n.textContent === 'Retract');
    assert.equal(retract.length, 1);
    const form = findAll(node, (n) => n.tagName === 'FORM');
    assert.equal(form.length, 1);
    assert.equal(form[0].getAttribute('action'), ACTIONS.retract_url);
    const method = findAll(form[0], (n) => n.tagName === 'INPUT' && n.getAttribute('name') === '_method');
    assert.equal(method[0].getAttribute('value'), 'DELETE');
    const token = findAll(form[0], (n) => n.tagName === 'INPUT' && n.getAttribute('name') === '_token');
    assert.equal(token[0].getAttribute('value'), 'tok');

    assert.equal(findAll(node, (n) => n.tagName === 'SUMMARY' && n.textContent === 'Report').length, 0);
    assert.equal(findAll(node, (n) => n.tagName === 'BUTTON' && n.textContent === 'Remove').length, 0);
    console.log('PASS own messages offer Retract only');
}

// 6. Ordinary recipients get Report with a reason field, never Remove.
{
    const doc = new FakeDocument();
    const node = buildMessageNode(doc, payload({ actions: ACTIONS }), { id: 3, csrf: 'tok', canModerate: false, roomKind: 'project' });

    const report = findAll(node, (n) => n.tagName === 'SUMMARY' && n.textContent === 'Report');
    assert.equal(report.length, 1);
    const reason = findAll(node, (n) => n.tagName === 'INPUT' && n.getAttribute('name') === 'reason');
    assert.equal(reason.length, 1);
    assert.equal(reason[0].getAttribute('id'), 'chat-report-7');
    assert.equal(reason[0].getAttribute('required'), '');

    assert.equal(findAll(node, (n) => n.tagName === 'BUTTON' && n.textContent === 'Retract').length, 0);
    assert.equal(findAll(node, (n) => n.tagName === 'BUTTON' && n.textContent === 'Remove').length, 0);
    console.log('PASS recipients get Report with a reason field');
}

// 7. Maintainers additionally get Remove on others' project messages.
{
    const doc = new FakeDocument();
    const node = buildMessageNode(doc, payload({ actions: ACTIONS }), { id: 3, csrf: 'tok', canModerate: true, roomKind: 'project' });

    const remove = findAll(node, (n) => n.tagName === 'BUTTON' && n.textContent === 'Remove');
    assert.equal(remove.length, 1);
    assert.equal(findAll(node, (n) => n.tagName === 'SUMMARY' && n.textContent === 'Report').length, 1);

    // …but never on their own messages, and never in the lounge.
    const own = buildMessageNode(new FakeDocument(), payload({ actions: ACTIONS }), { id: 9, csrf: 'tok', canModerate: true, roomKind: 'project' });
    assert.equal(findAll(own, (n) => n.tagName === 'BUTTON' && n.textContent === 'Remove').length, 0);

    const lounge = buildMessageNode(new FakeDocument(), payload({ actions: ACTIONS }), { id: 3, csrf: 'tok', canModerate: true, roomKind: 'lounge' });
    assert.equal(findAll(lounge, (n) => n.tagName === 'BUTTON' && n.textContent === 'Remove').length, 0);
    console.log('PASS maintainers get Remove on others’ project messages only');
}

// 8. Guests and legacy payloads render no actions at all.
{
    const guest = buildMessageNode(new FakeDocument(), payload({ actions: ACTIONS }), { id: null, csrf: 'tok', canModerate: false, roomKind: 'project' });
    assert.equal(findAll(guest, (n) => n.tagName === 'FORM').length, 0);
    assert.equal(findAll(guest, (n) => n.tagName === 'DETAILS').length, 0);

    const legacy = buildMessageNode(new FakeDocument(), payload(), { id: 3, csrf: 'tok', canModerate: true, roomKind: 'project' });
    assert.equal(findAll(legacy, (n) => n.tagName === 'FORM').length, 0);
    assert.equal(findAll(legacy, (n) => n.tagName === 'DETAILS').length, 0);
    console.log('PASS guests and legacy payloads render no actions');
}

console.log('chat-render.test.mjs: all assertions passed');
