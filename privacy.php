<?php
/**
 * Privacy policy — on the 2026-11 site shell (partials/site/), as safeguarding.php is. Wording unchanged from the previous design; #cookies and #casl keep their ids (the footer links /privacy#cookies).
 */
declare(strict_types=1);
require __DIR__ . '/partials/site/shell.php';

site_head(array (
  'title' => 'Privacy Policy — MAM Academy',
  'description' => 'MAM Academy privacy policy — how we collect, store and use your information.',
  'path' => '/privacy',
));
site_header('inner');
ob_start();
?>
<main id="main">
<?php
site_doc_open('Privacy', 'Privacy policy',
    'Effective 3 July 2026. This policy describes how MAM Academy collects, uses and protects your information. Aligned with Nigeria\'s NDPA (2023), the EU GDPR, the UK GDPR and Canada\'s CASL &amp; PIPEDA.');

site_doc_section('who-we-are', '1. Who we are (Data Controller)',
    <<<'HTML'
    <p>MAM Academy is the training arm of <strong>MAM Centre</strong>, 2 Oremeji Street, Egbeda, Lagos, Nigeria — the data controller responsible for your personal data. For any privacy request or to reach our data protection contact, email <a href="mailto:academy@mamcentre.org">academy@mamcentre.org</a> or call +234 704 444 6517.</p>
    HTML);

site_doc_section('information-we-collect', '2. Information we collect',
    <<<'HTML'
    <ul>
    <li><strong>Application &amp; profile data</strong> you submit (name, email, phone, date of birth/age range, sex, country/city, address where given, pathway/level/discipline choice, experience and learning goals).</li>
    <li><strong>Parent/guardian &amp; emergency-contact details</strong> for applicants under 18 (guardian name and phone, emergency contact).</li>
    <li><strong>A photo/headshot</strong>, if you choose to upload one with your application.</li>
    <li><strong>Learning records</strong> created while you study with us — attendance, practice logs (including built-in metronome sessions: date, duration and tempo), assignments, submissions, assessments and MAM Auditer results.</li>
    <li><strong>Payment metadata</strong> (transaction reference, amount, status, provider) — never your card number, which is handled directly by certified payment providers.</li>
    <li><strong>Technical &amp; security data</strong> collected automatically — IP address, browser/device information and server logs — used to keep the service secure, prevent abuse and rate-limit requests.</li>
    <li><strong>Local storage</strong> on your device for form drafts, currency preference and accessibility settings.</li>
    <li><strong>Usage analytics</strong> — first-party and anonymised, and only where you have not opted out.</li>
    </ul>
    HTML);

site_doc_section('how-we-use', '3. How we use it',
    <<<'HTML'
    <ul>
    <li>To process your application and schedule your MAM Auditer assessment.</li>
    <li>To deliver classes and track your attendance, practice and progress.</li>
    <li>To send confirmations, schedules and academy updates by email.</li>
    <li>To keep the platform secure and prevent fraud and abuse.</li>
    <li>To improve the website and our educational offerings.</li>
    <li>To comply with legal obligations.</li>
    </ul>
    HTML);

site_doc_section('payment-processors', '4. Payment processors',
    <<<'HTML'
    <p>We use <strong>Paystack</strong>, <strong>Flutterwave</strong> and <strong>Stripe</strong> for payments. These providers are PCI-DSS compliant and handle your card data directly. Their privacy policies apply to that data; we only receive a confirmation token.</p>
    HTML);

site_doc_section('sharing', '5. Sharing',
    <<<'HTML'
    <p>We do not sell your data. We share only with: payment processors (above), our email service provider for transactional emails, and as required by law.</p>
    HTML);

site_doc_section('legal-basis-for', '6. Legal basis for processing',
    <<<'HTML'
    <p>Under the NDPA and GDPR we process your data only where we have a lawful basis: <strong>contract</strong> (to deliver the tuition you enrol for), <strong>consent</strong> (for marketing emails — withdrawable any time), <strong>legitimate interests</strong> (to run and improve the academy, kept balanced against your rights) and <strong>legal obligation</strong> (tax, accounting and regulatory requirements).</p>
    HTML);

site_doc_section('data-retention', '7. Data retention',
    <<<'HTML'
    <p>We keep personal data only as long as necessary: application and student records for the duration of your relationship with us plus up to <strong>6 years</strong> for financial/legal compliance; newsletter data until you unsubscribe; form drafts in your browser's local storage until you clear them. After these periods data is securely deleted or anonymised.</p>
    HTML);

site_doc_section('international-transfers', '8. International transfers',
    <<<'HTML'
    <p>Some processors (e.g. Stripe, our email infrastructure) may process data outside Nigeria. Where data leaves Nigeria or the EEA, we rely on adequacy decisions or standard contractual clauses so it stays protected to NDPA/GDPR standards.</p>
    HTML);

site_doc_section('your-rights', '9. Your rights',
    <<<'HTML'
    <p>You have the right to <strong>access, correct, delete, restrict or port</strong> your data, to <strong>object</strong> to processing, and to <strong>withdraw consent</strong> at any time. Exercise any right by emailing <a href="mailto:academy@mamcentre.org">academy@mamcentre.org</a> — we respond within 30 days, free of charge. We do not use automated decision-making or profiling that produces legal effects. If you are unsatisfied, you may lodge a complaint with the <strong>Nigeria Data Protection Commission (NDPC)</strong> or your local supervisory authority.</p>
    HTML);

site_doc_section('marketing-unsubscribe', '10. Marketing & unsubscribe',
    <<<'HTML'
    <p>We send marketing emails only to people who opted in, and every one includes a working one-click <strong>unsubscribe</strong> link (and a List-Unsubscribe header). Unsubscribing stops marketing immediately; you'll still receive essential transactional messages (e.g. application confirmations, password resets).</p>
    HTML);

site_doc_section('casl', '11. Canadian recipients (CASL)',
    <<<'HTML'
    <p>If you are in Canada, we comply with Canada's Anti-Spam Legislation (CASL). We send commercial electronic messages (CEMs) only with your <strong>express or implied consent</strong>; every CEM identifies the sender (MAM Academy, MAM Centre, 2 Oremeji Street, Egbeda, Lagos, Nigeria), provides a way to contact us, and includes a working unsubscribe mechanism honoured within 10 business days. You may withdraw consent at any time using the unsubscribe link in any message or by emailing <a href="mailto:academy@mamcentre.org">academy@mamcentre.org</a>. CASL complaints may also be filed with the <strong>Canadian Radio-television and Telecommunications Commission (CRTC)</strong>. Personal information of Canadian residents is otherwise handled in line with <strong>PIPEDA</strong>.</p>
    HTML);

site_doc_section('cookies', '12. Cookies & local storage',
    <<<'HTML'
    <p>We use only essential cookies and local storage for site functionality (form drafts, currency &amp; accessibility preferences). We do not use third-party tracking cookies by default.</p>
    HTML);

site_doc_section('children', '13. Children\'s privacy',
    <<<'HTML'
    <p>Applicants under 18 must apply and enrol through a parent or legal guardian, who agrees to this policy and our Terms on the child's behalf. We collect and process a minor's data (including the learning records in section 2) on the basis of guardian consent and the enrollment contract, and use it solely for academy purposes. A guardian may exercise the child's data rights and withdraw consent at any time by emailing <a href="mailto:academy@mamcentre.org">academy@mamcentre.org</a>.</p>
    HTML);

site_doc_section('changes', '14. Changes',
    <<<'HTML'
    <p>If we change this policy, we'll update the "last updated" date above and, for material changes, notify enrolled students by email.</p>
    HTML);

?>
</main>
<?php
echo (string) ob_get_clean();
site_footer();
