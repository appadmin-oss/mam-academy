<footer style="background: #171009; color: #E9E1D6; font-size: 15px; overflow: hidden">
    <div style="max-width: 1360px; margin: 0 auto; padding: clamp(56px, 6.1vw, 88px) clamp(20px, 4vw, 40px) 0; display: flex; flex-direction: column; gap: 64px">
      <div style="display: flex; flex-wrap: wrap; gap: 40px 80px; justify-content: space-between; align-items: flex-end; padding-bottom: 56px; border-bottom: 1px solid rgba(252,248,243,.12)">
        <div style="flex: 1 1 420px; display: flex; flex-direction: column; gap: 16px">
          <p style="margin: 0; font-family: 'Cormorant Garamond', Georgia, serif; font-size: clamp(30px, 3.06vw, 44px); font-weight: 500; line-height: 1.1; color: #FCF8F3">Notes from the music room</p>
          <p style="margin: 0; color: #D8CEC1; max-width: 440px">One email a month: intake dates, showcases and Power Play sessions. No spam — unsubscribe any time.</p>
        </div>
        <form aria-label="Newsletter" data-newsletter action="/api/newsletter" method="post" style="position: relative; flex: 1 1 420px; display: flex; gap: 8px; flex-wrap: wrap">
          <label for="news-email" style="position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0)">Email address</label>
          <input id="news-email" name="email" type="email" required autocomplete="email" placeholder="you@example.com" style="flex: 1 1 240px; min-width: 0; height: 52px; box-sizing: border-box; padding: 0 18px; border-radius: 10px; border: 1px solid rgba(252,248,243,.22); background: rgba(252,248,243,.06); color: #FCF8F3; font: inherit">
          <button type="submit" class="btn" style="min-height: 52px; padding: 0 24px; border-radius: 10px; border: 0; background: #F2A93B; color: #211A13; font: inherit; font-weight: 600; cursor: pointer">Subscribe</button>
          <p data-newsletter-status role="status" aria-live="polite" style="position: absolute; left: 0; top: calc(100% + 8px); margin: 0; font-size: 14px; color: #D8CEC1"></p>
        </form>
      </div>

      <div style="display: flex; flex-wrap: wrap; gap: 48px 64px">
        <div style="flex: 1.2 1 280px; display: flex; flex-direction: column; gap: 20px">
          <a href="/" style="display: flex; align-items: center; gap: 12px; text-decoration: none; color: #FCF8F3"><img src="/mam-academy-logo.svg" alt="" width="40" height="40" style="width: 40px; height: 40px"><span style="font-family: 'Cormorant Garamond', Georgia, serif; font-size: 28px; font-weight: 600">MAM Academy</span></a>
          <p style="margin: 0; color: #D8CEC1; max-width: 320px">A creative intelligence and performance academy, forming complete musicians since 2008. Part of MAM Centre.</p>
          <a href="/apply" class="btn arrow" style="align-self: flex-start; display: inline-flex; align-items: center; gap: 8px; min-height: 48px; padding: 0 22px; border-radius: 10px; border: 1px solid rgba(252,248,243,.35); color: #FCF8F3; font-weight: 600; text-decoration: none">Apply for <?= site_h(site_intake()) ?> <span aria-hidden="true">→</span></a>
        </div>
        <nav aria-label="Footer" style="flex: 2.4 1 600px; display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 32px">
<?php
/* The handoff's four intent groups. Each keeps the destinations the previous
   footer offered under the intent they belong to, so the redesign loses nothing
   a visitor could reach (checked with Faithful's parity run, HANDOFF §4z16). */
$groups = [
    'Study' => [['Programmes', '/programmes'], ['Admissions &amp; fees', '/programmes#fees'], ['MAM Auditer', '/auditer'], ['Scholarships', '/scholarship'],
                ['The four schools', '/schools'], ['Compare pathways', '/compare'], ['Crash Programme', '/crash'], ['The MetaMusic Order', '/metamusic-order']],
    'Families' => [['Parents &amp; guardians', '/how-it-works'], ['Questions', '/faq'], ['Class policies', '/policies'], ['Book a session', '/book'], ['Pay tuition', '/tuition']],
    'Community' => [['Events', '/life'], ['Power Play', '/life#powerplay'], ['Store', '/store'], ['Teach at MAM', '/contact?topic=Faculty'],
                    ['Faculty', '/faculty'], ['Practice Studio', '/studio'], ['Notation showcase', '/notation'], ['Blog', 'https://mamcentre.org/blog']],
    'Get in touch' => [['Contact admissions', '/contact'], ['academy@mamcentre.org', 'mailto:academy@mamcentre.org'], ['+234 704 444 6517', 'tel:+2347044446517'], ['Sign in to the portal', '/portal/login']],
];
foreach ($groups as $title => $links): ?>
            <div style="display: flex; flex-direction: column; gap: 2px">
              <h2 style="margin: 0 0 10px; font-size: 13px; font-weight: 600; letter-spacing: .1em; text-transform: uppercase; color: #F2A93B"><?= $title ?></h2>
              <ul style="list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column">
                <?php foreach ($links as [$t, $u]): ?><li><a class="ul" style="display: inline-flex; align-items: center; min-height: 36px; color: #E9E1D6; text-decoration: none" href="<?= $u ?>"><?= $t ?></a></li><?php endforeach; ?>
              </ul>
            </div>
<?php endforeach; ?>
        </nav>
      </div>

      <div style="display: flex; flex-wrap: wrap; gap: 16px 32px; justify-content: space-between; align-items: center; padding: 24px 0; border-top: 1px solid rgba(252,248,243,.12); font-size: 13px; color: #D8CEC1">
        <ul aria-label="MAM Academy on social media" style="list-style: none; margin: 0; padding: 0; display: flex; gap: 4px">
          <li><a class="btn" style="width: 44px; height: 44px; display: inline-flex; align-items: center; justify-content: center; border-radius: 10px; color: #E9E1D6" href="https://instagram.com/mamacademy1" aria-label="Instagram"><svg aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069zM12 0C8.741 0 8.333.014 7.053.072 2.695.272.273 2.69.073 7.052.014 8.333 0 8.741 0 12c0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98C8.333 23.986 8.741 24 12 24c3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98C15.668.014 15.259 0 12 0zm0 5.838a6.162 6.162 0 100 12.324 6.162 6.162 0 000-12.324zM12 16a4 4 0 110-8 4 4 0 010 8zm6.406-11.845a1.44 1.44 0 100 2.881 1.44 1.44 0 000-2.881z"></path></svg></a></li><li><a class="btn" style="width: 44px; height: 44px; display: inline-flex; align-items: center; justify-content: center; border-radius: 10px; color: #E9E1D6" href="https://twitter.com/mamacademy1" aria-label="X"><svg aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="currentColor"><path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z"></path></svg></a></li><li><a class="btn" style="width: 44px; height: 44px; display: inline-flex; align-items: center; justify-content: center; border-radius: 10px; color: #E9E1D6" href="https://facebook.com/mamacademy1" aria-label="Facebook"><svg aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="currentColor"><path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"></path></svg></a></li><li><a class="btn" style="width: 44px; height: 44px; display: inline-flex; align-items: center; justify-content: center; border-radius: 10px; color: #E9E1D6" href="https://youtube.com/mamacademy1" aria-label="YouTube"><svg aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="currentColor"><path d="M23.498 6.186a3.016 3.016 0 00-2.122-2.136C19.505 3.545 12 3.545 12 3.545s-7.505 0-9.377.505A3.017 3.017 0 00.502 6.186C0 8.07 0 12 0 12s0 3.93.502 5.814a3.016 3.016 0 002.122 2.136c1.871.505 9.376.505 9.376.505s7.505 0 9.377-.505a3.015 3.015 0 002.122-2.136C24 15.93 24 12 24 12s0-3.93-.502-5.814zM9.545 15.568V8.432L15.818 12l-6.273 3.568z"></path></svg></a></li>
        </ul>
        <span>© <?= date("Y") ?> MAM Academy</span>
        <ul aria-label="Legal" style="list-style: none; margin: 0; padding: 0; display: flex; flex-wrap: wrap; gap: 4px 20px"><li><a href="/privacy" class="ul" style="display: inline-flex; align-items: center; min-height: 24px; color: #D8CEC1; text-decoration: none">Privacy</a></li><li><a href="/terms" class="ul" style="display: inline-flex; align-items: center; min-height: 24px; color: #D8CEC1; text-decoration: none">Terms</a></li><li><a href="/policies" class="ul" style="display: inline-flex; align-items: center; min-height: 24px; color: #D8CEC1; text-decoration: none">Class policies</a></li><li><a href="/privacy" class="ul" style="display: inline-flex; align-items: center; min-height: 24px; color: #D8CEC1; text-decoration: none">Cookie settings</a></li><li><a href="/sitemap.xml" class="ul" style="display: inline-flex; align-items: center; min-height: 24px; color: #D8CEC1; text-decoration: none">Sitemap</a></li><li><a href="https://mamcentre.org" class="ul" style="display: inline-flex; align-items: center; min-height: 24px; color: #D8CEC1; text-decoration: none">MAM Centre</a></li><li><a href="/admin/" class="ul" style="display: inline-flex; align-items: center; min-height: 24px; color: #D8CEC1; text-decoration: none">Staff</a></li></ul>
      </div>
    </div>
    <p aria-hidden="true" class="wordmark" style="margin: 0; text-align: center; font-family: 'Cormorant Garamond', Georgia, serif; font-weight: 500; font-size: 16vw; line-height: .82; letter-spacing: -.03em; color: transparent; -webkit-text-stroke: 1px rgba(242,169,59,.34); white-space: nowrap; user-select: none; padding-top: 2vw; margin-bottom: -2.2vw">MAMCENTRE</p>
  </footer>
