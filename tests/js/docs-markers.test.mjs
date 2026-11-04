// Annotation-marker math: normalized fractions stay aligned at any
// display size, clicks clamp into the image, and ids/labels match
// the server-side anchor scheme.
import assert from 'node:assert/strict';
import { clamp01, fromClient, legendLabel, markerId, toPercent } from '../../resources/js/docs/markers.js';

// 1. Clicks map to normalized fractions with 4-decimal rounding.
{
    const at = fromClient({ left: 10, top: 20, width: 200, height: 100 }, 60, 45);
    assert.deepEqual(at, { x: 0.25, y: 0.25 });
    console.log('PASS clicks map to normalized fractions');
}

// 2. Out-of-image clicks clamp into range instead of escaping it.
{
    const at = fromClient({ left: 0, top: 0, width: 100, height: 100 }, 250, -30);
    assert.deepEqual(at, { x: 1, y: 0 });
    assert.equal(clamp01(Number.NaN), 0);
    console.log('PASS out-of-image clicks clamp into range');
}

// 3. Zero-size rects place nothing.
{
    assert.equal(fromClient({ left: 0, top: 0, width: 0, height: 0 }, 5, 5), null);
    assert.equal(fromClient(null, 5, 5), null);
    console.log('PASS zero-size rects place nothing');
}

// 4. Same proportional click on different display sizes yields the
// same fractions — responsive alignment by construction.
{
    const thumb = fromClient({ left: 0, top: 0, width: 96, height: 96 }, 24, 72);
    const wide = fromClient({ left: 0, top: 0, width: 1200, height: 1200 }, 300, 900);
    assert.deepEqual(thumb, wide);
    assert.equal(toPercent(thumb.x), 25);
    assert.equal(toPercent(thumb.y), 75);
    console.log('PASS fractions are display-size independent');
}

// 5. Anchor ids and legend labels match the server scheme.
{
    assert.equal(markerId(1, 2), 'img-1-marker-2');
    assert.equal(legendLabel({ label: 'U1' }, 1), 'U1');
    assert.equal(legendLabel({ label: '  ' }, 3), 'Marker 3');
    assert.equal(legendLabel(null, 4), 'Marker 4');
    console.log('PASS anchor ids and legend labels match the server scheme');
}
