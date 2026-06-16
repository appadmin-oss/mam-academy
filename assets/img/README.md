# MAM Academy image assets

The site UI references the photos below. **Drop the actual image files at these
exact paths** and commit them — the sections are already wired to them and will
appear automatically (until then a tasteful branded placeholder shows, so
nothing looks broken).

> Note: these two photos were shared in chat and can't be written to the repo
> programmatically — please add the files here.

| Path | Used on | Suggested image |
|------|---------|-----------------|
| `assets/img/gallery/mam-drummer.jpg` | About → "Life at MAM" band | The B&W student-drummer performance photo |
| `assets/img/values/mam-guitar-morale.jpg` | About → "Life at MAM" band | The guitar artwork — "At MAM we use music lessons to boost morale" |
| `assets/img/showcase/mam-voice.jpg` | Home → "Find Your Sound" showcase (row 1) | The promo tile of the girl singing (orange bg + soundwave halo) |
| `assets/img/showcase/mam-sax.jpg` | Home → "Find Your Sound" showcase (row 2) | The promo tile of the boy on saxophone |
| `assets/img/showcase/mam-strings.jpg` | Home → "Find Your Sound" showcase (row 3) | The promo tile of the boy on violin |

### "Find Your Sound" showcase tiles
These are the square campaign promo images (subject on the orange gradient with
the soundwave halo). The frame is a dark matte with a warm glow, so the vibrant
orange tiles pop against the dark page. Square (1:1) is ideal — they're shown at
`aspect-ratio:1/1` with `object-fit:cover`. JPG/WebP, < 300 KB each. Until the
files are dropped in, a branded music-note placeholder shows in each frame.

## Guidance
- **Format:** JPG or WebP. Keep each file < 300 KB (compress; these are display
  photos, not print). ~1200–1600 px on the long edge is plenty.
- **Aspect:** the frames are 4:3 and crop with `object-fit:cover`, so any
  landscape-ish crop works.
- **Alt text** is already set on each `<img>` for accessibility + SEO.

Add more gallery photos any time by dropping files in `assets/img/gallery/` and
referencing them the same way.
