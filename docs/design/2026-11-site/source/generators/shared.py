# Shared building blocks for every MAM page: helmet CSS, header (full bar), footer, shared logic.
import re, json
MAIN = open('/home/claude/mam/project/Main.dc.html').read()

STYLE = MAIN[MAIN.index('<style>'):MAIN.index('</style>')+len('</style>')]

# programmes panel (from the homepage header), with page links
_nav = MAIN[MAIN.index('  <!-- ================= NAV'):MAIN.index('  <main id="main">')]
PANEL = _nav[_nav.index('      <sc-if value="{{ menuOpen }}"'):_nav.index('    </div>\n  </header>')]
PANEL = (PANEL.replace('href="#journey"', 'href="Programmes.dc.html#levels"', 4)
              .replace('href="#life"', 'href="Life.dc.html"')
              .replace('href="#admissions"', 'href="Apply.dc.html"')
              .replace('href="#about"', 'href="About.dc.html"'))
PANEL = PANEL.replace('<li><a href="#journey"', '<li><a href="Programmes.dc.html#instruments"').replace('href="#journey" onClick="{{ closeMenu }}" class="zoom"', 'href="Auditer.dc.html" onClick="{{ closeMenu }}" class="zoom"')
PANEL = PANEL.replace('href="#journey"', 'href="Programmes.dc.html#levels"')

def header(active=''):
    def link(href, label, key):
        cur = ' aria-current="page"' if key == active else ''
        col = '#8A5208' if key == active else '#211A13'
        return f'<a href="{href}" class="ul"{cur} style="display: inline-flex; align-items: center; min-height: 44px; margin: 0 10px; font-size: 15px; color: {col}; text-decoration: none">{label}</a>'
    pcol = '#8A5208' if active == 'programmes' else '#211A13'
    return f'''  <header style="position: sticky; top: 0; z-index: 40; height: 72px; background: rgba(250,248,244,.92); backdrop-filter: saturate(1.3) blur(18px); -webkit-backdrop-filter: saturate(1.3) blur(18px); box-shadow: {{{{ headerShadow }}}}; transition: box-shadow .4s ease">
    <div style="position: relative">
      <div style="max-width: 1360px; margin: 0 auto; padding: 0 clamp(20px, 4vw, 40px); height: 72px; display: flex; align-items: center; justify-content: space-between; gap: 24px">
        <a href="Main.dc.html" style="display: flex; align-items: center; gap: 10px; min-height: 44px; text-decoration: none; color: #211A13">
          <img src="/_blob/00dfca51d0e87c346b5650eddf166e20" alt="" width="30" height="30" style="width: 30px; height: 30px">
          <span class="wm" style="font-family: 'Cormorant Garamond', Georgia, serif; font-size: 24px; font-weight: 600; line-height: 1; white-space: nowrap">MAM Academy</span>
        </a>
        <div style="display: flex; align-items: center; gap: 4px">
          <nav aria-label="Main" class="navlinks" style="align-items: center; gap: 4px; margin-right: 36px">
            <button type="button" class="ul" aria-expanded="{{{{ menuOpenAttr }}}}" aria-controls="programmes-panel" onClick="{{{{ toggleMenu }}}}" style="min-height: 44px; margin: 0 10px; padding: 0; border: 0; background-color: transparent; font: inherit; font-size: 15px; color: {pcol}; cursor: pointer; display: inline-flex; align-items: center; gap: 6px">Programmes <svg aria-hidden="true" width="11" height="11" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.6" style="{{{{ chevStyle }}}}"><path d="M2.5 4.5L6 8l3.5-3.5"></path></svg></button>
            {link('Apply.dc.html', 'Admissions', 'admissions')}
            {link('Life.dc.html', 'Life at MAM', 'life')}
            {link('About.dc.html', 'About', 'about')}
            {link('#signin', 'Sign in', 'signin')}
          </nav>
          <a href="Auditer.dc.html" class="btn navlinks" style="align-items: center; min-height: 40px; padding: 0 16px; border-radius: 10px; border: 1px solid #D6CCBE; color: #211A13; font-size: 15px; text-decoration: none">Book an assessment</a>
          <a href="Apply.dc.html" class="btn arrow" style="display: inline-flex; align-items: center; gap: 8px; min-height: 40px; padding: 0 18px; border-radius: 10px; background: #211A13; color: #FAF8F4; font-size: 15px; font-weight: 500; text-decoration: none">Apply <span aria-hidden="true">→</span></a>
          <button type="button" class="menubtn btn" aria-label="Menu" aria-expanded="{{{{ menuOpenAttr }}}}" aria-controls="programmes-panel" onClick="{{{{ toggleMenu }}}}" style="width: 44px; height: 44px; align-items: center; justify-content: center; background: none; border: 0; color: #211A13; cursor: pointer"><svg aria-hidden="true" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M4 8h16M4 16h16"></path></svg></button>
        </div>
      </div>
{PANEL}    </div>
  </header>
'''

FOOTER = MAIN[MAIN.index('  <!-- ================= FOOTER'):MAIN.index('</div>\n</x-dc>')]
FOOTER = FOOTER.replace('href="#apply"', 'href="Apply.dc.html"').replace('href="Main.dc.html"', 'href="Main.dc.html"')

# shared data + header logic (JS)
def _grab_js(start, end_token='];'):
    i = MAIN.index(start); j = MAIN.index(end_token, i) + len(end_token); return MAIN[i:j]
JS_DATA = '\n'.join([_grab_js('    const footerGroups = ['), _grab_js('    const socials = [')])
JS_DATA = (JS_DATA.replace("href: '#journey' }, { t: 'Admissions & fees', href: '#admissions' }, { t: 'MAM Auditer', href: '#journey' }",
                           "href: 'Programmes.dc.html' }, { t: 'Admissions & fees', href: 'Programmes.dc.html#fees' }, { t: 'MAM Auditer', href: 'Auditer.dc.html' }")
                  .replace("{ t: 'Events', href: '#life' }, { t: 'Power Play', href: '#life' }", "{ t: 'Events', href: 'Life.dc.html' }, { t: 'Power Play', href: 'Life.dc.html#powerplay' }")
                  .replace("{ t: 'Questions', href: '#questions' }", "{ t: 'Questions', href: 'Main.dc.html#questions' }")
                  .replace("{ t: 'Scholarships', href: '#scholarship' }", "{ t: 'Scholarships', href: 'Programmes.dc.html#scholarship' }"))
LEVELS_JS = """    const levels = [
      { n: '1', grades: 'Grades 1–2', name: 'Pre-Certificate', base: 120, desc: 'Foundations: technique, posture, rhythm and first performances.' },
      { n: '2', grades: 'Grades 3–4', name: 'Certificate', base: 130, desc: 'Fluent playing, sight reading and stage presentation.' },
      { n: '3', grades: 'Grades 5–6', name: 'Pre-Diploma', base: 160, desc: 'Advanced repertoire, improvisation and ensemble leadership.' },
      { n: '4', grades: 'Grades 7–8', name: 'Diploma', base: 180, desc: 'Professional mastery, conducting and artistic identity.' }
    ];"""
INSTR_JS = "['Piano & Keyboard', 'Voice & Choir', 'Violin & Strings', 'Guitar', 'Drums & Percussion', 'Wind & Brass', 'Music Theory & Ear', 'Performance']"

def script(state_init, extra_methods, extra_vals, height):
    return f"""<script type="text/x-dc" data-dc-script data-props='{{"$preview":{{"width":1440,"height":{height}}}}}'>
class Component extends DCLogic {{
  constructor(props) {{ super(props); this.state = Object.assign({{ menu: false, scrolled: false }}, {state_init}); this.onScroll = this.onScroll.bind(this); }}
  componentDidMount() {{ window.addEventListener('scroll', this.onScroll, {{ passive: true }}); this.onScroll(); }}
  componentWillUnmount() {{ window.removeEventListener('scroll', this.onScroll); }}
  onScroll() {{ const s = (window.scrollY || 0) > 8; if (s !== this.state.scrolled) this.setState({{ scrolled: s }}); }}
{extra_methods}
  renderVals() {{
{LEVELS_JS}
{JS_DATA}
    return Object.assign({{
      headerShadow: this.state.scrolled || this.state.menu ? '0 1px 0 rgba(33,26,19,.10)' : 'none',
      menuOpen: this.state.menu,
      menuOpenAttr: this.state.menu ? 'true' : 'false',
      chevStyle: 'transition: transform .3s; transform: rotate(' + (this.state.menu ? '180deg' : '0deg') + ')',
      toggleMenu: () => this.setState({{ menu: !this.state.menu }}),
      closeMenu: () => this.setState({{ menu: false }}),
      panelKey: (e) => {{ if (e.key === 'Escape') this.setState({{ menu: false }}); }},
      levels, instruments: {INSTR_JS}, footerGroups, socials
    }}, ({extra_vals})(levels));
  }}
}}
</script>"""

def page(title, active, body, state_init='{}', extra_methods='', extra_vals='() => ({})', height=4000):
    return f"""<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>{title}</title>
<script src="./support.js"></script>
</head>
<body>
<x-dc>
<helmet>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,400;0,500;0,600;0,700;1,400;1,500&amp;family=Inter:wght@400;500;600&amp;display=swap" rel="stylesheet">
{STYLE}
</helmet>
<div style="font-family: Inter, system-ui, sans-serif; color: #211A13; background: #FAF8F4; font-size: 16px; line-height: 1.6">
  <a href="#main" class="skip" style="position: absolute; left: 8px; top: -60px; z-index: 50; background: #FFFFFF; color: #211A13; padding: 12px 16px; border-radius: 6px">Skip to main content</a>
{header(active)}
  <main id="main">
{body}
  </main>
{FOOTER}</div>
</x-dc>
{script(state_init, extra_methods, extra_vals, height)}
</body>
</html>
"""

# small helpers for consistent section openers
def eyebrow(t, dark=False): return f'<p class="reveal" style="margin: 0; font-size: 13px; font-weight: 600; letter-spacing: .16em; text-transform: uppercase; color: {"#F2A93B" if dark else "#8A5208"}">{t}</p>'
def h2(id_, text, size=60, center=False):
    words = text.split(' ')
    spans = ''.join('<span class="mask" aria-hidden="true"><span class="sw" style="animation-range: entry %d%% entry %d%%">%s</span></span>%s' % (i*6, min(100, 60+i*6), w, ' ' if i < len(words)-1 else '') for i, w in enumerate(words))
    al = ' text-align: center;' if center else ''
    return f'<h2 id="{id_}" aria-label="{text}" style="margin: 0; font-family: \'Cormorant Garamond\', Georgia, serif; font-weight: 500; font-size: clamp({max(30, round(size*.56))}px, {size/14.4:.2f}vw, {size}px); line-height: 1.05;{al}">{spans}</h2>'
def page_hero(crumb, eyebrow_t, title_html, title_label, lead, extra=''):
    return f'''    <section aria-labelledby="page-h" style="max-width: 1360px; margin: 0 auto; padding: clamp(40px, 5vw, 72px) clamp(20px, 4vw, 40px) clamp(56px, 7vw, 104px); display: flex; flex-direction: column; gap: 24px">
      <nav aria-label="Breadcrumb" class="fade-in" style="font-size: 14px; color: #574B40; display: flex; gap: 8px; flex-wrap: wrap"><a href="Main.dc.html" class="ul" style="color: #574B40; text-decoration: none">Home</a><span aria-hidden="true">/</span><span aria-current="page">{crumb}</span></nav>
      <p class="fade-in" style="animation-delay: .1s; margin: 24px 0 0; font-size: 13px; font-weight: 600; letter-spacing: .16em; text-transform: uppercase; color: #8A5208">{eyebrow_t}</p>
      <h1 id="page-h" aria-label="{title_label}" class="fade-in" style="animation-delay: .18s; margin: 0; font-family: 'Cormorant Garamond', Georgia, serif; font-weight: 500; font-size: clamp(46px, 5.6vw, 80px); line-height: 1.02; letter-spacing: -.01em; max-width: 980px">{title_html}</h1>
      <p class="fade-in" style="animation-delay: .3s; margin: 0; font-size: 19px; color: #574B40; max-width: 640px">{lead}</p>
{extra}    </section>
'''
