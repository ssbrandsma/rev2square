// Current-channel discovery for clients that missed a rotation while
// offline. Resolves the authorized channel names for a room and tells
// genuine denial (403/401: lost access) apart from transport failure,
// so reconnects rejoin instead of misreporting lost access. `fetchImpl`
// is injectable for tests/js/channel-refresh.test.mjs. No imports.

/**
 * @param {(url: string, init: object) => Promise<{ ok: boolean, status: number, json: () => Promise<object> }>} fetchImpl
 * @param {string} url Discovery endpoint URL (server-rendered).
 * @returns {Promise<{ status: 'ok'|'denied'|'failed', channels: { chat: string|null, presence: string|null }|null }>}
 */
export async function resolveChannels(fetchImpl, url) {
    let response;
    try {
        response = await fetchImpl(url, { headers: { Accept: 'application/json' } });
    } catch (_) {
        return { status: 'failed', channels: null };
    }

    if (response.status === 401 || response.status === 403) {
        return { status: 'denied', channels: null };
    }

    if (!response.ok) {
        return { status: 'failed', channels: null };
    }

    let data;
    try {
        data = await response.json();
    } catch (_) {
        return { status: 'failed', channels: null };
    }

    if (!data || typeof data !== 'object') {
        return { status: 'failed', channels: null };
    }

    return {
        status: 'ok',
        channels: {
            chat: typeof data.chat === 'string' ? data.chat : null,
            presence: typeof data.presence === 'string' ? data.presence : null,
        },
    };
}
