// Pure annotation-marker helpers shared by the docs editor. Marker
// positions are normalized 0..1 fractions of the displayed image, so
// the same fractions align at any display size (thumbnails, cards,
// lightbox). Nothing here touches the DOM, so it is unit-testable.
export function clamp01(n) {
    if (Number.isNaN(n)) {
        return 0;
    }
    return Math.min(1, Math.max(0, n));
}

// Fractional position of a pointer event inside an image rect,
// rounded to 4 decimals like the server-side validator.
export function fromClient(rect, clientX, clientY) {
    if (!rect || rect.width === 0 || rect.height === 0) {
        return null;
    }
    const x = clamp01((clientX - rect.left) / rect.width);
    const y = clamp01((clientY - rect.top) / rect.height);
    return { x: Math.round(x * 10000) / 10000, y: Math.round(y * 10000) / 10000 };
}

export function toPercent(fraction) {
    return clamp01(fraction) * 100;
}

export function markerId(imageIndex, n) {
    return 'img-' + imageIndex + '-marker-' + n;
}

export function legendLabel(marker, n) {
    const label = ((marker && marker.label) || '').trim();
    return label !== '' ? label : 'Marker ' + n;
}
