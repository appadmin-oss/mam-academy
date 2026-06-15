<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="color-scheme" content="dark">
<title>Free Online Music Practice Tools — Metronome, Tuner, Ear Training &amp; Rhythm Games | MAM Academy Studio</title>
<meta name="description" content="Free online music practice studio: a precise metronome, chromatic tuner, focus timer, ear-training drills and a browser beat maker — plus Trinity-graded Rhythm Tap, the Pitch Game and a speed/pitch trainer for MAM Academy students. Works in your browser, your audio never leaves your device.">
<meta name="keywords" content="online metronome, free chromatic tuner, ear training online, rhythm game, sight-reading rhythm trainer, music practice tools, beat maker online, pitch trainer, tempo trainer, Trinity rhythm grading, MAM Academy">
<meta name="robots" content="index, follow, max-image-preview:large">
<link rel="canonical" href="https://academy.mamcentre.org/studio">
<link rel="icon" type="image/svg+xml" href="mam-academy-logo.svg">
<meta property="og:type" content="website">
<meta property="og:url" content="https://academy.mamcentre.org/studio">
<meta property="og:site_name" content="MAM Academy">
<meta property="og:title" content="Free Online Music Practice Studio — Metronome, Tuner, Ear Training &amp; Rhythm Games">
<meta property="og:description" content="A precise metronome, chromatic tuner, focus timer, ear-training drills and a beat maker — free in your browser. Plus Trinity-graded Rhythm Tap and the Pitch Game for MAM Academy students.">
<meta property="og:image" content="https://academy.mamcentre.org/mam-academy-logo.svg">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="Free Online Music Practice Studio — MAM Academy">
<meta name="twitter:description" content="Metronome, tuner, ear training, beat maker, Trinity-graded rhythm trainer and pitch game — free in your browser.">
<script type="application/ld+json">
{"@context":"https://schema.org","@graph":[
  {"@type":"WebApplication","@id":"https://academy.mamcentre.org/studio#app","name":"MAM Academy Practice Studio","url":"https://academy.mamcentre.org/studio","applicationCategory":"MusicApplication","operatingSystem":"Any (web browser)","offers":{"@type":"Offer","price":"0","priceCurrency":"USD"},"description":"A suite of free in-browser music practice tools — metronome, chromatic tuner, focus timer, ear-training drills and a beat maker — plus Trinity-graded Rhythm Tap, the Pitch Game and a speed/pitch trainer for enrolled students.","featureList":["Metronome with tempo trainer","Chromatic tuner","Pomodoro practice timer","Ear-training drills","Beat maker / step sequencer","Rhythm Tap (Trinity-graded)","Pitch Game","Speed & pitch trainer"],"publisher":{"@type":"EducationalOrganization","name":"MAM Academy","url":"https://academy.mamcentre.org"}},
  {"@type":"BreadcrumbList","itemListElement":[{"@type":"ListItem","position":1,"name":"Home","item":"https://academy.mamcentre.org/"},{"@type":"ListItem","position":2,"name":"Practice Studio","item":"https://academy.mamcentre.org/studio"}]}
]}
</script>
<!-- Permissions-Policy: the host injects a default policy that disallows
     microphone; without explicit allow, the tuner's getUserMedia errors with
     'Permissions policy violation'. The meta is a hint to the browser; the
     authoritative version is the matching .htaccess Header set rule.       -->
<meta http-equiv="Permissions-Policy" content="microphone=(self), camera=(), geolocation=(), interest-cohort=()">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=Cinzel:wght@600;700&family=Cormorant+Garamond:ital,wght@0,400;0,600;0,700;1,400&family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" referrerpolicy="no-referrer">
<link rel="stylesheet" href="assets/css/main.css?v=20260613b">
<style>
.tools-shell{max-width:1180px;margin:0 auto;padding:32px 22px 96px}
.tools-tabs{display:flex;flex-wrap:wrap;gap:6px;padding:6px;background:var(--bg-surface);border:1px solid var(--border);border-radius:18px;margin:14px 0 26px;width:fit-content;max-width:100%}
.tools-tabs button{padding:10px 18px;border-radius:12px;font-size:0.88rem;font-weight:700;color:var(--text-muted);background:transparent;border:0;cursor:pointer;display:inline-flex;align-items:center;gap:8px;min-height:42px;white-space:nowrap;transition:background .18s var(--ease),color .18s var(--ease)}
.tools-tabs button:hover{color:var(--text)}
.tools-tabs button.active{background:linear-gradient(135deg,var(--gold),var(--gold-light));color:#1A120B}
.tools-tabs button[data-locked] .tab-lock{font-size:0.66rem;opacity:0.7}
.tools-tabs button.active[data-locked] .tab-lock{opacity:0.85}
.tools-tabs-hint{display:flex;align-items:center;gap:8px;margin:18px 0 0;font-size:0.84rem;color:var(--text-muted)}
.tools-tabs-hint a{color:var(--gold-bright,#FCC647);font-weight:700;text-decoration:none}
.tools-tabs-hint a:hover{text-decoration:underline}
.tool-pill{display:inline-block;vertical-align:middle;font-size:0.6rem;font-weight:800;letter-spacing:0.08em;text-transform:uppercase;padding:3px 8px;border-radius:100px;background:rgba(224,138,26,0.16);color:var(--gold-bright,#FCC647);border:1px solid var(--border-gold,rgba(224,138,26,0.4));margin-left:8px}
/* Mobile: a single clean scroll-row instead of a wrapped pill blob. */
@media (max-width:760px){
  .tools-tabs{flex-wrap:nowrap;overflow-x:auto;width:100%;border-radius:14px;scrollbar-width:none;-webkit-overflow-scrolling:touch}
  .tools-tabs::-webkit-scrollbar{display:none}
  .tools-tabs button{flex:0 0 auto}
}
.tool-panel{display:none;background:var(--bg-surface);border:1px solid var(--border);border-radius:18px;padding:clamp(20px,3vw,36px);box-shadow:0 14px 36px rgba(0,0,0,0.25)}
.tool-panel.active{display:block;animation:fadeUp .3s var(--ease)}
@keyframes fadeUp{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:translateY(0)}}
.tool-head{display:flex;align-items:flex-start;justify-content:space-between;gap:14px;flex-wrap:wrap;margin-bottom:22px}
.tool-head h2{font-family:var(--font-serif);font-size:clamp(1.4rem,3vw,1.9rem);font-weight:700;color:var(--text);margin:0 0 4px}
.tool-head p{margin:0;color:var(--text-soft);font-size:0.94rem;line-height:1.6}

/* ── Metronome ─────────────────────────────────────────────────── */
.met-display{display:grid;grid-template-columns:1fr auto;gap:24px;align-items:center;margin-bottom:24px;padding:24px;background:var(--bg-raised);border:1px solid var(--border);border-radius:14px}
.met-bpm{font-family:var(--font-display);font-size:clamp(4rem,12vw,7rem);line-height:1;color:var(--gold-bright,#FCC647);letter-spacing:0.02em;text-align:center;min-width:160px}
.met-bpm small{display:block;font-family:var(--font-body);font-size:0.78rem;color:var(--text-muted);letter-spacing:0.1em;text-transform:uppercase;font-weight:700;margin-top:8px;letter-spacing:0.14em}
.met-beats{display:flex;gap:10px;justify-content:center;flex-wrap:wrap;min-height:36px}
.met-beat{width:32px;height:32px;border-radius:50%;background:var(--bg-elevated);border:2px solid var(--border-strong);transition:background .08s linear,transform .08s linear}
.met-beat.beat-active{background:var(--gold-bright,#FCC647);transform:scale(1.18);border-color:var(--gold-bright,#FCC647);box-shadow:0 0 18px rgba(252,198,71,0.6)}
.met-beat.beat-accent{border-color:var(--gold-bright,#FCC647)}
.met-controls{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:14px;margin-bottom:18px}
.met-controls label{display:block;font-size:0.74rem;font-weight:800;letter-spacing:0.08em;text-transform:uppercase;color:var(--text-muted);margin-bottom:6px}
.met-controls input[type=range]{width:100%}
.met-controls select,.met-controls input[type=number]{width:100%;padding:10px 12px;background:var(--bg-raised);border:1.5px solid var(--border-strong);border-radius:10px;color:var(--text);font-size:0.96rem;min-height:44px}
.met-actions{display:flex;gap:12px;flex-wrap:wrap;align-items:center}
.met-start{display:inline-flex;align-items:center;gap:10px;padding:14px 28px;background:linear-gradient(135deg,var(--gold),var(--gold-light));color:#1A120B;font-weight:800;letter-spacing:0.05em;font-size:1rem;border-radius:12px;border:0;cursor:pointer;min-height:52px;box-shadow:0 8px 22px rgba(224,138,26,0.4)}
.met-start.playing{background:linear-gradient(135deg,#F4423B,#FF6B6B);color:#fff;box-shadow:0 8px 22px rgba(244,66,59,0.45)}
.met-tap{padding:14px 22px;background:transparent;border:1px solid var(--border-gold);color:var(--gold-bright);font-weight:700;border-radius:12px;min-height:52px;cursor:pointer}
.met-tap:hover{background:rgba(224,138,26,0.06)}
.met-hint{font-size:0.78rem;color:var(--text-muted)}
.met-beat{cursor:pointer}
.met-trainer{margin-top:16px;background:var(--bg-raised);border:1px solid var(--border);border-radius:12px;padding:4px 16px}
.met-trainer summary{cursor:pointer;padding:12px 0;font-size:0.84rem;font-weight:800;letter-spacing:0.06em;text-transform:uppercase;color:var(--text-muted);display:flex;align-items:center;gap:8px}
.met-trainer summary #metTrainerStatus{margin-left:auto;font-weight:700;letter-spacing:0;text-transform:none;color:var(--gold-bright,#FCC647)}
.met-trainer-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:14px;padding:6px 0 16px}
.met-trainer-grid label{display:block;font-size:0.72rem;font-weight:800;letter-spacing:0.06em;text-transform:uppercase;color:var(--text-muted);margin-bottom:6px}
.met-trainer-grid input[type=number]{width:100%;padding:9px 12px;background:var(--bg-elevated);border:1.5px solid var(--border-strong);border-radius:10px;color:var(--text);font-size:0.94rem;min-height:42px}
.met-trainer-toggle{display:flex;align-items:center;gap:8px;text-transform:none !important;letter-spacing:0 !important;font-size:0.9rem !important;color:var(--text-soft) !important;align-self:end}
.met-trainer-toggle input{width:16px;height:16px;accent-color:var(--gold-bright,#FCC647)}

/* ── Tuner ─────────────────────────────────────────────────────── */
.tuner-display{padding:30px 24px;background:var(--bg-raised);border:1px solid var(--border);border-radius:14px;text-align:center;margin-bottom:18px}
.tuner-note{font-family:var(--font-display);font-size:clamp(5rem,14vw,8rem);line-height:1;color:var(--gold-bright,#FCC647);letter-spacing:0.02em;min-height:1em}
.tuner-octave{font-family:var(--font-body);font-size:0.5em;color:var(--text-muted);vertical-align:super;margin-left:6px}
.tuner-freq{font-size:0.95rem;color:var(--text-soft);margin-top:8px;font-variant-numeric:tabular-nums}
.tuner-cents{margin:20px auto 0;width:min(440px,90%);height:64px;position:relative;background:var(--bg-elevated);border-radius:8px;border:1px solid var(--border)}
.tuner-cents-center{position:absolute;top:0;bottom:0;left:50%;width:2px;background:var(--gold-bright,#FCC647);transform:translateX(-50%)}
.tuner-cents-tick{position:absolute;top:50%;transform:translate(-50%,-50%);width:12px;height:12px;background:#fff;border-radius:50%;left:50%;transition:left .12s ease-out;box-shadow:0 0 14px rgba(255,255,255,0.45)}
.tuner-cents-tick.in-tune{background:#34D399;box-shadow:0 0 18px rgba(52,211,153,0.65)}
.tuner-cents-tick.flat{background:#7CC3F0;box-shadow:0 0 18px rgba(124,195,240,0.65)}
.tuner-cents-tick.sharp{background:#F89B8A;box-shadow:0 0 18px rgba(248,155,138,0.65)}
.tuner-cents-labels{display:flex;justify-content:space-between;padding:6px 12px 0;font-size:0.74rem;color:var(--text-muted)}
.tuner-status{margin:14px 0 0;font-size:0.94rem;color:var(--text-muted);line-height:1.55}
.tuner-start{display:inline-flex;align-items:center;gap:10px;padding:13px 24px;background:linear-gradient(135deg,var(--gold),var(--gold-light));color:#1A120B;font-weight:800;letter-spacing:0.04em;border-radius:12px;border:0;cursor:pointer;min-height:48px;font-size:0.96rem}
.tuner-start.listening{background:linear-gradient(135deg,#F4423B,#FF6B6B);color:#fff}
.tuner-tools{display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-top:16px}
.tuner-a4{display:inline-flex;align-items:center;gap:8px;font-size:0.84rem;font-weight:700;color:var(--text-muted)}
.tuner-a4 input{width:78px;padding:9px 10px;background:var(--bg-raised);border:1.5px solid var(--border-strong);border-radius:9px;color:var(--text);font-size:0.92rem;min-height:42px}
.tuner-ref{display:inline-flex;align-items:center;gap:8px;padding:11px 18px;background:transparent;border:1px solid var(--border-gold);color:var(--gold-bright);font-weight:700;border-radius:10px;min-height:44px;cursor:pointer}
.tuner-ref:hover{background:rgba(224,138,26,0.06)}

/* ── Pomodoro ──────────────────────────────────────────────────── */
.pomo-display{text-align:center;padding:36px 24px;background:var(--bg-raised);border:1px solid var(--border);border-radius:14px;margin-bottom:18px}
.pomo-ring-wrap{position:relative;width:180px;height:180px;margin:0 auto}
.pomo-ring-wrap canvas{display:block}
.pomo-ring-inner{position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:4px}
.pomo-ring-inner .pomo-time{font-size:2.6rem}
.pomo-ring-inner .pomo-mode{margin-top:2px;font-size:0.66rem}
.pomo-time{font-family:var(--font-display);font-size:clamp(5rem,16vw,9rem);line-height:1;color:var(--gold-bright,#FCC647);letter-spacing:0.02em;font-variant-numeric:tabular-nums}
.pomo-mode{font-size:0.84rem;color:var(--text-muted);letter-spacing:0.14em;text-transform:uppercase;font-weight:800;margin-top:8px}
.pomo-mode.break{color:#7CC3F0}
.pomo-mode.long-break{color:#A5B4FC}
.pomo-actions{display:flex;gap:12px;justify-content:center;flex-wrap:wrap;margin:18px 0 4px}
.pomo-start{padding:14px 32px;background:linear-gradient(135deg,var(--gold),var(--gold-light));color:#1A120B;font-weight:800;letter-spacing:0.05em;border-radius:12px;border:0;cursor:pointer;min-height:52px;font-size:1rem;display:inline-flex;align-items:center;gap:10px}
.pomo-start.running{background:linear-gradient(135deg,#F4423B,#FF6B6B);color:#fff}
.pomo-reset{padding:14px 22px;background:transparent;border:1px solid var(--border-strong);color:var(--text-muted);font-weight:700;border-radius:12px;min-height:52px;cursor:pointer}
.pomo-cfg{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:14px;padding:16px;background:var(--bg-elevated);border:1px dashed var(--border);border-radius:12px;margin-top:12px}
.pomo-cfg label{display:block;font-size:0.74rem;font-weight:800;letter-spacing:0.08em;text-transform:uppercase;color:var(--text-muted);margin-bottom:6px}
.pomo-cfg input{width:100%;padding:10px 12px;background:var(--bg-raised);border:1.5px solid var(--border-strong);border-radius:10px;color:var(--text);font-size:0.96rem;min-height:42px}
.pomo-log{margin-top:18px;padding:16px;background:var(--bg-elevated);border:1px solid var(--border);border-radius:12px}
.pomo-log h4{font-size:0.84rem;color:var(--text-muted);letter-spacing:0.06em;text-transform:uppercase;font-weight:800;margin:0 0 8px}
.pomo-log ul{list-style:none;padding:0;margin:0;display:grid;gap:6px;max-height:160px;overflow:auto}
.pomo-log li{display:flex;justify-content:space-between;font-size:0.88rem;padding:6px 10px;background:var(--bg-raised);border-radius:8px}
.pomo-log .when{color:var(--text-muted);font-size:0.78rem}
.pomo-log .clear{margin-top:10px;font-size:0.78rem;color:#FCA5A5;background:none;border:0;cursor:pointer;padding:4px 0;text-decoration:underline}
.pomo-total{float:right;text-transform:none;letter-spacing:0;font-weight:700;color:var(--gold-bright,#FCC647)}
.pomo-auto{display:inline-flex;align-items:center;gap:8px;font-size:0.84rem;color:var(--text-soft);cursor:pointer}
.pomo-auto input{width:16px;height:16px;accent-color:var(--gold-bright,#FCC647)}

/* ── Ear training ──────────────────────────────────────────────── */
.ear-display{padding:24px;background:var(--bg-raised);border:1px solid var(--border);border-radius:14px;margin-bottom:18px}
.ear-score{display:flex;justify-content:space-between;align-items:center;margin-bottom:18px;font-size:0.92rem;color:var(--text-muted)}
.ear-score b{color:var(--text);font-family:var(--font-display);font-size:1.3rem;letter-spacing:0.02em}
.ear-question{text-align:center;padding:30px 14px;border-radius:12px;background:var(--bg-elevated);border:1px solid var(--border)}
.ear-replay{display:inline-flex;align-items:center;gap:10px;padding:14px 28px;background:linear-gradient(135deg,var(--gold),var(--gold-light));color:#1A120B;font-weight:800;letter-spacing:0.04em;border-radius:12px;border:0;cursor:pointer;min-height:52px;font-size:0.98rem}
.ear-options{display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:10px;margin-top:18px}
.ear-options button{padding:14px 16px;background:var(--bg-elevated);border:1.5px solid var(--border-strong);border-radius:10px;color:var(--text);font-weight:700;cursor:pointer;min-height:48px;transition:all .15s var(--ease)}
.ear-options button:hover{border-color:var(--border-gold);background:rgba(224,138,26,0.06)}
.ear-options button.correct{border-color:#34D399;background:rgba(52,211,153,0.12);color:#A7F3D0}
.ear-options button.wrong{border-color:#F4423B;background:rgba(244,66,59,0.12);color:#FCA5A5}
.ear-feedback{margin-top:14px;text-align:center;font-size:0.94rem;color:var(--text-soft);min-height:1.5em}
.ear-mode{display:inline-flex;gap:4px;padding:4px;background:var(--bg-elevated);border:1px solid var(--border);border-radius:100px;margin-bottom:14px}
.ear-mode button{padding:8px 14px;border-radius:100px;font-size:0.82rem;font-weight:700;color:var(--text-muted);background:transparent;border:0;cursor:pointer}
.ear-mode button.active{background:linear-gradient(135deg,var(--gold),var(--gold-light));color:#1A120B}
.ear-playmode{display:inline-flex;align-items:center;gap:8px;margin:0 0 14px;font-size:0.86rem;color:var(--text-soft);cursor:pointer}
.ear-playmode input{width:16px;height:16px;accent-color:var(--gold-bright,#FCC647)}
.ear-settings{display:flex;align-items:center;justify-content:space-between;gap:14px;flex-wrap:wrap;margin:0 0 14px}
.ear-diff{display:inline-flex;align-items:center;gap:8px;font-size:0.84rem;font-weight:700;color:var(--text-muted)}
.ear-diff select{padding:9px 12px;background:var(--bg-elevated);border:1.5px solid var(--border-strong);border-radius:10px;color:var(--text);font-size:0.9rem;font-weight:600;min-height:42px;cursor:pointer}
.ear-best{text-align:center;font-size:0.82rem;color:var(--gold-bright,#FCC647);font-weight:700;margin:-6px 0 14px}

/* ── Speed & Pitch trainer (premium) ──────────────────────────── */
.sp-drop{display:flex;flex-direction:column;align-items:center;justify-content:center;gap:8px;padding:48px 22px;border:2px dashed var(--border-gold);border-radius:14px;background:linear-gradient(180deg,rgba(252,198,71,0.05),var(--bg-raised));text-align:center;cursor:pointer;transition:all .2s var(--ease)}
.sp-drop:hover,.sp-drop.is-drag{background:linear-gradient(180deg,rgba(252,198,71,0.12),var(--bg-raised));border-color:var(--gold-bright);transform:translateY(-2px)}
.sp-drop i{font-size:2rem;color:var(--gold-bright)}
.sp-drop strong{font-size:1rem;color:var(--text);font-weight:700}
.sp-drop span{font-size:0.84rem;color:var(--text-muted)}

.sp-player{display:grid;gap:22px}
.sp-track{background:var(--bg-raised);border:1px solid var(--border);border-radius:14px;padding:18px 20px}
.sp-meta{display:flex;align-items:center;gap:12px;margin-bottom:14px}
.sp-meta i{flex-shrink:0;width:42px;height:42px;border-radius:50%;background:linear-gradient(135deg,var(--gold),var(--gold-light));color:#1A120B;display:flex;align-items:center;justify-content:center;font-size:1.1rem}
.sp-meta-text{flex:1;min-width:0}
.sp-meta-text strong{display:block;color:var(--text);font-size:0.96rem;font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.sp-meta-text span{font-size:0.78rem;color:var(--text-muted)}
.sp-clear{flex-shrink:0;width:34px;height:34px;border-radius:50%;background:var(--bg-elevated);border:1px solid var(--border);color:var(--text-muted);cursor:pointer;display:inline-flex;align-items:center;justify-content:center}
.sp-clear:hover{background:var(--bg-surface);color:var(--text)}

.sp-timeline{position:relative;height:36px;background:var(--bg-elevated);border-radius:10px;border:1px solid var(--border);cursor:pointer;overflow:hidden;user-select:none}
.sp-progress{position:absolute;inset:0 auto 0 0;width:0%;background:linear-gradient(90deg,var(--gold),var(--gold-bright));transition:width .08s linear;border-radius:10px 0 0 10px}
.sp-loop{position:absolute;top:0;bottom:0;background:rgba(99,102,241,0.32);border-left:2px solid #A5B4FC;border-right:2px solid #A5B4FC;pointer-events:none}
.sp-time-cur,.sp-time-dur{position:absolute;top:50%;transform:translateY(-50%);font-family:'SF Mono',Menlo,Consolas,monospace;font-size:0.78rem;color:#fff;text-shadow:0 1px 2px rgba(0,0,0,0.7);pointer-events:none;letter-spacing:0.02em}
.sp-time-cur{left:10px}
.sp-time-dur{right:10px;color:rgba(255,255,255,0.65)}

.sp-transport{display:flex;align-items:center;gap:10px;margin-top:16px}
.sp-tx-btn{width:46px;height:46px;border-radius:50%;background:var(--bg-elevated);border:1px solid var(--border);color:var(--text);cursor:pointer;display:inline-flex;align-items:center;justify-content:center;font-size:1rem;transition:all .15s var(--ease)}
.sp-tx-btn:hover{background:var(--bg-surface);border-color:var(--border-gold)}
.sp-tx-play{width:58px;height:58px;background:linear-gradient(135deg,var(--gold),var(--gold-light));color:#1A120B;border-color:transparent;font-size:1.15rem;box-shadow:0 8px 22px rgba(224,138,26,0.35)}
.sp-tx-play:hover{transform:translateY(-1px);box-shadow:0 10px 28px rgba(224,138,26,0.45)}
.sp-tx-sep{flex:1}
.sp-tx-loop{padding:0 16px;width:auto;min-width:90px;font-size:0.78rem;letter-spacing:0.04em;font-weight:700}
.sp-tx-loop.is-armed{background:rgba(99,102,241,0.18);border-color:#A5B4FC;color:#C7D2FE}
.sp-tx-loop.is-active{background:#A5B4FC;color:#0E0A06;border-color:transparent}

.sp-knobs{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:16px}
.sp-knob{padding:14px 16px;background:var(--bg-raised);border:1px solid var(--border);border-radius:12px}
.sp-knob label{display:flex;justify-content:space-between;align-items:baseline;font-size:0.72rem;font-weight:800;letter-spacing:0.1em;text-transform:uppercase;color:var(--text-muted);margin-bottom:8px}
.sp-knob label b{font-family:var(--font-display);font-size:1.15rem;color:var(--gold-bright);letter-spacing:0.02em;font-weight:normal}
.sp-knob input[type=range]{width:100%;margin:0;accent-color:var(--gold-bright)}
.sp-knob-marks{display:flex;justify-content:space-between;margin-top:4px;font-size:0.7rem;color:var(--text-muted);font-variant-numeric:tabular-nums}

.sp-presets{display:flex;flex-wrap:wrap;gap:8px;justify-content:flex-start}
.sp-presets button{padding:10px 16px;background:var(--bg-raised);border:1px solid var(--border);border-radius:10px;color:var(--text-soft);font-size:0.86rem;font-weight:700;cursor:pointer;transition:all .15s var(--ease)}
.sp-presets button:hover{border-color:var(--border-gold);color:var(--text)}
.sp-preset-reset{margin-left:auto;border-color:#FCA5A5 !important;color:#FCA5A5 !important;background:rgba(244,63,94,0.05) !important}
.sp-preset-reset:hover{background:rgba(244,63,94,0.12) !important}

@media (max-width:600px){
  .sp-drop{padding:36px 18px}
  .sp-tx-btn{width:42px;height:42px}
  .sp-tx-play{width:54px;height:54px}
}

@media (max-width:600px){
  .met-display{grid-template-columns:1fr;text-align:center}
  .met-bpm{margin-bottom:8px}
}

/* ── Beat Maker (step sequencer) ───────────────────────────────── */
.seq-transport{display:flex;align-items:flex-end;gap:18px;flex-wrap:wrap;padding:18px;background:var(--bg-raised);border:1px solid var(--border);border-radius:14px;margin-bottom:18px}
.seq-play{display:inline-flex;align-items:center;gap:10px;padding:14px 28px;background:linear-gradient(135deg,var(--gold),var(--gold-light));color:#1A120B;font-weight:800;letter-spacing:0.05em;font-size:1rem;border-radius:12px;border:0;cursor:pointer;min-height:54px;box-shadow:0 8px 22px rgba(224,138,26,0.4)}
.seq-play.playing{background:linear-gradient(135deg,#F4423B,#FF6B6B);color:#fff;box-shadow:0 8px 22px rgba(244,66,59,0.45)}
.seq-field{flex:1;min-width:150px}
.seq-field label{display:flex;justify-content:space-between;align-items:baseline;font-size:0.72rem;font-weight:800;letter-spacing:0.1em;text-transform:uppercase;color:var(--text-muted);margin-bottom:8px}
.seq-field label b{font-family:var(--font-display);font-size:1.1rem;color:var(--gold-bright,#FCC647);letter-spacing:0.02em;font-weight:normal}
.seq-field input[type=range]{width:100%;margin:0;accent-color:var(--gold-bright,#FCC647)}

.seq-grid{display:flex;gap:10px;align-items:flex-start;background:var(--bg-raised);border:1px solid var(--border);border-radius:14px;padding:14px;margin-bottom:18px}
.seq-tracks{flex-shrink:0;width:172px}
.seq-track-row{display:flex;align-items:center;gap:6px;height:46px;box-sizing:border-box}
.seq-track-name{flex:1;min-width:0;font-size:0.82rem;font-weight:700;color:var(--text-soft);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.seq-chip{width:26px;height:26px;flex-shrink:0;border-radius:7px;border:1px solid var(--border-strong);background:var(--bg-elevated);color:var(--text-muted);font-size:0.72rem;font-weight:800;cursor:pointer;line-height:1;padding:0}
.seq-chip.seq-mute.on{background:#F4423B;border-color:#F4423B;color:#fff}
.seq-chip.seq-solo.on{background:#34D399;border-color:#34D399;color:#0E0A06}
.seq-tvol{width:48px;flex-shrink:0;accent-color:var(--gold-bright,#FCC647)}
.seq-canvas-wrap{flex:1;min-width:0;overflow-x:auto;overflow-y:hidden}
#seqCanvas{display:block;touch-action:pan-x;cursor:pointer}

.seq-toolbar{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:12px}
.seq-tsep{flex:1}
.seq-preset-select{padding:11px 14px;background:var(--bg-raised);border:1.5px solid var(--border-strong);border-radius:10px;color:var(--text);font-size:0.9rem;font-weight:600;min-height:46px;cursor:pointer}
.seq-tbtn{display:inline-flex;align-items:center;gap:8px;padding:11px 18px;background:var(--bg-raised);border:1px solid var(--border-strong);border-radius:10px;color:var(--text-soft);font-size:0.88rem;font-weight:700;cursor:pointer;min-height:46px;transition:all .15s var(--ease)}
.seq-tbtn:hover{border-color:var(--border-gold);color:var(--text)}
.seq-tbtn-gold{background:linear-gradient(135deg,var(--gold),var(--gold-light));color:#1A120B;border-color:transparent;font-weight:800;box-shadow:0 6px 16px rgba(224,138,26,0.3)}
.seq-tbtn-gold:hover{transform:translateY(-1px);box-shadow:0 8px 20px rgba(224,138,26,0.4);color:#1A120B}
.seq-status{margin:0;font-size:0.88rem;color:var(--text-muted);line-height:1.55;min-height:1.4em}
.seq-bank{display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:14px}
.seq-bank-label{font-size:0.72rem;font-weight:800;letter-spacing:0.1em;text-transform:uppercase;color:var(--text-muted);margin-right:2px}
.seq-slot{width:42px;min-height:42px;border-radius:10px;border:1.5px solid var(--border-strong);background:var(--bg-raised);color:var(--text-muted);font-weight:800;font-size:0.95rem;cursor:pointer;position:relative;transition:all .15s var(--ease)}
.seq-slot:hover{border-color:var(--border-gold);color:var(--text)}
.seq-slot.filled{color:var(--text)}
.seq-slot.filled::after{content:"";position:absolute;top:6px;right:6px;width:6px;height:6px;border-radius:50%;background:var(--gold-bright,#FCC647)}
.seq-slot.active{background:linear-gradient(135deg,var(--gold),var(--gold-light));color:#1A120B;border-color:transparent}
.seq-steps-field{display:inline-flex;align-items:center;gap:8px;font-size:0.78rem;font-weight:700;letter-spacing:0.04em;text-transform:uppercase;color:var(--text-muted)}
.seq-steps-field select{padding:9px 12px;background:var(--bg-raised);border:1.5px solid var(--border-strong);border-radius:10px;color:var(--text);font-size:0.9rem;font-weight:600;min-height:42px;cursor:pointer}

.seq-chain{width:96px;padding:8px 10px;background:var(--bg-raised);border:1.5px solid var(--border-strong);border-radius:9px;color:var(--text);font-size:0.9rem;letter-spacing:0.18em;text-transform:uppercase;font-weight:800}
.seq-chain:focus{outline:none;border-color:var(--gold-bright)}
.seq-tbtn[disabled]{opacity:0.45;cursor:not-allowed}
.seq-tbtn.playing{background:linear-gradient(135deg,#F4423B,#FF6B6B);color:#fff;border-color:transparent}
@media (max-width:640px){
  .seq-grid{gap:6px;padding:10px}
  .seq-tracks{width:128px}
  .seq-track-name{font-size:0.74rem}
  .seq-tvol{width:36px}
  .seq-transport{gap:14px}
  .seq-toolbar .seq-tsep{flex-basis:100%;height:0}
  .seq-bank{flex-wrap:wrap}
}

/* ── Pitch Game ─────────────────────────────────────────────────── */
.pg-controls{display:flex;gap:14px 18px;flex-wrap:wrap;align-items:flex-end;margin-bottom:16px}
.pg-field{display:flex;flex-direction:column;gap:6px;font-size:0.78rem;font-weight:700;color:var(--text-muted);letter-spacing:0.02em}
.pg-field select,.pg-field input[type=range]{min-height:42px}
.pg-field select{padding:9px 12px;background:var(--bg-raised);border:1.5px solid var(--border-strong);border-radius:10px;color:var(--text);font-size:0.92rem;min-width:170px}
.pg-field input[type=range]{width:200px;accent-color:var(--gold-bright,#FCC647)}
.pg-check{display:inline-flex;align-items:center;gap:8px;font-size:0.84rem;font-weight:700;color:var(--text-muted)}
.pg-check input{width:18px;height:18px;accent-color:var(--gold-bright,#FCC647)}
.pg-stage{position:relative;background:radial-gradient(120% 120% at 50% 0%,rgba(232,150,31,0.06),var(--bg-elevated));border:1px solid var(--border);border-radius:16px;overflow:hidden}
.pg-stage canvas{display:block;width:100%}
.pg-results{position:absolute;inset:0;display:none;align-items:center;justify-content:center;background:rgba(10,8,4,0.78);backdrop-filter:blur(6px);padding:18px}
.pg-results.shown{display:flex}
.pg-result-card{text-align:center;max-width:420px;width:100%}
.pg-stars{font-size:1.7rem;letter-spacing:6px;margin-bottom:6px}
.pg-star{color:rgba(255,255,255,0.22)}.pg-star.on{color:var(--gold-bright,#FCC647);text-shadow:0 0 14px rgba(252,198,71,0.6)}
.pg-final{font-family:var(--font-display,inherit);font-size:4rem;line-height:1;color:var(--gold-bright,#FCC647)}
.pg-final small{font-size:1.4rem;color:var(--text-muted)}
.pg-final-label{color:var(--text-soft);margin:2px 0 14px;font-size:0.95rem}
.pg-breakdown{display:flex;justify-content:center;gap:22px;margin-bottom:14px}
.pg-breakdown b{display:block;font-size:1.3rem;color:var(--text)}
.pg-breakdown span{font-size:0.72rem;text-transform:uppercase;letter-spacing:0.08em;color:var(--text-muted)}
.pg-rows{display:flex;flex-direction:column;gap:5px;max-height:140px;overflow:auto;margin-bottom:16px;text-align:left}
.pg-rowline{display:flex;align-items:center;gap:10px;font-size:0.82rem;color:var(--text-soft)}
.pg-rowline span:first-child{width:42px;font-weight:700}
.pg-bar{flex:1;height:8px;background:var(--bg-raised);border-radius:5px;overflow:hidden}
.pg-bar i{display:block;height:100%;background:linear-gradient(90deg,var(--gold),var(--gold-bright));border-radius:5px}
.pg-meter{margin:16px 0}
.pg-readout{display:flex;justify-content:center;gap:clamp(18px,6vw,52px);margin-bottom:14px}
.pg-r{text-align:center}.pg-r-l{display:block;font-size:0.7rem;text-transform:uppercase;letter-spacing:0.1em;color:var(--text-muted);margin-bottom:3px}
.pg-r b{font-family:var(--font-display,inherit);font-size:2.2rem;line-height:1;color:var(--text)}
.pg-needle-track{position:relative;width:min(460px,92%);height:54px;margin:0 auto;background:var(--bg-elevated);border:1px solid var(--border);border-radius:8px}
.pg-needle-center{position:absolute;top:0;bottom:0;left:50%;width:2px;background:var(--gold-bright,#FCC647);transform:translateX(-50%)}
.pg-needle{position:absolute;top:50%;left:50%;width:14px;height:14px;border-radius:50%;background:#fff;transform:translate(-50%,-50%);transition:left .1s ease-out,background .1s;box-shadow:0 0 14px rgba(255,255,255,0.45)}
.pg-needle.in{background:#34D399;box-shadow:0 0 18px rgba(52,211,153,0.7)}
.pg-needle.sharp{background:#F89B8A}.pg-needle.flat{background:#7CC3F0}
.pg-needle-lab{position:absolute;bottom:4px;font-size:0.68rem;color:var(--text-muted)}
.pg-needle-lab.pg-flat{left:10px}.pg-needle-lab.pg-sharp{right:10px}
.pg-verdict{text-align:center;margin:14px 0 4px;font-size:1.05rem;font-weight:700;color:var(--text-soft)}
.pg-verdict.good{color:#34D399}.pg-verdict.off{color:#F4C56A}
.pg-cents{text-align:center;font-variant-numeric:tabular-nums;color:var(--text-muted);font-size:0.9rem}
.pg-actions{display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-top:8px}
.pg-btn{display:inline-flex;align-items:center;gap:8px;padding:12px 20px;border-radius:11px;border:1px solid var(--border-strong);background:var(--bg-raised);color:var(--text);font-weight:700;font-size:0.92rem;cursor:pointer;min-height:46px}
.pg-btn:hover{border-color:var(--border-gold)}
.pg-primary{background:linear-gradient(135deg,var(--gold),var(--gold-light));color:#1A120B;border-color:transparent}
.pg-running .pg-primary{background:linear-gradient(135deg,#F4423B,#FF6B6B);color:#fff}
.pg-status{font-size:0.84rem;color:var(--text-muted);flex:1 1 220px;min-width:0}
.pg-status.warn{color:#F4C56A}
@media (max-width:600px){.pg-field input[type=range]{width:150px}.pg-r b{font-size:1.7rem}}

/* ── Studio hero chips + tools-at-a-glance overview + FAQ (UX & SEO) ── */
.studio-hero-chips{display:flex;flex-wrap:wrap;gap:10px;margin-top:18px}
.studio-chip{display:inline-flex;align-items:center;gap:7px;padding:7px 14px;border-radius:100px;background:rgba(255,255,255,0.06);border:1px solid var(--border);color:var(--text-soft);font-size:0.82rem;font-weight:600}
.studio-chip i{color:var(--gold-bright,#FCC647)}
.studio-overview-band{max-width:1180px;margin:0 auto;padding:28px 22px 0}
.studio-overview{display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:14px}
.studio-card{display:flex;flex-direction:column;align-items:flex-start;gap:7px;text-align:left;padding:18px 18px 16px;border-radius:16px;border:1px solid var(--border);background:var(--bg-surface);cursor:pointer;transition:transform .16s var(--ease),border-color .16s var(--ease),box-shadow .16s var(--ease);position:relative}
.studio-card:hover{transform:translateY(-3px);border-color:var(--border-gold);box-shadow:0 14px 34px rgba(0,0,0,0.28)}
.studio-card-ic{width:42px;height:42px;border-radius:12px;display:grid;place-items:center;font-size:1.15rem;color:#1A120B;background:linear-gradient(135deg,var(--gold),var(--gold-light))}
.studio-card.locked .studio-card-ic{background:linear-gradient(135deg,#7a6a52,#9a8264);color:#fff}
.studio-card-name{font-weight:800;color:var(--text);font-size:1rem;display:flex;align-items:center;gap:7px}
.studio-card-name .fa-lock{font-size:0.7rem;color:var(--text-muted)}
.studio-card-desc{font-size:0.86rem;color:var(--text-soft);line-height:1.55}
.studio-card-tag{margin-top:2px;font-size:0.62rem;font-weight:800;letter-spacing:0.08em;text-transform:uppercase;padding:3px 9px;border-radius:100px;background:rgba(255,255,255,0.06);color:var(--text-muted);border:1px solid var(--border)}
.studio-card-tag.free{color:#34D399;border-color:rgba(52,211,153,0.4);background:rgba(52,211,153,0.1)}
.studio-faq{max-width:900px;margin:56px auto 0;padding:0 22px 80px}
.studio-faq h2{font-family:var(--font-serif);font-size:clamp(1.4rem,3vw,1.9rem);color:var(--text);margin:0 0 20px;text-align:center}
.faq-grid{display:grid;gap:12px}
.faq-item{background:var(--bg-surface);border:1px solid var(--border);border-radius:12px;padding:4px 18px}
.faq-item summary{cursor:pointer;padding:14px 0;font-weight:700;color:var(--text);font-size:0.98rem;list-style:none;display:flex;justify-content:space-between;align-items:center;gap:14px}
.faq-item summary::-webkit-details-marker{display:none}
.faq-item summary::after{content:'+';color:var(--gold-bright,#FCC647);font-size:1.3rem;font-weight:400;flex:0 0 auto}
.faq-item[open] summary::after{content:'−'}
.faq-item p{margin:0 0 16px;color:var(--text-soft);line-height:1.7;font-size:0.92rem}
.faq-item p a{color:var(--gold-bright,#FCC647)}
@media (max-width:600px){.studio-overview{grid-template-columns:1fr 1fr}.studio-card-desc{display:none}.studio-overview-band{padding:20px 16px 0}}
@media (max-width:400px){.studio-overview{grid-template-columns:1fr}}
</style>
</head>
<body>
<a href="#main" class="skip-link">Skip to main content</a>
<?php include __DIR__ . '/partials/header.html'; ?>

<main id="main">
  <header class="page-hero" style="--page-hero-img:url('https://images.unsplash.com/photo-1465225314224-587cd83d322b?w=1920&q=80');padding:84px 22px 44px">
    <div class="page-hero-inner">
      <nav class="breadcrumb"><a href="/">Home</a><i class="fa-solid fa-chevron-right"></i><span>Practice Studio</span></nav>
      <div class="page-hero-eyebrow"><i class="fa-solid fa-wand-magic-sparkles"></i>The Practice Studio</div>
      <h1 class="page-hero-title">Free online music <span class="highlight-gold">practice tools</span></h1>
      <p class="page-hero-lede">A pro-grade metronome, chromatic tuner, focus timer, ear-training drills and a browser beat maker — plus a Trinity-graded rhythm trainer and pitch game for MAM students. In your browser, nothing to install, your audio never leaves your device.</p>
      <div class="studio-hero-chips">
        <span class="studio-chip"><i class="fa-solid fa-bolt"></i> No install</span>
        <span class="studio-chip"><i class="fa-solid fa-shield-halved"></i> Private — on-device audio</span>
        <span class="studio-chip"><i class="fa-solid fa-infinity"></i> Free for everyone</span>
      </div>
    </div>
  </header>

  <!-- Tools at a glance — orientation + crawlable, keyword-rich content. Each
       card jumps to its tab (data-goto-tool, handled in tools.js). -->
  <section class="studio-overview-band" aria-label="Practice tools overview">
    <div class="studio-overview" id="studioOverview">
      <button class="studio-card" type="button" data-goto-tool="metronome"><span class="studio-card-ic"><i class="fa-solid fa-stopwatch-20"></i></span><span class="studio-card-name">Metronome</span><span class="studio-card-desc">Rock-steady tempo with accents, time signatures &amp; a speed trainer.</span><span class="studio-card-tag free">Free</span></button>
      <button class="studio-card" type="button" data-goto-tool="tuner"><span class="studio-card-ic"><i class="fa-solid fa-tower-broadcast"></i></span><span class="studio-card-name">Chromatic Tuner</span><span class="studio-card-desc">Tune any instrument by microphone, with an adjustable A4 reference.</span><span class="studio-card-tag free">Free</span></button>
      <button class="studio-card" type="button" data-goto-tool="pomodoro"><span class="studio-card-ic"><i class="fa-solid fa-hourglass-half"></i></span><span class="studio-card-name">Practice Timer</span><span class="studio-card-desc">Focused Pomodoro blocks that keep a log of every session.</span><span class="studio-card-tag free">Free</span></button>
      <button class="studio-card" type="button" data-goto-tool="ear"><span class="studio-card-ic"><i class="fa-solid fa-headphones"></i></span><span class="studio-card-name">Ear Training</span><span class="studio-card-desc">Interval, chord-quality &amp; pitch-direction drills that adapt to you.</span><span class="studio-card-tag free">Free</span></button>
      <button class="studio-card" type="button" data-goto-tool="seq"><span class="studio-card-ic"><i class="fa-solid fa-grip"></i></span><span class="studio-card-name">Beat Maker</span><span class="studio-card-desc">A 16-step sequencer with kits, swing, FX and one-click WAV export.</span><span class="studio-card-tag free">Free</span></button>
      <button class="studio-card locked" type="button" data-goto-tool="rhythm"><span class="studio-card-ic"><i class="fa-solid fa-drum"></i></span><span class="studio-card-name">Rhythm Trainer <i class="fa-solid fa-lock"></i></span><span class="studio-card-desc">Endless, Trinity-graded sight-reading — tap it or play it on any instrument.</span><span class="studio-card-tag">Students</span></button>
      <button class="studio-card locked" type="button" data-goto-tool="pitchgame"><span class="studio-card-ic"><i class="fa-solid fa-microphone-lines"></i></span><span class="studio-card-name">Pitch Game <i class="fa-solid fa-lock"></i></span><span class="studio-card-desc">Real-time intonation, timing &amp; consistency scoring as you sing or play.</span><span class="studio-card-tag">Students</span></button>
      <button class="studio-card locked" type="button" data-goto-tool="speed"><span class="studio-card-ic"><i class="fa-solid fa-gauge-high"></i></span><span class="studio-card-name">Speed &amp; Pitch <i class="fa-solid fa-lock"></i></span><span class="studio-card-desc">Slow any track down and transpose it to your key — independently.</span><span class="studio-card-tag">Students</span></button>
    </div>
  </section>

  <section class="tools-shell">
    <p class="tools-tabs-hint" id="toolsTabsHint" hidden><i class="fa-solid fa-lock"></i> <span></span></p>
    <nav class="tools-tabs" role="tablist" aria-label="Practice tools">
      <button class="active" data-tool="metronome" role="tab" aria-selected="true"><i class="fa-solid fa-stopwatch-20"></i> Metronome</button>
      <button data-tool="tuner" role="tab" aria-selected="false"><i class="fa-solid fa-tower-broadcast"></i> Tuner</button>
      <button data-tool="pomodoro" role="tab" aria-selected="false"><i class="fa-solid fa-hourglass-half"></i> Pomodoro</button>
      <button data-tool="ear" role="tab" aria-selected="false"><i class="fa-solid fa-headphones"></i> Ear Training</button>
      <button data-tool="seq" role="tab" aria-selected="false"><i class="fa-solid fa-grip"></i> Beat Maker</button>
      <button data-tool="rhythm" data-locked="student" role="tab" aria-selected="false"><i class="fa-solid fa-drum"></i> Rhythm Tap <i class="fa-solid fa-lock tab-lock" aria-label="Students only" title="Students only"></i></button>
      <button data-tool="pitchgame" data-locked="student" role="tab" aria-selected="false"><i class="fa-solid fa-microphone-lines"></i> Pitch Game <i class="fa-solid fa-lock tab-lock" aria-label="Students only" title="Students only"></i></button>
      <button data-tool="speed" data-locked="student" role="tab" aria-selected="false"><i class="fa-solid fa-gauge-high"></i> Speed &amp; Pitch <i class="fa-solid fa-lock tab-lock" aria-label="Students only" title="Students only"></i></button>
    </nav>

    <!-- ─── Metronome ─── -->
    <section class="tool-panel active" data-tool="metronome" role="tabpanel">
      <div class="tool-head">
        <div><h2>Metronome</h2><p>Steady time, accented downbeats, time-signature aware. Press space to start &amp; stop. Tempo is shared with the Beat Maker.</p></div>
      </div>
      <div class="met-display">
        <div class="met-bpm" aria-live="polite"><span id="metBpmVal">100</span><small>BPM</small></div>
        <div class="met-beats" id="metBeats"></div>
      </div>
      <div class="met-controls">
        <div><label for="metBpmRange">Tempo</label><input type="range" id="metBpmRange" min="30" max="240" value="100"></div>
        <div><label for="metBeatsCount">Beats per bar</label>
          <select id="metBeatsCount">
            <option value="2">2 / 4</option><option value="3">3 / 4</option>
            <option value="4" selected>4 / 4</option><option value="5">5 / 4</option>
            <option value="6">6 / 8</option><option value="7">7 / 8</option>
          </select>
        </div>
        <div><label for="metSubdivision">Subdivision</label>
          <select id="metSubdivision">
            <option value="1" selected>Quarter</option>
            <option value="2">Eighth</option>
            <option value="3">Triplet</option>
            <option value="4">Sixteenth</option>
          </select>
        </div>
        <div><label for="metVolume">Volume</label><input type="range" id="metVolume" min="0" max="100" value="60"></div>
      </div>
      <div class="met-actions">
        <button class="met-start" id="metStart" type="button"><i class="fa-solid fa-play"></i> <span>Start</span></button>
        <button class="met-tap" id="metTap" type="button"><i class="fa-solid fa-hand-pointer"></i> Tap tempo</button>
        <span class="met-hint">Click a beat dot to toggle its accent.</span>
      </div>

      <details class="met-trainer">
        <summary><i class="fa-solid fa-gauge-high"></i> Tempo trainer <span id="metTrainerStatus"></span></summary>
        <div class="met-trainer-grid">
          <label class="met-trainer-toggle"><input type="checkbox" id="metTrainer"> Speed up automatically</label>
          <div><label for="metTrainerStep">Increase by (BPM)</label><input type="number" id="metTrainerStep" min="1" max="40" value="4"></div>
          <div><label for="metTrainerBars">Every (bars)</label><input type="number" id="metTrainerBars" min="1" max="16" value="2"></div>
          <div><label for="metTrainerMax">Up to (BPM)</label><input type="number" id="metTrainerMax" min="40" max="240" value="180"></div>
        </div>
      </details>
    </section>

    <!-- ─── Tuner ─── -->
    <section class="tool-panel" data-tool="tuner" role="tabpanel">
      <div class="tool-head">
        <div><h2>Chromatic tuner</h2><p>Adjustable concert-pitch reference. Click the mic icon to grant access — your audio stays on your device, nothing is recorded.</p></div>
      </div>
      <div class="tuner-display">
        <div class="tuner-note"><span id="tunerNote">—</span><span class="tuner-octave" id="tunerOctave"></span></div>
        <div class="tuner-freq"><span id="tunerFreq">—</span></div>
        <div class="tuner-cents" aria-hidden="true">
          <div class="tuner-cents-center"></div>
          <div class="tuner-cents-tick" id="tunerTick" style="left:50%"></div>
        </div>
        <div class="tuner-cents-labels"><span>−50¢ flat</span><span>0¢ in tune</span><span>+50¢ sharp</span></div>
        <p class="tuner-status" id="tunerStatus">Click "Listen" to start.</p>
      </div>
      <div class="tuner-tools">
        <button class="tuner-start" id="tunerStart" type="button"><i class="fa-solid fa-microphone"></i> <span>Listen</span></button>
        <label class="tuner-a4" for="tunerA4">A4 <input type="number" id="tunerA4" min="415" max="466" value="440"> Hz</label>
        <label class="tuner-a4" for="tunerTranspose">Instrument
          <select id="tunerTranspose">
            <option value="0">Concert (C)</option>
            <option value="2">B♭ (trumpet, clarinet, tenor)</option>
            <option value="9">E♭ (alto sax)</option>
            <option value="7">F (horn)</option>
            <option value="-12">Guitar / bass (8vb)</option>
          </select>
        </label>
        <button class="tuner-ref" id="tunerRef" type="button"><i class="fa-solid fa-volume-high"></i> Play A4</button>
      </div>
    </section>

    <!-- ─── Pomodoro ─── -->
    <section class="tool-panel" data-tool="pomodoro" role="tabpanel">
      <div class="tool-head">
        <div><h2>Practice timer</h2><p>Focused 25-minute practice blocks with short + long breaks. Saves your last 7 sessions on this device.</p></div>
      </div>
      <div class="pomo-display">
        <div class="pomo-ring-wrap">
          <canvas id="pomoRing" width="180" height="180" aria-hidden="true"></canvas>
          <div class="pomo-ring-inner">
            <div class="pomo-time" id="pomoTime">25:00</div>
            <div class="pomo-mode" id="pomoMode">Focus · Block 1 of 4</div>
          </div>
        </div>
      </div>
      <div class="pomo-actions">
        <button class="pomo-start" id="pomoStart" type="button"><i class="fa-solid fa-play"></i> <span>Start focus</span></button>
        <button class="pomo-reset" id="pomoReset" type="button"><i class="fa-solid fa-rotate-left"></i> Reset</button>
        <label class="pomo-auto"><input type="checkbox" id="pomoAuto" checked> Auto-start next phase</label>
      </div>
      <div class="pomo-cfg">
        <div><label for="pomoFocus">Focus (min)</label><input type="number" id="pomoFocus" min="5" max="90" value="25"></div>
        <div><label for="pomoShort">Short break</label><input type="number" id="pomoShort" min="2" max="30" value="5"></div>
        <div><label for="pomoLong">Long break</label><input type="number" id="pomoLong" min="5" max="60" value="20"></div>
        <div><label for="pomoBlocks">Blocks → long break</label><input type="number" id="pomoBlocks" min="2" max="8" value="4"></div>
      </div>
      <div class="pomo-log">
        <h4><i class="fa-solid fa-list-check"></i> Today's sessions <span class="pomo-total" id="pomoTotal"></span></h4>
        <ul id="pomoSessions"><li style="justify-content:center;color:var(--text-muted)">No sessions logged yet.</li></ul>
        <button class="clear" id="pomoClear" type="button">Clear history</button>
      </div>
    </section>

    <!-- ─── Ear Training ─── -->
    <section class="tool-panel" data-tool="ear" role="tabpanel">
      <div class="tool-head">
        <div><h2>Ear training</h2><p>Practice intervals, chords and pitch direction. Score updates live; difficulty adjusts to your accuracy.</p></div>
      </div>
      <div class="ear-mode" role="tablist">
        <button class="active" data-ear-mode="intervals" type="button">Intervals</button>
        <button data-ear-mode="chords" type="button">Chord quality</button>
        <button data-ear-mode="direction" type="button">Pitch direction</button>
      </div>
      <div class="ear-settings">
        <label class="ear-playmode"><input type="checkbox" id="earHarmonic"> Play intervals harmonically (both notes together)</label>
        <label class="ear-diff">Difficulty
          <select id="earDifficulty">
            <option value="easy">Easy</option>
            <option value="medium">Medium</option>
            <option value="all" selected>Hard (all)</option>
          </select>
        </label>
      </div>
      <div class="ear-display">
        <div class="ear-score">
          <span>Correct: <b id="earCorrect">0</b> / <b id="earTotal">0</b></span>
          <span>Streak: <b id="earStreak">0</b></span>
        </div>
        <div class="ear-best" id="earBest"></div>
        <div class="ear-question" id="earQuestion">
          <button class="ear-replay" id="earPlay" type="button"><i class="fa-solid fa-play"></i> <span>Play interval</span></button>
        </div>
        <div class="ear-options" id="earOptions"></div>
        <div class="ear-feedback" id="earFeedback">Pick a mode above, then press Play.</div>
      </div>
    </section>

    <!-- ─── Speed & Pitch Trainer (premium · student-only) ─── -->
    <section class="tool-panel" data-tool="speed" data-locked="student" role="tabpanel">
      <div class="tool-head">
        <div>
          <h2>Speed &amp; Pitch Trainer <span class="tool-pill">Students only</span></h2>
          <p>Load a track, slow it down to learn it, transpose it to your key — independently. Audio never leaves your device.</p>
        </div>
      </div>

      <!-- Drop-zone / file picker -->
      <div class="sp-drop" id="spDrop" tabindex="0" role="button" aria-label="Upload audio file">
        <i class="fa-solid fa-cloud-arrow-up"></i>
        <strong id="spDropTitle">Drop an audio file, or click to choose</strong>
        <span>MP3, WAV, M4A, FLAC, OGG &middot; up to 50 MB &middot; stays on your device</span>
        <input type="file" id="spFile" accept="audio/*" hidden>
      </div>

      <!-- Player + controls (hidden until a file is loaded) -->
      <div class="sp-player" id="spPlayer" hidden>
        <div class="sp-track">
          <div class="sp-meta">
            <i class="fa-solid fa-music"></i>
            <div class="sp-meta-text">
              <strong id="spName">—</strong>
              <span id="spDuration">—</span>
            </div>
            <button type="button" class="sp-clear" id="spClear" title="Load a different track" aria-label="Clear track">
              <i class="fa-solid fa-xmark"></i>
            </button>
          </div>

          <!-- Timeline + A/B loop markers -->
          <div class="sp-timeline" id="spTimeline" tabindex="0" role="slider" aria-label="Seek">
            <div class="sp-progress" id="spProgress"></div>
            <div class="sp-loop" id="spLoopBand" hidden></div>
            <div class="sp-time-cur" id="spCurTime">0:00</div>
            <div class="sp-time-dur" id="spDurTime">0:00</div>
          </div>

          <!-- Transport -->
          <div class="sp-transport">
            <button type="button" class="sp-tx-btn" id="spRew" title="Rewind 10s"><i class="fa-solid fa-backward"></i></button>
            <button type="button" class="sp-tx-btn sp-tx-play" id="spPlay" title="Play / pause">
              <i class="fa-solid fa-play" id="spPlayIcon"></i>
            </button>
            <button type="button" class="sp-tx-btn" id="spFwd" title="Forward 10s"><i class="fa-solid fa-forward"></i></button>
            <span class="sp-tx-sep"></span>
            <button type="button" class="sp-tx-btn sp-tx-loop" id="spLoopBtn" title="Set A/B loop points">
              <i class="fa-solid fa-repeat"></i> <span id="spLoopLabel">A–B</span>
            </button>
          </div>
        </div>

        <!-- Sliders: speed + pitch + volume -->
        <div class="sp-knobs">
          <div class="sp-knob">
            <label for="spSpeed">Speed <b id="spSpeedVal">1.00×</b></label>
            <input type="range" id="spSpeed" min="0.25" max="2" step="0.05" value="1">
            <div class="sp-knob-marks"><span>0.25×</span><span>1×</span><span>2×</span></div>
          </div>
          <div class="sp-knob">
            <label for="spPitch">Pitch <b id="spPitchVal">±0 st</b></label>
            <input type="range" id="spPitch" min="-12" max="12" step="1" value="0">
            <div class="sp-knob-marks"><span>−12</span><span>0</span><span>+12</span></div>
          </div>
          <div class="sp-knob">
            <label for="spVol">Volume <b id="spVolVal">100%</b></label>
            <input type="range" id="spVol" min="0" max="100" step="1" value="90">
            <div class="sp-knob-marks"><span>0</span><span>50</span><span>100</span></div>
          </div>
        </div>

        <!-- Quick presets -->
        <div class="sp-presets">
          <button type="button" data-preset="0.5">½× speed</button>
          <button type="button" data-preset="0.75">¾× speed</button>
          <button type="button" data-preset="1">Original</button>
          <button type="button" class="sp-preset-reset" data-preset="reset">Reset all</button>
        </div>
      </div>
    </section>

    <!-- ─── Beat Maker (step sequencer) ─── -->
    <section class="tool-panel" data-tool="seq" role="tabpanel">
      <div class="tool-head">
        <div>
          <h2>Beat Maker</h2>
          <p>A pocket production studio. Tap the grid to build a groove (the bottom lane is a <b>pitched bass</b> — drag a lit cell up/down to change its note), pick a kit, dial in space &amp; echo, then chain patterns A–D into a song and bounce it to WAV. Everything is synthesised live in your browser.</p>
        </div>
      </div>

      <!-- Transport -->
      <div class="seq-transport">
        <button class="seq-play" id="seqPlay" type="button"><i class="fa-solid fa-play"></i> <span>Play</span></button>
        <div class="seq-field">
          <label for="seqBpm">Tempo <b id="seqBpmVal">100</b> BPM</label>
          <input type="range" id="seqBpm" min="40" max="240" value="100">
        </div>
        <div class="seq-field">
          <label for="seqSwing">Swing <b id="seqSwingVal">0%</b></label>
          <input type="range" id="seqSwing" min="0" max="60" value="0">
        </div>
        <div class="seq-field">
          <label for="seqMaster">Volume <b id="seqMasterVal">85%</b></label>
          <input type="range" id="seqMaster" min="0" max="100" value="85">
        </div>
        <div class="seq-field">
          <label for="seqSpace">Space <b id="seqSpaceVal">16%</b></label>
          <input type="range" id="seqSpace" min="0" max="60" value="16" title="Reverb send">
        </div>
        <div class="seq-field">
          <label for="seqEcho">Echo <b id="seqEchoVal">12%</b></label>
          <input type="range" id="seqEcho" min="0" max="60" value="12" title="Tempo-synced delay send">
        </div>
        <label class="seq-steps-field" for="seqKit">Kit
          <select id="seqKit" aria-label="Drum kit">
            <option value="classic" selected>MAM Classic</option>
            <option value="e808">808</option>
            <option value="afro">Afro Percussion</option>
          </select>
        </label>
        <label class="seq-steps-field" for="seqKey">Key
          <select id="seqKey" aria-label="Bass key">
            <option value="0" selected>C</option><option value="1">C#</option><option value="2">D</option>
            <option value="3">D#</option><option value="4">E</option><option value="5">F</option>
            <option value="6">F#</option><option value="7">G</option><option value="8">G#</option>
            <option value="9">A</option><option value="10">A#</option><option value="11">B</option>
          </select>
        </label>
      </div>

      <!-- Grid: track controls + canvas -->
      <div class="seq-grid">
        <div class="seq-tracks" id="seqTracks"></div>
        <div class="seq-canvas-wrap" id="seqCanvasWrap">
          <canvas id="seqCanvas" aria-label="Step sequencer grid — tap a cell once for a hit, twice for an accent"></canvas>
        </div>
      </div>

      <!-- Pattern bank + length -->
      <div class="seq-bank">
        <span class="seq-bank-label">Patterns</span>
        <button class="seq-slot active" data-slot="0" type="button" title="Pattern A">A</button>
        <button class="seq-slot" data-slot="1" type="button" title="Pattern B">B</button>
        <button class="seq-slot" data-slot="2" type="button" title="Pattern C">C</button>
        <button class="seq-slot" data-slot="3" type="button" title="Pattern D">D</button>
        <button class="seq-tbtn" id="seqStore" type="button" title="Store the current groove in the selected slot"><i class="fa-solid fa-floppy-disk"></i> Store</button>
        <span class="seq-tsep"></span>
        <label class="seq-steps-field" for="seqSteps">Steps
          <select id="seqSteps" aria-label="Pattern length in steps">
            <option value="8">8</option>
            <option value="16" selected>16</option>
            <option value="32">32</option>
          </select>
        </label>
        <span class="seq-tsep"></span>
        <label class="seq-steps-field" for="seqChain">Song
          <input type="text" id="seqChain" class="seq-chain" placeholder="e.g. ABBC" maxlength="16" autocomplete="off" spellcheck="false" aria-label="Pattern chain, letters A to D">
        </label>
        <button class="seq-tbtn" id="seqPlaySong" type="button" disabled><i class="fa-solid fa-list-music"></i> Play song</button>
        <button class="seq-tbtn" id="seqExportSong" type="button" disabled title="Render the whole chain to a WAV"><i class="fa-solid fa-file-export"></i> Export song</button>
      </div>

      <!-- Toolbar -->
      <div class="seq-toolbar">
        <select id="seqPresets" class="seq-preset-select" aria-label="Load a preset groove">
          <option value="">Load a groove…</option>
          <option value="Four on the floor">Four on the floor</option>
          <option value="Boom bap">Boom bap</option>
          <option value="Afrobeat">Afrobeat</option>
          <option value="Highlife">Highlife</option>
          <option value="One drop">One drop</option>
        </select>
        <button class="seq-tbtn" id="seqRandom" type="button"><i class="fa-solid fa-shuffle"></i> Randomise</button>
        <button class="seq-tbtn" id="seqClear" type="button"><i class="fa-solid fa-eraser"></i> Clear</button>
        <span class="seq-tsep"></span>
        <button class="seq-tbtn seq-tbtn-gold" id="seqExport" type="button"><i class="fa-solid fa-download"></i> Export WAV</button>
      </div>
      <p class="seq-status" id="seqStatus">Tip: tap a cell once for a hit, twice for an accent. Press play, add swing, and store grooves in A–D.</p>
    </section>

    <!-- ─── Rhythm Tap (student-only · Trinity-graded, endless, listen-and-play) ─── -->
    <section class="tool-panel" data-tool="rhythm" data-locked="student" role="tabpanel">
      <div class="tool-head">
        <div><h2>Rhythm Tap <span class="tool-pill">Students only</span></h2><p>Read the rhythm, then tap it or <strong>play it back</strong> — clap, drum, sing or any instrument; we detect the timing, not the notes. Endless auto-levelling, Trinity-standard grading and rewards.</p></div>
      </div>
      <div id="rhythmGame"></div>
    </section>

    <!-- ─── Pitch Game (sing & play — scored intonation + timing) ─── -->
    <section class="tool-panel" data-tool="pitchgame" data-locked="student" role="tabpanel">
      <div class="tool-head">
        <div><h2>Pitch Game — sing &amp; play <span class="tool-pill">Students only</span></h2><p>Match each note as it reaches the line. Real-time pitch detection scores your intonation (in cents), timing and consistency — like a coach in your pocket. Sing it or play your instrument; your audio stays on your device and nothing is recorded.</p></div>
      </div>
      <div id="pitchGame"></div>
    </section>
  </section>

  <!-- ─── SEO + UX: a real, indexable FAQ (mirrored in FAQPage structured data) ─── -->
  <section class="studio-faq" aria-labelledby="studioFaqH">
    <h2 id="studioFaqH">Practice Studio — frequently asked questions</h2>
    <div class="faq-grid">
      <details class="faq-item"><summary>Are these music practice tools really free?</summary><p>Yes. The metronome, chromatic tuner, practice timer, ear-training drills and beat maker are free for everyone — no account, no install, no payment. The Rhythm Trainer, Pitch Game and Speed &amp; Pitch trainer are unlocked for enrolled MAM Academy students.</p></details>
      <details class="faq-item"><summary>Does the online tuner work on my phone?</summary><p>It works in any modern mobile or desktop browser that can access the microphone. Tap “Listen”, allow microphone access, and play a single note — the tuner shows the nearest pitch and how many cents sharp or flat you are. You can set the A4 reference for transposing instruments.</p></details>
      <details class="faq-item"><summary>Is my microphone audio recorded or uploaded?</summary><p>No. The tuner, Pitch Game and Rhythm Trainer analyse sound entirely on your device in real time — nothing is recorded, stored or sent anywhere. The Speed &amp; Pitch trainer decodes your audio file locally too.</p></details>
      <details class="faq-item"><summary>How is the Rhythm Trainer graded?</summary><p>Each level is scored out of 100 and banded to the Trinity College London standard — Distinction (≥87), Merit (≥75) and Pass (≥60). It auto-advances through endless, procedurally generated levels, and you can tap the rhythm or play it on any instrument (clap, drum, sing) while it listens to your timing.</p></details>
      <details class="faq-item"><summary>Can I use the metronome and tuner without signing up?</summary><p>Absolutely — the five core tools need no sign-in. To unlock the student tools and have your practice automatically logged to your portal, <a href="/apply">apply to MAM Academy</a>.</p></details>
      <details class="faq-item"><summary>What is the Beat Maker?</summary><p>A 16-step drum sequencer you can play in the browser: pick a kit, program a groove across drum and pitched-bass lanes, add swing and effects, chain patterns into a song and export it to WAV — all synthesised live, with nothing to download.</p></details>
    </div>
  </section>
  <script type="application/ld+json">
  {"@context":"https://schema.org","@type":"FAQPage","mainEntity":[
    {"@type":"Question","name":"Are these music practice tools really free?","acceptedAnswer":{"@type":"Answer","text":"Yes. The metronome, chromatic tuner, practice timer, ear-training drills and beat maker are free for everyone — no account, no install, no payment. The Rhythm Trainer, Pitch Game and Speed & Pitch trainer are unlocked for enrolled MAM Academy students."}},
    {"@type":"Question","name":"Does the online tuner work on my phone?","acceptedAnswer":{"@type":"Answer","text":"It works in any modern mobile or desktop browser that can access the microphone. Tap Listen, allow microphone access and play a single note — the tuner shows the nearest pitch and how many cents sharp or flat you are, with an adjustable A4 reference."}},
    {"@type":"Question","name":"Is my microphone audio recorded or uploaded?","acceptedAnswer":{"@type":"Answer","text":"No. The tuner, Pitch Game and Rhythm Trainer analyse sound entirely on your device in real time — nothing is recorded, stored or sent anywhere."}},
    {"@type":"Question","name":"How is the Rhythm Trainer graded?","acceptedAnswer":{"@type":"Answer","text":"Each level is scored out of 100 and banded to the Trinity College London standard — Distinction (87+), Merit (75+) and Pass (60+) — auto-advancing through endless procedurally generated levels. Tap the rhythm or play it on any instrument while it listens to your timing."}},
    {"@type":"Question","name":"Can I use the metronome and tuner without signing up?","acceptedAnswer":{"@type":"Answer","text":"Yes — the five core tools need no sign-in. Apply to MAM Academy to unlock the student tools and have your practice automatically logged to your portal."}},
    {"@type":"Question","name":"What is the Beat Maker?","acceptedAnswer":{"@type":"Answer","text":"A 16-step drum sequencer in the browser: pick a kit, program a groove across drum and pitched-bass lanes, add swing and effects, chain patterns into a song and export to WAV — all synthesised live."}}
  ]}
  </script>
</main>

<?php include __DIR__ . '/partials/footer.html'; ?>
<script src="assets/js/config.js?v=20260613b"></script>
<script src="assets/js/main.js?v=20260613b"></script>
<!--
  Audio libraries are DEFERRED until the first user interaction with the
  studio. Tone.js performs capability probes at script-evaluation time
  (creates a transient AudioContext to test ConstantSourceNode and
  ScheduledSource), which trips Chrome's autoplay-policy warning. Loading
  Tone after a gesture means the warning never fires and the context starts
  cleanly. Pitchy is ES-module — imported the same way.
-->
<script>
  (function () {
    var loaded = false;
    function loadAudioLibs() {
      if (loaded) return; loaded = true;
      var t = document.createElement('script');
      t.src = 'https://cdn.jsdelivr.net/npm/tone@15.0.4/build/Tone.js';
      t.async = true;
      t.onload = function () { window.dispatchEvent(new CustomEvent('mam:tone-ready')); };
      document.head.appendChild(t);
      var p = document.createElement('script');
      p.type = 'module';
      p.textContent = "import('https://cdn.jsdelivr.net/npm/pitchy@4.1.0/+esm').then(m=>{window.MAM_PITCHY=m;window.dispatchEvent(new CustomEvent('mam:pitchy-ready'))}).catch(e=>console.warn('[MAM_STUDIO] pitchy load failed',e));";
      document.head.appendChild(p);
    }
    var attach = function () {
      var shell = document.querySelector('.tools-shell') || document.body;
      var fire = function () { loadAudioLibs(); };
      shell.addEventListener('pointerdown', fire, { once: true });
      document.addEventListener('keydown', fire, { once: true });
    };
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', attach);
    else attach();
  })();
</script>
<script src="assets/js/studio-core.js?v=20260615a" defer></script>
<script src="assets/js/tools.js?v=20260615a" defer></script>
<script src="assets/js/sequencer.js?v=20260613a" defer></script>
<script src="assets/js/pitch-game.js?v=20260613a" defer></script>
<script src="assets/js/rhythm-game.js?v=20260613d" defer></script>
<script src="assets/js/studio-gate.js?v=20260615c" defer></script>
</body>
</html>
