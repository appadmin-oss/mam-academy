from kit import *
def head(id_, eb, title, intro='', dark=False):
    mut = '#D8CEC1' if dark else '#574B40'
    return f'<div style="display: flex; flex-wrap: wrap; justify-content: space-between; align-items: flex-end; gap: 16px 64px"><div style="display: flex; flex-direction: column; gap: 14px">{eyebrow(eb, dark)}{h2(id_, title)}</div>' + (f'<p class="read" style="margin: 0; font-size: 16px; color: {mut}; max-width: 420px">{intro}</p>' if intro else '') + '</div>'
DARKSEC = "padding: 0 clamp(8px, 1vw, 14px)"
DARKBOX = "border-radius: clamp(14px, 1.6vw, 22px); background: radial-gradient(80% 120% at 85% 0%, #5A3815 0%, #24160B 50%, #140D08 100%); color: #FCF8F3"
def photo(label, ratio='4 / 5', extra=''):
    return f'<div role="img" aria-label="Photo placeholder: {label}" class="ph" style="aspect-ratio: {ratio}; border-radius: 16px; background: linear-gradient(135deg, #E8DECF, #D3C3AC); display: flex; align-items: flex-end; padding: 14px; box-sizing: border-box; font-size: 12px; color: #574B40; {extra}">Photo — {label} (upload)</div>'
def pick_list(list_, label):
    return f'''<div role="tablist" aria-label="{label}" aria-orientation="vertical" style="display: flex; flex-direction: column; border-top: 1px solid {{{{ lineCol }}}}">
            <sc-for list="{{{{ {list_} }}}}" as="p" hint-placeholder-count="5"><button type="button" role="tab" aria-selected="{{{{ p.sel }}}}" aria-controls="{list_}-panel" onClick="{{{{ p.pick }}}}" onMouseEnter="{{{{ p.pick }}}}" class="prow" style="{{{{ p.style }}}}"><span aria-hidden="true" style="font-size: 13px; width: 26px; flex: none; opacity: .7">{{{{ p.n }}}}</span><span style="{SER}; font-size: clamp(26px, 2.4vw, 36px); line-height: 1.1">{{{{ p.t }}}}</span><span aria-hidden="true" style="margin-left: auto; transition: transform .5s var(--out); {{{{ p.arrow }}}}">→</span></button></sc-for>
          </div>'''
PICK_JS = """const pickStyle = (on, dark) => 'display: flex; align-items: center; gap: 14px; width: 100%; min-height: 72px; padding: 14px 4px; border: 0; border-bottom: 1px solid ' + (dark ? 'rgba(252,248,243,.14)' : '#E3DACD') + '; background: none; font: inherit; text-align: left; cursor: pointer; transition: color .3s ease, padding .5s cubic-bezier(.19,1,.22,1); color: ' + (on ? (dark ? '#F2A93B' : '#8A5208') : (dark ? 'rgba(252,248,243,.6)' : '#211A13')) + (on ? '; padding-left: 14px' : '');"""
CSS = '<style>.prow:hover{color:inherit}.prow:focus-visible{outline:3px solid #F2A93B;outline-offset:-3px}.pnl{animation:qIn .6s var(--out) both}@keyframes qIn{from{opacity:0;transform:translateY(14px)}to{opacity:1;transform:none}}@keyframes qIn2{from{opacity:0;transform:translateY(14px)}to{opacity:1;transform:none}}.erow:hover .ph{transform:scale(1.03)}.erow .ph{transition:transform 1.2s var(--out)}.lrow:hover .lname{color:#8A5208;transform:translateX(8px)}.lrow .lname{transition:transform .6s var(--out),color .3s}@media (prefers-reduced-motion: reduce){.pnl{animation:none}}</style>'
def build(fname, title, active, body, state, methods, vals, h):
    html = page(title, active, body, state, methods, vals, h).replace('</helmet>', CSS + '\n</helmet>', 1)
    open(f'/home/claude/mam/project/{fname}', 'w').write(html)

# ======================= ABOUT =======================
about = cine_hero('About', 'About', 'Not merely a music school.', (3, 4), 'A creative intelligence and performance academy — part of MAM Centre since 2008.', 'MAM students and faculty after a showcase',
    '', [('#story', 'Our story'), ('#method', 'The MAM Method'), ('#faculty', 'Faculty')]) + \
  statement('We form <em style="color: #8A5208">complete</em> musicians — people who can play, perform, read, lead and think, with discipline, confidence and joy.') + \
  split('story', 'Our story', 'Since 2008', 'Commissioned as the training arm of MAM Centre, built on a method of our own.', f'''<ol style="list-style: none; margin: 0; padding: 0; border-bottom: 1px solid #E3DACD">
          <sc-for list="{{{{ story }}}}" as="s" hint-placeholder-count="4"><li class="reveal" style="display: grid; grid-template-columns: minmax(120px, 1fr) 3fr; gap: 20px; padding: 28px 0; border-top: 1px solid #E3DACD"><span style="{SER}; font-size: 28px; color: #8A5208">{{{{ s.k }}}}</span><div><h3 style="margin: 0; {SER}; font-weight: 500; font-size: 30px; line-height: 1.1">{{{{ s.t }}}}</h3><p style="margin: 6px 0 0; font-size: 16px; color: #574B40">{{{{ s.d }}}}</p></div></li></sc-for>
        </ol>''') + f'''
    <section id="method" aria-labelledby="method-h" style="{DARKSEC}">
      <div style="{DARKBOX}">
        <div style="{WRAP}; {SEC}; display: flex; flex-direction: column; gap: 48px">
          {head('method-h', 'Our framework', 'The MAM Method', 'A complete system, not just a curriculum. Five pillars shape every lesson, at every level.', True)}
          <div style="display: flex; flex-wrap: wrap; gap: 40px clamp(40px, 6vw, 96px); align-items: flex-start">
            <div style="flex: 1 1 380px; min-width: 0; --lc: rgba(252,248,243,.14)">{pick_list('pillars', 'The five pillars').replace('{{ lineCol }}', 'rgba(252,248,243,.14)')}</div>
            <div id="pillars-panel" role="tabpanel" aria-live="polite" style="flex: 1 1 420px; min-width: 0; position: sticky; top: 150px">
              <div class="pnl" style="{{{{ pnlKey }}}}; display: flex; flex-direction: column; gap: 16px">
                <span aria-hidden="true" style="{SER}; font-size: clamp(120px, 14vw, 200px); line-height: .8; color: #F2A93B">{{{{ pillar.n }}}}</span>
                <h3 style="margin: 0; {SER}; font-weight: 500; font-size: clamp(36px, 3.4vw, 52px); line-height: 1.05">{{{{ pillar.t }}}}</h3>
                <p style="margin: 0; font-size: 18px; color: #E9E1D6">{{{{ pillar.d }}}}</p>
                <p style="margin: 12px 0 0; font-size: 13px; font-weight: 600; letter-spacing: .14em; text-transform: uppercase; color: #D8CEC1">In class, this looks like</p>
                <ul style="margin: 0; padding: 0; list-style: none; display: flex; flex-direction: column; gap: 8px; font-size: 16px"><sc-for list="{{{{ pillar.x }}}}" as="x" hint-placeholder-count="3"><li style="display: flex; gap: 12px"><span aria-hidden="true" style="color: #F2A93B">—</span>{{{{ x }}}}</li></sc-for></ul>
              </div>
            </div>
          </div>
        </div>
      </div>
    </section>
    <section aria-labelledby="who-h" style="{WRAP}; {SEC}; display: flex; flex-direction: column; gap: 28px">
      {eyebrow('Who we develop')}
      <h2 id="who-h" class="read" style="margin: 0; {SER}; font-weight: 500; font-size: clamp(34px, 4.2vw, 64px); line-height: 1.15; max-width: 1200px"><sc-for list="{{{{ roles }}}}" as="r" hint-placeholder-count="9"><span style="{{{{ r.st }}}}">{{{{ r.t }}}}</span><span aria-hidden="true" style="color: #C27A12">{{{{ r.sep }}}}</span></sc-for></h2>
    </section>
    <section aria-label="Our vision" style="{DARKSEC}">
      <figure style="margin: 0; border-radius: clamp(14px, 1.6vw, 22px); background: #211A13; color: #FCF8F3; padding: clamp(72px, 9vw, 140px) clamp(20px, 6vw, 96px); display: flex; flex-direction: column; align-items: center; gap: 24px; text-align: center">
        <blockquote class="read" style="margin: 0; {SER}; font-style: italic; font-weight: 500; font-size: clamp(32px, 3.8vw, 56px); line-height: 1.2; max-width: 980px">“We interpret the timeless using instruments and replay it in time.”</blockquote>
        <figcaption style="font-size: 13px; font-weight: 600; letter-spacing: .16em; text-transform: uppercase; color: #F2A93B">The MAM Academy vision</figcaption>
      </figure>
    </section>
    <section id="faculty" aria-labelledby="fac-h" style="{WRAP}; {SEC}; display: flex; flex-direction: column; gap: 48px">
      {head('fac-h', 'Faculty', 'Trained by working musicians', 'Every class is taught by an active performer, worship leader or composer. Your assessment matches you with the right teacher.')}
      <div style="display: flex; flex-wrap: wrap; gap: 24px 40px; align-items: flex-end">
        <article class="reveal zoom" style="flex: 1 1 380px; min-width: 0; display: flex; flex-direction: column; gap: 14px">{photo('Just-Joe Adeola portrait', '4 / 3.4')}<p style="margin: 6px 0 0; font-size: 13px; font-weight: 600; letter-spacing: .12em; text-transform: uppercase; color: #8A5208">Founder · Director &amp; Lead Faculty</p><h3 style="margin: 0; {SER}; font-weight: 500; font-size: 44px; line-height: 1">Just-Joe Adeola</h3><p style="margin: 0; font-size: 16px; color: #574B40; max-width: 520px">Architect of the MAM Method. Leads The MetaMusic Order and the monthly Power Play series.</p></article>
        <ul class="stagger" style="flex: 1.4 1 520px; min-width: 0; list-style: none; margin: 0; padding: 0; display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 24px 16px">
          <sc-for list="{{{{ faculty }}}}" as="f" hint-placeholder-count="4"><li class="zoom" style="display: flex; flex-direction: column; gap: 8px"><div style="overflow: hidden; border-radius: 16px">{photo('{{ f.t }} faculty')}</div><h3 style="margin: 6px 0 0; {SER}; font-weight: 600; font-size: 24px; line-height: 1.1">{{{{ f.t }}}}</h3><p style="margin: 0; font-size: 14px; color: #574B40">{{{{ f.d }}}}</p></li></sc-for>
        </ul>
      </div>
    </section>
''' + closing('Come and be heard.', 'Apply in about five minutes, or start with a MAM Auditer assessment.', ('Apply.dc.html', 'Start your application'), ('Auditer.dc.html', 'Book an assessment'))
about_vals = """(levels) => {
      """ + PICK_JS + """
      const P = [
        { n: '1', t: 'Technique', d: 'Instrument mastery and technical excellence.', x: ['Posture, tone and clean execution from the first lesson', 'Scales and studies chosen for your level', 'Weekly targets you can hear yourself reach'] },
        { n: '2', t: 'Rhythm Intelligence', d: 'Fast musical interpretation and timing fluency.', x: ['The MAM Rapid Rhythm System', 'Reading and feeling time together', 'Playing in ensemble without drifting'] },
        { n: '3', t: 'Performance Intelligence', d: 'Professional stage mastery and presentation.', x: ['Performing every term, at every level', 'Presence, recovery and connecting with a room', 'Feedback from faculty after each showcase'] },
        { n: '4', t: 'Creative Consciousness', d: 'MetaMusic understanding and artistic awareness.', x: ['Improvising and writing your own music', 'Knowing why music moves people', 'Finding a voice of your own'] },
        { n: '5', t: 'Artistic Discipline', d: 'Professional culture, ethics and excellence.', x: ['Practice habits that last', 'Punctuality, preparation and respect', 'Leading others as a musician'] }
      ];
      const i = this.state.pillar, R = ['Musicians', 'Performers', 'Worship leaders', 'Composers', 'Conductors', 'Actors', 'Public performers', 'Creative thinkers', 'Cultural artists'];
      return {
        pillars: P.map((p, k) => ({ n: '0' + p.n, t: p.t, sel: k === i ? 'true' : 'false', style: pickStyle(k === i, true), arrow: k === i ? 'transform: translateX(0); opacity: 1' : 'transform: translateX(-8px); opacity: 0', pick: () => { if (this.state.pillar !== k) this.setState({ pillar: k }); } })),
        pillar: P[i], pnlKey: 'animation-name: ' + (i % 2 ? 'qIn' : 'qIn2'),
        roles: R.map((t, k) => ({ t, sep: k < R.length - 1 ? '  ·  ' : '', st: k % 3 === 1 ? 'font-style: italic; color: #8A5208' : '' })),
        story: [{ k: '2008', t: 'Commissioned', d: 'MAM Academy begins as the training arm of MAM Centre.' }, { k: 'The method', t: 'Five pillars, one system', d: 'Technique, rhythm, performance, creativity and discipline — taught together.' }, { k: 'The levels', t: 'Four levels, Grades 1–8', d: 'A clear path, equivalent to Trinity College London grades.' }, { k: 'Today', t: 'Online and in person', d: 'Live classes anywhere, studios for those nearby, and a community that meets every month.' }],
        faculty: [{ t: 'Piano & Keyboard', d: 'Classical, gospel, jazz and worship.' }, { t: 'Strings', d: 'Violin, viola, cello and guitar.' }, { t: 'Voice & Stage', d: 'Breath, presence and stagecraft.' }, { t: 'Rhythm & Percussion', d: 'MAM Rapid Rhythm System and ensemble.' }]
      };
    }"""
build('About.dc.html', 'MAM Academy — About', 'about', about, '{ pillar: 0 }', '', about_vals, 5200)

# ======================= AUDITER =======================
aud = cine_hero('MAM Auditer', 'MAM Auditer', 'Placed by listening, not guessing.', (3, 4), 'A short assessment before training begins. It shows where you are today — and the report becomes your term-by-term plan.', 'an assessor listening to a young violinist',
    pill('Apply.dc.html', 'Apply and book', 'gold') + pill('#try', 'Try a question', 'ghost'), [('#measures', 'What it measures'), ('#try', 'Try it'), ('#process', 'How it works')]) + \
  statement('Most schools guess your level from your age. <em style="color: #8A5208">We listen first.</em> Thirty minutes of playing, listening and rhythm tasks tell us exactly where to begin.') + f'''
    <section id="measures" aria-labelledby="meas-h" style="background: #F3EEE6">
      <div style="{WRAP}; {SEC}; display: flex; flex-direction: column; gap: 48px">
        {head('meas-h', 'What it measures', 'Ten dimensions of readiness', 'Not just how well you play — how you hear, move, perform and learn. Select a dimension to see what we look for.')}
        <div style="display: flex; flex-wrap: wrap; gap: 40px clamp(40px, 5vw, 80px); align-items: center">
          <figure class="reveal" style="flex: 1 1 420px; min-width: 0; margin: 0; display: flex; flex-direction: column; align-items: center; gap: 12px">
            <svg viewBox="-70 -30 540 460" role="img" aria-label="Sample MAM Auditer profile across ten dimensions" style="width: 100%; max-width: 560px; height: auto; overflow: visible">
              <polygon points="200.0,162.5 222.0,169.7 235.7,188.4 235.7,211.6 222.0,230.3 200.0,237.5 178.0,230.3 164.3,211.6 164.3,188.4 178.0,169.7" fill="none" stroke="#D9CCBA" stroke-width="1"></polygon><polygon points="200.0,125.0 244.1,139.3 271.3,176.8 271.3,223.2 244.1,260.7 200.0,275.0 155.9,260.7 128.7,223.2 128.7,176.8 155.9,139.3" fill="none" stroke="#D9CCBA" stroke-width="1"></polygon><polygon points="200.0,87.5 266.1,109.0 307.0,165.2 307.0,234.8 266.1,291.0 200.0,312.5 133.9,291.0 93.0,234.8 93.0,165.2 133.9,109.0" fill="none" stroke="#D9CCBA" stroke-width="1"></polygon><polygon points="200.0,50.0 288.2,78.6 342.7,153.6 342.7,246.4 288.2,321.4 200.0,350.0 111.8,321.4 57.3,246.4 57.3,153.6 111.8,78.6" fill="none" stroke="#D9CCBA" stroke-width="1"></polygon><line x1="200" y1="200" x2="200.0" y2="50.0" stroke="{{{{ ax.a0.stroke }}}}" stroke-width="1"></line><line x1="200" y1="200" x2="288.2" y2="78.6" stroke="{{{{ ax.a1.stroke }}}}" stroke-width="1"></line><line x1="200" y1="200" x2="342.7" y2="153.6" stroke="{{{{ ax.a2.stroke }}}}" stroke-width="1"></line><line x1="200" y1="200" x2="342.7" y2="246.4" stroke="{{{{ ax.a3.stroke }}}}" stroke-width="1"></line><line x1="200" y1="200" x2="288.2" y2="321.4" stroke="{{{{ ax.a4.stroke }}}}" stroke-width="1"></line><line x1="200" y1="200" x2="200.0" y2="350.0" stroke="{{{{ ax.a5.stroke }}}}" stroke-width="1"></line><line x1="200" y1="200" x2="111.8" y2="321.4" stroke="{{{{ ax.a6.stroke }}}}" stroke-width="1"></line><line x1="200" y1="200" x2="57.3" y2="246.4" stroke="{{{{ ax.a7.stroke }}}}" stroke-width="1"></line><line x1="200" y1="200" x2="57.3" y2="153.6" stroke="{{{{ ax.a8.stroke }}}}" stroke-width="1"></line><line x1="200" y1="200" x2="111.8" y2="78.6" stroke="{{{{ ax.a9.stroke }}}}" stroke-width="1"></line><polygon points="200.0,95.0 260.0,117.5 317.0,162.0 305.6,234.3 267.9,293.4 200.0,291.5 130.3,295.9 108.7,229.7 74.5,159.2 125.1,96.9" fill="rgba(242,169,59,.28)" stroke="#C27A12" stroke-width="2" stroke-linejoin="round"></polygon><circle cx="200.0" cy="95.0" r="{{{{ ax.a0.r }}}}" fill="{{{{ ax.a0.fill }}}}" stroke="#FAF8F4" stroke-width="2"></circle><text x="200.0" y="24.0" text-anchor="middle" dominant-baseline="middle" style="font-family: Inter, sans-serif; font-size: 13px; fill: {{{{ ax.a0.tc }}}}; font-weight: {{{{ ax.a0.fw }}}}">Level</text><circle cx="260.0" cy="117.5" r="{{{{ ax.a1.r }}}}" fill="{{{{ ax.a1.fill }}}}" stroke="#FAF8F4" stroke-width="2"></circle><text x="303.5" y="57.6" text-anchor="start" dominant-baseline="middle" style="font-family: Inter, sans-serif; font-size: 13px; fill: {{{{ ax.a1.tc }}}}; font-weight: {{{{ ax.a1.fw }}}}">Technique</text><circle cx="317.0" cy="162.0" r="{{{{ ax.a2.r }}}}" fill="{{{{ ax.a2.fill }}}}" stroke="#FAF8F4" stroke-width="2"></circle><text x="367.4" y="145.6" text-anchor="start" dominant-baseline="middle" style="font-family: Inter, sans-serif; font-size: 13px; fill: {{{{ ax.a2.tc }}}}; font-weight: {{{{ ax.a2.fw }}}}">Rhythm</text><circle cx="305.6" cy="234.3" r="{{{{ ax.a3.r }}}}" fill="{{{{ ax.a3.fill }}}}" stroke="#FAF8F4" stroke-width="2"></circle><text x="367.4" y="254.4" text-anchor="start" dominant-baseline="middle" style="font-family: Inter, sans-serif; font-size: 13px; fill: {{{{ ax.a3.tc }}}}; font-weight: {{{{ ax.a3.fw }}}}">Ear</text><circle cx="267.9" cy="293.4" r="{{{{ ax.a4.r }}}}" fill="{{{{ ax.a4.fill }}}}" stroke="#FAF8F4" stroke-width="2"></circle><text x="303.5" y="342.4" text-anchor="start" dominant-baseline="middle" style="font-family: Inter, sans-serif; font-size: 13px; fill: {{{{ ax.a4.tc }}}}; font-weight: {{{{ ax.a4.fw }}}}">Tonal</text><circle cx="200.0" cy="291.5" r="{{{{ ax.a5.r }}}}" fill="{{{{ ax.a5.fill }}}}" stroke="#FAF8F4" stroke-width="2"></circle><text x="200.0" y="376.0" text-anchor="middle" dominant-baseline="middle" style="font-family: Inter, sans-serif; font-size: 13px; fill: {{{{ ax.a5.tc }}}}; font-weight: {{{{ ax.a5.fw }}}}">Stage</text><circle cx="130.3" cy="295.9" r="{{{{ ax.a6.r }}}}" fill="{{{{ ax.a6.fill }}}}" stroke="#FAF8F4" stroke-width="2"></circle><text x="96.5" y="342.4" text-anchor="end" dominant-baseline="middle" style="font-family: Inter, sans-serif; font-size: 13px; fill: {{{{ ax.a6.tc }}}}; font-weight: {{{{ ax.a6.fw }}}}">Artistry</text><circle cx="108.7" cy="229.7" r="{{{{ ax.a7.r }}}}" fill="{{{{ ax.a7.fill }}}}" stroke="#FAF8F4" stroke-width="2"></circle><text x="32.6" y="254.4" text-anchor="end" dominant-baseline="middle" style="font-family: Inter, sans-serif; font-size: 13px; fill: {{{{ ax.a7.tc }}}}; font-weight: {{{{ ax.a7.fw }}}}">Readiness</text><circle cx="74.5" cy="159.2" r="{{{{ ax.a8.r }}}}" fill="{{{{ ax.a8.fill }}}}" stroke="#FAF8F4" stroke-width="2"></circle><text x="32.6" y="145.6" text-anchor="end" dominant-baseline="middle" style="font-family: Inter, sans-serif; font-size: 13px; fill: {{{{ ax.a8.tc }}}}; font-weight: {{{{ ax.a8.fw }}}}">Discipline</text><circle cx="125.1" cy="96.9" r="{{{{ ax.a9.r }}}}" fill="{{{{ ax.a9.fill }}}}" stroke="#FAF8F4" stroke-width="2"></circle><text x="96.5" y="57.6" text-anchor="end" dominant-baseline="middle" style="font-family: Inter, sans-serif; font-size: 13px; fill: {{{{ ax.a9.tc }}}}; font-weight: {{{{ ax.a9.fw }}}}">Learning</text>
            </svg>
            <figcaption style="font-size: 13px; color: #574B40">Sample profile. Every student gets their own.</figcaption>
          </figure>
          <div style="flex: 1 1 380px; min-width: 0; display: flex; flex-direction: column; gap: 24px">
            <div role="tablist" aria-label="Dimensions" style="display: flex; flex-wrap: wrap; gap: 8px"><sc-for list="{{{{ dimTabs }}}}" as="d" hint-placeholder-count="10"><button type="button" role="tab" aria-selected="{{{{ d.sel }}}}" aria-controls="dim-panel" onClick="{{{{ d.pick }}}}" onMouseEnter="{{{{ d.pick }}}}" class="btn" style="{{{{ d.style }}}}">{{{{ d.t }}}}</button></sc-for></div>
            <div id="dim-panel" role="tabpanel" aria-live="polite" style="padding-top: 24px; border-top: 1px solid #D9CCBA; min-height: 190px">
              <div class="pnl" style="{{{{ dimKey }}}}">
                <div style="display: flex; justify-content: space-between; align-items: baseline; gap: 16px"><h3 style="margin: 0; {SER}; font-weight: 500; font-size: clamp(32px, 3vw, 44px); line-height: 1.05">{{{{ dim.t }}}}</h3><span style="{SER}; font-size: 44px; color: #8A5208">{{{{ dim.v }}}}</span></div>
                <p style="margin: 10px 0 0; font-size: 17px; color: #574B40">{{{{ dim.d }}}}</p>
              </div>
            </div>
          </div>
        </div>
      </div>
    </section>

    <section id="try" aria-labelledby="try-h" style="{DARKSEC}; padding-top: clamp(12px, 1.4vw, 20px)">
      <div style="{DARKBOX}">
        <div style="{WRAP}; padding-top: clamp(64px, 8vw, 120px); padding-bottom: clamp(64px, 8vw, 120px); display: flex; flex-wrap: wrap; gap: 48px clamp(40px, 6vw, 112px); align-items: center">
          <div style="flex: 1 1 380px; display: flex; flex-direction: column; gap: 16px">{eyebrow('Try one question', True)}{h2('try-h', 'Which note is higher?')}<p class="read" style="margin: 0; font-size: 17px; color: #D8CEC1; max-width: 460px">One of the ear tasks from a real session. Turn your sound on, play the pair, then choose.</p></div>
          <div style="flex: 1 1 420px; min-width: 0; display: flex; flex-direction: column; align-items: flex-start; gap: 22px">
            <button type="button" onClick="{{{{ play }}}}" class="btn" style="display: inline-flex; align-items: center; gap: 14px; min-height: 64px; padding: 0 28px 0 12px; border-radius: 999px; border: 0; background: #F2A93B; color: #211A13; font: inherit; font-size: 17px; font-weight: 600; cursor: pointer"><span aria-hidden="true" style="width: 44px; height: 44px; border-radius: 50%; background: #211A13; color: #F2A93B; display: flex; align-items: center; justify-content: center">▶</span>{{{{ playLabel }}}}</button>
            <div role="group" aria-label="Your answer" style="display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; width: 100%; max-width: 440px">
              <button type="button" onClick="{{{{ pickFirst }}}}" aria-disabled="{{{{ ansDisabled }}}}" style="{{{{ ansStyle }}}}">First note</button>
              <button type="button" onClick="{{{{ pickSecond }}}}" aria-disabled="{{{{ ansDisabled }}}}" style="{{{{ ansStyle }}}}">Second note</button>
            </div>
            <p role="status" style="margin: 0; min-height: 28px; font-size: 17px; color: #FCF8F3">{{{{ feedback }}}}</p>
            <p style="margin: 0; font-size: 14px; color: #D8CEC1">Score <strong style="color: #F2A93B">{{{{ score }}}}</strong> · a full session has dozens of short tasks like this.</p>
          </div>
        </div>
      </div>
    </section>

    <section id="process" aria-labelledby="proc-h" style="{WRAP}; {SEC}; display: flex; flex-direction: column; gap: 56px">
      {head('proc-h', 'How it works', 'From session to plan')}
      <ol class="stagger" style="list-style: none; margin: 0; padding: 0; display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr))">
        <sc-for list="{{{{ how }}}}" as="h" hint-placeholder-count="4"><li style="position: relative; padding: 36px 28px 8px 0; border-top: 1px solid #CFC3B3; display: flex; flex-direction: column; gap: 10px"><span aria-hidden="true" style="position: absolute; top: -7px; left: 0; width: 13px; height: 13px; border-radius: 50%; background: #F2A93B; box-shadow: 0 0 0 5px #FAF8F4"></span><span style="font-size: 13px; font-weight: 600; letter-spacing: .12em; text-transform: uppercase; color: #8A5208">{{{{ h.when }}}}</span><h3 style="margin: 0; {SER}; font-weight: 500; font-size: 34px; line-height: 1.05">{{{{ h.t }}}}</h3><p style="margin: 0; font-size: 15px; color: #574B40; max-width: 280px">{{{{ h.d }}}}</p></li></sc-for>
      </ol>
    </section>
''' + closing('Get heard. Get placed.', 'Book your assessment in the same flow as your application.', ('Apply.dc.html', 'Apply and book'), ('Programmes.dc.html', 'Programmes & fees'))
aud_methods = """  ctx() { if (!this.ac) { const A = window.AudioContext || window.webkitAudioContext; if (!A) return null; this.ac = new A(); } if (this.ac.state === 'suspended') this.ac.resume(); return this.ac; }
  tone(ac, f, t) { const o = ac.createOscillator(), o2 = ac.createOscillator(), g = ac.createGain(); o.type = 'triangle'; o2.type = 'sine'; o.frequency.value = f; o2.frequency.value = f * 2; g.gain.setValueAtTime(0, t); g.gain.linearRampToValueAtTime(0.22, t + 0.02); g.gain.exponentialRampToValueAtTime(0.001, t + 0.55); o.connect(g); o2.connect(g); g.connect(ac.destination); o.start(t); o2.start(t); o.stop(t + 0.6); o2.stop(t + 0.6); }
  play() { const ac = this.ctx(); let q = this.state.q; if (!q || q.done) { const base = 220 * Math.pow(2, Math.floor(Math.random() * 12) / 12), semis = (2 + Math.floor(Math.random() * 6)) * (Math.random() < 0.5 ? -1 : 1); q = { a: base, b: base * Math.pow(2, semis / 12), done: false }; }
    if (ac) { const t = ac.currentTime + 0.05; this.tone(ac, q.a, t); this.tone(ac, q.b, t + 0.7); }
    this.setState({ q, fb: ac ? '' : 'Sound is not available in this browser.' }); }
  answer(first) { const q = this.state.q; if (!q || q.done) { this.setState({ fb: 'Play the pair first.' }); return; } const right = first === (q.a > q.b);
    this.setState({ q: Object.assign({}, q, { done: true }), score: this.state.score + (right ? 1 : 0), fb: right ? 'Correct — the ' + (first ? 'first' : 'second') + ' note was higher. Play another?' : 'Not quite — the ' + (q.a > q.b ? 'first' : 'second') + ' note was higher. Try another.' }); }
"""
aud_vals = """(levels) => {
      const D = [['Musical level', 'Level', 70, 'Where your playing sits today on the four-level path.'], ['Technical strength', 'Technique', 68, 'Control, tone and accuracy on your instrument.'], ['Rhythm intelligence', 'Rhythm', 82, 'How quickly you read, feel and hold time.'], ['Ear sensitivity', 'Ear', 74, 'Hearing differences in pitch, tone and texture.'], ['Tonal recognition', 'Tonal', 77, 'Recognising keys, chords and intervals.'], ['Stage confidence', 'Stage', 61, 'Ease and presence when someone is listening.'], ['Artistic potential', 'Artistry', 79, 'Musical ideas, phrasing and expression.'], ['Performance readiness', 'Readiness', 64, 'How close you are to performing a full piece.'], ['Discipline', 'Discipline', 88, 'Focus, preparation and follow-through.'], ['Learning capacity', 'Learning', 85, 'How fast new ideas take hold.']];
      const s = this.state, sel = s.dim, N = D.length, cx = 200, cy = 200, R = 150;
      const pt = (i, r) => { const a = -Math.PI / 2 + i * 2 * Math.PI / N; return [cx + Math.cos(a) * r, cy + Math.sin(a) * r]; };
      const f = (n) => n.toFixed(1);
      const tabStyle = (on) => 'min-height: 40px; padding: 0 14px; border-radius: 999px; font: inherit; font-size: 14px; cursor: pointer; border: 1px solid ' + (on ? '#211A13' : '#CFC3B3') + '; background: ' + (on ? '#211A13' : 'transparent') + '; color: ' + (on ? '#FAF8F4' : '#211A13');
      const q = s.q, waiting = !q || q.done;
      const ans = 'min-height: 56px; border-radius: 14px; font: inherit; font-size: 16px; font-weight: 600; cursor: pointer; border: 1px solid rgba(252,248,243,' + (waiting ? '.2' : '.55') + '); background: rgba(252,248,243,' + (waiting ? '.03' : '.1') + '); color: ' + (waiting ? 'rgba(252,248,243,.5)' : '#FCF8F3');
      return {
        ax: Object.fromEntries(D.map((d, i) => { const on = i === sel; return ['a' + i, { r: on ? 7 : 4, fill: on ? '#211A13' : '#C27A12', stroke: on ? '#211A13' : '#D9CCBA', tc: on ? '#211A13' : '#574B40', fw: on ? 600 : 400 }]; })),
        dimTabs: D.map((d, i) => ({ t: d[1], sel: i === sel ? 'true' : 'false', style: tabStyle(i === sel), pick: () => { if (this.state.dim !== i) this.setState({ dim: i }); } })),
        dim: { t: D[sel][0], v: D[sel][2] + '%', d: D[sel][3] }, dimKey: 'animation-name: ' + (sel % 2 ? 'qIn' : 'qIn2'),
        play: () => this.play(), playLabel: waiting ? (q ? 'Play a new pair' : 'Play the two notes') : 'Play again',
        pickFirst: () => this.answer(true), pickSecond: () => this.answer(false), ansStyle: ans, ansDisabled: waiting ? 'true' : 'false',
        feedback: s.fb, score: String(s.score),
        how: [{ when: 'When you apply', t: 'Book', d: 'Choose a slot, online or in person.' }, { when: 'About 30 minutes', t: 'The session', d: 'Playing, listening and rhythm tasks with an assessor.' }, { when: 'After the session', t: 'Your report', d: 'Ten clear measurements — strengths and gaps.' }, { when: 'Before lessons start', t: 'Your plan', d: 'Level, matched instructor and a term-by-term roadmap.' }]
      };
    }"""
build('Auditer.dc.html', 'MAM Academy — MAM Auditer', 'auditer', aud, "{ dim: 2, q: null, fb: '', score: 0 }", aud_methods, aud_vals, 4700)

# ======================= LIFE =======================
life = cine_hero('Life at MAM', 'Life at MAM', 'Music that builds people.', (2,), 'Lessons are only the start. Stages, gatherings and intensives build discipline, confidence and joy.', 'students on stage at the termly showcase',
    pill('#powerplay', 'Join Power Play — free', 'gold'), [('#powerplay', 'Power Play'), ('#term', 'A term at MAM'), ('#events', 'Stages & intensives'), ('#tools', 'Between lessons')]) + \
  statement('Every term ends on a stage. Every month, the community meets. <em style="color: #8A5208">Music here is something you do together.</em>') + f'''
    <section id="powerplay" aria-labelledby="pp-h" style="{DARKSEC}">
      <div style="{DARKBOX}">
        <div style="{WRAP}; {SEC}; display: flex; flex-wrap: wrap; gap: 48px clamp(40px, 6vw, 112px); align-items: center">
          <div style="flex: 1.2 1 460px; min-width: 0; display: flex; flex-direction: column; gap: 18px">
            {eyebrow('Free · monthly · online', True)}
            <h2 id="pp-h" class="reveal" style="margin: 0; {SER}; font-weight: 500; font-size: clamp(52px, 6vw, 96px); line-height: .95">Power Play</h2>
            <p class="read" style="margin: 0; font-size: 18px; color: #E9E1D6; max-width: 560px">A monthly gathering with Just-Joe Adeola for musicians, creatives and culture shapers — mindset, discipline and artistry, the spirituality of music, leadership for creatives, and open Q&amp;A.</p>
            <p style="margin: 6px 0 0; font-size: 15px; color: #D8CEC1">First Wednesday of every month · 90 minutes · Google Meet · open to all</p>
            <div style="display: flex; gap: 10px; flex-wrap: wrap; margin-top: 10px">{pill('#register', 'Register free <span aria-hidden="true">→</span>', 'gold')}</div>
          </div>
          <div class="reveal" style="flex: 1 1 340px; min-width: 0; display: flex; flex-direction: column; gap: 6px; padding: clamp(24px, 3vw, 40px); border-radius: 20px; border: 1px solid rgba(252,248,243,.16); background: rgba(252,248,243,.04)">
            <p style="margin: 0; font-size: 13px; font-weight: 600; letter-spacing: .14em; text-transform: uppercase; color: #F2A93B">Next session</p>
            <p style="margin: 0; {SER}; font-size: clamp(96px, 10vw, 148px); line-height: .9">{{{{ ppDays }}}}</p>
            <p style="margin: 0; font-size: 17px">{{{{ ppUnit }}}}</p>
            <p style="margin: 14px 0 0; padding-top: 14px; border-top: 1px solid rgba(252,248,243,.14); {SER}; font-size: 26px">{{{{ ppDate }}}}</p>
          </div>
        </div>
      </div>
    </section>

    <section id="term" aria-labelledby="term-h" style="{WRAP}; {SEC}; display: flex; flex-direction: column; gap: 56px">
      {head('term-h', 'Rhythm of the year', 'A term at MAM', 'Every term follows the same shape, so students and families always know what comes next.')}
      <ol class="stagger" style="list-style: none; margin: 0; padding: 0; display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr))">
        <sc-for list="{{{{ term }}}}" as="h" hint-placeholder-count="4"><li style="position: relative; padding: 36px 28px 8px 0; border-top: 1px solid #CFC3B3; display: flex; flex-direction: column; gap: 10px"><span aria-hidden="true" style="position: absolute; top: -7px; left: 0; width: 13px; height: 13px; border-radius: 50%; background: #F2A93B; box-shadow: 0 0 0 5px #FAF8F4"></span><span style="font-size: 13px; font-weight: 600; letter-spacing: .12em; text-transform: uppercase; color: #8A5208">{{{{ h.when }}}}</span><h3 style="margin: 0; {SER}; font-weight: 500; font-size: 34px; line-height: 1.05">{{{{ h.t }}}}</h3><p style="margin: 0; font-size: 15px; color: #574B40; max-width: 280px">{{{{ h.d }}}}</p></li></sc-for>
      </ol>
    </section>

    <section id="events" aria-labelledby="ev-h" style="background: #F3EEE6">
      <div style="{WRAP}; {SEC}; display: flex; flex-direction: column; gap: 56px">
        {head('ev-h', 'All year', 'Stages, intensives and gatherings')}
        <div style="display: flex; flex-direction: column; gap: clamp(48px, 6vw, 96px)">
          <sc-for list="{{{{ events }}}}" as="e" hint-placeholder-count="3"><article class="erow reveal" style="{{{{ e.row }}}}"><div style="flex: 1.2 1 440px; min-width: 0; overflow: hidden; border-radius: 18px">{photo('{{ e.photo }}', '16 / 10')}</div><div style="flex: 1 1 360px; min-width: 0; display: flex; flex-direction: column; gap: 12px"><p style="margin: 0; font-size: 13px; font-weight: 600; letter-spacing: .12em; text-transform: uppercase; color: #8A5208">{{{{ e.when }}}}</p><h3 style="margin: 0; {SER}; font-weight: 500; font-size: clamp(38px, 3.6vw, 56px); line-height: 1">{{{{ e.t }}}}</h3><p style="margin: 0; font-size: 17px; color: #574B40; max-width: 460px">{{{{ e.d }}}}</p><a href="{{{{ e.href }}}}" class="ul" style="align-self: flex-start; display: inline-flex; align-items: center; gap: 8px; min-height: 44px; font-weight: 600; color: #211A13; text-decoration: none">{{{{ e.cta }}}} <span aria-hidden="true">→</span></a></div></article></sc-for>
        </div>
      </div>
    </section>
''' + split('tools', 'Between lessons', 'Practise, track and connect', 'The work continues between classes — with tools for students and families.', f'''<ul style="list-style: none; margin: 0; padding: 0; border-bottom: 1px solid #E3DACD">
          <sc-for list="{{{{ tools }}}}" as="t" hint-placeholder-count="3"><li><a href="{{{{ t.href }}}}" class="lrow" style="display: flex; justify-content: space-between; align-items: center; gap: 24px; padding: 28px 0; border-top: 1px solid #E3DACD; color: #211A13; text-decoration: none"><span style="display: flex; flex-direction: column; gap: 4px"><span class="lname" style="{SER}; font-size: clamp(30px, 2.8vw, 42px); line-height: 1.05">{{{{ t.t }}}}</span><span style="font-size: 15px; color: #574B40">{{{{ t.d }}}}</span></span><span aria-hidden="true" style="flex: none; width: 48px; height: 48px; border-radius: 50%; border: 1px solid #CFC3B3; display: flex; align-items: center; justify-content: center">→</span></a></li></sc-for>
        </ul>''') + closing('Join the community.', 'Start with a free Power Play session, or apply for the next intake.', ('Apply.dc.html', 'Start your application'), ('#powerplay', 'Join Power Play'))
life_vals = """(levels) => {
      const now = new Date(), today = new Date(now.getFullYear(), now.getMonth(), now.getDate());
      const firstWed = (y, m) => { const d = new Date(y, m, 1); d.setDate(1 + ((3 - d.getDay() + 7) % 7)); return d; };
      let nx = firstWed(today.getFullYear(), today.getMonth()); if (nx < today) nx = firstWed(today.getFullYear(), today.getMonth() + 1);
      const days = Math.round((nx - today) / 864e5);
      return {
        ppDays: days === 0 ? 'Today' : String(days), ppUnit: days === 0 ? 'Join us this evening' : (days === 1 ? 'day to go' : 'days to go'),
        ppDate: nx.toLocaleDateString('en-GB', { weekday: 'long', day: 'numeric', month: 'long' }),
        term: [{ when: 'Week 1', t: 'Assess & plan', d: 'New students take the MAM Auditer; everyone gets term goals.' }, { when: 'Every week', t: 'Lessons', d: 'Small classes, online or in person, with practice set between.' }, { when: 'Mid-term', t: 'Check-in', d: 'Progress reviewed against your plan; families get an update.' }, { when: 'Term end', t: 'Showcase', d: 'Every student performs. Families and friends are invited.' }],
        events: [
          { when: 'Every term', t: 'Student Showcase', d: 'The term ends on a stage. Every student performs — first notes and diploma recitals alike.', photo: 'student showcase', href: 'Apply.dc.html', cta: 'Start this term' },
          { when: '12 weeks · $60', t: 'The MetaMusic Order', d: 'A post-diploma intensive for cultural architects, psychological influencers, national builders and MetaMusicians.', photo: 'MetaMusic Order cohort', href: 'Programmes.dc.html#intensives', cta: 'See the intensive' },
          { when: '6 weeks · $70', t: 'Crash Programme', d: 'A focused intensive in any discipline, online or in person. No levels needed.', photo: 'crash programme class', href: 'Programmes.dc.html#intensives', cta: 'See the intensive' }
        ].map((e, i) => Object.assign(e, { row: 'display: flex; flex-wrap: wrap; align-items: center; gap: 32px clamp(32px, 5vw, 80px); flex-direction: ' + (i % 2 ? 'row-reverse' : 'row') })),
        tools: [{ t: 'Practice Studio', d: 'Notation, rhythm and ear-training tools between lessons.', href: '#studio' }, { t: 'Student & parent portals', d: 'Timetables, attendance, termly reports and fees.', href: '#signin' }, { t: 'Store', d: 'Books, workbooks and essentials chosen by our faculty.', href: '#store' }]
      };
    }"""
build('Life.dc.html', 'MAM Academy — Life at MAM', 'life', life, '{}', '', life_vals, 5000)
print('ok')
