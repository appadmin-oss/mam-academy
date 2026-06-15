<?php
/**
 * Embeddable MAM Academy metronome widget — a standalone, dependency-free,
 * iframe-able tool partner sites can drop in, with a "powered by MAM Academy"
 * backlink (reach + SEO). Self-contained Web Audio (no app JS), no mic/camera
 * permissions, and frameable cross-origin (X-Frame-Options is unset for this
 * file in .htaccess).
 *
 * Embed:  <iframe src="https://academy.mamcentre.org/embed" width="340"
 *           height="420" style="border:0;border-radius:16px" title="Metronome"></iframe>
 *
 * NOTE: needs browser QA — the Web Audio timing + iframe framing can only be
 * confirmed in a real browser.
 */
declare(strict_types=1);
$bpm = max(40, min(240, (int)($_GET['bpm'] ?? 120)));
header('Content-Type: text/html; charset=utf-8');
?><!doctype html>
<html lang="en"><head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Metronome — MAM Academy</title>
<meta name="robots" content="index,follow">
<style>
  :root{--bg:#120D08;--panel:#1A120B;--gold:#FBBF24;--gold2:#E08A1A;--ink:#FBF7F3;--muted:rgba(251,247,243,.6);--line:rgba(251,191,36,.25)}
  *{box-sizing:border-box}
  html,body{margin:0;height:100%}
  body{background:radial-gradient(120% 100% at 50% 0,#241710,#120D08);color:var(--ink);font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif;display:flex;align-items:center;justify-content:center;padding:18px}
  .card{width:100%;max-width:320px;background:var(--panel);border:1px solid var(--line);border-radius:18px;padding:22px 22px 16px;box-shadow:0 18px 50px rgba(0,0,0,.45);text-align:center}
  .bpm{font-size:64px;font-weight:800;letter-spacing:-.02em;line-height:1;font-variant-numeric:tabular-nums;color:var(--gold)}
  .bpm small{display:block;font-size:11px;font-weight:700;letter-spacing:.28em;text-transform:uppercase;color:var(--muted);margin-top:8px}
  input[type=range]{width:100%;margin:18px 0 6px;accent-color:var(--gold2)}
  .row{display:flex;gap:8px;justify-content:center;align-items:center;margin-top:6px}
  button{font:inherit;cursor:pointer;border-radius:11px;border:1px solid var(--line);background:transparent;color:var(--ink);padding:10px 14px;font-weight:700;min-height:44px}
  .play{flex:1;background:linear-gradient(135deg,var(--gold2),var(--gold));color:#1A120B;border:0;font-size:15px}
  .beats{display:flex;gap:7px;justify-content:center;margin:16px 0 2px}
  .dot{width:13px;height:13px;border-radius:50%;background:rgba(251,247,243,.18);transition:transform .05s,background .05s}
  .dot.on{background:var(--gold);transform:scale(1.5)}
  .dot.accent.on{background:#fff}
  label.sig{display:inline-flex;align-items:center;gap:6px;font-size:12px;color:var(--muted)}
  select{background:#120D08;color:var(--ink);border:1px solid var(--line);border-radius:8px;padding:6px 8px;font:inherit}
  .by{margin-top:14px;font-size:11px;color:var(--muted)}
  .by a{color:var(--gold);text-decoration:none;font-weight:700}
</style>
</head><body>
  <div class="card">
    <div class="bpm"><span id="bpmVal"><?= $bpm ?></span><small>BPM</small></div>
    <div class="beats" id="beats" aria-hidden="true"></div>
    <input id="tempo" type="range" min="40" max="240" value="<?= $bpm ?>" aria-label="Tempo in beats per minute">
    <div class="row">
      <button type="button" id="dec" aria-label="Slower">−</button>
      <button type="button" class="play" id="play" aria-pressed="false">▶ Start</button>
      <button type="button" id="inc" aria-label="Faster">+</button>
    </div>
    <div class="row" style="margin-top:12px">
      <label class="sig">Beats
        <select id="sig" aria-label="Beats per bar">
          <option>2</option><option>3</option><option selected>4</option><option>5</option><option>6</option>
        </select>
      </label>
    </div>
    <p id="live" role="status" aria-live="polite" style="position:absolute;left:-9999px">Stopped</p>
    <div class="by">Powered by <a href="https://academy.mamcentre.org/studio" target="_blank" rel="noopener">MAM Academy</a></div>
  </div>
<script>
(function(){
  var actx, timer=null, running=false, nextTime=0, cur=0;
  var bpm=<?= $bpm ?>, beats=4, LOOKAHEAD=25, AHEAD=0.12;
  var $=function(id){return document.getElementById(id);};
  var dots=[];
  function buildDots(){
    var b=$('beats'); b.innerHTML='';
    dots=[];
    for(var i=0;i<beats;i++){ var d=document.createElement('span'); d.className='dot'+(i===0?' accent':''); b.appendChild(d); dots.push(d); }
  }
  function flash(i){ dots.forEach(function(d,k){ d.classList.toggle('on', k===i); }); }
  function click(t, accent){
    var o=actx.createOscillator(), g=actx.createGain();
    o.frequency.value=accent?1600:1050;
    g.gain.setValueAtTime(0.0001,t);
    g.gain.exponentialRampToValueAtTime(0.5,t+0.001);
    g.gain.exponentialRampToValueAtTime(0.0001,t+0.05);
    o.connect(g); g.connect(actx.destination);
    o.start(t); o.stop(t+0.06);
  }
  function scheduler(){
    while(nextTime < actx.currentTime + AHEAD){
      var beat=cur, accent=(cur===0), when=nextTime;
      click(when, accent);
      setTimeout(function(){ if(running) flash(beat); }, Math.max(0,(when-actx.currentTime)*1000));
      nextTime += 60.0/bpm;
      cur=(cur+1)%beats;
    }
  }
  function start(){
    if(running) return;
    try{ actx=actx||new (window.AudioContext||window.webkitAudioContext)(); }catch(e){ return; }
    if(actx.state==='suspended') actx.resume();
    running=true; cur=0; nextTime=actx.currentTime+0.06;
    timer=setInterval(scheduler, LOOKAHEAD);
    $('play').textContent='■ Stop'; $('play').setAttribute('aria-pressed','true'); $('live').textContent='Playing at '+bpm+' BPM';
  }
  function stop(){
    running=false; if(timer) clearInterval(timer); timer=null;
    flash(-1); $('play').textContent='▶ Start'; $('play').setAttribute('aria-pressed','false'); $('live').textContent='Stopped';
  }
  function setBpm(v){ bpm=Math.max(40,Math.min(240,v|0)); $('bpmVal').textContent=bpm; $('tempo').value=bpm; if(running) $('live').textContent='Playing at '+bpm+' BPM'; }
  $('play').addEventListener('click', function(){ running?stop():start(); });
  $('tempo').addEventListener('input', function(e){ setBpm(parseInt(e.target.value,10)); });
  $('dec').addEventListener('click', function(){ setBpm(bpm-1); });
  $('inc').addEventListener('click', function(){ setBpm(bpm+1); });
  $('sig').addEventListener('change', function(e){ beats=parseInt(e.target.value,10)||4; cur=0; buildDots(); });
  buildDots();
})();
</script>
</body></html>
