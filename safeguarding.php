<?php
/**
 * Safeguarding — what the academy does to keep children safe, stated from the
 * code that does it rather than written as promises. The checks listed are
 * MAM_VETTING_REQUIRED and MAM_VETTING_ALTERNATIVES (api/modules/safeguarding/lib/vetting.php); the
 * sentence about what happens without them follows the gate's ACTUAL mode; the
 * officer is the one named in settings, and the page says so plainly when nobody
 * has been named rather than inventing a contact. A page that promised `enforce`
 * while the academy ran `warn` would be the most dangerous sentence on the site.
 */
declare(strict_types=1);
require __DIR__ . '/partials/site/shell.php';

$sgMode = mam_vetting_mode();
$sgDso  = mam_vetting_dso();
$sgList = array_map(static function (string $k): string {
    $alt = MAM_VETTING_ALTERNATIVES[$k] ?? [];
    $label = MAM_VETTING_TYPES[$k]['label'];
    if ($alt) {
        $label .= ' — or, for an instructor who cannot obtain it, '
            . implode(' or ', array_map(static fn($a) => MAM_VETTING_TYPES[$a]['label'], $alt));
    }
    return '<li>' . site_h($label) . '</li>';
}, MAM_VETTING_REQUIRED);
$sgGate = match ($sgMode) {
    'enforce' => 'An instructor without every check current <strong>cannot be assigned</strong> to a class with a learner under 18. The system refuses the assignment.',
    'off'     => 'The automatic check on assignments is switched off on this site. Clearances are still recorded and reviewed by the academy.',
    default   => 'Every check is recorded with its expiry date. An assignment to a class with a learner under 18 without every check current is <strong>flagged to the academy and recorded</strong>; the academy is moving to refusing such assignments outright once every instructor\'s records are in.',
};
$sgContact = $sgDso
    ? 'Our Designated Safeguarding Officer is <strong>' . site_h((string) $sgDso['name']) . '</strong>: <a href="mailto:' . site_h((string) $sgDso['email']) . '">' . site_h((string) $sgDso['email']) . '</a>.'
    : 'Write to <a href="mailto:academy@mamcentre.org?subject=Safeguarding">academy@mamcentre.org</a> with “Safeguarding” as the subject; it is passed to the person responsible for safeguarding.';

site_head(['title' => 'Safeguarding — MAM Academy', 'description' => 'How MAM Academy keeps children safe: the checks every instructor of a child must hold, how to raise a concern, and who acts on it.', 'path' => '/safeguarding']);
site_header('inner');
ob_start();
?>
<main id="main">
<?php
site_doc_open('Safeguarding', 'Safeguarding',
    'Many of our students are children, and many lessons are one-to-one. This page says what we check before anybody teaches a child, and what to do if you are worried about one.');

site_doc_section('worried', 'If you are worried about a child',
    '<p><strong>If a child is in immediate danger, call 112</strong> (Nigeria’s emergency number) before anything else.</p>'
    . '<p>' . $sgContact . '</p>'
    . '<p>Students, families and staff who are signed in can raise a concern from their portal. A concern is never refused: it goes to the person responsible for safeguarding, who decides what happens next. You do not need proof, and you do not need to be sure.</p>');

site_doc_section('checks', 'Before anybody teaches a child',
    '<p>Every instructor assigned to a class with a learner under 18 must hold each of these, current and in date:</p>'
    . '<ul>' . implode('', $sgList) . '</ul>'
    . '<p>' . $sgGate . '</p>'
    . '<p>A clearance lapses on its expiry date, not at the next review: the check reads the date every time. We keep the type of each check, its reference and its dates — never the content of a certificate — so the record that protects children does not itself become a risk to the people in it.</p>');

site_doc_section('online', 'Online classes and class spaces',
    '<ul>'
    . '<li>There are no private messages between instructors and students anywhere on the platform.</li>'
    . '<li>Words posted in a class space are checked before anybody sees them. A post with a phone number, an email address or a link, or one asking to talk somewhere else, is held for a person to read; wording that suggests a child may be at risk is held for the safeguarding lead.</li>'
    . '<li>A learner under 18 whose family has not consented to video shares recordings with their instructor only.</li>'
    . '<li>Marks and feedback are private to the student and their family.</li>'
    . '</ul>');

site_doc_section('framework', 'What we follow',
    '<p>The Child Rights Act 2003, which requires standards for the suitability of anyone working with children, and the Lagos State Safeguarding and Child Protection Policy (2016), which sets out the safer-recruitment checks above and asks every organisation to name a safeguarding officer.</p>'
    . '<p>Our <a href="/policies">class policies</a> and <a href="/privacy">privacy notice</a> say how we handle attendance, conduct and personal information.</p>');
?>
</main>
<?php
echo (string) ob_get_clean();
site_footer();
