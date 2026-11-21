from shared import *
seg = "'min-height: 52px; display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 6px 8px; border: 0; border-radius: 9px; font: inherit; font-size: 15px; cursor: pointer; transition: background .2s, box-shadow .2s; ' + (on ? 'background: #FFFFFF; color: #211A13; font-weight: 600; box-shadow: 0 1px 3px rgba(33,26,19,.18)' : 'background: transparent; color: #574B40')"
body = page_hero('Programmes & fees', 'Programmes', 'Find your path — and know your fee.', 'Find your path, and know your fee',
  'Every programme runs across four certification levels, online or in person. Your level is set by your MAM Auditer assessment, and your fee follows it.',
  '''      <div class="fade-in" style="animation-delay: .4s; display: flex; gap: 10px; flex-wrap: wrap; margin-top: 8px">
        <a href="#fees" class="btn arrow" style="display: inline-flex; align-items: center; gap: 8px; min-height: 48px; padding: 0 22px; border-radius: 10px; background: #211A13; color: #FAF8F4; font-weight: 600; text-decoration: none">Estimate my fee <span aria-hidden="true">→</span></a>
        <a href="Auditer.dc.html" class="btn" style="display: inline-flex; align-items: center; min-height: 48px; padding: 0 21px; border-radius: 10px; border: 1px solid #D6CCBE; color: #211A13; font-weight: 600; text-decoration: none">Book an assessment first</a>
      </div>
''') + f'''
    <!-- three ways to study -->
    <section id="programmes" aria-labelledby="prog-h" style="max-width: 1360px; margin: 0 auto; padding: 0 clamp(20px, 4vw, 40px) clamp(64px, 8.9vw, 128px); display: flex; flex-direction: column; gap: 40px">
      <div style="display: flex; flex-direction: column; gap: 14px">{eyebrow('Three ways to study')}{h2('prog-h', 'Choose how much you want to learn')}</div>
      <ul class="stagger" style="list-style: none; margin: 0; padding: 0; display: grid; grid-template-columns: repeat(auto-fit, minmax(290px, 1fr)); gap: 16px">
        <sc-for list="{{{{ plans }}}}" as="p" hint-placeholder-count="3">
          <li class="lift" style="{{{{ p.cardStyle }}}}">
            <div style="display: flex; justify-content: space-between; align-items: center; min-height: 28px"><span style="font-size: 12px; font-weight: 600; letter-spacing: .1em; text-transform: uppercase; color: #8A5208">{{{{ p.kicker }}}}</span><sc-if value="{{{{ p.popular }}}}" hint-placeholder-val="{{{{ false }}}}"><span style="font-size: 12px; font-weight: 600; padding: 5px 10px; border-radius: 999px; background: #211A13; color: #FAF8F4">Most popular</span></sc-if></div>
            <h3 style="margin: 0; font-family: 'Cormorant Garamond', Georgia, serif; font-weight: 600; font-size: 38px; line-height: 1.05">{{{{ p.name }}}}</h3>
            <p style="margin: 0; font-size: 15px; color: #574B40">{{{{ p.desc }}}}</p>
            <p style="margin: 8px 0 0; display: flex; align-items: baseline; gap: 8px"><span style="font-size: 14px; color: #574B40">from</span><span style="font-family: 'Cormorant Garamond', Georgia, serif; font-size: 48px; font-weight: 600; line-height: 1">{{{{ p.price }}}}</span><span style="font-size: 14px; color: #574B40">per term</span></p>
            <ul style="margin: 8px 0 0; padding: 18px 0 0; border-top: 1px solid #F1EADF; list-style: none; display: flex; flex-direction: column; gap: 10px; font-size: 15px">
              <sc-for list="{{{{ p.items }}}}" as="it" hint-placeholder-count="4"><li style="display: flex; gap: 10px; align-items: flex-start"><svg aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#8A5208" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" style="flex: none; margin-top: 3px"><path d="M5 12l5 5L20 7"></path></svg>{{{{ it }}}}</li></sc-for>
            </ul>
            <button type="button" onClick="{{{{ p.pick }}}}" class="btn arrow" style="margin-top: auto; align-self: flex-start; display: inline-flex; align-items: center; gap: 8px; min-height: 44px; padding: 0 18px; border-radius: 10px; border: 1px solid #D6CCBE; background: transparent; font: inherit; font-size: 15px; font-weight: 600; color: #211A13; cursor: pointer">Estimate {{{{ p.name }}}} <span aria-hidden="true">→</span></button>
          </li>
        </sc-for>
      </ul>
    </section>

    <!-- four levels -->
    <section id="levels" aria-labelledby="lev-h" style="background: #F3EEE6">
      <div style="max-width: 1360px; margin: 0 auto; padding: clamp(64px, 8.9vw, 128px) clamp(20px, 4vw, 40px); display: flex; flex-direction: column; gap: 40px">
        <div style="display: flex; flex-wrap: wrap; justify-content: space-between; align-items: flex-end; gap: 16px 64px">
          <div style="display: flex; flex-direction: column; gap: 14px">{eyebrow('Four levels')}{h2('lev-h', 'One continuous journey')}</div>
          <p class="read" style="margin: 0; font-size: 16px; color: #574B40; max-width: 420px">You don't buy a level — you progress through them. Each maps to Trinity College London grades and sets your base fee.</p>
        </div>
        <ol class="stagger" style="list-style: none; margin: 0; padding: 0; display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 16px">
          <sc-for list="{{{{ levels }}}}" as="l" hint-placeholder-count="4">
            <li class="lift lvl" style="background: #FFFFFF; border: 1px solid #ECE5DA; border-radius: 20px; padding: 28px; display: flex; flex-direction: column; gap: 12px; min-height: 280px; box-sizing: border-box">
              <div style="display: flex; justify-content: space-between; align-items: center"><span style="font-size: 12px; font-weight: 600; letter-spacing: .1em; text-transform: uppercase; color: #8A5208">Level {{{{ l.n }}}} of 4</span><span style="font-size: 12px; font-weight: 600; color: #574B40; padding: 5px 10px; border-radius: 999px; background: #F6F1E9">{{{{ l.grades }}}}</span></div>
              <h3 style="margin: 10px 0 0; font-family: 'Cormorant Garamond', Georgia, serif; font-weight: 600; font-size: 32px; line-height: 1.05">{{{{ l.name }}}}</h3>
              <p style="margin: 0; font-size: 15px; color: #574B40">{{{{ l.desc }}}}</p>
              <p style="margin: auto 0 0; padding-top: 16px; border-top: 1px solid #F1EADF; display: flex; justify-content: space-between; align-items: baseline"><span style="font-size: 14px; color: #574B40">Base fee</span><span style="font-family: 'Cormorant Garamond', Georgia, serif; font-size: 30px; font-weight: 600">${{{{ l.base }}}}<span style="font-family: Inter, sans-serif; font-size: 13px; font-weight: 400; color: #574B40"> /term</span></span></p>
            </li>
          </sc-for>
        </ol>
      </div>
    </section>

    <!-- fee estimator -->
    <section id="fees" aria-labelledby="fees-h" style="max-width: 1360px; margin: 0 auto; padding: clamp(64px, 8.9vw, 128px) clamp(20px, 4vw, 40px); display: flex; flex-direction: column; gap: 40px">
      <div style="display: flex; flex-direction: column; gap: 14px; align-items: center; text-align: center">{eyebrow('Fee estimator')}{h2('fees-h', 'Build it, see the price', center=True)}<p class="read" style="margin: 0; font-size: 17px; color: #574B40; max-width: 560px">Pick your options and the estimate updates as you go. The exact figure is confirmed after your assessment.</p></div>
      <div style="display: flex; flex-wrap: wrap; gap: 16px; align-items: stretch">
        <form aria-label="Fee estimator" style="flex: 1.4 1 520px; min-width: 0; background: #FFFFFF; border: 1px solid #ECE5DA; border-radius: 24px; padding: clamp(24px, 3vw, 40px); display: flex; flex-direction: column; gap: 28px">
          <fieldset style="border: 0; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 10px"><legend style="padding: 0 0 10px; font-weight: 600">Programme</legend>
            <div role="radiogroup" aria-label="Programme" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(130px, 1fr)); gap: 4px; padding: 4px; background: #F3EEE6; border-radius: 12px">
              <sc-for list="{{{{ progOpts }}}}" as="o" hint-placeholder-count="4"><button type="button" role="radio" aria-checked="{{{{ o.checked }}}}" onClick="{{{{ o.pick }}}}" style="{{{{ o.style }}}}">{{{{ o.label }}}}<span style="font-size: 12px; font-weight: 400; color: #574B40">{{{{ o.sub }}}}</span></button></sc-for>
            </div>
          </fieldset>
          <sc-if value="{{{{ showLevel }}}}" hint-placeholder-val="{{{{ true }}}}">
            <fieldset style="border: 0; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 10px"><legend style="padding: 0 0 10px; font-weight: 600">Level <span style="font-weight: 400; color: #574B40">(your assessment confirms it)</span></legend>
              <div role="radiogroup" aria-label="Level" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(110px, 1fr)); gap: 4px; padding: 4px; background: #F3EEE6; border-radius: 12px">
                <sc-for list="{{{{ levelOpts }}}}" as="o" hint-placeholder-count="4"><button type="button" role="radio" aria-checked="{{{{ o.checked }}}}" onClick="{{{{ o.pick }}}}" style="{{{{ o.style }}}}">{{{{ o.label }}}}<span style="font-size: 12px; font-weight: 400; color: #574B40">{{{{ o.sub }}}}</span></button></sc-for>
              </div>
            </fieldset>
            <fieldset style="border: 0; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 10px"><legend style="padding: 0 0 10px; font-weight: 600">How you'll learn</legend>
              <div role="radiogroup" aria-label="Mode" style="display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 4px; padding: 4px; background: #F3EEE6; border-radius: 12px">
                <sc-for list="{{{{ modeOpts }}}}" as="o" hint-placeholder-count="2"><button type="button" role="radio" aria-checked="{{{{ o.checked }}}}" onClick="{{{{ o.pick }}}}" style="{{{{ o.style }}}}">{{{{ o.label }}}}<span style="font-size: 12px; font-weight: 400; color: #574B40">{{{{ o.sub }}}}</span></button></sc-for>
              </div>
            </fieldset>
          </sc-if>
          <sc-if value="{{{{ showExtras }}}}" hint-placeholder-val="{{{{ true }}}}">
            <div style="display: flex; justify-content: space-between; align-items: center; gap: 16px; flex-wrap: wrap; padding-top: 4px">
              <div style="display: flex; flex-direction: column"><span id="extras-l" style="font-weight: 600">Additional courses</span><span style="font-size: 14px; color: #574B40">Each adds 50% of the level's base fee</span></div>
              <div role="group" aria-labelledby="extras-l" style="display: flex; align-items: center; gap: 6px; padding: 4px; background: #F3EEE6; border-radius: 12px">
                <button type="button" aria-label="One fewer course" onClick="{{{{ dec }}}}" style="width: 44px; height: 44px; border: 0; border-radius: 9px; background: #FFFFFF; font: inherit; font-size: 20px; cursor: pointer">−</button>
                <output aria-live="polite" style="min-width: 32px; text-align: center; font-weight: 600; font-size: 18px">{{{{ extras }}}}</output>
                <button type="button" aria-label="One more course" onClick="{{{{ inc }}}}" style="width: 44px; height: 44px; border: 0; border-radius: 9px; background: #FFFFFF; font: inherit; font-size: 20px; cursor: pointer">+</button>
              </div>
            </div>
          </sc-if>
        </form>
        <aside aria-label="Your estimate" style="flex: 1 1 340px; min-width: 0; background: #211A13; color: #FCF8F3; border-radius: 24px; padding: clamp(24px, 3vw, 40px); display: flex; flex-direction: column; gap: 20px">
          <div style="display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap">
            <span style="font-size: 14px; color: #D8CEC1">Estimated tuition</span>
            <div role="radiogroup" aria-label="Currency" style="display: flex; gap: 2px; padding: 3px; border-radius: 10px; background: rgba(252,248,243,.08)">
              <sc-for list="{{{{ curOpts }}}}" as="o" hint-placeholder-count="3"><button type="button" role="radio" aria-checked="{{{{ o.checked }}}}" onClick="{{{{ o.pick }}}}" style="{{{{ o.style }}}}">{{{{ o.label }}}}</button></sc-for>
            </div>
          </div>
          <p aria-live="polite" style="margin: 0; display: flex; align-items: baseline; gap: 10px; flex-wrap: wrap"><span style="font-family: 'Cormorant Garamond', Georgia, serif; font-size: clamp(56px, 5vw, 76px); font-weight: 600; line-height: 1">{{{{ total }}}}</span><span style="font-size: 14px; color: #D8CEC1">{{{{ per }}}}</span></p>
          <dl style="margin: 0; display: flex; flex-direction: column; gap: 10px; padding-top: 18px; border-top: 1px solid rgba(252,248,243,.14); font-size: 15px">
            <sc-for list="{{{{ lines }}}}" as="ln" hint-placeholder-count="3"><div style="display: flex; justify-content: space-between; gap: 12px"><dt style="color: #D8CEC1">{{{{ ln.k }}}}</dt><dd style="margin: 0">{{{{ ln.v }}}}</dd></div></sc-for>
          </dl>
          <p style="margin: 0; font-size: 13px; color: #D8CEC1">Plus a one-time {{{{ reg }}}} registration. {{{{ curNote }}}}</p>
          <a href="Apply.dc.html" class="btn arrow" style="margin-top: auto; display: inline-flex; justify-content: center; align-items: center; gap: 8px; min-height: 52px; border-radius: 10px; background: #F2A93B; color: #211A13; font-weight: 600; text-decoration: none">Apply with this choice <span aria-hidden="true">→</span></a>
        </aside>
      </div>
    </section>

    <!-- instruments + expressions -->
    <section id="instruments" aria-labelledby="ins-h" style="background: #F3EEE6">
      <div style="max-width: 1360px; margin: 0 auto; padding: clamp(64px, 8.9vw, 128px) clamp(20px, 4vw, 40px); display: flex; flex-wrap: wrap; gap: 40px 80px">
        <div style="flex: 1 1 360px; display: flex; flex-direction: column; gap: 14px">{eyebrow('What you can study')}{h2('ins-h', 'Instruments and expressions')}<p class="read" style="margin: 0; font-size: 16px; color: #574B40">Choose a primary course, then the musical tradition you want to grow in. Grand Musician students study all three.</p></div>
        <div style="flex: 1.4 1 520px; min-width: 0; display: flex; flex-direction: column; gap: 32px">
          <ul class="stagger" style="list-style: none; margin: 0; padding: 0; display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 10px">
            <sc-for list="{{{{ instruments }}}}" as="i" hint-placeholder-count="8"><li style="background: #FFFFFF; border: 1px solid #ECE5DA; border-radius: 14px; padding: 16px 18px; font-family: 'Cormorant Garamond', Georgia, serif; font-size: 22px; font-weight: 600">{{{{ i }}}}</li></sc-for>
          </ul>
          <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 10px">
            <div style="padding: 20px; border-radius: 14px; background: #211A13; color: #FCF8F3"><p style="margin: 0 0 4px; font-family: 'Cormorant Garamond', Georgia, serif; font-size: 24px; font-weight: 600">African</p><p style="margin: 0; font-size: 14px; color: #D8CEC1">Rhythms, voices and forms of the continent.</p></div>
            <div style="padding: 20px; border-radius: 14px; background: #211A13; color: #FCF8F3"><p style="margin: 0 0 4px; font-family: 'Cormorant Garamond', Georgia, serif; font-size: 24px; font-weight: 600">Contemporary</p><p style="margin: 0; font-size: 14px; color: #D8CEC1">Gospel, pop, jazz and modern repertoire.</p></div>
            <div style="padding: 20px; border-radius: 14px; background: #211A13; color: #FCF8F3"><p style="margin: 0 0 4px; font-family: 'Cormorant Garamond', Georgia, serif; font-size: 24px; font-weight: 600">Classical</p><p style="margin: 0; font-size: 14px; color: #D8CEC1">The Western concert tradition and its technique.</p></div>
          </div>
        </div>
      </div>
    </section>

    <!-- intensives + scholarship -->
    <section aria-labelledby="int-h" style="max-width: 1360px; margin: 0 auto; padding: clamp(64px, 8.9vw, 128px) clamp(20px, 4vw, 40px); display: flex; flex-direction: column; gap: 40px">
      <div style="display: flex; flex-direction: column; gap: 14px">{eyebrow('Short and focused')}{h2('int-h', 'Intensives')}</div>
      <div class="stagger" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 16px">
        <article class="lift" style="background: #FFFFFF; border: 1px solid #ECE5DA; border-radius: 20px; padding: 28px; display: flex; flex-direction: column; gap: 10px"><p style="margin: 0; font-size: 12px; font-weight: 600; letter-spacing: .1em; text-transform: uppercase; color: #8A5208">6 weeks · flat fee · no levels</p><h3 style="margin: 0; font-family: 'Cormorant Garamond', Georgia, serif; font-weight: 600; font-size: 30px">Crash Programme</h3><p style="margin: 0; font-size: 15px; color: #574B40">A focused intensive in any discipline, online or in person.</p><p style="margin: auto 0 0; font-family: 'Cormorant Garamond', Georgia, serif; font-size: 36px; font-weight: 600">$70</p></article>
        <article class="lift" style="background: #FFFFFF; border: 1px solid #ECE5DA; border-radius: 20px; padding: 28px; display: flex; flex-direction: column; gap: 10px"><p style="margin: 0; font-size: 12px; font-weight: 600; letter-spacing: .1em; text-transform: uppercase; color: #8A5208">12 weeks · post-diploma</p><h3 style="margin: 0; font-family: 'Cormorant Garamond', Georgia, serif; font-weight: 600; font-size: 30px">The MetaMusic Order</h3><p style="margin: 0; font-size: 15px; color: #574B40">From performer to architect: sound, culture and atmosphere.</p><p style="margin: auto 0 0; font-family: 'Cormorant Garamond', Georgia, serif; font-size: 36px; font-weight: 600">$60</p></article>
      </div>
      <p id="scholarship" style="margin: 0; padding-top: 20px; border-top: 1px solid #ECE5DA; font-size: 15px; color: #574B40">Need support with tuition? The NGV Scholarship helps talented students for whom cost is the only barrier. <a href="Apply.dc.html" class="ul" style="color: #211A13; font-weight: 600; text-decoration: none">Ask about it when you apply</a></p>
    </section>
'''
methods = ''
vals = r"""(levels) => {
      const s = this.state, rates = { USD: [1, '$'], NGN: [1500, '₦'], GBP: [0.79, '£'] };
      const segStyle = (on) => """ + seg + r""";
      const curStyle = (on) => 'min-height: 32px; padding: 0 12px; border: 0; border-radius: 8px; font: inherit; font-size: 13px; font-weight: 600; cursor: pointer; ' + (on ? 'background: #FCF8F3; color: #211A13' : 'background: transparent; color: #D8CEC1');
      const progs = [['standard', 'Standard', 'One course'], ['certified', 'Certified', '+ 4 core'], ['grand', 'Grand', 'All 3 styles'], ['crash', 'Crash', '6 weeks']];
      const B = levels[s.level].base, crash = s.prog === 'crash', extrasAllowed = s.prog === 'standard' || s.prog === 'grand';
      const ex = extrasAllowed ? s.extras : 0, half = 0.5 * B;
      let sub = 0, lines = [];
      if (crash) { sub = 70; lines = [{ k: 'Crash Programme (flat)', v: 70 }]; }
      else if (s.prog === 'standard') { sub = B + half * ex; lines = [{ k: 'Level base fee', v: B }]; if (ex) lines.push({ k: ex + ' additional course' + (ex > 1 ? 's' : ''), v: half * ex }); }
      else if (s.prog === 'certified') { sub = B + 120; lines = [{ k: 'Level base fee', v: B }, { k: 'Four compulsory courses', v: 120 }]; }
      else { const pre = 3 * B + half * ex; sub = pre * 0.87; lines = [{ k: 'All three expressions (3 × base)', v: 3 * B }]; if (ex) lines.push({ k: ex + ' additional course' + (ex > 1 ? 's' : ''), v: half * ex }); lines.push({ k: 'Grand saving (13%)', v: -(pre - sub) }); }
      if (!crash && s.mode === 'online') { lines.push({ k: 'Online (+20%)', v: sub * 0.2 }); sub *= 1.2; }
      const [rate, sym] = rates[s.cur];
      const fmt = (usd) => { const v = Math.round(Math.abs(usd) * rate); return (usd < 0 ? '−' : '') + sym + v.toLocaleString('en-US'); };
      const plans = [
        { key: 'standard', kicker: 'Single discipline', name: 'Standard', price: '$120', desc: 'Focus on one course at your level — add more whenever you like.', items: ['One primary course (instrument or music education)', 'One expression: African, Contemporary or Classical', 'Add courses at 50% of the base fee'] },
        { key: 'certified', kicker: 'Full certification', name: 'Certified Musician', price: '$240', popular: true, desc: 'Your major plus the four compulsory courses — up to five courses in all.', items: ['Includes the 4 compulsory courses ($120)', 'One expression of your choice', 'Progresses across all four certification levels'] },
        { key: 'grand', kicker: 'Flagship', name: 'Grand Musician', price: '$313', desc: 'The complete musician — all three expressions, up to seven courses at a time.', items: ['All three expressions', 'All four compulsory courses included', '13% saving built into the price'] }
      ];
      return {
        plans: plans.map((p) => ({ ...p, popular: !!p.popular, pick: () => { this.setState({ prog: p.key }); const el = document.getElementById('fees'); if (el) el.scrollIntoView({ behavior: 'smooth' }); },
          cardStyle: 'position: relative; background: #FFFFFF; border-radius: 24px; padding: 32px; display: flex; flex-direction: column; gap: 12px; box-sizing: border-box; ' + (p.popular ? 'border: 2px solid #211A13; padding: 31px' : 'border: 1px solid #ECE5DA') })),
        progOpts: progs.map(([k, l, sb]) => ({ label: l, sub: sb, checked: s.prog === k ? 'true' : 'false', style: segStyle(s.prog === k), pick: () => this.setState({ prog: k }) })),
        levelOpts: levels.map((l, i) => ({ label: l.name.replace('Certificate', 'Cert.').replace('Pre-Cert.', 'Pre-Cert'), sub: l.grades, checked: s.level === i ? 'true' : 'false', style: segStyle(s.level === i), pick: () => this.setState({ level: i }) })),
        modeOpts: [['onsite', 'In person', 'Standard fee'], ['online', 'Online', '+20%']].map(([k, l, sb]) => ({ label: l, sub: sb, checked: s.mode === k ? 'true' : 'false', style: segStyle(s.mode === k), pick: () => this.setState({ mode: k }) })),
        curOpts: Object.keys(rates).map((k) => ({ label: k, checked: s.cur === k ? 'true' : 'false', style: curStyle(s.cur === k), pick: () => this.setState({ cur: k }) })),
        showLevel: !crash, showExtras: extrasAllowed, extras: String(ex),
        inc: () => this.setState({ extras: Math.min(4, s.extras + 1) }), dec: () => this.setState({ extras: Math.max(0, s.extras - 1) }),
        total: fmt(sub), per: crash ? 'for 6 weeks' : 'per term', lines: lines.map((l) => ({ k: l.k, v: fmt(l.v) })),
        reg: fmt(5), curNote: s.cur === 'USD' ? 'Pay in USD, NGN or GBP.' : 'Converted at an indicative rate; final amount set at checkout.'
      };
    }"""
html = page('MAM Academy — Programmes & fees', 'programmes', body, "{ prog: 'certified', level: 0, mode: 'onsite', extras: 0, cur: 'USD' }", methods, vals, 4600)
open('/home/claude/mam/project/Programmes.dc.html', 'w').write(html)
print(len(html))
