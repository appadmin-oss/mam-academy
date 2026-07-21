<?php
/** Tests for mam_email_active_banner() — date-gating, precedence, rendering. */

t_group('Promo banner — active banner selection');

function _tb_insert(array $fields): void {
    $defaults = [
        'title' => 'Test Banner', 'body' => 'Hello world', 'cta_label' => '',
        'cta_url' => '', 'bg_color' => '#FEF3C7', 'active' => 1,
        'starts_at' => null, 'ends_at' => null,
        'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
    ];
    $data = array_merge($defaults, $fields);
    $cols = implode(',', array_keys($data));
    $phs  = implode(',', array_fill(0, count($data), '?'));
    mam_db()->prepare("INSERT INTO promo_banners ($cols) VALUES ($phs)")->execute(array_values($data));
}

function _tb_clean(): void {
    mam_db()->exec('DELETE FROM promo_banners');
}

// 1. No banners → empty string
_tb_clean();
t_eq('', mam_email_active_banner(), 'no banners → empty string');

// 2. One active banner, no date restriction → body rendered
_tb_clean();
_tb_insert(['body' => 'Welcome to summer camp']);
t_ok(str_contains(mam_email_active_banner(), 'Welcome to summer camp'), 'active no-dates banner renders body');

// 3. Inactive banner (active=0) → not shown
_tb_clean();
_tb_insert(['body' => 'Hidden', 'active' => 0]);
t_eq('', mam_email_active_banner(), 'inactive banner → empty string');

// 4. Banner with future starts_at → not shown yet
_tb_clean();
_tb_insert(['body' => 'Not yet', 'starts_at' => date('Y-m-d', strtotime('+2 days'))]);
t_eq('', mam_email_active_banner(), 'future-start banner → not shown');

// 5. Banner with past ends_at → expired
_tb_clean();
_tb_insert(['body' => 'Over', 'ends_at' => date('Y-m-d', strtotime('-1 day'))]);
t_eq('', mam_email_active_banner(), 'expired banner → not shown');

// 6. Banner within date window → shown
_tb_clean();
_tb_insert([
    'body'      => 'In window',
    'starts_at' => date('Y-m-d', strtotime('-1 day')),
    'ends_at'   => date('Y-m-d', strtotime('+1 day')),
]);
t_ok(str_contains(mam_email_active_banner(), 'In window'), 'banner within date window renders');

// 7. starts_at today (inclusive boundary) → shown
_tb_clean();
_tb_insert(['body' => 'Starts today', 'starts_at' => date('Y-m-d')]);
t_ok(str_contains(mam_email_active_banner(), 'Starts today'), 'banner starting today is live');

// 8. ends_at today (inclusive boundary) → shown
_tb_clean();
_tb_insert(['body' => 'Ends today', 'ends_at' => date('Y-m-d')]);
t_ok(str_contains(mam_email_active_banner(), 'Ends today'), 'banner ending today is still live');

// 9. Multiple active banners → most recently started wins
_tb_clean();
_tb_insert(['body' => 'Old banner',  'starts_at' => date('Y-m-d', strtotime('-5 days'))]);
_tb_insert(['body' => 'New banner',  'starts_at' => date('Y-m-d', strtotime('-1 day'))]);
t_ok(str_contains(mam_email_active_banner(), 'New banner'), 'most recently started banner wins');

// 10. CTA rendered when both label and URL are set
_tb_clean();
_tb_insert(['body' => 'Promo', 'cta_label' => 'Apply now', 'cta_url' => 'https://example.com']);
$html = mam_email_active_banner();
t_ok(str_contains($html, 'Apply now'),          'CTA label is rendered');
t_ok(str_contains($html, 'https://example.com'), 'CTA URL is rendered');

// 11. CTA NOT rendered when URL is empty
_tb_clean();
_tb_insert(['body' => 'Promo', 'cta_label' => 'Apply now', 'cta_url' => '']);
t_ok(!str_contains(mam_email_active_banner(), 'Apply now'), 'CTA omitted when URL is blank');

// 12. Unsafe cta_url (javascript:) → replaced with #
_tb_clean();
_tb_insert(['body' => 'XSS test', 'cta_label' => 'Click', 'cta_url' => 'javascript:alert(1)']);
$html = mam_email_active_banner();
t_ok(!str_contains($html, 'javascript:'), 'unsafe CTA scheme replaced with #');
t_ok(str_contains($html, 'href="#"'), 'unsafe scheme falls back to #');

// 13. bg_color injection: non-hex value falls back to default
_tb_clean();
_tb_insert(['body' => 'Color test', 'bg_color' => '" style="x:y']);
$html = mam_email_active_banner();
t_ok(str_contains($html, '#FEF3C7'), 'invalid bg_color falls back to default');
t_ok(!str_contains($html, 'x:y'), 'bg_color injection is neutralised');

_tb_clean();
