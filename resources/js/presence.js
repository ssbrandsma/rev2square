// Sitewide presence registration (P2-5). Opted-in members join the global
// `online` channel from every app page — not only /online — so Online now
// reflects members browsing anywhere. Registration is membership only;
// roster rendering stays with [data-presence-roster] elements.
//
// Joins follow channel rotation like rosters do: the server announces the
// new name on the old channel and rejoin re-checks opt-in (P1-2).
//
// Ports are injectable so this runs under Node in tests/js/presence.test.mjs.
// No imports: this module must stay dependency-free for that reason.

/**
 * @param {Document} doc
 * @param {{ join: (name: string) => { listen: (event: string, cb: (payload: object) => void) => void },
 *   leave: (name: string) => void, track: (name: string) => void, untrack: (name: string) => void }} ports
 * @returns {string[]} Joined channel names (canonical, prefix stripped).
 */
export function registerGlobalPresence(doc, ports) {
    const joined = [];

    function subscribe(element, name) {
        const previous = element.getAttribute('data-joined');
        if (previous) {
            try {
                ports.leave(previous);
            } catch (_) {}
            ports.untrack(previous);
        }

        let channel;
        try {
            channel = ports.join(name);
            ports.track(name);
        } catch (_) {
            return;
        }

        element.setAttribute('data-joined', name);
        joined.push(name);

        channel.listen('.channel.rotated', (event) => {
            if (event && typeof event.channel === 'string' && event.channel !== name) {
                subscribe(element, event.channel);
            }
        });
    }

    doc.querySelectorAll('[data-presence-register]').forEach((element) => {
        const raw = element.getAttribute('data-channel');
        if (!raw) {
            return;
        }

        const name = raw.replace(/^presence-/, '');

        // A page roster for the same channel already registers this tab;
        // joining twice would only complicate leave bookkeeping.
        const rostered = [...doc.querySelectorAll('[data-presence-roster]')]
            .some((roster) => (roster.getAttribute('data-channel') ?? '').replace(/^presence-/, '') === name);
        if (rostered) {
            return;
        }

        try {
            subscribe(element, name);
        } catch (_) {
            // Registration is best-effort; the roster page still works.
        }
    });

    return joined;
}
