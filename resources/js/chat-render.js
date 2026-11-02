// DOM builder for live chat messages. Author names, profile URLs, and
// timestamps are untrusted-or-opaque strings: they are assigned through
// textContent/setAttribute only, never concatenated into HTML. The single
// innerHTML assignment below is the message body, which the server has
// already sanitized (Markup::render); everything else is structural.
//
// `doc` is injectable so the builder runs under Node with a minimal fake
// document in tests/js/chat-render.test.mjs. No imports: this module must
// stay dependency-free for that reason.

/**
 * @param {Document} doc
 * @param {object} payload Serialized chat message (HTTP or socket shape).
 * @param {{ id: number|null, csrf: string, canModerate: boolean, roomKind: 'project'|'lounge' }} viewer
 * @returns {HTMLElement}
 */
export function buildMessageNode(doc, payload, viewer) {
    const mine = viewer.id !== null && Number(payload.author.id) === viewer.id;

    const wrapper = doc.createElement('div');
    wrapper.setAttribute('data-chat-message', '');
    wrapper.setAttribute('data-message-id', String(payload.id));
    wrapper.id = `message-${payload.id}`;
    wrapper.className = 'flex items-start gap-3';

    const avatarLink = doc.createElement('a');
    avatarLink.setAttribute('href', payload.author.profile_url);
    avatarLink.className = 'shrink-0';
    avatarLink.setAttribute('aria-label', `${payload.author.name}’s profile`);

    if (payload.author.avatar_url) {
        const img = doc.createElement('img');
        img.setAttribute('src', payload.author.avatar_url);
        img.setAttribute('alt', '');
        img.className = 'h-9 w-9 shrink-0 rounded-full object-cover';
        avatarLink.appendChild(img);
    } else {
        const fallback = doc.createElement('span');
        fallback.className = `flex h-9 w-9 shrink-0 items-center justify-center rounded-full ${mine ? 'bg-zinc-700' : 'bg-emerald-700'} text-xs font-bold text-white`;
        fallback.setAttribute('aria-hidden', 'true');
        fallback.textContent = payload.author.initials;
        avatarLink.appendChild(fallback);
    }
    wrapper.appendChild(avatarLink);

    const body = doc.createElement('div');
    body.className = 'min-w-0 flex-1';

    const headline = doc.createElement('p');
    headline.className = 'flex flex-wrap items-center gap-x-2 text-sm';

    const authorLink = doc.createElement('a');
    authorLink.setAttribute('href', payload.author.profile_url);
    authorLink.className = 'font-bold hover:text-emerald-700';
    authorLink.textContent = payload.author.name;
    headline.appendChild(authorLink);

    if (payload.author.is_maintainer) {
        const badge = doc.createElement('span');
        badge.className = 'rounded-full bg-emerald-100 px-2 py-0.5 text-[11px] font-bold text-emerald-900';
        badge.textContent = 'Maintainer';
        headline.appendChild(badge);
    }

    const time = doc.createElement('time');
    time.setAttribute('datetime', payload.created_at);
    time.className = 'text-xs text-zinc-500';
    time.textContent = payload.created_human;
    headline.appendChild(time);
    body.appendChild(headline);

    const text = doc.createElement('div');
    text.className = 'mt-0.5 text-[15px] leading-relaxed text-zinc-800 discussion-body';
    text.innerHTML = payload.body_html;
    body.appendChild(text);

    const actions = buildActionsNode(doc, payload, viewer);
    if (actions) {
        body.appendChild(actions);
    }

    wrapper.appendChild(body);

    return wrapper;
}

/**
 * Per-viewer Retract/Report/Remove controls mirroring chat/_room.blade.php,
 * so live messages carry the same actions without a refresh (P2-6). URLs
 * come from the payload; authorization stays server-side on POST. Returns
 * null when the payload carries no usable actions.
 *
 * @param {Document} doc
 * @param {object} payload
 * @param {{ id: number|null, csrf: string, canModerate: boolean, roomKind: 'project'|'lounge' }} viewer
 * @returns {HTMLElement|null}
 */
export function buildActionsNode(doc, payload, viewer) {
    const actions = payload.actions ?? null;
    if (!actions || viewer.id === null) {
        return null;
    }

    const mine = Number(payload.author.id) === viewer.id;

    const row = doc.createElement('div');
    row.className = 'mt-1 flex flex-wrap gap-3 text-xs';
    let rendered = false;

    const postForm = (action, buttonLabel, buttonClass) => {
        const form = doc.createElement('form');
        form.setAttribute('method', 'POST');
        form.setAttribute('action', action);
        form.className = 'inline';

        const token = doc.createElement('input');
        token.setAttribute('type', 'hidden');
        token.setAttribute('name', '_token');
        token.setAttribute('value', viewer.csrf);
        form.appendChild(token);

        const button = doc.createElement('button');
        button.setAttribute('type', 'submit');
        button.className = buttonClass;
        button.textContent = buttonLabel;
        form.appendChild(button);

        return form;
    };

    const withDeleteMethod = (form) => {
        const method = doc.createElement('input');
        method.setAttribute('type', 'hidden');
        method.setAttribute('name', '_method');
        method.setAttribute('value', 'DELETE');
        form.appendChild(method);
        return form;
    };

    if (mine && actions.retract_url) {
        row.appendChild(withDeleteMethod(postForm(actions.retract_url, 'Retract', 'font-semibold text-zinc-400 hover:text-zinc-700')));
        rendered = true;
    } else if (!mine && actions.report_url) {
        const details = doc.createElement('details');
        details.className = 'inline';

        const summary = doc.createElement('summary');
        summary.className = 'cursor-pointer font-semibold text-zinc-400 hover:text-zinc-700';
        summary.textContent = 'Report';
        details.appendChild(summary);

        const form = postForm(actions.report_url, 'Send', 'rounded-lg border border-zinc-300 px-2 py-1 text-xs font-semibold text-zinc-700 hover:bg-zinc-50');
        form.className = 'mt-1 flex flex-wrap items-center gap-2';

        const label = doc.createElement('label');
        label.setAttribute('for', `chat-report-${payload.id}`);
        label.className = 'sr-only';
        label.textContent = 'Report reason';
        form.appendChild(label);

        const reason = doc.createElement('input');
        reason.setAttribute('id', `chat-report-${payload.id}`);
        reason.setAttribute('name', 'reason');
        reason.setAttribute('type', 'text');
        reason.setAttribute('required', '');
        reason.setAttribute('maxlength', '1000');
        reason.setAttribute('placeholder', 'What is wrong?');
        reason.className = 'rounded-lg border-zinc-300 text-xs shadow-sm focus:border-emerald-500 focus:ring-emerald-500';
        form.appendChild(reason);

        details.appendChild(form);
        row.appendChild(details);
        rendered = true;
    }

    if (!mine && viewer.canModerate && viewer.roomKind === 'project' && actions.remove_url) {
        row.appendChild(withDeleteMethod(postForm(actions.remove_url, 'Remove', 'font-semibold text-zinc-400 hover:text-red-600')));
        rendered = true;
    }

    return rendered ? row : null;
}
