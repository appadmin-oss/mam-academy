# MAM Academy — Design Handoff Package

Start with **HANDOFF.pdf** (or HANDOFF.md): the full design and technical specification with screenshots.

| Folder / file | What it contains |
| --- | --- |
| HANDOFF.pdf / HANDOFF.md | Full spec: tokens, motion, components, page-by-page specs, fee model, application flow, accessibility, responsive rules, open items, technical notes, QA checklist |
| specs/design-tokens.json | Colour, type, space, radius, shadow, motion and breakpoint tokens (machine-readable) |
| specs/tokens.css | The same tokens as CSS custom properties |
| specs/motion-and-base.css | Base styles, motion classes (scroll-driven) and responsive layer, extracted from the homepage |
| specs/effects.css + effects.js | Shared effects layer: page transitions, image reveal, card spotlight, smart header, scroll-spy |
| specs/fee-model.js + .test.js | Single source of truth for fees, with check-value tests (`node specs/fee-model.test.js`) |
| source/pages/*.dc.html | Page sources from the design canvas (Home = Main.dc.html) + canvas.json layout |
| source/generators/*.py | Python generators that produced the inner pages and the effects layer |
| screenshots/desktop, screenshots/mobile | Full-length renders at 1440px and 390px (animations frozen, photos as placeholders) |
| assets/ | MAM Academy logo (SVG) |

Living copy of the spec: https://claude.ai/artifact/A6ZhRAvmXMnT5kPJCevZPq
Design canvas: https://claude.ai/artifact/L699EFC2bV8Fc25D2PBH1u
