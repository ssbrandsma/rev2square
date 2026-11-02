// Minimal fake DOM for testing pure render/sync modules under Node.
// No dependencies; intentionally tiny. It records every innerHTML
// assignment so tests can prove untrusted strings never pass through
// HTML parsing.

export class FakeElement {
    constructor(tag, doc) {
        this.tagName = tag.toUpperCase();
        this.doc = doc;
        this.attrs = {};
        this.children = [];
        this._text = '';
        this.className = '';
        this.id = '';
    }

    setAttribute(name, value) {
        this.attrs[name] = String(value);
    }

    getAttribute(name) {
        return this.attrs[name] ?? null;
    }

    appendChild(child) {
        this.children.push(child);
        return child;
    }

    set textContent(value) {
        this._text = String(value);
        this.children = [];
    }

    get textContent() {
        return this._text;
    }

    set innerHTML(value) {
        this.doc.innerHtmlCalls.push({ tag: this.tagName, html: String(value) });
        this.children = [];
    }

    get innerHTML() {
        return '';
    }
}

export class FakeDocument {
    constructor() {
        this.innerHtmlCalls = [];
        this.roots = [];
    }

    createElement(tag) {
        return new FakeElement(tag, this);
    }

    appendRoot(element) {
        this.roots.push(element);
        return element;
    }

    /** Minimal selector support: [attr] and [attr="value"] only. */
    querySelectorAll(selector) {
        const match = /^\[([a-zA-Z][a-zA-Z0-9-]*)(?:="([^"]*)")?\]$/.exec(selector);
        if (!match) {
            throw new Error(`unsupported selector: ${selector}`);
        }

        const [, attr, value] = match;
        const found = [];
        const walk = (node) => {
            const current = node.getAttribute(attr);
            if (current !== null && (value === undefined || current === value)) {
                found.push(node);
            }
            for (const child of node.children ?? []) {
                walk(child);
            }
        };
        for (const root of this.roots) {
            walk(root);
        }
        return found;
    }
}

/** @returns {FakeElement[]} */
export function findAll(root, predicate) {
    const found = [];
    const walk = (node) => {
        if (predicate(node)) {
            found.push(node);
        }
        for (const child of node.children ?? []) {
            walk(child);
        }
    };
    walk(root);
    return found;
}
