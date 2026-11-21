from kit import *
W = f"max-width: 1200px; margin: 0 auto; {PAD}; box-sizing: border-box"
def ph(label, ratio='3 / 2'):
    return f'<div role="img" aria-label="Photo placeholder: {label}" style="aspect-ratio: {ratio}; border-radius: 14px; background: linear-gradient(135deg, #E8DECF, #CDB89C); display: flex; align-items: flex-end; padding: 16px; box-sizing: border-box; font-size: 13px; color: #574B40">Photo — {label} (upload)</div>'
def mword(text):
    ws = text.replace('&amp;', '&').split(' ')
    return ''.join('<span class="mask" aria-hidden="true"><span class="sw" style="animation-range: entry %d%% entry %d%%">%s</span></span>%s' % (min(40, i*5), min(100, 60+i*5), w.replace('&', '&amp;'), ' ' if i < len(ws)-1 else '') for i, w in enumerate(ws))
def more(href, label, sub):
    return f'<a href="{href}" class="lrow" style="display: inline-flex; flex-direction: column; gap: 2px; margin-top: 8px; color: #211A13; text-decoration: none"><span style="display: inline-flex; align-items: center; gap: 8px; font-weight: 600; font-size: 17px"><span class="ul">{label}</span> <span aria-hidden="true" style="color: #8A5208">→</span></span><span style="font-size: 14px; color: #574B40">{sub}</span></a>'
def quote(html):
    return f'<blockquote style="margin: 8px 0 0; padding: 4px 0 4px 24px; border-left: 3px solid #C27A12; {SER}; font-size: clamp(22px, 1.9vw, 28px); line-height: 1.35; color: #211A13">{html}</blockquote>'
def block(i, title, paras, q, link, photo):
    rev = i % 2 == 1
    text = f'''<div style="flex: 1 1 440px; min-width: 0; display: flex; flex-direction: column; gap: 16px">
          <h3 id="b{i}-h" aria-label="{title.replace('&amp;', '&')}" style="margin: 0; {SER}; font-weight: 500; font-size: clamp(32px, 3vw, 44px); line-height: 1.1">{mword(title)}</h3>
          {''.join(f'<p class="read" style="margin: 0; font-size: 18px; line-height: 1.7; color: #3E342B">{p}</p>' for p in paras)}
          {quote(q) if q else ''}
          {more(*link)}
        </div>'''
    img = f'<div class="reveal" style="flex: 1 1 440px; min-width: 0">{ph(photo)}</div>'
    return f'''    <section aria-labelledby="b{i}-h" style="{W}; padding-top: clamp(48px, 6vw, 88px); padding-bottom: clamp(48px, 6vw, 88px); border-top: 1px solid #E6DED2">
      <div style="display: flex; flex-wrap: wrap; gap: 40px clamp(40px, 6vw, 96px); align-items: center; flex-direction: {'row-reverse' if rev else 'row'}">
        {text}
        {img}
      </div>
    </section>
'''
blocks = [
 ('Excellence across four schools of learning', ['Every programme draws on four schools: Instrumental, Performance, Rhythm, and MetaMusic &amp; Consciousness. Students grow as players, performers, readers and thinkers at the same time.'],
  'Instrumental Intelligence · Performance Intelligence · Rhythm Intelligence · MetaMusic &amp; Consciousness', ('Programmes.dc.html', 'Learn more about our programmes', 'Programmes, levels and fees'), 'students in a group lesson'),
 ('Every student is heard first', ['Before training begins, each applicant takes a MAM Auditer assessment. We listen to how they play, hear, move and perform, and place them at the level where they will grow fastest.'],
  'Ten dimensions of musical readiness, measured on entry and reported every term.', ('Auditer.dc.html', 'Learn more about the MAM Auditer', 'How the assessment works'), 'an assessor listening to a student'),
 ('A method of our own', ['The MAM Method is a complete system, not just a curriculum. Five pillars shape every lesson at every level, from a four-year-old’s first notes to a diploma recital.'],
  'Technique · Rhythm Intelligence · Performance Intelligence · Creative Consciousness · Artistic Discipline', ('Programmes.dc.html#levels', 'Learn more about the four levels', 'From Pre-Certificate to Diploma'), 'a student practising at the piano'),
 ('Taught by working musicians', ['Our faculty are performers, worship leaders and composers who are active in their craft. The academy is led by its founder, Van. BOJA, who also leads The MetaMusic Order and the monthly Power Play series.'],
  '', ('#learn-more', 'Meet our leadership and faculty', 'Founder, directors and lead faculty'), 'faculty performing together'),
 ('Support that makes a difference', ['Cost should not decide who becomes a musician. The NGV Scholarship supports talented students for whom tuition is the only barrier.'],
  '', ('Apply.dc.html', 'Ask about the scholarship when you apply', 'Admissions and support'), 'a student smiling after a lesson'),
 ('A community beyond lessons', ['Every term ends in a showcase where every student performs. Each month, Power Play brings musicians, creatives and culture shapers together online — free and open to all.'],
  'Music that builds people.', ('Life.dc.html', 'Learn more about life at MAM', 'Showcases, Power Play and intensives'), 'the termly showcase'),
]
body = f'''    <section aria-labelledby="page-h" style="{W}; padding-top: clamp(40px, 5vw, 72px); display: flex; flex-direction: column; gap: 20px">
      <nav aria-label="Breadcrumb" class="fade-in" style="font-size: 14px; color: #574B40; display: flex; gap: 8px"><a href="Main.dc.html" class="ul" style="color: #574B40; text-decoration: none">Home</a><span aria-hidden="true">/</span><span aria-current="page">About</span></nav>
      <h1 id="page-h" aria-label="About MAM Academy" style="margin: 0; {SER}; font-weight: 500; font-size: clamp(52px, 6.4vw, 96px); line-height: 1; letter-spacing: -.01em">{words('About MAM Academy', (2,)).replace('color: #F2A93B', 'color: #8A5208')}</h1>
    </section>
    <div class="fade-in" style="animation-delay: .25s; max-width: 1440px; margin: clamp(28px, 3vw, 44px) auto 0; padding: 0 clamp(8px, 1vw, 14px)"><div role="img" aria-label="Photo placeholder: MAM students and faculty on stage" style="height: clamp(260px, 42vw, 620px); border-radius: clamp(14px, 1.6vw, 22px); background: linear-gradient(135deg, #4A3726, #2E2118); display: flex; align-items: flex-end; padding: 24px; box-sizing: border-box; font-size: 13px; color: rgba(252,248,243,.75)">Photo — MAM students and faculty on stage after a showcase (upload)</div></div>

    <section aria-labelledby="intro-h" style="{W}; padding-top: clamp(64px, 8vw, 120px); padding-bottom: clamp(48px, 6vw, 88px)">
      <div style="max-width: 820px; display: flex; flex-direction: column; gap: 20px">
        <h2 id="intro-h" aria-label="A creative intelligence and performance academy" style="margin: 0; {SER}; font-weight: 500; font-size: clamp(38px, 4vw, 58px); line-height: 1.08">{mword('A creative intelligence and performance academy')}</h2>
        <p class="read" style="margin: 0; font-size: 19px; line-height: 1.7; color: #3E342B">MAM Academy is not merely a music school. Since 2008, as the training arm of MAM Centre, it has formed musicians, performers, worship leaders, composers, conductors and creative thinkers — people who can play, perform, read, lead and think.</p>
        <p class="read" style="margin: 0; font-size: 19px; line-height: 1.7; color: #3E342B">Students learn online or in person, through four levels equivalent to Trinity College London Grades 1–8, guided by a method of our own and taught by working musicians.</p>
        {more('#learn-more', 'Our history', 'Learn more about the history of MAM Academy')}
      </div>
    </section>
''' + ''.join(block(i, *b) for i, b in enumerate(blocks)) + f'''
    <section id="learn-more" aria-labelledby="lm-h" style="background: #F3EEE6; margin-top: clamp(24px, 3vw, 48px)">
      <div style="{W}; padding-top: clamp(64px, 8vw, 112px); padding-bottom: clamp(64px, 8vw, 112px); display: flex; flex-direction: column; gap: 28px">
        <h2 id="lm-h" aria-label="Learn more" style="margin: 0; {SER}; font-weight: 500; font-size: clamp(38px, 4vw, 58px); line-height: 1.08">{mword('Learn more')}</h2>
        <p class="read" style="margin: 0; font-size: 18px; line-height: 1.7; color: #3E342B; max-width: 760px">MAM Academy was commissioned in 2008 as the training arm of MAM Centre. Its vision: to interpret the timeless using instruments, and replay it in time.</p>
        <div class="stagger" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 16px">
          <a href="#leadership" class="lift" style="display: flex; flex-direction: column; gap: 8px; padding: 28px; border-radius: 16px; background: #FFFFFF; border: 1px solid #E6DED2; color: #211A13; text-decoration: none"><span style="{SER}; font-size: 30px; font-weight: 500">Leadership &amp; faculty</span><span style="font-size: 16px; color: #574B40">Meet the founder, directors and lead faculty who shape the academy.</span><span aria-hidden="true" style="margin-top: 8px; color: #8A5208; font-weight: 600">→</span></a>
          <a href="#history" class="lift" style="display: flex; flex-direction: column; gap: 8px; padding: 28px; border-radius: 16px; background: #FFFFFF; border: 1px solid #E6DED2; color: #211A13; text-decoration: none"><span style="{SER}; font-size: 30px; font-weight: 500">Our history</span><span style="font-size: 16px; color: #574B40">From a training arm of MAM Centre in 2008 to an academy online and in person.</span><span aria-hidden="true" style="margin-top: 8px; color: #8A5208; font-weight: 600">→</span></a>
        </div>
      </div>
    </section>
'''
html = page('MAM Academy — About', 'about', body, '{}', '', '(levels) => ({})', 5200)
open('/home/claude/mam/project/About.dc.html', 'w').write(html)
print('ok')
