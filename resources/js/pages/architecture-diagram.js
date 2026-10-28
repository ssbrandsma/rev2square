// Architecture diagram: renders the validated snapshot payload as an
// interactive SVG. All labels are inserted via textContent — model output
// is never interpreted as markup, script, or diagram directives.
(function () {
    const TYPE_ORDER = ['entry_point', 'service', 'module', 'config', 'build', 'datastore', 'external_system'];

    const TYPE_COLORS = {
        entry_point: '#059669',
        service: '#2563eb',
        module: '#4f46e5',
        config: '#a16207',
        build: '#6d28d9',
        datastore: '#0d9488',
        external_system: '#57534e',
    };

    const CERTAINTY_DASH = { OBSERVED: '', INFERRED: '5 3', UNKNOWN: '2 3' };

    function initDiagram(root) {
        const script = root.parentElement.querySelector('script[data-architecture-graph]');
        if (!script) return;

        let graph;
        try {
            graph = JSON.parse(script.textContent);
        } catch (e) {
            root.textContent = root.dataset.diagramEmpty || 'Diagram unavailable.';
            return;
        }

        const components = Array.isArray(graph.components) ? graph.components : [];
        const edges = Array.isArray(graph.relationships) ? graph.relationships : [];
        if (components.length === 0) {
            root.textContent = root.dataset.diagramEmpty || 'Diagram unavailable.';
            return;
        }

        const byId = {};
        components.forEach((c) => { byId[c.id] = c; });

        const columns = {};
        components.forEach((c) => {
            const type = TYPE_ORDER.includes(c.type) ? c.type : 'module';
            (columns[type] = columns[type] || []).push(c);
        });
        const columnKeys = TYPE_ORDER.filter((t) => columns[t]);

        const NS = 'http://www.w3.org/2000/svg';
        const COL_W = 190;
        const ROW_H = 64;
        const width = Math.max(320, columnKeys.length * COL_W + 24);
        const height = Math.max(...columnKeys.map((k) => columns[k].length)) * ROW_H + 56;

        const svg = document.createElementNS(NS, 'svg');
        svg.setAttribute('viewBox', `0 0 ${width} ${height}`);
        svg.setAttribute('width', width);
        svg.setAttribute('height', height);
        svg.setAttribute('role', 'img');
        svg.setAttribute('aria-label', 'Architecture diagram. The component and relationship lists below carry the same findings.');

        const positions = {};
        columnKeys.forEach((type, ci) => {
            const label = document.createElementNS(NS, 'text');
            label.setAttribute('x', 12 + ci * COL_W + 8);
            label.setAttribute('y', 20);
            label.setAttribute('font-size', '11');
            label.setAttribute('font-weight', 'bold');
            label.setAttribute('fill', '#71717a');
            label.textContent = type.replace(/_/g, ' ').toUpperCase();
            svg.appendChild(label);

            columns[type].forEach((c, ri) => {
                const x = 12 + ci * COL_W;
                const y = 32 + ri * ROW_H;
                positions[c.id] = { x: x + COL_W - 24, y: y + 22, cx: x + (COL_W - 24) / 2, cy: y + 22 };

                const group = document.createElementNS(NS, 'g');
                group.setAttribute('data-component', c.id);
                group.setAttribute('tabindex', '0');
                group.setAttribute('role', 'button');
                group.setAttribute('aria-label', 'Component ' + c.label);
                group.style.cursor = 'pointer';

                const rect = document.createElementNS(NS, 'rect');
                rect.setAttribute('x', x);
                rect.setAttribute('y', y);
                rect.setAttribute('width', COL_W - 24);
                rect.setAttribute('height', 48);
                rect.setAttribute('rx', '8');
                rect.setAttribute('fill', '#ffffff');
                rect.setAttribute('stroke', TYPE_COLORS[type] || '#71717a');
                rect.setAttribute('stroke-width', '2');
                if (CERTAINTY_DASH[c.certainty]) rect.setAttribute('stroke-dasharray', CERTAINTY_DASH[c.certainty]);
                group.appendChild(rect);

                const title = document.createElementNS(NS, 'text');
                title.setAttribute('x', x + 10);
                title.setAttribute('y', y + 20);
                title.setAttribute('font-size', '12');
                title.setAttribute('font-weight', 'bold');
                title.setAttribute('fill', '#18181b');
                title.textContent = truncate(c.label, 22);
                const full = document.createElementNS(NS, 'title');
                full.textContent = c.label;
                title.appendChild(full);
                group.appendChild(title);

                const sub = document.createElementNS(NS, 'text');
                sub.setAttribute('x', x + 10);
                sub.setAttribute('y', y + 36);
                sub.setAttribute('font-size', '10');
                sub.setAttribute('fill', '#71717a');
                sub.textContent = (c.certainty || 'UNKNOWN') + ' · ' + (c.evidence ? c.evidence.length : 0) + ' refs';
                group.appendChild(sub);

                group.addEventListener('click', () => selectComponent(c.id));
                group.addEventListener('keydown', (e) => {
                    if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); selectComponent(c.id); }
                });
                svg.appendChild(group);
            });
        });

        const edgeLayer = document.createElementNS(NS, 'g');
        edges.forEach((edge) => {
            const a = positions[edge.from];
            const b = positions[edge.to];
            if (!a || !b) return;

            const line = document.createElementNS(NS, 'line');
            line.setAttribute('x1', a.cx);
            line.setAttribute('y1', a.cy);
            line.setAttribute('x2', b.cx);
            line.setAttribute('y2', b.cy);
            line.setAttribute('stroke', edge.certainty === 'OBSERVED' ? '#10b981' : '#a1a1aa');
            line.setAttribute('stroke-width', '1.5');
            if (CERTAINTY_DASH[edge.certainty]) line.setAttribute('stroke-dasharray', CERTAINTY_DASH[edge.certainty]);
            line.style.cursor = 'pointer';
            const tip = document.createElementNS(NS, 'title');
            tip.textContent = (edge.label || edge.type) + ' — select for details';
            line.appendChild(tip);
            line.addEventListener('click', (e) => { e.stopPropagation(); selectEdge(edge.id); });
            edgeLayer.appendChild(line);
        });
        svg.appendChild(edgeLayer);

        // Edges sit below the nodes so node clicks always land.
        svg.insertBefore(edgeLayer, svg.firstChild);
        root.textContent = '';
        root.appendChild(svg);

        const details = document.querySelector('[data-architecture-details]');

        function selectComponent(id) {
            const c = byId[id];
            if (!c || !details) return;
            renderDetails(details, c.type.replace(/_/g, ' '), c.label, c.certainty, c.explanation, c.evidence, 'component-' + id);
        }

        function selectEdge(id) {
            const edge = edges.find((e) => e.id === id);
            if (!edge || !details) return;
            const from = byId[edge.from];
            const to = byId[edge.to];
            const label = (from ? from.label : edge.from) + ' → ' + (to ? to.label : edge.to);
            renderDetails(details, 'relationship · ' + String(edge.type).replace(/_/g, ' '), label, edge.certainty,
                (edge.label ? edge.label + '. ' : '') + edge.explanation, edge.evidence, 'relationship-' + id);
        }
    }

    function renderDetails(details, kind, label, certainty, explanation, evidence, anchor) {
        details.textContent = '';
        const kindP = document.createElement('p');
        kindP.className = 'text-xs font-semibold uppercase tracking-wide text-zinc-500';
        kindP.textContent = kind;
        details.appendChild(kindP);

        const labelP = document.createElement('p');
        labelP.className = 'mt-1 font-semibold';
        labelP.textContent = label;
        details.appendChild(labelP);

        const badge = document.createElement('p');
        badge.className = 'mt-1 text-xs font-semibold';
        badge.textContent = 'Certainty: ' + certainty;
        details.appendChild(badge);

        const expl = document.createElement('p');
        expl.className = 'mt-2 text-zinc-700';
        expl.textContent = explanation;
        details.appendChild(expl);

        const evHead = document.createElement('p');
        evHead.className = 'mt-3 text-xs font-semibold text-zinc-500';
        evHead.textContent = 'Supporting evidence';
        details.appendChild(evHead);

        const list = document.createElement('ul');
        list.className = 'mt-1 space-y-1 font-mono text-xs';
        (evidence || []).forEach((ref) => {
            const li = document.createElement('li');
            li.textContent = ref.locator + (ref.is_partial ? ' (partial excerpt)' : '');
            list.appendChild(li);
        });
        if (!evidence || evidence.length === 0) {
            const li = document.createElement('li');
            li.textContent = 'none recorded';
            list.appendChild(li);
        }
        details.appendChild(list);

        const link = document.createElement('a');
        link.className = 'mt-3 inline-block font-semibold text-emerald-700 hover:underline';
        link.setAttribute('href', '#' + anchor);
        link.textContent = 'Jump to the full entry ↓';
        details.appendChild(link);
    }

    function truncate(value, max) {
        const s = String(value || '');
        return s.length > max ? s.slice(0, max - 1) + '…' : s;
    }

    document.addEventListener('DOMContentLoaded', () => {
        document.querySelectorAll('[data-architecture-diagram]').forEach(initDiagram);
        if (document.querySelector('[data-architecture-poll]')) {
            window.setTimeout(() => window.location.reload(), 12000);
        }
    });
})();
