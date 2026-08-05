# Vendored third-party libraries

Self-hosted, not CDN: the site's CSP is `script-src 'self' …` and CDN assets are
blocked (see CLAUDE.md). Loaded **on demand** by `assets/js/card-export.js` only
when an admin actually exports from the Card Studio — none of this is on the
critical path of any public page.

| File | Version | Licence | Used for |
|------|---------|---------|----------|
| `html2canvas.min.js` | 1.4.1 | MIT — © 2022 Niklas von Hertzen | Rasterising the card DOM to a canvas |
| `jspdf.umd.min.js` | 2.5.2 | MIT — © 2010–2024 James Hall, yWorks GmbH et al. | Building the print-ready imposition PDF |
| `jsQR.min.js` | 1.4.0 | Apache-2.0 — © Cosmo Wolfe | Decoding the *rendered* QR to prove it survived rasterisation |
| `qrcode.min.js` | — | MIT — © davidshimjs | Painting the QR onto the card face |

Full licence texts ship inside each minified file's header comment; do not strip
the headers when updating.
