from kit import *
ns = {}; exec(open('/home/claude/gen/programmes.py').read(), ns)
fees = ns['body'][ns['body'].index('    <!-- fee estimator -->'):ns['body'].index('    <!-- instruments')]
fees = fees.replace("{eyebrow('Fee estimator')}", '')  # already formatted
CHK = '<svg aria-label="Included" role="img" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#8A5208" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12l5 5L20 7"></path></svg>'
DASH = '<span aria-label="Not included" role="img" style="color: #B9AC9B">—</span>'
rows = [('Main course, graded by level', 1, 1, 1), ('One expression: African, Contemporary or Classical', 1, 1, 0), ('All three expressions', 0, 0, 1),
        ('Four compulsory courses (theory, aural, sight-reading, performance)', 0, 1, 1), ('Certification track, Trinity-equivalent', 0, 1, 1),
        ('Termly showcase and MAM Auditer reports', 1, 1, 1), ('Add extra courses at 50% of base', 1, 0, 1)]
TH = "padding: 18px 20px; text-align: left; vertical-align: bottom; font-weight: 400"
trs = ''.join(f'<tr><th scope="row" style="padding: 16px 20px 16px 0; text-align: left; font-weight: 400; font-size: 15px; border-top: 1px solid #ECE5DA">{r[0]}</th>' + ''.join(f'<td style="padding: 16px 20px; border-top: 1px solid #ECE5DA;{" background: #FFFFFF;" if i == 1 else ""}">{CHK if r[i+1] else DASH}</td>' for i in range(3)) + '</tr>' for r in rows)
def col(name, kicker, price, note, hi=False):
    bg = ' background: #FFFFFF; border-radius: 18px 18px 0 0;' if hi else ''
    badge = '<span style="display: inline-block; margin-bottom: 10px; font-size: 12px; font-weight: 600; padding: 4px 10px; border-radius: 999px; background: #211A13; color: #FAF8F4">Most chosen</span><br>' if hi else ''
    return f'<th scope="col" style="{TH};{bg} width: 22%">{badge}<span style="font-size: 12px; font-weight: 600; letter-spacing: .1em; text-transform: uppercase; color: #8A5208">{kicker}</span><br><span style="{SER}; font-size: 30px; font-weight: 600; line-height: 1.1">{name}</span><br><span style="{SER}; font-size: 40px; font-weight: 600">{price}</span><span style="font-size: 14px; color: #574B40"> {note}</span></th>'
table = f'''<div style="overflow-x: auto; margin: 0 calc(-1 * clamp(20px, 4vw, 40px)); padding: 0 clamp(20px, 4vw, 40px)">
          <table class="reveal" style="width: 100%; min-width: 760px; border-collapse: collapse">
            <caption style="position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0)">Compare the three programmes</caption>
            <thead><tr><td style="width: 34%"></td>{col('Standard', 'One discipline', '$120', 'from / term')}{col('Certified Musician', 'Full certification', '$240', 'from / term', True)}{col('Grand Musician', 'Flagship', '$313', 'from / term')}</tr></thead>
            <tbody>{trs}<tr><td></td>''' + ''.join(f'<td style="padding: 20px;{" background: #FFFFFF; border-radius: 0 0 18px 18px;" if k == "certified" else ""}"><button type="button" onClick="{{{{ est.{k} }}}}" class="btn" style="min-height: 44px; padding: 0 18px; border-radius: 999px; border: 1px solid {"#211A13" if k == "certified" else "#CFC3B3"}; background: {"#211A13" if k == "certified" else "transparent"}; color: {"#FAF8F4" if k == "certified" else "#211A13"}; font: inherit; font-size: 15px; font-weight: 600; cursor: pointer">Estimate</button></td>' for k in ['standard', 'certified', 'grand']) + '''</tr></tbody>
          </table>
        </div>'''

journey = f'''<ol class="stagger jrny" style="list-style: none; margin: 0; padding: 0; display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 0">
          <sc-for list="{{{{ levels }}}}" as="l" hint-placeholder-count="4"><li style="position: relative; padding: 36px 28px 8px 0; border-top: 1px solid #CFC3B3; display: flex; flex-direction: column; gap: 10px">
            <span aria-hidden="true" style="position: absolute; top: -7px; left: 0; width: 13px; height: 13px; border-radius: 50%; background: #F2A93B; box-shadow: 0 0 0 5px #F3EEE6"></span>
            <span style="font-size: 13px; font-weight: 600; letter-spacing: .12em; text-transform: uppercase; color: #8A5208">Level {{{{ l.n }}}} · {{{{ l.grades }}}}</span>
            <h3 style="margin: 0; {SER}; font-weight: 500; font-size: clamp(32px, 2.8vw, 42px); line-height: 1">{{{{ l.name }}}}</h3>
            <p style="margin: 0; font-size: 15px; color: #574B40; max-width: 280px">{{{{ l.desc }}}}</p>
            <p style="margin: 6px 0 0; font-size: 14px; color: #574B40">Base fee <strong style="color: #211A13">${{{{ l.base }}}}</strong> a term</p>
          </li></sc-for>
        </ol>'''

instr = f'''<ul style="list-style: none; margin: 0; padding: 0; border-bottom: 1px solid #E3DACD">
          <sc-for list="{{{{ instrRows }}}}" as="i" hint-placeholder-count="8"><li class="irow" style="display: flex; align-items: baseline; justify-content: space-between; gap: 16px; padding: 18px 0; border-top: 1px solid #E3DACD"><span style="display: flex; align-items: baseline; gap: 20px"><span aria-hidden="true" style="font-size: 13px; color: #8C7F70; width: 22px">{{{{ i.n }}}}</span><span class="iname" style="{SER}; font-size: clamp(28px, 2.6vw, 40px); line-height: 1.1">{{{{ i.t }}}}</span></span><span class="hide-sm" style="font-size: 14px; color: #574B40; text-align: right">{{{{ i.d }}}}</span></li></sc-for>
        </ul>
        <div style="margin-top: 40px; display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 28px">
          <div><p style="margin: 0; {SER}; font-size: 26px; font-weight: 600">African</p><p style="margin: 4px 0 0; font-size: 15px; color: #574B40">Rhythms, voices and forms of the continent.</p></div>
          <div><p style="margin: 0; {SER}; font-size: 26px; font-weight: 600">Contemporary</p><p style="margin: 4px 0 0; font-size: 15px; color: #574B40">Gospel, pop, jazz and modern repertoire.</p></div>
          <div><p style="margin: 0; {SER}; font-size: 26px; font-weight: 600">Classical</p><p style="margin: 4px 0 0; font-size: 15px; color: #574B40">The concert tradition and its technique.</p></div>
        </div>'''

intens = f'''<div style="border-bottom: 1px solid #E3DACD">
          <article class="reveal" style="display: flex; flex-wrap: wrap; justify-content: space-between; align-items: flex-end; gap: 16px 40px; padding: 32px 0; border-top: 1px solid #E3DACD"><div style="flex: 1 1 360px"><p style="margin: 0; font-size: 13px; font-weight: 600; letter-spacing: .12em; text-transform: uppercase; color: #8A5208">6 weeks · any discipline · no levels</p><h3 style="margin: 6px 0 0; {SER}; font-weight: 500; font-size: 44px; line-height: 1.05">Crash Programme</h3><p style="margin: 6px 0 0; font-size: 16px; color: #574B40">A focused intensive, online or in person, for a fast start or a specific skill.</p></div><p style="margin: 0; {SER}; font-size: 56px; font-weight: 500; line-height: 1">$70<span style="font-family: Inter, sans-serif; font-size: 14px; color: #574B40"> flat</span></p></article>
          <article class="reveal" style="display: flex; flex-wrap: wrap; justify-content: space-between; align-items: flex-end; gap: 16px 40px; padding: 32px 0; border-top: 1px solid #E3DACD"><div style="flex: 1 1 360px"><p style="margin: 0; font-size: 13px; font-weight: 600; letter-spacing: .12em; text-transform: uppercase; color: #8A5208">12 weeks · post-diploma</p><h3 style="margin: 6px 0 0; {SER}; font-weight: 500; font-size: 44px; line-height: 1.05">The MetaMusic Order</h3><p style="margin: 6px 0 0; font-size: 16px; color: #574B40">From performer to architect: sound, culture and atmosphere. <a href="Life.dc.html" class="ul" style="color: #211A13; font-weight: 600; text-decoration: none">Learn more</a></p></div><p style="margin: 0; {SER}; font-size: 56px; font-weight: 500; line-height: 1">$60<span style="font-family: Inter, sans-serif; font-size: 14px; color: #574B40"> flat</span></p></article>
        </div>
        <p id="scholarship" style="margin: 28px 0 0; font-size: 15px; color: #574B40">Need support with tuition? The NGV Scholarship helps talented students for whom cost is the only barrier. <a href="Apply.dc.html" class="ul" style="color: #211A13; font-weight: 600; text-decoration: none">Ask about it when you apply</a></p>'''

body = cine_hero('Programmes', 'Programmes & fees', 'Find your path, and know your fee.', (2,), 'Three programmes, four levels, online or in person. Your level is set by your MAM Auditer assessment — your fee follows it.', 'students in a piano lesson',
  pill('#fees', 'Estimate my fee'), [('#programmes', 'Programmes'), ('#levels', 'Levels'), ('#fees', 'Fees'), ('#instruments', 'Instruments'), ('#intensives', 'Intensives')]) + \
  statement('You don’t buy a level — <em style="color: #8A5208">you grow through them.</em> Choose how broad you want your training to be, and we place you where you are today.') + \
  f'''    <section id="programmes" aria-labelledby="prog-h" style="{WRAP}; padding-bottom: clamp(72px, 10vw, 144px); display: flex; flex-direction: column; gap: 40px">
      <div style="display: flex; flex-wrap: wrap; justify-content: space-between; align-items: flex-end; gap: 16px 64px"><div style="display: flex; flex-direction: column; gap: 14px">{eyebrow('Three programmes')}{h2('prog-h', 'Compare at a glance')}</div><p class="read" style="margin: 0; font-size: 16px; color: #574B40; max-width: 400px">Prices shown at the starting level, in person. Online adds 20%.</p></div>
        {table}
    </section>
    <section id="levels" aria-labelledby="lev-h" style="background: #F3EEE6">
      <div style="{WRAP}; {SEC}; display: flex; flex-direction: column; gap: 56px">
        <div style="display: flex; flex-wrap: wrap; justify-content: space-between; align-items: flex-end; gap: 16px 64px"><div style="display: flex; flex-direction: column; gap: 14px">{eyebrow('Four levels')}{h2('lev-h', 'One continuous journey')}</div><p class="read" style="margin: 0; font-size: 16px; color: #574B40; max-width: 400px">Each level maps to Trinity College London grades and sets your base fee.</p></div>
        {journey}
      </div>
    </section>
''' + fees + split('instruments', 'What you can study', 'Instruments and expressions', 'Choose a primary course, then the tradition you want to grow in. Grand Musician students study all three.', instr) + \
  split('intensives', 'Short and focused', 'Intensives', 'No levels, no long commitment — a flat fee and a clear outcome.', intens, 'background: #F3EEE6') + \
  closing('Ready when you are.', 'Apply in about five minutes. Your assessment confirms your level and final fee.', ('Apply.dc.html', 'Start your application'), ('Auditer.dc.html', 'About the assessment'))

vals = ns['vals'].rstrip()
assert vals.endswith('};\n    }') or vals.endswith('}')
vals = vals.replace("      return {\n        plans:", """      const est = {}; ['standard', 'certified', 'grand'].forEach((k) => { est[k] = () => { this.setState({ prog: k }); const el = document.getElementById('fees'); if (el) el.scrollIntoView({ behavior: 'smooth' }); }; });
      const instrRows = [['Piano & Keyboard', 'Classical, gospel, jazz'], ['Voice & Choir', 'Solo, choir, worship'], ['Violin & Strings', 'Violin, viola, cello'], ['Guitar', 'Acoustic, electric, bass'], ['Drums & Percussion', 'Kit, African percussion'], ['Wind & Brass', 'Flute, sax, trumpet'], ['Music Theory & Ear', 'Reading, harmony, aural'], ['Performance', 'Stagecraft and presence']].map(([t, d], i) => ({ n: String(i + 1).padStart(2, '0'), t, d }));
      return {
        est, instrRows,
        plans:""")
CSS = '<style>.irow .iname{transition:transform .6s var(--out),color .3s ease}.irow:hover .iname{transform:translateX(10px);color:#8A5208}@media (max-width: 720px){.jrny>sc-for>li,.jrny>li{border-top:0!important;border-left:1px solid #CFC3B3;padding:0 0 32px 28px!important}.jrny span[aria-hidden]{top:4px!important;left:-7px!important}}</style>'
html = page('MAM Academy — Programmes & fees', 'programmes', body, "{ prog: 'certified', level: 0, mode: 'onsite', extras: 0, cur: 'USD' }", '', vals, 5200)
html = html.replace('</helmet>', CSS + '\n</helmet>', 1)
open('/home/claude/mam/project/Programmes.dc.html', 'w').write(html)
print('ok', 'est, instrRows' in html)
