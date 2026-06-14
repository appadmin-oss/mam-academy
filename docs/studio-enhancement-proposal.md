# MAM Academy — Studio: Enhancement Proposal

**Status:** proposal for review · **Author:** engineering · **Date:** 2026-06-14
**Scope:** turn the Studio from a strong set of tools into an award-winning, dynamic, highly-sought-after surface that drives applications — and into a measurable, AI-optimized practice environment — without regressing what already works.

> Companion to `docs/lms-enterprise-proposal.md` and `docs/application-enhancement-proposal.md`. The Studio is the top of the funnel; this proposal connects it to the same spine (AI gateway, automation cron, practice tracker). Grounded in `tools.php`, `assets/js/tools.js`, `assets/js/rhythm-game.js`, `assets/js/pitch-game.js`, `assets/js/sequencer.js`, `assets/js/studio-gate.js`, `assets/js/config.js`.

---

## 0 · TL;DR

The Studio is genuinely good: **eight real tools** built on a proper Web-Audio engine (AudioContext scheduling, latency compensation, instant-stop), a tasteful free-to-discover / locked-until-sign-in gate with a real capped demo, an auto practice-log for signed-in students, and solid SEO (WebApplication + BreadcrumbList + FAQPage JSON-LD). What it lacks is everything that would make it **spread and convert**:

1. **No telemetry** — we can't see which tools drive engagement or applications.
2. **No shareability or gamification beyond a personal best** — no shareable score cards, streaks, or badges, so the Studio creates no awareness loop.
3. **No AI feedback** — the engines score *what* but never explain *why* or prescribe *what to practise next*.
4. **Free tools don't bridge to "apply"**, and tools aren't independently discoverable in search.

This proposal adds those four things in phases, reusing the LMS AI gateway and automation spine.

---

## 1 · Current state (honest assessment)

**Tool inventory (verified in `tools.php`):**

| Tool | Key | Access | What it does |
|------|-----|--------|--------------|
| Metronome | `metronome` | Free | Tempo, accents, time signatures, speed trainer |
| Chromatic Tuner | `tuner` | Free | Mic tuning, adjustable A4 |
| Practice Timer | `pomodoro` | Free | Focused blocks, logs each session |
| Ear Training | `ear` | Free | Interval / chord-quality / pitch-direction drills, adaptive |
| Beat Maker | `seq` | Free | 16-step sequencer, kits, swing, FX, one-click WAV export |
| **Rhythm Trainer** | `rhythm` | **Students** | Endless, Trinity-graded sight-reading; tap *or* play on any instrument (mic onset detection) |
| **Pitch Game** | `pitchgame` | **Students** | Real-time intonation / timing / consistency scoring |
| **Speed & Pitch** | `speed` | **Students** | Slow any track down + transpose independently |

**Strengths:**
- **Real engines, not toys** — AudioContext-clock scheduling with latency compensation and gain-bus instant-stop; the Rhythm Trainer is procedurally endless, Trinity-graded, and accepts mic input (tap or any instrument); Beat Maker exports WAV.
- **A funnel-aware gate (`studio-gate.js`):** the 3 student tools are *discoverable* but locked, each showing a pitch + Sign-in/Apply CTAs + a **real 45-second capped demo** (`DEMO_CAP_MS`). If auth is unknown it **stays locked** (never leaks a premium tool).
- **Auto practice-log:** `logScore()` persists per-student personal bests and records a practice session to the portal tracker for signed-in students (throttled) — the Studio already feeds the LMS.
- **Accessibility & SEO baseline:** roving-tabindex tabs, overview cards that deep-jump to a tool, and WebApplication + BreadcrumbList + FAQPage structured data with OG/Twitter.

---

## 2 · Gaps & weaknesses (grounded)

1. **No engagement telemetry.** We can't see tool usage, demo→apply conversion, or which tool is the hook. (mirrors the apply-funnel analytics gap)
2. **No shareable results.** Rhythm/Pitch scores and a Trinity "Distinction" can't be shared as an image/OG card — the Studio produces no awareness or social proof.
3. **Gamification stops at a personal best.** `logScore()` stores bests, but the Studio surfaces no **streak**, no badges, and no (opt-in) **cohort leaderboard** to create return-visits and friendly competition.
4. **Free tools never bridge to "apply."** A non-applicant can use all five free tools indefinitely with no contextual, value-first nudge tying mastery → application. The gate only pitches on the three locked tools.
5. **No AI feedback.** The engines produce numbers (intonation %, timing, Trinity band) but never explain the *why* or prescribe the *next drill* — the single biggest differentiator, and the data is already computed.
6. **Tools aren't independently discoverable.** Everything lives under `tools.php`; there are no per-tool deep-linkable pages with their own metadata to win long-tail search ("online metronome", "guitar tuner by mic", "rhythm sight-reading trainer").
7. **The practice loop is one-way.** Auto practice-log feeds the portal, but the Studio doesn't surface the signed-in student's own streak/history inline to motivate the next session.
8. **A11y / mobile-audio depth unverified.** Audio cues need visual/caption alternatives and reduced-motion support for **WCAG 2.2 AA**; iOS audio-unlock and mic-permission UX need a pass.
9. **No embeddable widgets.** A tuner/metronome embeddable on partner blogs would earn backlinks + reach.

---

## 3 · Target improvements (phased, additive)

### S1 · Instrument + make tools discoverable
- **First-party engagement telemetry:** `tool_open`, `demo_start`, `demo_complete`, `score_logged`, `gate_apply_click` → a first-party endpoint (no third-party pixel). Finally answers "which tool drives applications."
- **Per-tool deep-linkable SEO:** stable anchors/routes (`/tools#metronome` → optionally `/tools/metronome`) each with their own title/description/`SoftwareApplication` JSON-LD, so each tool wins its own long-tail keyword while the hub page stays intact.

### S2 · Shareability + gamification — *the awareness loop*
- **Shareable score cards:** a server-rendered OG image for a Rhythm "Distinction" / Pitch best ("I scored 92 — Trinity Distinction on MAM Academy") with a deep link back. Turns practice into reach.
- **Streaks + badges, surfaced:** show the signed-in student's current streak and recent badges inline in the Studio (data already in `logScore`/the portal), plus an **opt-in cohort leaderboard** for friendly competition.
- **A "mastery → apply" bridge** on the free tools: once a visitor hits a meaningful free-tool milestone, a tasteful, dismissible prompt connecting their progress to the full programme.

### S3 · AI practice feedback + value-first capture (via the LMS AI gateway)
- **Explain-the-score + next-drill:** after a Rhythm/Pitch run, the `api/lib/ai.php` gateway (Sonnet-tier, prompt-cached on the rubric, **Batch** for non-blocking generation) turns the numeric result + weak-figure data into one or two sentences of plain-language feedback and a concrete next exercise. The differentiator the user asked for ("initiates thinking").
- **Value-first lead capture on free tools:** "save your tuner presets / metronome setlists — sign in" — capture that *gives* value rather than gating it, feeding the same accounts the apply funnel uses.

### S4 · Reach + hardening
- **Embeddable widgets:** an `<iframe>`-able tuner/metronome with a "powered by MAM Academy" link for partner sites — backlinks + brand reach.
- **Accessibility to WCAG 2.2 AA:** visual/caption alternatives for every audio cue, full keyboard operation of the audio tools, reduced-motion; verify iOS audio-unlock + mic-permission flows on mobile.

---

## 4 · Success metrics

| Dimension | Metric |
|-----------|--------|
| Reach | Studio MAU; organic impressions for tool keywords |
| Engagement | Tool opens, demo completion rate, return-visit rate, streak length |
| Conversion | demo→apply and free-tool→apply click-through |
| Virality | Share-card generations and click-backs |
| Practice loop | Signed-in practice-logs/week; AI-feedback acceptance |

---

## 5 · Quick wins (ship now)
1. **First analytics events** (`tool_open`, `demo_start/complete`, `gate_apply_click`) — unblocks measuring the funnel.
2. **Surface the signed-in student's streak/personal-best inline** (data already exists via `logScore`/portal).
3. **A soft "apply to unlock more" bridge** on free tools (dismissible, value-first).
4. **Per-tool anchors + metadata** for long-tail SEO, no structural change.

---

## 6 · Risks & sequencing

| Risk | Mitigation |
|------|------------|
| Gamification feels gimmicky vs. "professional" | Keep it restrained + Trinity-aligned; leaderboards opt-in; bests/streaks framed as practice discipline, not points-grinding |
| Share cards leak PII | First-name/initials only; student opts in per share |
| AI feedback cost | Sonnet-tier + prompt-cached rubric + Batch for non-blocking runs; per-account quota (gateway) |
| A11y regressions in audio tools | Bake AA checks into the test pass; visual alternatives for all audio cues |

**Sequence:** quick wins (§5) → **S1** (instrument + discover) → **S2** (share + gamify — the awareness loop) → **S3** (AI feedback + capture) → **S4** (embeds + a11y). Each ships independently and reuses the AI gateway + automation spine from the LMS proposal.

---

*The Studio already has the hard part — real, accurate, Trinity-grade engines behind a smart gate. The opportunity is to make it measurable, shareable, and AI-coached, so it stops being a quiet utility and becomes the awareness-and-conversion engine the academy deserves.*
