<?php
/**
 * MAM Academy — Music education online and in person — from the 2026-11 design handoff (Main.dc.html),
 * ported through partials/site/ (see shell.php for how, and HANDOFF §4z16).
 */
declare(strict_types=1);
require __DIR__ . '/partials/site/shell.php';
site_head(['title' => 'MAM Academy — Music education online and in person', 'description' => 'Graded music education from age 4 to diploma, online or in person. Four levels equivalent to Trinity College London Grades 1–8, placed by the MAM Auditer assessment.', 'path' => '/']);
site_header('home', '');
ob_start();
?>
<main id="main">

    <section aria-labelledby="hero-h" data-hero style="max-width: 1360px; margin: 0 auto; padding: clamp(40px, 5vw, 72px) clamp(20px, 4vw, 40px) 0; box-sizing: border-box">
      <h1 id="hero-h" aria-label="Where young musicians become complete artists" style="margin: 0; font-family: 'Cormorant Garamond', Georgia, serif; font-weight: 500; font-size: clamp(47px, 5.83vw, 84px); line-height: 1.02; letter-spacing: -0.01em; max-width: 980px; color: #211A13"><span class="mask" aria-hidden="true"><span style="animation-delay: 0.200s" class="w">Where</span></span> <span class="mask" aria-hidden="true"><span style="animation-delay: 0.285s" class="w">young</span></span> <span class="mask" aria-hidden="true"><span style="animation-delay: 0.370s" class="w">musicians</span></span> <span class="mask" aria-hidden="true"><span style="animation-delay: 0.455s" class="w">become</span></span> <span class="mask" aria-hidden="true"><span style="font-style: italic; color: #8A5208; animation-delay: 0.540s" class="w">complete</span></span> <span class="mask" aria-hidden="true"><span style="animation-delay: 0.625s" class="w">artists</span></span></h1>
      <div style="display: flex; flex-wrap: wrap; align-items: flex-end; justify-content: space-between; gap: 20px 48px; margin-top: clamp(20px, 2.4vw, 32px)">
        <p class="fade-in" style="animation-delay: .7s; margin: 0; font-size: 19px; line-height: 1.6; color: #3E342B; max-width: 560px">Graded music education from age 4 to diploma — online or in person at our studios.</p>
        <div class="fade-in" style="animation-delay: .9s; display: flex; gap: 12px; flex-wrap: wrap">
          <a href="/apply" class="btn" style="display: inline-flex; align-items: center; min-height: 48px; padding: 0 26px; border-radius: 999px; background: #F2A93B; color: #211A13; font-weight: 600; text-decoration: none">Apply now</a>
          <a href="/auditer" style="display: inline-flex; align-items: center; min-height: 48px; padding: 0 25px; border-radius: 999px; border: 1px solid #CFC3B3; color: #211A13; font-weight: 600; text-decoration: none">Visit a class</a>
        </div>
      </div>
    </section>
    <div class="film-wrap fade-in" style="animation-delay: .5s">
      <?php $heroMedia = site_hero_media(); ?>
      <div class="film<?= $heroMedia['poster'] !== '' ? ' has-poster' : '' ?>" data-film data-src="<?= site_h($heroMedia['mp4']) ?>" data-webm="<?= site_h($heroMedia['webm']) ?>" data-mobile="<?= site_h($heroMedia['mobile']) ?>">
        <?php if ($heroMedia['poster'] !== ''): ?><img class="film-poster" src="<?= site_h($heroMedia['poster']) ?>" alt="" decoding="async" fetchpriority="high"><?php endif; ?>
        <div class="film-light" aria-hidden="true"><span></span><span></span><span></span></div>
        <div class="film-grain" aria-hidden="true"></div>
        <button type="button" class="film-pause" data-film-toggle aria-pressed="false" aria-label="Pause the moving background"><svg aria-hidden="true" width="14" height="14" viewBox="0 0 14 14"><rect class="i-pause" x="3" y="2" width="3" height="10" rx="1"/><rect class="i-pause" x="8" y="2" width="3" height="10" rx="1"/><path class="i-play" d="M4 2.5v9l7.5-4.5z"/></svg></button>
      </div>
    </div>

    <section id="about" aria-labelledby="about-h" style="max-width: 900px; margin: 0 auto; padding: clamp(64px, 8.3vw, 120px) clamp(20px, 4vw, 40px) clamp(56px, 7.8vw, 112px); display: flex; flex-direction: column; align-items: center; gap: 24px; text-align: center">
      <h2 aria-label="More than music lessons" id="about-h" style="margin: 0; font-family: 'Cormorant Garamond', Georgia, serif; font-weight: 500; font-size: clamp(34px, 4.17vw, 60px); line-height: 1.08; letter-spacing: -0.005em"><span class="mask" aria-hidden="true"><span class="sw" style="animation-range: entry 0% entry 60%">More</span></span> <span class="mask" aria-hidden="true"><span class="sw" style="animation-range: entry 6% entry 66%">than</span></span> <span class="mask" aria-hidden="true"><span class="sw" style="animation-range: entry 12% entry 72%">music</span></span> <span class="mask" aria-hidden="true"><span class="sw" style="animation-range: entry 18% entry 78%">lessons</span></span></h2>
      <p class="read" style="margin: 0; font-size: 18px; color: #574B40; max-width: 680px">Since 2008, MAM Academy has formed musicians who can play, perform, read and think. Every student is heard first, placed at the right level, and taught by a working musician — then grows across four schools of learning.</p>
      <div style="display: flex; gap: 10px; flex-wrap: wrap; justify-content: center; margin-top: 8px">
        <a href="/about" style="display: inline-flex; align-items: center; min-height: 44px; padding: 0 20px; border-radius: 999px; background: #2A1E14; color: #FCF8F3; font-size: 15px; font-weight: 600; text-decoration: none">About MAM</a>
        <a href="#schools-h" style="display: inline-flex; align-items: center; min-height: 44px; padding: 0 19px; border-radius: 999px; border: 1px solid #CDBFAE; color: #211A13; font-size: 15px; font-weight: 600; text-decoration: none">The MAM Method</a>
      </div>
    </section>

    <section id="method" aria-labelledby="schools-h" style="max-width: 1360px; margin: 0 auto; padding: 48px clamp(20px, 4vw, 40px) clamp(72px, 10vw, 144px); display: flex; flex-direction: column; gap: 48px">
      <div class="reveal" style="display: flex; flex-direction: column; gap: 14px; max-width: 760px">
        <p style="margin: 0; font-size: 13px; font-weight: 600; letter-spacing: .16em; text-transform: uppercase; color: #8A5208">The MAM Method</p>
        <h2 aria-label="Four schools inside every programme" id="schools-h" style="margin: 0; font-family: 'Cormorant Garamond', Georgia, serif; font-weight: 500; font-size: clamp(34px, 4.17vw, 60px); line-height: 1.05"><span class="mask" aria-hidden="true"><span class="sw" style="animation-range: entry 0% entry 60%">Four</span></span> <span class="mask" aria-hidden="true"><span class="sw" style="animation-range: entry 6% entry 66%">schools</span></span> <span class="mask" aria-hidden="true"><span class="sw" style="animation-range: entry 12% entry 72%">inside</span></span> <span class="mask" aria-hidden="true"><span class="sw" style="animation-range: entry 18% entry 78%">every</span></span> <span class="mask" aria-hidden="true"><span class="sw" style="animation-range: entry 24% entry 84%">programme</span></span></h2>
      </div>
      <div class="bento" style="display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); grid-auto-rows: 320px; gap: 20px">
        
          <article class="zoom reveal" style="grid-column: span 2; position: relative; isolation: isolate; overflow: hidden; border-radius: 24px; background: #2A1E14; color: #FCF8F3; padding: 32px; display: flex; flex-direction: column; gap: 10px; min-width: 0">
            <img alt="" loading="lazy" decoding="async" style="position: absolute; right: 0; bottom: 0; height: 92%; z-index: -1" src="/assets/img/showcase/mam-strings.webp">
            
            <div aria-hidden="true" style="position: absolute; inset: 0; z-index: -1; background: linear-gradient(90deg, rgba(42,30,20,.95) 0%, rgba(42,30,20,.6) 55%, rgba(42,30,20,0) 100%)"></div>
            <span aria-hidden="true" style="font-family: 'Cormorant Garamond', Georgia, serif; font-size: 20px; font-style: italic; color: #F2A93B">i</span>
            <h3 style="margin: auto 0 0; font-family: 'Cormorant Garamond', Georgia, serif; font-weight: 600; font-size: 34px; line-height: 1.05; max-width: 70%">Instrumental Intelligence</h3>
            <p style="margin: 0; font-size: 15px; color: #E9E1D6; max-width: 60%">Mastery of keys, strings, wind, brass, drums and voice.</p>
          </article>
        
          <article class="zoom reveal" style="grid-column: span 1; position: relative; isolation: isolate; overflow: hidden; border-radius: 24px; background: #2A1E14; color: #FCF8F3; padding: 32px; display: flex; flex-direction: column; gap: 10px; min-width: 0">
            <img alt="" loading="lazy" decoding="async" style="position: absolute; right: 0; bottom: 0; height: 92%; z-index: -1" src="/assets/img/showcase/mam-voice.webp">
            
            <div aria-hidden="true" style="position: absolute; inset: 0; z-index: -1; background: linear-gradient(90deg, rgba(42,30,20,.95) 0%, rgba(42,30,20,.6) 55%, rgba(42,30,20,0) 100%)"></div>
            <span aria-hidden="true" style="font-family: 'Cormorant Garamond', Georgia, serif; font-size: 20px; font-style: italic; color: #F2A93B">ii</span>
            <h3 style="margin: auto 0 0; font-family: 'Cormorant Garamond', Georgia, serif; font-weight: 600; font-size: 34px; line-height: 1.05; max-width: 70%">Performance Intelligence</h3>
            <p style="margin: 0; font-size: 15px; color: #E9E1D6; max-width: 60%">Stagecraft, etiquette and performance psychology.</p>
          </article>
        
          <article class="zoom reveal" style="grid-column: span 1; position: relative; isolation: isolate; overflow: hidden; border-radius: 24px; background: #2A1E14; color: #FCF8F3; padding: 32px; display: flex; flex-direction: column; gap: 10px; min-width: 0">
            <img alt="" loading="lazy" decoding="async" style="position: absolute; right: 0; bottom: 0; height: 92%; z-index: -1" src="/assets/img/showcase/mam-sax.webp">
            
            <div aria-hidden="true" style="position: absolute; inset: 0; z-index: -1; background: linear-gradient(90deg, rgba(42,30,20,.95) 0%, rgba(42,30,20,.6) 55%, rgba(42,30,20,0) 100%)"></div>
            <span aria-hidden="true" style="font-family: 'Cormorant Garamond', Georgia, serif; font-size: 20px; font-style: italic; color: #F2A93B">iii</span>
            <h3 style="margin: auto 0 0; font-family: 'Cormorant Garamond', Georgia, serif; font-weight: 600; font-size: 34px; line-height: 1.05; max-width: 70%">Rhythm Intelligence</h3>
            <p style="margin: 0; font-size: 15px; color: #E9E1D6; max-width: 60%">The MAM Rapid Rhythm System: sight singing, ear and timing.</p>
          </article>
        
          <article class="zoom reveal" style="grid-column: span 2; position: relative; isolation: isolate; overflow: hidden; border-radius: 24px; background: #2A1E14; color: #FCF8F3; padding: 32px; display: flex; flex-direction: column; gap: 10px; min-width: 0">
            
            <div class="ph" data-fx-img="" style="position: absolute; inset: 0; z-index: -1; display: flex; align-items: flex-start; justify-content: flex-end; padding: 20px; font-size: 12px; color: rgba(252,248,243,.7)"></div>
            <div aria-hidden="true" style="position: absolute; inset: 0; z-index: -1; background: linear-gradient(90deg, rgba(42,30,20,.95) 0%, rgba(42,30,20,.6) 55%, rgba(42,30,20,0) 100%)"></div>
            <span aria-hidden="true" style="font-family: 'Cormorant Garamond', Georgia, serif; font-size: 20px; font-style: italic; color: #F2A93B">iv</span>
            <h3 style="margin: auto 0 0; font-family: 'Cormorant Garamond', Georgia, serif; font-weight: 600; font-size: 34px; line-height: 1.05; max-width: 70%">MetaMusic &amp; Consciousness</h3>
            <p style="margin: 0; font-size: 15px; color: #E9E1D6; max-width: 60%">The philosophy, culture and purpose of sound.</p>
          </article>
        
      </div>
    </section>

    <section id="journey" aria-labelledby="journey-h" style="max-width: 1360px; margin: 0 auto; padding: clamp(72px, 10.0vw, 144px) clamp(20px, 4vw, 40px) clamp(64px, 8.9vw, 128px); display: flex; flex-direction: column; gap: 56px">
      <div style="display: flex; flex-wrap: wrap; justify-content: space-between; align-items: flex-end; gap: 16px 64px">
        <div class="reveal" style="display: flex; flex-direction: column; gap: 14px">
          <p style="margin: 0; font-size: 13px; font-weight: 600; letter-spacing: .16em; text-transform: uppercase; color: #8A5208">The learning journey</p>
          <h2 id="journey-h" aria-label="Four levels, one continuous path" style="margin: 0; font-family: 'Cormorant Garamond', Georgia, serif; font-weight: 500; font-size: clamp(34px, 4.17vw, 60px); line-height: 1.05"><span class="mask" aria-hidden="true"><span class="sw" style="animation-range: entry 0% entry 60%">Four</span></span> <span class="mask" aria-hidden="true"><span class="sw" style="animation-range: entry 6% entry 66%">levels,</span></span> <span class="mask" aria-hidden="true"><span class="sw" style="animation-range: entry 12% entry 72%">one</span></span> <span class="mask" aria-hidden="true"><span class="sw" style="animation-range: entry 18% entry 78%">continuous</span></span> <span class="mask" aria-hidden="true"><span class="sw" style="animation-range: entry 24% entry 84%">path</span></span></h2>
        </div>
        <p class="read" style="margin: 0; font-size: 16px; color: #574B40; max-width: 400px">Equivalent to Trinity College London Grades 1–8. You don't pick a starting point — your assessment does.</p>
      </div>
      <ol class="stagger" style="list-style: none; margin: 0; padding: 0; display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 16px; counter-reset: lvl">
        
          <li class="lift lvl" style="position: relative; background: #FFFFFF; border: 1px solid #ECE5DA; border-radius: 20px; padding: 28px; display: flex; flex-direction: column; gap: 12px; min-height: 340px; box-sizing: border-box; overflow: hidden">
            <div style="display: flex; justify-content: space-between; align-items: center">
              <span style="font-size: 12px; font-weight: 600; letter-spacing: .1em; text-transform: uppercase; color: #8A5208">Level 1 of 4</span>
              <span style="font-size: 12px; font-weight: 600; color: #574B40; padding: 5px 10px; border-radius: 999px; background: #F6F1E9">Grades 1–2</span>
            </div>
            <h3 style="margin: 18px 0 0; font-family: 'Cormorant Garamond', Georgia, serif; font-weight: 600; font-size: 34px; line-height: 1.05">Pre-Certificate</h3>
            <p style="margin: 0; font-size: 15px; color: #574B40">Foundations: technique, posture, rhythm and first performances.</p>
            <p style="margin: auto 0 0; padding-top: 18px; border-top: 1px solid #F1EADF; display: flex; justify-content: space-between; align-items: baseline"><span style="font-size: 14px; color: #574B40">Tuition from</span><span style="font-family: 'Cormorant Garamond', Georgia, serif; font-size: 30px; font-weight: 600"><?= site_money('level0') ?><span style="font-family: Inter, sans-serif; font-size: 13px; font-weight: 400; color: #574B40"> /term</span></span></p>
          </li>
        
          <li class="lift lvl" style="position: relative; background: #FFFFFF; border: 1px solid #ECE5DA; border-radius: 20px; padding: 28px; display: flex; flex-direction: column; gap: 12px; min-height: 340px; box-sizing: border-box; overflow: hidden">
            <div style="display: flex; justify-content: space-between; align-items: center">
              <span style="font-size: 12px; font-weight: 600; letter-spacing: .1em; text-transform: uppercase; color: #8A5208">Level 2 of 4</span>
              <span style="font-size: 12px; font-weight: 600; color: #574B40; padding: 5px 10px; border-radius: 999px; background: #F6F1E9">Grades 3–4</span>
            </div>
            <h3 style="margin: 18px 0 0; font-family: 'Cormorant Garamond', Georgia, serif; font-weight: 600; font-size: 34px; line-height: 1.05">Certificate</h3>
            <p style="margin: 0; font-size: 15px; color: #574B40">Fluent playing, sight reading and stage presentation.</p>
            <p style="margin: auto 0 0; padding-top: 18px; border-top: 1px solid #F1EADF; display: flex; justify-content: space-between; align-items: baseline"><span style="font-size: 14px; color: #574B40">Tuition from</span><span style="font-family: 'Cormorant Garamond', Georgia, serif; font-size: 30px; font-weight: 600"><?= site_money('level1') ?><span style="font-family: Inter, sans-serif; font-size: 13px; font-weight: 400; color: #574B40"> /term</span></span></p>
          </li>
        
          <li class="lift lvl" style="position: relative; background: #FFFFFF; border: 1px solid #ECE5DA; border-radius: 20px; padding: 28px; display: flex; flex-direction: column; gap: 12px; min-height: 340px; box-sizing: border-box; overflow: hidden">
            <div style="display: flex; justify-content: space-between; align-items: center">
              <span style="font-size: 12px; font-weight: 600; letter-spacing: .1em; text-transform: uppercase; color: #8A5208">Level 3 of 4</span>
              <span style="font-size: 12px; font-weight: 600; color: #574B40; padding: 5px 10px; border-radius: 999px; background: #F6F1E9">Grades 5–6</span>
            </div>
            <h3 style="margin: 18px 0 0; font-family: 'Cormorant Garamond', Georgia, serif; font-weight: 600; font-size: 34px; line-height: 1.05">Pre-Diploma</h3>
            <p style="margin: 0; font-size: 15px; color: #574B40">Advanced repertoire, improvisation and ensemble leadership.</p>
            <p style="margin: auto 0 0; padding-top: 18px; border-top: 1px solid #F1EADF; display: flex; justify-content: space-between; align-items: baseline"><span style="font-size: 14px; color: #574B40">Tuition from</span><span style="font-family: 'Cormorant Garamond', Georgia, serif; font-size: 30px; font-weight: 600"><?= site_money('level2') ?><span style="font-family: Inter, sans-serif; font-size: 13px; font-weight: 400; color: #574B40"> /term</span></span></p>
          </li>
        
          <li class="lift lvl" style="position: relative; background: #FFFFFF; border: 1px solid #ECE5DA; border-radius: 20px; padding: 28px; display: flex; flex-direction: column; gap: 12px; min-height: 340px; box-sizing: border-box; overflow: hidden">
            <div style="display: flex; justify-content: space-between; align-items: center">
              <span style="font-size: 12px; font-weight: 600; letter-spacing: .1em; text-transform: uppercase; color: #8A5208">Level 4 of 4</span>
              <span style="font-size: 12px; font-weight: 600; color: #574B40; padding: 5px 10px; border-radius: 999px; background: #F6F1E9">Grades 7–8</span>
            </div>
            <h3 style="margin: 18px 0 0; font-family: 'Cormorant Garamond', Georgia, serif; font-weight: 600; font-size: 34px; line-height: 1.05">Diploma</h3>
            <p style="margin: 0; font-size: 15px; color: #574B40">Professional mastery, conducting and artistic identity.</p>
            <p style="margin: auto 0 0; padding-top: 18px; border-top: 1px solid #F1EADF; display: flex; justify-content: space-between; align-items: baseline"><span style="font-size: 14px; color: #574B40">Tuition from</span><span style="font-family: 'Cormorant Garamond', Georgia, serif; font-size: 30px; font-weight: 600"><?= site_money('level3') ?><span style="font-family: Inter, sans-serif; font-size: 13px; font-weight: 400; color: #574B40"> /term</span></span></p>
          </li>
        
      </ol>
      <div class="reveal" style="display: flex; flex-wrap: wrap; gap: 24px 48px; align-items: center; justify-content: space-between; padding: 28px clamp(20px, 4vw, 32px); border-radius: 20px; background: #2A1E14; color: #FCF8F3">
        <div style="flex: 1 1 360px; display: flex; flex-direction: column; gap: 6px">
          <h3 style="margin: 0; font-family: 'Cormorant Garamond', Georgia, serif; font-weight: 600; font-size: 30px">Placed by audition, not by guesswork</h3>
          <p style="margin: 0; font-size: 15px; color: #E9E1D6">A 30-minute MAM Auditer session measures ten dimensions of musical readiness.</p>
        </div>
        <a href="/auditer" class="btn arrow" style="display: inline-flex; align-items: center; gap: 8px; min-height: 48px; padding: 0 22px; border-radius: 10px; background: #F2A93B; color: #211A13; font-weight: 600; text-decoration: none">Book an assessment <span aria-hidden="true">→</span></a>
      </div>
    </section>

    <section id="proof" aria-labelledby="proof-h" style="max-width: 1360px; margin: 0 auto; padding: clamp(64px, 8.9vw, 128px) clamp(20px, 4vw, 40px); display: flex; flex-direction: column; gap: 56px">
      <div style="display: flex; flex-direction: column; align-items: center; gap: 14px; text-align: center">
        <p class="reveal" style="margin: 0; font-size: 13px; font-weight: 600; letter-spacing: .16em; text-transform: uppercase; color: #8A5208">Progress you can see</p>
        <h2 id="proof-h" aria-label="We measure what matters" style="margin: 0; font-family: 'Cormorant Garamond', Georgia, serif; font-weight: 500; font-size: clamp(34px, 4.17vw, 60px); line-height: 1.05"><span class="mask" aria-hidden="true"><span class="sw" style="animation-range: entry 0% entry 60%">We</span></span> <span class="mask" aria-hidden="true"><span class="sw" style="animation-range: entry 6% entry 66%">measure</span></span> <span class="mask" aria-hidden="true"><span class="sw" style="animation-range: entry 12% entry 72%">what</span></span> <span class="mask" aria-hidden="true"><span class="sw" style="animation-range: entry 18% entry 78%">matters</span></span></h2>
        <p class="read" style="margin: 0; font-size: 17px; color: #574B40; max-width: 600px">Every student is assessed on entry and reported on every term, so parents and students always know where they stand.</p>
      </div>
      <div style="display: flex; flex-wrap: wrap; gap: 16px">
        <figure class="reveal" style="flex: 1.15 1 520px; min-width: 0; margin: 0; background: #2A1E14; color: #FCF8F3; border-radius: 24px; padding: clamp(24px, 2.8vw, 40px); box-sizing: border-box; display: flex; flex-direction: column; gap: 28px">
          <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; flex-wrap: wrap">
            <div style="display: flex; flex-direction: column; gap: 4px">
              <h3 style="margin: 0; font-family: 'Cormorant Garamond', Georgia, serif; font-weight: 600; font-size: 32px; line-height: 1.1">MAM Auditer profile</h3>
              <p style="margin: 0; font-size: 14px; color: #D8CEC1">6 of the 10 dimensions measured at entry</p>
            </div>
            <span style="font-size: 12px; font-weight: 600; letter-spacing: .08em; text-transform: uppercase; padding: 6px 10px; border-radius: 999px; background: rgba(252,248,243,.12); color: #E9E1D6">Sample report</span>
          </div>
          <dl style="margin: 0; display: flex; flex-direction: column; gap: 18px">
            
              <div style="display: flex; flex-direction: column; gap: 8px">
                <div style="display: flex; justify-content: space-between; gap: 12px; font-size: 15px"><dt>Rhythm intelligence</dt><dd style="margin: 0; font-weight: 600; font-variant-numeric: tabular-nums"><span class="sr">82%</span><span aria-hidden="true" class="count" style="--to: 82"></span><span aria-hidden="true">%</span></dd></div>
                <div aria-hidden="true" style="height: 8px; border-radius: 8px; background: rgba(252,248,243,.12); overflow: hidden"><div class="bar" style="height: 100%; width: 82%; border-radius: 8px; background: linear-gradient(90deg, #C27A12, #F2A93B); transform-origin: left"></div></div>
              </div>
            
              <div style="display: flex; flex-direction: column; gap: 8px">
                <div style="display: flex; justify-content: space-between; gap: 12px; font-size: 15px"><dt>Ear sensitivity</dt><dd style="margin: 0; font-weight: 600; font-variant-numeric: tabular-nums"><span class="sr">74%</span><span aria-hidden="true" class="count" style="--to: 74"></span><span aria-hidden="true">%</span></dd></div>
                <div aria-hidden="true" style="height: 8px; border-radius: 8px; background: rgba(252,248,243,.12); overflow: hidden"><div class="bar" style="height: 100%; width: 74%; border-radius: 8px; background: linear-gradient(90deg, #C27A12, #F2A93B); transform-origin: left"></div></div>
              </div>
            
              <div style="display: flex; flex-direction: column; gap: 8px">
                <div style="display: flex; justify-content: space-between; gap: 12px; font-size: 15px"><dt>Tonal recognition</dt><dd style="margin: 0; font-weight: 600; font-variant-numeric: tabular-nums"><span class="sr">77%</span><span aria-hidden="true" class="count" style="--to: 77"></span><span aria-hidden="true">%</span></dd></div>
                <div aria-hidden="true" style="height: 8px; border-radius: 8px; background: rgba(252,248,243,.12); overflow: hidden"><div class="bar" style="height: 100%; width: 77%; border-radius: 8px; background: linear-gradient(90deg, #C27A12, #F2A93B); transform-origin: left"></div></div>
              </div>
            
              <div style="display: flex; flex-direction: column; gap: 8px">
                <div style="display: flex; justify-content: space-between; gap: 12px; font-size: 15px"><dt>Technical knowledge</dt><dd style="margin: 0; font-weight: 600; font-variant-numeric: tabular-nums"><span class="sr">68%</span><span aria-hidden="true" class="count" style="--to: 68"></span><span aria-hidden="true">%</span></dd></div>
                <div aria-hidden="true" style="height: 8px; border-radius: 8px; background: rgba(252,248,243,.12); overflow: hidden"><div class="bar" style="height: 100%; width: 68%; border-radius: 8px; background: linear-gradient(90deg, #C27A12, #F2A93B); transform-origin: left"></div></div>
              </div>
            
              <div style="display: flex; flex-direction: column; gap: 8px">
                <div style="display: flex; justify-content: space-between; gap: 12px; font-size: 15px"><dt>Performance confidence</dt><dd style="margin: 0; font-weight: 600; font-variant-numeric: tabular-nums"><span class="sr">61%</span><span aria-hidden="true" class="count" style="--to: 61"></span><span aria-hidden="true">%</span></dd></div>
                <div aria-hidden="true" style="height: 8px; border-radius: 8px; background: rgba(252,248,243,.12); overflow: hidden"><div class="bar" style="height: 100%; width: 61%; border-radius: 8px; background: linear-gradient(90deg, #C27A12, #F2A93B); transform-origin: left"></div></div>
              </div>
            
              <div style="display: flex; flex-direction: column; gap: 8px">
                <div style="display: flex; justify-content: space-between; gap: 12px; font-size: 15px"><dt>Learning capacity &amp; discipline</dt><dd style="margin: 0; font-weight: 600; font-variant-numeric: tabular-nums"><span class="sr">88%</span><span aria-hidden="true" class="count" style="--to: 88"></span><span aria-hidden="true">%</span></dd></div>
                <div aria-hidden="true" style="height: 8px; border-radius: 8px; background: rgba(252,248,243,.12); overflow: hidden"><div class="bar" style="height: 100%; width: 88%; border-radius: 8px; background: linear-gradient(90deg, #C27A12, #F2A93B); transform-origin: left"></div></div>
              </div>
            
          </dl>
          <figcaption style="font-size: 13px; color: #D8CEC1">Your report shapes your level, your instructor match and a term-by-term plan.</figcaption>
        </figure>
        <ul class="stagger" style="flex: 1 1 420px; min-width: 0; list-style: none; margin: 0; padding: 0; display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 16px">
          
            <li class="lift" style="background: #FFFFFF; border: 1px solid #ECE5DA; border-radius: 20px; padding: 28px; display: flex; flex-direction: column; gap: 12px">
              <span aria-hidden="true" style="width: 44px; height: 44px; border-radius: 12px; background: #F6F1E9; color: #8A5208; display: flex; align-items: center; justify-content: center"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3l8 4v5c0 4.5-3.4 8.3-8 9-4.6-.7-8-4.5-8-9V7l8-4z M9 12l2 2 4-4"></path></svg></span>
              <h3 style="margin: 0; font-family: 'Cormorant Garamond', Georgia, serif; font-weight: 600; font-size: 26px; line-height: 1.1">Trinity-equivalent standards</h3>
              <p style="margin: 0; font-size: 15px; color: #574B40">Four levels mapped to Trinity College London Grades 1–8, so progress means something outside MAM too.</p>
            </li>
          
            <li class="lift" style="background: #FFFFFF; border: 1px solid #ECE5DA; border-radius: 20px; padding: 28px; display: flex; flex-direction: column; gap: 12px">
              <span aria-hidden="true" style="width: 44px; height: 44px; border-radius: 12px; background: #F6F1E9; color: #8A5208; display: flex; align-items: center; justify-content: center"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M7 3h7l5 5v13H7z M14 3v5h5 M10 13h6 M10 17h6"></path></svg></span>
              <h3 style="margin: 0; font-family: 'Cormorant Garamond', Georgia, serif; font-weight: 600; font-size: 26px; line-height: 1.1">A written report every term</h3>
              <p style="margin: 0; font-size: 15px; color: #574B40">Strengths, gaps and next steps from your instructor, shared in the student and parent portals.</p>
            </li>
          
            <li class="lift" style="background: #FFFFFF; border: 1px solid #ECE5DA; border-radius: 20px; padding: 28px; display: flex; flex-direction: column; gap: 12px">
              <span aria-hidden="true" style="width: 44px; height: 44px; border-radius: 12px; background: #F6F1E9; color: #8A5208; display: flex; align-items: center; justify-content: center"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M9 18V5l11-2v13 M9 18a3 3 0 1 1-6 0 3 3 0 0 1 6 0z M20 16a3 3 0 1 1-6 0 3 3 0 0 1 6 0z"></path></svg></span>
              <h3 style="margin: 0; font-family: 'Cormorant Garamond', Georgia, serif; font-weight: 600; font-size: 26px; line-height: 1.1">Every student performs</h3>
              <p style="margin: 0; font-size: 15px; color: #574B40">Each term ends in a showcase. Stage experience is built into the training, not an extra.</p>
            </li>
          
            <li class="lift" style="background: #FFFFFF; border: 1px solid #ECE5DA; border-radius: 20px; padding: 28px; display: flex; flex-direction: column; gap: 12px">
              <span aria-hidden="true" style="width: 44px; height: 44px; border-radius: 12px; background: #F6F1E9; color: #8A5208; display: flex; align-items: center; justify-content: center"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 7v5l3 2 M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0z"></path></svg></span>
              <h3 style="margin: 0; font-family: 'Cormorant Garamond', Georgia, serif; font-weight: 600; font-size: 26px; line-height: 1.1">Flexible when life happens</h3>
              <p style="margin: 0; font-size: 15px; color: #574B40">Pause for up to four weeks a term and we hold your seat. Your fee always follows your placed level.</p>
            </li>
          
        </ul>
      </div>
    </section>

    <section id="life" aria-labelledby="life-h" style="max-width: 1360px; margin: 0 auto; padding: clamp(64px, 8.9vw, 128px) clamp(20px, 4vw, 40px) clamp(72px, 10vw, 144px); display: flex; flex-direction: column; gap: 48px">
      <div style="display: flex; flex-wrap: wrap; justify-content: space-between; align-items: flex-end; gap: 16px 48px">
        <div class="reveal" style="display: flex; flex-direction: column; gap: 14px">
          <p style="margin: 0; font-size: 13px; font-weight: 600; letter-spacing: .16em; text-transform: uppercase; color: #8A5208">Life at MAM</p>
          <h2 id="life-h" aria-label="On stage, all year" style="margin: 0; font-family: 'Cormorant Garamond', Georgia, serif; font-weight: 500; font-size: clamp(34px, 4.17vw, 60px); line-height: 1.05"><span class="mask" aria-hidden="true"><span class="sw" style="animation-range: entry 0% entry 60%">On</span></span> <span class="mask" aria-hidden="true"><span class="sw" style="animation-range: entry 6% entry 66%">stage,</span></span> <span class="mask" aria-hidden="true"><span class="sw" style="animation-range: entry 12% entry 72%">all</span></span> <span class="mask" aria-hidden="true"><span class="sw" style="animation-range: entry 18% entry 78%">year</span></span></h2>
        </div>
        <a href="/life" class="arrow ul" style="display: inline-flex; align-items: center; gap: 8px; min-height: 44px; font-weight: 600; color: #211A13; text-decoration: none">All events <span aria-hidden="true">→</span></a>
      </div>
      <div style="display: flex; flex-wrap: wrap; gap: 16px">
        <article class="zoom reveal" style="flex: 1.25 1 520px; min-width: 0; position: relative; isolation: isolate; overflow: hidden; border-radius: 24px; min-height: clamp(380px, 40vw, 520px); background: #2A1E14; color: #FCF8F3; display: flex; flex-direction: column; justify-content: flex-end; padding: clamp(24px, 2.8vw, 40px); box-sizing: border-box">
          <div data-photo="student showcase" aria-hidden="true" data-fx-img="" class="ph par" style="position: absolute; inset: -6% 0; z-index: -2; background: radial-gradient(80% 70% at 60% 30%, #5A4230, #2A1E14 75%); display: flex; align-items: flex-start; justify-content: flex-end; padding: 24px; font-size: 12px; color: rgba(252,248,243,.7)"></div>
          <div aria-hidden="true" style="position: absolute; inset: 0; z-index: -1; background: linear-gradient(0deg, rgba(23,16,9,.9) 0%, rgba(23,16,9,0) 60%)"></div>
          <span style="align-self: flex-start; font-size: 12px; font-weight: 600; letter-spacing: .08em; text-transform: uppercase; padding: 6px 12px; border-radius: 999px; background: rgba(252,248,243,.14); backdrop-filter: blur(8px)">Every term</span>
          <h3 style="margin: 16px 0 8px; font-family: 'Cormorant Garamond', Georgia, serif; font-weight: 600; font-size: clamp(30px, 3.06vw, 44px); line-height: 1.05">Student Showcase</h3>
          <p style="margin: 0; font-size: 16px; color: #E9E1D6; max-width: 460px">Every student performs at the end of term. Stage experience is part of the training — and families are warmly invited.</p>
        </article>
        <ul style="flex: 1 1 400px; min-width: 0; list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 16px">
          
            <li class="reveal lift" style="flex: 1; background: #FFFFFF; border: 1px solid #ECE5DA; border-radius: 20px; padding: 24px 28px; display: flex; gap: 20px; align-items: center">
              <div style="flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 4px">
                <p style="margin: 0; font-size: 12px; font-weight: 600; letter-spacing: .08em; text-transform: uppercase; color: #8A5208">First Wednesday of every month</p>
                <h3 style="margin: 0; font-family: 'Cormorant Garamond', Georgia, serif; font-weight: 600; font-size: 28px; line-height: 1.1">Power Play</h3>
                <p style="margin: 0; font-size: 14px; color: #574B40">A global broadcast with Just-Joe Adeola for musicians and creatives.</p>
              </div>
              <span style="flex: none; font-size: 13px; font-weight: 600; padding: 8px 12px; border-radius: 10px; background: #F6F1E9; color: #211A13">Free</span>
            </li>
          
            <li class="reveal lift" style="flex: 1; background: #FFFFFF; border: 1px solid #ECE5DA; border-radius: 20px; padding: 24px 28px; display: flex; gap: 20px; align-items: center">
              <div style="flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 4px">
                <p style="margin: 0; font-size: 12px; font-weight: 600; letter-spacing: .08em; text-transform: uppercase; color: #8A5208">12-week intensive</p>
                <h3 style="margin: 0; font-family: 'Cormorant Garamond', Georgia, serif; font-weight: 600; font-size: 28px; line-height: 1.1">The MetaMusic Order</h3>
                <p style="margin: 0; font-size: 14px; color: #574B40">From performer to architect: sound, culture and atmosphere.</p>
              </div>
              <span style="flex: none; font-size: 13px; font-weight: 600; padding: 8px 12px; border-radius: 10px; background: #F6F1E9; color: #211A13"><?= site_money('meta') ?></span>
            </li>
          
            <li class="reveal lift" style="flex: 1; background: #FFFFFF; border: 1px solid #ECE5DA; border-radius: 20px; padding: 24px 28px; display: flex; gap: 20px; align-items: center">
              <div style="flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 4px">
                <p style="margin: 0; font-size: 12px; font-weight: 600; letter-spacing: .08em; text-transform: uppercase; color: #8A5208">6 weeks · any discipline</p>
                <h3 style="margin: 0; font-family: 'Cormorant Garamond', Georgia, serif; font-weight: 600; font-size: 28px; line-height: 1.1">Crash Programme</h3>
                <p style="margin: 0; font-size: 14px; color: #574B40">A focused intensive, online or in person.</p>
              </div>
              <span style="flex: none; font-size: 13px; font-weight: 600; padding: 8px 12px; border-radius: 10px; background: #F6F1E9; color: #211A13"><?= site_money('crash') ?></span>
            </li>
          
        </ul>
      </div>
    </section>

    <section id="admissions" aria-labelledby="adm-h" style="background: #2A1E14; color: #FCF8F3">
      <div style="max-width: 1360px; margin: 0 auto; padding: clamp(72px, 10.0vw, 144px) clamp(20px, 4vw, 32px); display: flex; flex-wrap: wrap; gap: 48px 96px; align-items: flex-start">
        <div class="stick" style="flex: 1 1 380px; min-width: 0; position: sticky; top: 112px; display: flex; flex-direction: column; gap: 20px">
          <p style="margin: 0; font-size: 13px; font-weight: 600; letter-spacing: .16em; text-transform: uppercase; color: #F2A93B">Admissions</p>
          <h2 aria-label="From first enquiry to first lesson in about a week" id="adm-h" style="margin: 0; font-family: 'Cormorant Garamond', Georgia, serif; font-weight: 500; font-size: clamp(36px, 4.44vw, 64px); line-height: 1.02"><span class="mask" aria-hidden="true"><span class="sw" style="animation-range: entry 0% entry 60%">From</span></span> <span class="mask" aria-hidden="true"><span class="sw" style="animation-range: entry 6% entry 66%">first</span></span> <span class="mask" aria-hidden="true"><span class="sw" style="animation-range: entry 12% entry 72%">enquiry</span></span> <span class="mask" aria-hidden="true"><span class="sw" style="animation-range: entry 18% entry 78%">to</span></span> <span class="mask" aria-hidden="true"><span class="sw" style="animation-range: entry 24% entry 84%">first</span></span> <span class="mask" aria-hidden="true"><span class="sw" style="animation-range: entry 30% entry 90%">lesson</span></span> <span class="mask" aria-hidden="true"><span class="sw" style="animation-range: entry 36% entry 96%">in</span></span> <span class="mask" aria-hidden="true"><span class="sw" style="animation-range: entry 42% entry 100%">about</span></span> <span class="mask" aria-hidden="true"><span class="sw" style="animation-range: entry 48% entry 100%">a</span></span> <span class="mask" aria-hidden="true"><span class="sw" style="animation-range: entry 54% entry 100%">week</span></span></h2>
          <p style="margin: 0; font-size: 17px; color: #D8CEC1"><?= site_h(site_intake()) ?> intake now open. Applications are reviewed weekly.</p>
          <div style="display: flex; gap: 10px; flex-wrap: wrap; margin-top: 8px">
            <a href="/apply" class="arrow" style="display: inline-flex; align-items: center; gap: 8px; min-height: 48px; padding: 0 24px; border-radius: 999px; background: #F2A93B; color: #211A13; font-weight: 600; text-decoration: none">Apply now <span aria-hidden="true">→</span></a>
            <a href="/programmes#fees" style="display: inline-flex; align-items: center; min-height: 48px; padding: 0 23px; border-radius: 999px; border: 1px solid rgba(252,248,243,.45); color: #FCF8F3; font-weight: 600; text-decoration: none">Fee estimator</a>
          </div>
        </div>
        <ol style="flex: 1.1 1 480px; min-width: 0; list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 16px">
          
            <li class="reveal" style="background: #352719; border-radius: 20px; padding: 32px; display: flex; gap: 24px; align-items: flex-start">
              <span aria-hidden="true" style="flex: none; width: 52px; height: 52px; border-radius: 50%; border: 1px solid rgba(242,169,59,.6); color: #F2A93B; display: flex; align-items: center; justify-content: center; font-family: 'Cormorant Garamond', Georgia, serif; font-size: 24px">1</span>
              <div style="display: flex; flex-direction: column; gap: 6px; min-width: 0">
                <p style="margin: 0; font-size: 12px; font-weight: 600; letter-spacing: .12em; text-transform: uppercase; color: #D8CEC1">Today · 5 minutes</p>
                <h3 style="margin: 0; font-family: 'Cormorant Garamond', Georgia, serif; font-weight: 600; font-size: 30px; line-height: 1.1">Apply online</h3>
                <p style="margin: 0; font-size: 15px; color: #E9E1D6">Tell us about the learner, choose an instrument and online or in person. Registration is <?= site_money('reg') ?>.</p>
              </div>
            </li>
          
            <li class="reveal" style="background: #352719; border-radius: 20px; padding: 32px; display: flex; gap: 24px; align-items: flex-start">
              <span aria-hidden="true" style="flex: none; width: 52px; height: 52px; border-radius: 50%; border: 1px solid rgba(242,169,59,.6); color: #F2A93B; display: flex; align-items: center; justify-content: center; font-family: 'Cormorant Garamond', Georgia, serif; font-size: 24px">2</span>
              <div style="display: flex; flex-direction: column; gap: 6px; min-width: 0">
                <p style="margin: 0; font-size: 12px; font-weight: 600; letter-spacing: .12em; text-transform: uppercase; color: #D8CEC1">Within a few days</p>
                <h3 style="margin: 0; font-family: 'Cormorant Garamond', Georgia, serif; font-weight: 600; font-size: 30px; line-height: 1.1">MAM Auditer assessment</h3>
                <p style="margin: 0; font-size: 15px; color: #E9E1D6">A 30-minute session on playing, ear and rhythm places you at the right level.</p>
              </div>
            </li>
          
            <li class="reveal" style="background: #352719; border-radius: 20px; padding: 32px; display: flex; gap: 24px; align-items: flex-start">
              <span aria-hidden="true" style="flex: none; width: 52px; height: 52px; border-radius: 50%; border: 1px solid rgba(242,169,59,.6); color: #F2A93B; display: flex; align-items: center; justify-content: center; font-family: 'Cormorant Garamond', Georgia, serif; font-size: 24px">3</span>
              <div style="display: flex; flex-direction: column; gap: 6px; min-width: 0">
                <p style="margin: 0; font-size: 12px; font-weight: 600; letter-spacing: .12em; text-transform: uppercase; color: #D8CEC1">Same week</p>
                <h3 style="margin: 0; font-family: 'Cormorant Garamond', Georgia, serif; font-weight: 600; font-size: 30px; line-height: 1.1">Your plan and fee</h3>
                <p style="margin: 0; font-size: 15px; color: #E9E1D6">Your level, matched instructor, timetable and exact fee. Pay in USD, NGN or GBP.</p>
              </div>
            </li>
          
            <li class="reveal" style="background: #352719; border-radius: 20px; padding: 32px; display: flex; gap: 24px; align-items: flex-start">
              <span aria-hidden="true" style="flex: none; width: 52px; height: 52px; border-radius: 50%; border: 1px solid rgba(242,169,59,.6); color: #F2A93B; display: flex; align-items: center; justify-content: center; font-family: 'Cormorant Garamond', Georgia, serif; font-size: 24px">4</span>
              <div style="display: flex; flex-direction: column; gap: 6px; min-width: 0">
                <p style="margin: 0; font-size: 12px; font-weight: 600; letter-spacing: .12em; text-transform: uppercase; color: #D8CEC1">Term start</p>
                <h3 style="margin: 0; font-family: 'Cormorant Garamond', Georgia, serif; font-weight: 600; font-size: 30px; line-height: 1.1">First lesson</h3>
                <p style="margin: 0; font-size: 15px; color: #E9E1D6">Weekly lessons begin, with progress tracked in the student and parent portals.</p>
              </div>
            </li>
          
          <li class="reveal" style="background: #352719; border-radius: 20px; padding: 32px; display: flex; flex-direction: column; gap: 18px">
            <div style="display: flex; justify-content: space-between; align-items: baseline; flex-wrap: wrap; gap: 8px"><h3 style="margin: 0; font-family: 'Cormorant Garamond', Georgia, serif; font-weight: 600; font-size: 30px">Tuition per term</h3><span style="font-size: 13px; color: #D8CEC1">Onsite · online adds <?= site_fee('online') ?></span></div>
            <dl style="margin: 0; display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 12px">
              <div style="padding: 18px; border-radius: 14px; background: #2A1E14; display: flex; flex-direction: column; gap: 4px"><dt style="font-size: 14px; color: #E9E1D6">Standard</dt><dd style="margin: 0; font-family: 'Cormorant Garamond', Georgia, serif; font-size: 34px; font-weight: 600; line-height: 1">from <?= site_money('from:standard') ?></dd><dd style="margin: 0; font-size: 13px; color: #D8CEC1">One course</dd></div>
              <div style="padding: 18px; border-radius: 14px; background: #2A1E14; box-shadow: inset 0 0 0 1px #F2A93B; display: flex; flex-direction: column; gap: 4px"><dt style="font-size: 14px; color: #F2A93B">Certified Musician</dt><dd style="margin: 0; font-family: 'Cormorant Garamond', Georgia, serif; font-size: 34px; font-weight: 600; line-height: 1">from <?= site_money('from:certified-musician') ?></dd><dd style="margin: 0; font-size: 13px; color: #D8CEC1">Major + 4 core courses</dd></div>
              <div style="padding: 18px; border-radius: 14px; background: #2A1E14; display: flex; flex-direction: column; gap: 4px"><dt style="font-size: 14px; color: #E9E1D6">Grand Musician</dt><dd style="margin: 0; font-family: 'Cormorant Garamond', Georgia, serif; font-size: 34px; font-weight: 600; line-height: 1">from <?= site_money('from:grand-musician') ?></dd><dd style="margin: 0; font-size: 13px; color: #D8CEC1">All 3 expressions</dd></div>
            </dl>
          </li>
          <li class="reveal" style="background: #F2A93B; color: #211A13; border-radius: 20px; padding: 32px; display: flex; flex-wrap: wrap; gap: 16px 32px; justify-content: space-between; align-items: center">
            <div style="display: flex; flex-direction: column; gap: 4px; flex: 1 1 260px"><h3 style="margin: 0; font-family: 'Cormorant Garamond', Georgia, serif; font-weight: 600; font-size: 30px">Fees in the way?</h3><p style="margin: 0; font-size: 15px">The NGV Scholarship supports talented students who can't afford tuition.</p></div>
            <a href="/scholarship" style="display: inline-flex; align-items: center; min-height: 44px; padding: 0 20px; border-radius: 999px; background: #211A13; color: #FCF8F3; font-weight: 600; text-decoration: none">Apply for a scholarship</a>
          </li>
        </ol>
      </div>
    </section>

    <section id="questions" aria-labelledby="faq-h" style="max-width: 860px; margin: 0 auto; padding: clamp(72px, 10.0vw, 144px) clamp(20px, 4vw, 40px) 64px; display: flex; flex-direction: column; gap: 40px">
      <h2 id="faq-h" style="margin: 0; text-align: center; font-family: 'Cormorant Garamond', Georgia, serif; font-weight: 500; font-size: clamp(34px, 4.17vw, 60px); line-height: 1.05">Questions</h2>
      <div style="border-top: 1px solid #E3DACD">
        
          <div style="border-bottom: 1px solid #E3DACD">
            <h3 style="margin: 0">
              <button type="button" style="width: 100%; min-height: 68px; display: flex; justify-content: space-between; align-items: center; gap: 24px; background: none; border: 0; padding: 20px 0; font-family: 'Cormorant Garamond', Georgia, serif; font-size: 25px; font-weight: 600; color: #211A13; text-align: left; cursor: pointer" id="q0" aria-controls="a0" aria-expanded="true" data-act="faq" data-i="0">
                <span>Do I need to know my level before applying?</span><span aria-hidden="true" style="flex: none; font-family: Inter, sans-serif; font-size: 24px; font-weight: 300; color: #8A5208; transition: transform .5s cubic-bezier(.19,1,.22,1); transform: rotate(45deg)">+</span>
              </button>
            </h3>
            <div role="region" style="display: grid; transition: grid-template-rows .4s cubic-bezier(.165,.84,.44,1), opacity .4s cubic-bezier(.165,.84,.44,1); grid-template-rows: 1fr; opacity: 1" id="a0" aria-labelledby="q0"><div style="overflow: hidden"><p style="margin: 0; padding: 0 48px 24px 0; font-size: 16px; color: #574B40">No. Every applicant takes a short MAM Auditer assessment, which places you at the right level. Your fee follows that level.</p></div></div>
          </div>
        
          <div style="border-bottom: 1px solid #E3DACD">
            <h3 style="margin: 0">
              <button type="button" style="width: 100%; min-height: 68px; display: flex; justify-content: space-between; align-items: center; gap: 24px; background: none; border: 0; padding: 20px 0; font-family: 'Cormorant Garamond', Georgia, serif; font-size: 25px; font-weight: 600; color: #211A13; text-align: left; cursor: pointer" id="q1" aria-controls="a1" aria-expanded="false" data-act="faq" data-i="1">
                <span>Can my child start as a complete beginner?</span><span aria-hidden="true" style="flex: none; font-family: Inter, sans-serif; font-size: 24px; font-weight: 300; color: #8A5208; transition: transform .5s cubic-bezier(.19,1,.22,1); transform: rotate(0deg)">+</span>
              </button>
            </h3>
            <div role="region" style="display: grid; transition: grid-template-rows .4s cubic-bezier(.165,.84,.44,1), opacity .4s cubic-bezier(.165,.84,.44,1); grid-template-rows: 0fr; opacity: 0" id="a1" aria-labelledby="q1"><div style="overflow: hidden"><p style="margin: 0; padding: 0 48px 24px 0; font-size: 16px; color: #574B40">Yes. Students can start from age 4 with no experience. Instruments are provided during in-person classes for beginners.</p></div></div>
          </div>
        
          <div style="border-bottom: 1px solid #E3DACD">
            <h3 style="margin: 0">
              <button type="button" style="width: 100%; min-height: 68px; display: flex; justify-content: space-between; align-items: center; gap: 24px; background: none; border: 0; padding: 20px 0; font-family: 'Cormorant Garamond', Georgia, serif; font-size: 25px; font-weight: 600; color: #211A13; text-align: left; cursor: pointer" id="q2" aria-controls="a2" aria-expanded="false" data-act="faq" data-i="2">
                <span>How do online lessons work?</span><span aria-hidden="true" style="flex: none; font-family: Inter, sans-serif; font-size: 24px; font-weight: 300; color: #8A5208; transition: transform .5s cubic-bezier(.19,1,.22,1); transform: rotate(0deg)">+</span>
              </button>
            </h3>
            <div role="region" style="display: grid; transition: grid-template-rows .4s cubic-bezier(.165,.84,.44,1), opacity .4s cubic-bezier(.165,.84,.44,1); grid-template-rows: 0fr; opacity: 0" id="a2" aria-labelledby="q2"><div style="overflow: hidden"><p style="margin: 0; padding: 0 48px 24px 0; font-size: 16px; color: #574B40">Live, one-to-one video lessons with your instructor, plus performance reviews and virtual rehearsals. Online fees are <?= site_fee('online') ?> higher than in-person fees.</p></div></div>
          </div>
        
          <div style="border-bottom: 1px solid #E3DACD">
            <h3 style="margin: 0">
              <button type="button" style="width: 100%; min-height: 68px; display: flex; justify-content: space-between; align-items: center; gap: 24px; background: none; border: 0; padding: 20px 0; font-family: 'Cormorant Garamond', Georgia, serif; font-size: 25px; font-weight: 600; color: #211A13; text-align: left; cursor: pointer" id="q3" aria-controls="a3" aria-expanded="false" data-act="faq" data-i="3">
                <span>How are the levels recognised?</span><span aria-hidden="true" style="flex: none; font-family: Inter, sans-serif; font-size: 24px; font-weight: 300; color: #8A5208; transition: transform .5s cubic-bezier(.19,1,.22,1); transform: rotate(0deg)">+</span>
              </button>
            </h3>
            <div role="region" style="display: grid; transition: grid-template-rows .4s cubic-bezier(.165,.84,.44,1), opacity .4s cubic-bezier(.165,.84,.44,1); grid-template-rows: 0fr; opacity: 0" id="a3" aria-labelledby="q3"><div style="overflow: hidden"><p style="margin: 0; padding: 0 48px 24px 0; font-size: 16px; color: #574B40">The four levels are equivalent to Trinity College London Grades 1–8: Pre-Certificate (1–2), Certificate (3–4), Pre-Diploma (5–6) and Diploma (7–8).</p></div></div>
          </div>
        
          <div style="border-bottom: 1px solid #E3DACD">
            <h3 style="margin: 0">
              <button type="button" style="width: 100%; min-height: 68px; display: flex; justify-content: space-between; align-items: center; gap: 24px; background: none; border: 0; padding: 20px 0; font-family: 'Cormorant Garamond', Georgia, serif; font-size: 25px; font-weight: 600; color: #211A13; text-align: left; cursor: pointer" id="q4" aria-controls="a4" aria-expanded="false" data-act="faq" data-i="4">
                <span>How do I pay?</span><span aria-hidden="true" style="flex: none; font-family: Inter, sans-serif; font-size: 24px; font-weight: 300; color: #8A5208; transition: transform .5s cubic-bezier(.19,1,.22,1); transform: rotate(0deg)">+</span>
              </button>
            </h3>
            <div role="region" style="display: grid; transition: grid-template-rows .4s cubic-bezier(.165,.84,.44,1), opacity .4s cubic-bezier(.165,.84,.44,1); grid-template-rows: 0fr; opacity: 0" id="a4" aria-labelledby="q4"><div style="overflow: hidden"><p style="margin: 0; padding: 0 48px 24px 0; font-size: 16px; color: #574B40">Securely online with Paystack, Flutterwave or Stripe in USD, NGN or GBP, or by bank transfer.</p></div></div>
          </div>
        
      </div>
    </section>

    <section id="apply" aria-labelledby="apply-h" style="position: relative; isolation: isolate; overflow: hidden; background: #FAF8F4; color: #211A13">
      <div aria-hidden="true" style="position: absolute; inset: 0; z-index: -1; background: radial-gradient(50% 80% at 50% 0%, rgba(242,169,59,.30), rgba(242,169,59,0) 70%)"></div>
      <div class="reveal" style="max-width: 980px; margin: 0 auto; padding: clamp(80px, 11vw, 160px) clamp(20px, 4vw, 32px); display: flex; flex-direction: column; align-items: center; gap: 28px; text-align: center">
        <h2 id="apply-h" style="margin: 0; font-family: 'Cormorant Garamond', Georgia, serif; font-weight: 500; font-size: clamp(54px, 6.67vw, 96px); line-height: .98; letter-spacing: -.015em">Your seat is <em style="color: #8A5208">waiting.</em></h2>
        <p style="margin: 0; font-size: 18px; color: #574B40; max-width: 520px">Apply in about five minutes. Places each intake are limited.</p>
        <div style="display: flex; gap: 12px; flex-wrap: wrap; justify-content: center">
          <a href="/apply" class="arrow" style="display: inline-flex; align-items: center; gap: 8px; min-height: 56px; padding: 0 clamp(20px, 4vw, 32px); border-radius: 999px; background: #F2A93B; color: #211A13; font-size: 16px; font-weight: 600; text-decoration: none">Start your application <span aria-hidden="true">→</span></a>
          <a href="/contact" style="display: inline-flex; align-items: center; min-height: 56px; padding: 0 31px; border-radius: 999px; border: 1px solid #211A13; color: #211A13; font-size: 16px; font-weight: 600; text-decoration: none">Talk to admissions</a>
        </div>
      </div>
    </section>
    
    <section id="resonance" aria-label="The shape of sound — an interactive waveform" style="padding: 0 clamp(12px, 2vw, 24px) clamp(12px, 2vw, 24px)">
      <div style="position: relative; isolation: isolate; overflow: hidden; border-radius: clamp(14px, 1.4vw, 20px); border: 1px solid rgba(252,248,243,.10); background: radial-gradient(75% 95% at 50% 100%, #6A3F17 0%, #3A2410 38%, #1C120A 70%, #130C07 100%); height: clamp(420px, 44vw, 620px); touch-action: pan-y">
        <canvas data-field aria-hidden="true" style="position: absolute; inset: 0; width: 100%; height: 100%; cursor: crosshair"></canvas>
        <div style="position: absolute; right: clamp(16px, 2vw, 28px); top: clamp(16px, 2vw, 28px); display: flex; gap: 8px; z-index: 2">
          <button type="button" class="btn" style="width: 32px; height: 32px; display: inline-flex; align-items: center; justify-content: center; border-radius: 7px; cursor: pointer; backdrop-filter: blur(8px); -webkit-backdrop-filter: blur(8px); background: rgba(252,248,243,.06); color: #FCF8F3; border: 1px solid rgba(252,248,243,.28)" aria-pressed="false" aria-label="Sing to it: uses your microphone on this device only, nothing is recorded or sent" title="Sing to it: uses your microphone on this device only, nothing is recorded or sent" data-act="mic"><svg aria-hidden="true" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="3" width="6" height="11" rx="3"></rect><path d="M5 11a7 7 0 0 0 14 0M12 18v3"></path></svg></button>
          <button type="button" class="btn" style="width: 32px; height: 32px; display: inline-flex; align-items: center; justify-content: center; border-radius: 7px; cursor: pointer; backdrop-filter: blur(8px); -webkit-backdrop-filter: blur(8px); background: rgba(252,248,243,.06); color: #FCF8F3; border: 1px solid rgba(252,248,243,.28)" aria-pressed="false" aria-label="Turn sound on" data-act="sound"><svg aria-hidden="true" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M11 5L6 9H3v6h3l5 4z"></path><path d="M16 9l5 6M21 9l-5 6"></path></svg></button>
        </div>
        <div data-pitch hidden aria-live="polite" style="position: absolute; left: 0; right: 0; top: 16%; display: flex; flex-direction: column; align-items: center; gap: 6px; color: #FCF8F3; pointer-events: none; text-align: center">
          <p style="margin: 0; font-family: 'Cormorant Garamond', Georgia, serif; font-weight: 500; font-size: clamp(64px, 8vw, 112px); line-height: 1; letter-spacing: -.01em"><span data-pitch-note>Hum</span><sup data-pitch-oct style="font-size: .32em; vertical-align: super; color: #F2A93B; margin-left: .06em"></sup></p>
          <p data-pitch-detail style="margin: 0; font-size: 14px; letter-spacing: .04em; color: #D8CEC1">or sing any note — we’ll show you which one</p>
        </div>
      </div>
    </section>

  </main>

<?php
echo site_photos((string) ob_get_clean());
site_footer(['site-home.js']);
