from shared import *
SER = "font-family: 'Cormorant Garamond', Georgia, serif"
PAD = "padding-left: clamp(20px, 4vw, 40px); padding-right: clamp(20px, 4vw, 40px)"
WRAP = f"max-width: 1360px; margin: 0 auto; {PAD}; box-sizing: border-box"
SEC = "padding-top: clamp(72px, 10vw, 144px); padding-bottom: clamp(72px, 10vw, 144px)"

def words(text, gold=()):
    out = []; ws = text.split(' ')
    for i, w in enumerate(ws):
        g = ' font-style: italic; color: #F2A93B;' if i in gold else ''
        out.append(f'<span class="mask" aria-hidden="true"><span class="w" style="animation-delay: {0.15 + i*0.07:.2f}s;{g}">{w}</span></span>' + (' ' if i < len(ws)-1 else ''))
    return ''.join(out)

def cine_hero(crumb, eyebrow_t, title, gold, lead, photo, ctas='', subnav=(), img=None):
    sn = ''
    if subnav:
        links = ''.join(f'<li><a href="{h}" class="ul" style="display: inline-flex; align-items: center; min-height: 44px; padding: 0 4px; color: #211A13; text-decoration: none; white-space: nowrap">{l}</a></li>' for h, l in subnav)
        sn = f'''    <nav aria-label="On this page" style="position: sticky; top: 72px; z-index: 30; background: rgba(250,248,244,.92); backdrop-filter: blur(14px); -webkit-backdrop-filter: blur(14px); border-bottom: 1px solid #ECE5DA">
      <div style="{WRAP}; display: flex; align-items: center; gap: 28px; overflow-x: auto; scrollbar-width: none">
        <span class="hide-sm" style="flex: none; font-size: 13px; font-weight: 600; letter-spacing: .14em; text-transform: uppercase; color: #8A5208">{crumb}</span>
        <ul style="list-style: none; margin: 0; padding: 0; display: flex; gap: 20px; font-size: 15px">{links}</ul>
      </div>
    </nav>
'''
    if img:
        bg = f'''      <div aria-hidden="true" style="position: absolute; inset: 0; z-index: -2; background: radial-gradient(50% 80% at 78% 75%, #F0802A 0%, rgba(170,74,20,.6) 40%, rgba(20,12,7,0) 75%), #140C07"></div>
      <img class="hero-cut" src="{img[0]}" alt="{img[1]}" style="position: absolute; z-index: -1; right: clamp(-40px, 2vw, 60px); bottom: 0; height: 104%; width: auto; animation: cutIn 1.6s var(--out) both">
      <div aria-hidden="true" style="position: absolute; inset: 0; z-index: -1; background: linear-gradient(90deg, rgba(20,12,7,.85) 0%, rgba(20,12,7,.35) 45%, rgba(20,12,7,0) 65%)"></div>
'''
        ctas_in, ctas_out = (f'<div class="fade-in" style="animation-delay: .8s; display: flex; gap: 12px; flex-wrap: wrap; margin-top: 8px">{ctas}</div>' if ctas else ''), ''
    else:
        bg = OLD_BG.replace('{photo}', photo)
        ctas_in, ctas_out = '', (f'<div class="fade-in" style="animation-delay: .8s; display: flex; gap: 12px; flex-wrap: wrap">{ctas}</div>' if ctas else '')
    return f'''    <div style="padding: 0 clamp(8px, 1vw, 14px)">
    <section aria-labelledby="page-h" style="position: relative; isolation: isolate; overflow: hidden; min-height: clamp(520px, 78svh, 700px); display: flex; align-items: flex-end; border-radius: clamp(14px, 1.6vw, 22px); background: #3B2C1F">
{bg}      <div class="hero-copy" style="{WRAP}; width: 100%; padding-bottom: clamp(40px, 5vw, 72px); display: flex; flex-wrap: wrap; align-items: flex-end; justify-content: space-between; gap: 24px 48px; color: #FCF8F3">
        <div style="flex: 1 1 620px; min-width: 0; max-width: {'640px' if img else 'none'}; display: flex; flex-direction: column; gap: 18px">
          <nav aria-label="Breadcrumb" class="fade-in" style="font-size: 14px; color: rgba(252,248,243,.75); display: flex; gap: 8px"><a href="Main.dc.html" class="ul" style="color: rgba(252,248,243,.85); text-decoration: none">Home</a><span aria-hidden="true">/</span><span aria-current="page">{crumb}</span></nav>
          <h1 id="page-h" aria-label="{title}" style="margin: 0; {SER}; font-weight: 500; font-size: clamp(46px, 5.6vw, 84px); line-height: 1.02; letter-spacing: -.01em; max-width: 900px">{words(title, gold)}</h1>
          <p class="fade-in" style="animation-delay: .6s; margin: 0; font-size: 19px; color: rgba(252,248,243,.88); max-width: 580px">{lead}</p>
          {ctas_in}
        </div>
        {ctas_out}
      </div>
    </section>
    </div>
{sn}'''

OLD_BG = '      <div role="img" aria-label="Photo placeholder: {photo}" style="position: absolute; inset: 0; z-index: -2; background: linear-gradient(135deg, #4A3726 0%, #2E2118 100%); display: flex; align-items: flex-start; justify-content: flex-end; padding: 28px clamp(20px, 4vw, 40px); animation: kenburns 2.6s var(--out) both"><span style="font-size: 13px; color: rgba(252,248,243,.75); border: 1px dashed rgba(252,248,243,.4); padding: 6px 12px; border-radius: 6px">Photo — {photo} (upload)</span></div>\n      <div aria-hidden="true" style="position: absolute; inset: 0; z-index: -1; background: linear-gradient(0deg, rgba(23,16,9,.88) 0%, rgba(23,16,9,.3) 60%, rgba(23,16,9,0) 100%)"></div>\n'
HERO_CSS = '<style>@keyframes cutIn{from{opacity:0;transform:translateY(6%) scale(.98)}to{opacity:1;transform:none}}@media (max-width: 900px){.hero-cut{opacity:.45;right:-30%!important;height:80%!important}}@media (prefers-reduced-motion: reduce){.hero-cut{animation:none!important}}</style>'
def pill(href, label, kind='gold'):
    st = {'gold': 'background: #F2A93B; color: #211A13', 'ghost': 'border: 1px solid rgba(252,248,243,.6); color: #FCF8F3', 'dark': 'background: #211A13; color: #FAF8F4', 'line': 'border: 1px solid #CFC3B3; color: #211A13'}[kind]
    return f'<a href="{href}" class="btn" style="display: inline-flex; align-items: center; gap: 8px; min-height: 48px; padding: 0 24px; border-radius: 999px; {st}; font-weight: 600; text-decoration: none">{label}</a>'

def statement(text, sub=''):
    return f'''    <section aria-label="Overview" style="{WRAP}; {SEC}">
      <p class="read" style="margin: 0; {SER}; font-weight: 500; font-size: clamp(30px, 3.4vw, 50px); line-height: 1.18; max-width: 1100px">{text}</p>
      {f'<p class="read" style="margin: 28px 0 0; font-size: 17px; color: #574B40; max-width: 620px">{sub}</p>' if sub else ''}
    </section>
'''

def split(id_, eyebrow_t, title, intro, body, bg='', dark=False):
    col = '#FCF8F3' if dark else '#211A13'; mut = '#D8CEC1' if dark else '#574B40'
    return f'''    <section id="{id_}" aria-labelledby="{id_}-h" style="{bg}">
      <div style="{WRAP}; {SEC}; display: flex; flex-wrap: wrap; gap: 48px clamp(40px, 6vw, 112px); color: {col}">
        <div style="flex: 1 1 320px; max-width: 440px">
          <div class="stick" style="position: sticky; top: 150px; display: flex; flex-direction: column; gap: 16px">{eyebrow(eyebrow_t, dark)}{h2(id_ + '-h', title, 56)}<p class="read" style="margin: 0; font-size: 17px; color: {mut}">{intro}</p></div>
        </div>
        <div style="flex: 2 1 560px; min-width: 0">{body}</div>
      </div>
    </section>
'''

def closing(title, text, primary, secondary):
    return f'''    <section aria-labelledby="cta-h" style="{WRAP}; {SEC}; display: flex; flex-direction: column; align-items: center; text-align: center; gap: 22px; border-top: 1px solid #ECE5DA">
      {h2('cta-h', title, 72, True)}
      <p class="read" style="margin: 0; font-size: 18px; color: #574B40; max-width: 520px">{text}</p>
      <div class="reveal" style="display: flex; gap: 10px; flex-wrap: wrap; justify-content: center">{pill(primary[0], primary[1] + ' <span aria-hidden="true">→</span>', 'dark')}{pill(secondary[0], secondary[1], 'line')}</div>
    </section>
'''
