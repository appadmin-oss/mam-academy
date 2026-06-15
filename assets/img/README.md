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

## Guidance
- **Format:** JPG or WebP. Keep each file < 300 KB (compress; these are display
  photos, not print). ~1200–1600 px on the long edge is plenty.
- **Aspect:** the frames are 4:3 and crop with `object-fit:cover`, so any
  landscape-ish crop works.
- **Alt text** is already set on each `<img>` for accessibility + SEO.

Add more gallery photos any time by dropping files in `assets/img/gallery/` and
referencing them the same way.
