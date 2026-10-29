# Rev²Square — Imagery Sources & Licenses

All brand imagery is original vector artwork created for this project. There
are no stock photos, no AI-generated images, and no external image
dependencies: every asset below is served locally from `public/`.

License for all listed assets: MIT, same as the project (see `LICENSE`).
No attribution required beyond the project license.

## Assets

| File | Size | Used in | Alt text |
|---|---|---|---|
| `public/favicon.svg` | 64×64 viewBox | Browser tab icon (`layouts/app`, `layouts/guest`) | n/a (icon) |
| `public/images/hero-board.svg` | 640×520 viewBox | Landing hero (`welcome`) | "Illustration of circuit-board traces converging on a chip with test points" |
| `public/images/card-pattern.svg` | 800×160 viewBox | Project card header (`projects/_card`), project page header (`projects/show`) as decorative CSS background | none (decorative background) |
| `public/images/spot-evidence.svg` | 120×120 viewBox | Landing "Evidence before confidence" (`welcome`) | none (decorative, `aria-hidden`) |
| `public/images/spot-community.svg` | 120×120 viewBox | Landing "Contributors over donations" (`welcome`) | none (decorative, `aria-hidden`) |

## Design notes

- Palette matches the existing design tokens: emerald `#10b981`,
  amber `#fbbf24`, zinc `#09090b`–`#3f3f46`.
- Motifs are geometric and technical (orthogonal/45° traces, vias, test
  points, node graphs) — deliberately abstract rather than fake-realistic
  hardware photography.
- SVG keeps every asset under 4 KB with zero raster weight; no WebP/AVIF
  pipeline needed.
- `<img>` tags carry explicit `width`/`height` to prevent layout shift.
  The hero image uses `fetchpriority="high"`; below-the-fold spots use
  `loading="lazy"` + `decoding="async"`.

## Future improvement (not implemented)

Projects have no image field, so project cards and headers use the shared
decorative pattern as a fallback. If per-device photography is wanted,
add an optional project/device image with a proper upload workflow,
license tracking per image, and keep this pattern as the empty-state
fallback. Do not hotlink external images without a clear source and
suitable license.
