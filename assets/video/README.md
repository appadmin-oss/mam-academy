# Homepage film panel

Drop the files here with exactly these names and the homepage picks them up — no code change.

| File | What | Target |
|---|---|---|
| `hero.mp4` | The desktop loop (H.264, no audio). The panel is 21:9 and opens to the full screen width on scroll, so keep the subject in the middle band of the frame | 2560×1080 (or 1920×1080, cropped top and bottom), under 4 MB |
| `hero.webm` | Optional smaller copy of the same loop | under 3 MB |
| `hero-mobile.mp4` | Optional phone crop, 4:5 | 1080×1350, under 2.5 MB |
| `hero-poster.webp` (or `.jpg`) | The first frame as a still | under 200 KB |

What plays where (assets/js/site.js, "the homepage film panel"):
- Desktop: the loop. Phone: `hero-mobile.mp4` if present, otherwise the still.
- Reduced motion or data-saver: the still only, never the video.
- Off screen: paused. The round button pauses it for good.
- With no files at all the panel draws its own studio light (site.css `.film`).

## Current files (21 Nov 2026)
From the owner's 10s studio clip (1280×720). The audio track was removed entirely: none
of these files carries sound. The last half-second crossfades into the first, so the
loop has no visible restart (9.5s). The phone file is a 4:5 crop that pans to follow
the action. The poster is a frame at 5.6s (the singer with the ensemble).
