/* Shared effects layer. Call mamFx() once after the page mounts. */
function mamFx() {
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

if (typeof module !== "undefined") module.exports = { mamFx };
