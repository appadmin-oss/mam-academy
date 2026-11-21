<?php
/**
 * Contact — on the 2026-11 redesign shell (partials/site/). The handoff did not
 * draw it; it is built from the handoff's own parts (the About page's breadcrumb
 * and serif H1, the Apply flow's fields). The form posts the same fields to the
 * same CSRF-gated endpoint as before (POST /api/contact: name, email, phone,
 * topic, message), from assets/js/contact.js, which needs neither config.js nor
 * main.js.
 */
declare(strict_types=1);
require __DIR__ . '/partials/site/shell.php';

$ctTopics = ['Enrollment & Pathways', 'MAM Auditer Assessment', 'MetaMusic Order', 'Power Play series', 'Crash Programmes',
             'Teaching at MAM (Faculty)', 'Partnerships & Speaking', 'Press & Media', 'Other'];
$ctLabel = 'display: block; margin-bottom: 8px; font-size: 15px; font-weight: 600; color: #211A13';
$ctInput = 'box-sizing: border-box; width: 100%; min-height: 52px; padding: 12px 16px; border-radius: 12px; border: 1px solid #CFC3B3; background: #FFFFFF; font: inherit; font-size: 16px; color: #211A13';
$ctErr = 'margin: 6px 0 0; font-size: 14px; color: #B0361C';

site_head([
    'title' => 'Contact MAM Academy',
    'description' => 'Get in touch with MAM Academy. Email, phone and contact form.',
    'path' => '/contact',
]);
site_header('inner');
ob_start();
?>
<main id="main">
<?php
site_doc_open('Contact', 'Talk to MAM Academy', 'Questions about pathways, the MetaMusic Order or your child’s musical journey? We reply within 24 hours.');
?>
    <section aria-label="Contact details and form" style="max-width: 1200px; margin: 0 auto; padding: clamp(40px, 5vw, 72px) clamp(20px, 4vw, 40px) clamp(64px, 8vw, 120px); box-sizing: border-box; border-top: 1px solid #E6DED2; display: flex; flex-wrap: wrap; gap: 48px clamp(40px, 6vw, 96px); align-items: flex-start">
      <div style="flex: 1 1 300px; min-width: 0; display: flex; flex-direction: column; gap: 32px">
        <div>
          <h2 style="margin: 0 0 16px; font-family: 'Cormorant Garamond', Georgia, serif; font-weight: 500; font-size: clamp(32px, 3vw, 44px); line-height: 1.1">Reach us directly</h2>
          <dl style="margin: 0; display: flex; flex-direction: column">
            <div style="padding: 16px 0; border-top: 1px solid #E6DED2"><dt style="font-size: 13px; font-weight: 600; letter-spacing: .16em; text-transform: uppercase; color: #8A5208">Email</dt><dd style="margin: 6px 0 0; font-size: 17px"><a href="mailto:academy@mamcentre.org" class="ul" style="color: #211A13; text-decoration: none">academy@mamcentre.org</a></dd></div>
            <div style="padding: 16px 0; border-top: 1px solid #E6DED2"><dt style="font-size: 13px; font-weight: 600; letter-spacing: .16em; text-transform: uppercase; color: #8A5208">Phone</dt><dd style="margin: 6px 0 0; font-size: 17px"><a href="tel:+2347044446517" class="ul" style="color: #211A13; text-decoration: none">+234 704 444 6517</a></dd></div>
            <div style="padding: 16px 0; border-top: 1px solid #E6DED2"><dt style="font-size: 13px; font-weight: 600; letter-spacing: .16em; text-transform: uppercase; color: #8A5208">Office</dt><dd style="margin: 6px 0 0; font-size: 16px; line-height: 1.6; color: #3E342B">CACENTRE-Egbeda, 2 Abolude Oremeji Street, Off Akowonjo Road, Bakery Bus Stop, Egbeda, Alimosho, Lagos</dd></div>
            <div style="padding: 16px 0; border-top: 1px solid #E6DED2"><dt style="font-size: 13px; font-weight: 600; letter-spacing: .16em; text-transform: uppercase; color: #8A5208">Hours</dt><dd style="margin: 6px 0 0; font-size: 16px; color: #3E342B">Mon–Sat · 9am–6pm WAT</dd></div>
          </dl>
        </div>
        <div>
          <h2 style="margin: 0 0 12px; font-family: 'Cormorant Garamond', Georgia, serif; font-weight: 500; font-size: 28px; line-height: 1.1">Follow MAM</h2>
          <ul style="list-style: none; margin: 0; padding: 0; display: flex; flex-wrap: wrap; gap: 4px 20px">
            <li><a href="https://instagram.com/mamacademy1" target="_blank" rel="noopener" class="ul" style="display: inline-flex; align-items: center; min-height: 44px; color: #211A13; text-decoration: none">Instagram</a></li>
            <li><a href="https://twitter.com/mamacademy1" target="_blank" rel="noopener" class="ul" style="display: inline-flex; align-items: center; min-height: 44px; color: #211A13; text-decoration: none">X (Twitter)</a></li>
            <li><a href="https://facebook.com/mamacademy1" target="_blank" rel="noopener" class="ul" style="display: inline-flex; align-items: center; min-height: 44px; color: #211A13; text-decoration: none">Facebook</a></li>
            <li><a href="https://youtube.com/mamacademy1" target="_blank" rel="noopener" class="ul" style="display: inline-flex; align-items: center; min-height: 44px; color: #211A13; text-decoration: none">YouTube</a></li>
          </ul>
        </div>
      </div>

      <div style="flex: 1.5 1 440px; min-width: 0; padding: clamp(24px, 3vw, 40px); border-radius: 20px; background: #FFFFFF; border: 1px solid #E6DED2; box-sizing: border-box">
        <h2 style="margin: 0 0 8px; font-family: 'Cormorant Garamond', Georgia, serif; font-weight: 500; font-size: clamp(32px, 3vw, 44px); line-height: 1.1">Send us a message</h2>
        <p style="margin: 0 0 24px; font-size: 16px; line-height: 1.6; color: #574B40">All messages go to <strong>academy@mamcentre.org</strong>. We reply within 24 hours.</p>
        <form id="contactForm" novalidate style="display: flex; flex-direction: column; gap: 20px">
          <input type="text" name="_honeypot" tabindex="-1" autocomplete="off" aria-hidden="true" style="position: absolute; left: -9999px; width: 1px; height: 1px">
          <div style="display: flex; flex-wrap: wrap; gap: 20px">
            <div style="flex: 1 1 220px; min-width: 0"><label for="cf-name" style="<?= $ctLabel ?>">Your name <span aria-hidden="true" style="color: #B0361C">*</span></label><input type="text" id="cf-name" placeholder="Full name" autocomplete="name" required aria-describedby="cf-name-err" style="<?= $ctInput ?>"><p id="cf-name-err" hidden style="<?= $ctErr ?>"></p></div>
            <div style="flex: 1 1 220px; min-width: 0"><label for="cf-email" style="<?= $ctLabel ?>">Email <span aria-hidden="true" style="color: #B0361C">*</span></label><input type="email" id="cf-email" placeholder="you@example.com" autocomplete="email" inputmode="email" required aria-describedby="cf-email-err" style="<?= $ctInput ?>"><p id="cf-email-err" hidden style="<?= $ctErr ?>"></p></div>
          </div>
          <div style="display: flex; flex-wrap: wrap; gap: 20px">
            <div style="flex: 1 1 220px; min-width: 0"><label for="cf-phone" style="<?= $ctLabel ?>">Phone</label><input type="tel" id="cf-phone" placeholder="+234…" autocomplete="tel" inputmode="tel" style="<?= $ctInput ?>"></div>
            <div style="flex: 1 1 220px; min-width: 0"><label for="cf-topic" style="<?= $ctLabel ?>">Topic <span aria-hidden="true" style="color: #B0361C">*</span></label><select id="cf-topic" required aria-describedby="cf-topic-err cf-topic-tip" style="<?= $ctInput ?>"><option value="">Select topic</option>
<?php foreach ($ctTopics as $t): ?>
              <option><?= site_h($t) ?></option>
<?php endforeach; ?>
            </select><p id="cf-topic-err" hidden style="<?= $ctErr ?>"></p></div>
          </div>
          <p id="cf-topic-tip" hidden style="margin: -8px 0 0; padding: 14px 16px; border-radius: 12px; background: #F3EEE6; font-size: 15px; line-height: 1.6; color: #3E342B"></p>
          <div><label for="cf-message" style="<?= $ctLabel ?>">Message <span aria-hidden="true" style="color: #B0361C">*</span></label><textarea id="cf-message" placeholder="Tell us how we can help…" required maxlength="2000" rows="6" aria-describedby="cf-message-err" style="<?= $ctInput ?>; min-height: 160px; resize: vertical"></textarea><p id="cf-message-err" hidden style="<?= $ctErr ?>"></p></div>
          <div style="display: flex; flex-wrap: wrap; align-items: center; gap: 16px">
            <button type="submit" id="cf-submit" class="btn arrow" style="display: inline-flex; align-items: center; gap: 8px; min-height: 52px; padding: 0 26px; border: 0; border-radius: 999px; background: #211A13; color: #FAF8F4; font: inherit; font-weight: 600; cursor: pointer">Send message <span aria-hidden="true">→</span></button>
            <p id="cf-msg" role="status" aria-live="polite" style="margin: 0; font-size: 15px; color: #3E342B"></p>
          </div>
        </form>
      </div>
    </section>
</main>
<?php
echo (string) ob_get_clean();
site_footer(['contact.js']);
