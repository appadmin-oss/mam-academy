# Shared "smart effects" layer, applied to every generated page (and the homepage) after generation.
import re, sys
CSS = r'''<style data-fx>
@view-transition{navigation:auto}
::view-transition-old(root){animation:.3s ease both fxVtOut}
::view-transition-new(root){animation:.6s cubic-bezier(.19,1,.22,1) both fxVtIn}
@keyframes fxVtOut{to{opacity:0}}
@keyframes fxVtIn{from{opacity:0;transform:translateY(14px)}}
@supports (animation-timeline: view()){[data-fx-img]{animation:fxImg linear both;animation-timeline:view();animation-range:entry 0% entry 80%}}
@keyframes fxImg{from{clip-path:inset(9% 7% 9% 7% round 26px);filter:saturate(.6) brightness(.96)}to{clip-path:inset(0 0 0 0 round 0);filter:none}}
.spot::after{content:"";position:absolute;inset:0;border-radius:inherit;pointer-events:none;z-index:1;background:radial-gradient(380px circle at var(--mx,50%) var(--my,50%),var(--spot,rgba(242,169,59,.16)),transparent 62%);opacity:0;transition:opacity .5s ease}
.spot:hover::after{opacity:1}
nav[aria-label="On this page"]{transition:top .55s cubic-bezier(.19,1,.22,1)}
nav[aria-label="On this page"] a.on{color:#8A5208!important;background-size:100% 1px!important}
[data-smart]{transition:transform .55s cubic-bezier(.19,1,.22,1),box-shadow .4s ease}
.fx-hide [data-smart]{transform:translateY(-100%)}
.fx-hide nav[aria-label="On this page"]{top:0!important}
@media (prefers-reduced-motion: reduce){::view-transition-old(root),::view-transition-new(root){animation:none}[data-fx-img]{animation:none!important}[data-smart]{transition:none}}
</style>'''
JS = r'''function mamFx() {
  if (window.__mamFx) return; window.__mamFx = true;
  var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  // Spotlight: a soft warm light follows the pointer across cards
  document.addEventListener('pointermove', function (e) {
    var t = e.target && e.target.closest ? e.target.closest('.lift, .zoom, .bento > *, [data-spot]') : null; if (!t) return;
    if (!t.classList.contains('spot')) { if (getComputedStyle(t).position === 'static') t.style.position = 'relative'; var bg = getComputedStyle(t).backgroundColor, m = bg.match(/\d+/g); if (m && (+m[0] + +m[1] + +m[2]) < 200) t.style.setProperty('--spot', 'rgba(242,169,59,.22)'); t.classList.add('spot'); }
    var r = t.getBoundingClientRect(); t.style.setProperty('--mx', (e.clientX - r.left) + 'px'); t.style.setProperty('--my', (e.clientY - r.top) + 'px');
  }, { passive: true });
  // Smart header: steps aside while you read down, returns the moment you scroll up
  if (document.querySelector('[data-smart]') && !reduce) {
    var last = window.scrollY || 0;
    window.addEventListener('scroll', function () { var y = window.scrollY || 0; if (Math.abs(y - last) < 6) return; document.documentElement.classList.toggle('fx-hide', y > last && y > 520); last = y; }, { passive: true });
  }
  // Scroll-spy: the section you are reading is marked in the page's local navigation
  var nav = document.querySelector('nav[aria-label="On this page"]');
  if (nav && window.IntersectionObserver) {
    var links = [].slice.call(nav.querySelectorAll('a[href^="#"]')), map = {};
    links.forEach(function (a) { var el = document.getElementById(a.getAttribute('href').slice(1)); if (el) map[el.id] = a; });
    var io = new IntersectionObserver(function (es) { es.forEach(function (en) { if (!en.isIntersecting) return; links.forEach(function (a) { a.classList.remove('on'); a.removeAttribute('aria-current'); }); var a = map[en.target.id]; if (a) { a.classList.add('on'); a.setAttribute('aria-current', 'location'); var ul = a.closest('div'); if (ul && ul.scrollWidth > ul.clientWidth) ul.scrollTo({ left: a.offsetLeft - 40, behavior: reduce ? 'auto' : 'smooth' }); } }); }, { rootMargin: '-40% 0px -55% 0px' });
    Object.keys(map).forEach(function (id) { io.observe(document.getElementById(id)); });
  }
}
'''
def apply(path, full=True):
    s = open(path).read()
    if 'data-fx>' in s: s = re.sub(r'<style data-fx>.*?</style>', '', s, flags=re.S)
    s = s.replace('</helmet>', CSS + '\n</helmet>', 1)
    if not full:
        open(path, 'w').write(s); return
    s = s.replace('<div class="fx-progress" aria-hidden="true"></div>\n  ', '')
    # image reveals (skip the first placeholder: it is the hero, already in view)
    n = [0]
    def tag(m):
        n[0] += 1
        return m.group(0) if n[0] == 1 or 'data-fx-img' in m.group(0) else m.group(0).replace('role="img"', 'data-fx-img role="img"', 1)
    s = re.sub(r'role="img" aria-label="Photo placeholder', tag, s)
    s = s.replace('<div class="ph" ', '<div class="ph" data-fx-img ')
    # smart header on inner pages (sticky 72px bar)
    s = s.replace('<header style="position: sticky; top: 0; z-index: 40; height: 72px;', '<header data-smart style="position: sticky; top: 0; z-index: 40; height: 72px;', 1)
    if 'function mamFx()' not in s:
        s = s.replace('class Component extends DCLogic {', JS + 'class Component extends DCLogic {', 1)
        s = re.sub(r'componentDidMount\(\) \{', 'componentDidMount() { mamFx();', s, count=1)
    open(path, 'w').write(s)
if __name__ == '__main__':
    P = '/home/claude/mam/project/'
    for f in ['Main', 'Programmes', 'About', 'Auditer', 'Life']: apply(P + f + '.dc.html')
    apply(P + 'Apply.dc.html', full=False)
    print('fx applied')
