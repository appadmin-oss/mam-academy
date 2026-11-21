<?php
/**
 * Terms of service — on the 2026-11 site shell (partials/site/), as safeguarding.php is. Wording unchanged from the previous design.
 */
declare(strict_types=1);
require __DIR__ . '/partials/site/shell.php';

site_head(array (
  'title' => 'Terms of Service — MAM Academy',
  'description' => 'MAM Academy terms of service — the rules of enrolling and learning with us.',
  'path' => '/terms',
));
site_header('inner');
ob_start();
?>
<main id="main">
<?php
site_doc_open('Terms', 'Terms of service',
    'By enrolling at MAM Academy you agree to the following terms. Effective 3 July 2026 · Governed by the laws of the Federal Republic of Nigeria.');

site_doc_section('before', 'Before you enrol',
    <<<'HTML'
    <p><strong>Please read these Terms carefully — by applying to or enrolling with MAM Academy you agree to them.</strong> They apply together with our <a href="/policies">Class Policies</a> and <a href="/privacy">Privacy Policy</a>. If you are under 18, a parent or legal guardian must apply and accept these Terms on your behalf and is responsible for any fees. Nothing in these Terms takes away rights the law gives you as a consumer (in Nigeria, the Federal Competition and Consumer Protection Act 2018; and equivalent laws where you live).</p>
    HTML);

site_doc_section('application-assessment-enrollment', '1. Application, assessment & enrollment',
    <<<'HTML'
    <p>Applying is free. To confirm your seat you pay a one-time, non-refundable <strong>booking fee</strong>, after which we schedule your <strong>MAM Auditer</strong> assessment. The Auditer determines your recommended pathway and starting level; final placement is confirmed by the Academy in writing. Enrollment on a programme is complete only once we confirm your place and the applicable programme fee is paid. Placement recommendations are advisory guidance — they are not a guarantee of any particular outcome, grade, certificate or result.</p>
    HTML);

site_doc_section('fees-currency-payment', '2. Fees, currency & payment',
    <<<'HTML'
    <p>The current fees for every programme, level, supporting course and resource are published on our <a href="/policies">Class Policies</a> and pricing pages and are shown to you before you pay; those published amounts are what govern. Prices are quoted in US dollars for reference and charged in Nigerian naira (or another supported currency) using the exchange rate at the time of payment, so the amount shown at checkout is the amount charged. Unless stated otherwise, fees exclude bank or payment-processor charges and any taxes that may apply. The booking fee is separate from tuition and is not credited against it.</p>
    HTML);

site_doc_section('refunds-cancellations', '3. Refunds & cancellations',
    <<<'HTML'
    <p>The booking fee and registration fee are <strong>non-refundable</strong> once your MAM Auditer assessment or onboarding has begun. Programme and supporting-course fees are generally <strong>non-refundable</strong> once your programme has started (this matches the "Non-Refundable Payments" clause in our <a href="/policies">Class Policies</a>). <strong>This does not affect your statutory rights:</strong> where the law gives you a cancellation or refund right — for example a distance-selling cooling-off period for consumers in the EU/UK, or a right under the Nigerian FCCPA — that right applies and overrides the above. As an alternative to a refund, an enrolled student may pause for up to 4 weeks per programme cycle and keep their seat. To ask about a cancellation, pause or refund, email <a href="mailto:academy@mamcentre.org">academy@mamcentre.org</a> and we will respond within 14 days.</p>
    HTML);

site_doc_section('attendance', '4. Attendance',
    <<<'HTML'
    <p>Students are expected to attend at least 80% of scheduled lessons per term. Persistent absence without communication may forfeit certification eligibility for that term.</p>
    HTML);

site_doc_section('practice-the-mam', '5. Practice & the MAM metronome',
    <<<'HTML'
    <p>Structured practice is part of your programme. Students must use the Academy's built-in metronome (available in the MAM Studio and student portal) for timed practice — not an external metronome app or device. The platform records each metronome practice session (date, duration and tempo) to your practice log, and this record may count toward your practice and online-attendance requirements. Leaving the metronome running idle does not count as practice.</p>
    HTML);

site_doc_section('code-of-conduct', '6. Code of conduct',
    <<<'HTML'
    <p>MAM students are expected to demonstrate discipline, honour and respect — toward instructors, peers and ensembles. The academy reserves the right to dismiss students who breach this code, without refund.</p>
    HTML);

site_doc_section('certificates-qualifications', '7. Certificates & qualifications',
    <<<'HTML'
    <p>Certificates issued by MAM Academy are the Academy's own certificates of completion; unless we state otherwise in writing, they are not degrees or government-regulated qualifications. Where our curriculum references or draws on an external syllabus, that reference describes the content we teach and does not imply accreditation, endorsement or affiliation unless a specific partnership is named in writing.</p>
    HTML);

site_doc_section('intellectual-property', '8. Intellectual property',
    <<<'HTML'
    <p>All curriculum materials, recordings and the "MAM Method" framework are the property of MAM Centre. Students receive personal-use rights for the duration of their enrollment and may not copy, resell or redistribute them.</p>
    HTML);

site_doc_section('performances-media', '9. Performances & media (consent)',
    <<<'HTML'
    <p>We record some showcases, rehearsals and classes. We will use recordings that identify you for marketing or promotion <strong>only with your consent</strong> (and, for a student under 18, the consent of their parent or guardian), which you can give or withdraw at any time by emailing <a href="mailto:academy@mamcentre.org">academy@mamcentre.org</a>. We may still make and keep recordings for internal teaching, assessment and record-keeping on the legal bases set out in our <a href="/privacy">Privacy Policy</a>.</p>
    HTML);

site_doc_section('fines-administrative-charges', '10. Fines & administrative charges',
    <<<'HTML'
    <p>Some Class Policies set out agreed administrative charges (for example for lateness, missed notice or rescheduling). These are disclosed to you before you enrol, are emailed to you with your signed policy, and are intended to reflect the cost and disruption involved — not to penalise you. They do not affect your statutory rights.</p>
    HTML);

site_doc_section('liability', '11. Liability',
    <<<'HTML'
    <p>MAM Academy is not liable for personal items you bring to onsite classes, and you take part in performances and physical activities at your own risk. <strong>Nothing in these Terms excludes or limits our liability for death or personal injury caused by our negligence, for fraud or fraudulent misrepresentation, or for any other liability that cannot be excluded or limited under applicable law.</strong> Subject to that, our total liability to you in connection with your enrollment is limited to the fees you have paid to us.</p>
    HTML);

site_doc_section('your-statutory-rights', '12. Your statutory rights',
    <<<'HTML'
    <p>You may have rights under consumer-protection and data-protection law that these Terms cannot remove or reduce. Where anything in these Terms or the Class Policies conflicts with a right the law gives you, the law prevails.</p>
    HTML);

site_doc_section('changes', '13. Changes',
    <<<'HTML'
    <p>We may revise these Terms. We will update the effective date above, and we will notify enrolled students of material changes by email at least 14 days before they take effect.</p>
    HTML);

site_doc_section('governing-law-disputes', '14. Governing law, disputes & general',
    <<<'HTML'
    <p>These Terms are governed by the laws of the Federal Republic of Nigeria, and disputes are subject to the courts of Lagos State, Nigeria — without depriving you of any protection or venue that mandatory law in your own country of residence gives you. If any provision is found unenforceable, the rest continues in force. These Terms, together with the Class Policies and Privacy Policy, are the entire agreement between you and MAM Academy about your enrollment. Questions or complaints: <a href="mailto:academy@mamcentre.org">academy@mamcentre.org</a> · +234 704 444 6517 · MAM Centre, 2 Oremeji Street, Egbeda, Lagos, Nigeria.</p>
    HTML);

?>
</main>
<?php
echo (string) ob_get_clean();
site_footer();
