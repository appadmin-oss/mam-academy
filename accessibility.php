<?php
/**
 * Accessibility statement. It claims only what the code does and what has been
 * measured, and lists the known gaps — a statement that claimed conformance
 * nobody has audited would be worse than none. When a gap below is closed, take
 * its line out in the same change.
 */
declare(strict_types=1);
require __DIR__ . '/partials/site/shell.php';

site_head(['title' => 'Accessibility — MAM Academy', 'description' => 'How MAM Academy’s website is built to be usable by everyone, what we have tested, what we know is not right yet, and how to tell us.', 'path' => '/accessibility']);
site_header('inner');
ob_start();
?>
<main id="main">
<?php
site_doc_open('Accessibility', 'Accessibility',
    'We want everybody to be able to find a programme, apply and learn with us — whatever device, browser or assistive technology they use. This statement says what we aim for, what we have checked, and what is not right yet.');

site_doc_section('aim', 'What we aim for',
    '<p>The public pages are built to the <strong>Web Content Accessibility Guidelines (WCAG) 2.2, level AA</strong>. We have not yet had an independent audit, so this is our aim and our own testing, not a certified claim.</p>');

site_doc_section('built', 'What is built in',
    '<ul>'
    . '<li>A “Skip to main content” link first on every page, one main heading per page, and labelled navigation regions.</li>'
    . '<li>Everything works with a keyboard: the Programmes menu closes with Escape, option groups move with the arrow keys, and focus is always visible.</li>'
    . '<li>Buttons and menu links are at least 24 pixels in each direction, and the main actions at least 44.</li>'
    . '<li>If your device asks for reduced motion, nothing on the pages animates, and every count shows its final number.</li>'
    . '<li>Nothing makes a sound until you press a button. The microphone is used only when you press “Sing to it”, and what it hears is analysed on your device — never recorded or sent.</li>'
    . '<li>Fees are written as text, not images, and the estimate is announced when it changes.</li>'
    . '<li>Pages still on our previous design carry a toolbar for higher contrast and larger or smaller text.</li>'
    . '<li>In the student, parent and instructor portals, the Display &amp; access screen offers larger text and a light or dark appearance.</li>'
    . '</ul>');

site_doc_section('tested', 'How we test',
    '<p>Each redesigned page is checked against its design and audited for clipped or overlapping text, small targets, sideways scrolling on a phone and readability, at a phone width and a desktop width. We also go through the pages with a keyboard alone.</p>');

site_doc_section('gaps', 'What is not right yet',
    '<ul>'
    . '<li>Several older pages (such as the store, the practice studio and the notation showcase) use our previous design and have not been re-tested against WCAG 2.2.</li>'
    . '<li>The fee comparison table on <a href="/programmes">Programmes</a> scrolls sideways on a phone rather than reflowing.</li>'
    . '<li>Some pages still show a coloured placeholder where a photograph will go; the placeholders are hidden from screen readers until a real photograph, with a description, replaces them.</li>'
    . '</ul>');

site_doc_section('tell-us', 'Tell us',
    '<p>If something stops you using the site, write to <a href="mailto:academy@mamcentre.org?subject=Accessibility">academy@mamcentre.org</a> with “Accessibility” as the subject, or call <a href="tel:+2347044446517">+234 704 444 6517</a>. Tell us the page and what happened; we will help you complete what you were doing another way while we fix it.</p>'
    . '<p>This statement was written in November 2026.</p>');
?>
</main>
<?php
echo (string) ob_get_clean();
site_footer();
