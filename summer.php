<?php
/**
 * /summer — MAM Summer Programme landing page + application form.
 * All content (dates, fee, age limits, description) is pulled from admin settings.
 * Shareable clean URL via .htaccess (summer.php → /summer).
 * On the 2026-11 redesign's shell. The form, its field names and assets/js/summer.js
 * are unchanged; headshot.js mounts the photo uploader as before (config.js is loaded
 * first so MAM_API and MAM_CONFIG exist for the upload chain). The fee is painted by
 * site-ccy-bridge.js from [data-amt-usd], as convertFees() did.
 * The shell (partials/site/shell.php) defines MAM_HTML_RESPONSE, which keeps bootstrap's
 * JSON envelope out of this page; MAM_RESPONSE_SENT is never pre-defined here.
 */
declare(strict_types=1);
require __DIR__ . '/partials/site/shell.php';

$progName    = (string)mam_setting('summer_prog_name',       'MAM Summer Music Camp');
$tagline     = (string)mam_setting('summer_tagline',         '');
$description = (string)mam_setting('summer_description',     '');
$highlights  = array_filter(array_map('trim', explode("\n", (string)mam_setting('summer_highlights', ''))));
$disciplines = array_filter(array_map('trim', explode("\n", (string)mam_setting('summer_disciplines', ''))));
$sessionStart= (string)mam_setting('summer_session_start',   '');
$sessionEnd  = (string)mam_setting('summer_session_end',     '');
$appOpen     = (string)mam_setting('summer_app_open',        '');
$appClose    = (string)mam_setting('summer_app_close',       '');
$minAge      = (int)mam_setting_num('summer_min_age',        8);
$maxAge      = (int)mam_setting_num('summer_max_age',        18);
$fee         = (float)mam_setting_num('summer_fee',          0);
$capacity    = (int)mam_setting_num('summer_capacity',       0);
$location    = (string)mam_setting('summer_location',        '');
$contactEmail= (string)mam_setting('summer_contact_email',   '');

$today       = date('Y-m-d');
// Shared predicate (admissions.php) so the form shows exactly when
// POST /api/summer/apply would accept. Each bound counts on its own — requiring
// both left a close-only configuration in a limbo that was neither open nor closed.
$isOpen      = mam_summer_accepting($today);
$notYet      = !$isOpen && $appOpen && $today < $appOpen;
$closed      = !$isOpen && $appClose && $today > $appClose;

// Format session dates for display
function formatDate(string $d, string $fmt = 'F j, Y'): string {
    if (!$d) return '';
    try { return (new DateTimeImmutable($d))->format($fmt); } catch (Throwable $e) { return $d; }
}
$dateRangeDisplay = '';
if ($sessionStart && $sessionEnd) {
    $s = new DateTimeImmutable($sessionStart); $e = new DateTimeImmutable($sessionEnd);
    $dateRangeDisplay = $s->format('F j') . ' – ' . $e->format('F j, Y');
} elseif ($sessionStart) {
    $dateRangeDisplay = formatDate($sessionStart);
}

$ageRange = $minAge . ($maxAge > 0 ? '–' . $maxAge : '+') . ' years';

site_head([
    'title' => $progName . ' — MAM Academy',
    'description' => $tagline ?: "An immersive summer music programme for young musicians aged $ageRange.",
    'path' => '/summer',
    'jsonld' => <<<'CSS'
<style>
.su-hero-chips{display:flex;flex-wrap:wrap;gap:8px}
.su-hero-chip{display:inline-flex;align-items:center;min-height:36px;padding:0 14px;border-radius:999px;background:#F3EEE6;font-size:15px;color:#3E342B}
.su-hero-ctas{display:flex;flex-wrap:wrap;gap:12px}
.su-btn-primary{display:inline-flex;align-items:center;min-height:48px;padding:0 26px;border-radius:999px;background:#F2A93B;color:#211A13;font-weight:600;text-decoration:none}
.su-btn-primary.is-static{background:#F3EEE6}
.su-btn-ghost{display:inline-flex;align-items:center;min-height:48px;padding:0 25px;border-radius:999px;border:1px solid #CFC3B3;color:#211A13;font-weight:600;text-decoration:none}
.su-info,.su-about,.su-disciplines,.su-apply,.su-contact{max-width:1200px;margin:0 auto;padding:clamp(40px,5vw,72px) clamp(20px,4vw,40px);box-sizing:border-box}
.su-info{padding-top:0}
.su-info-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(min(240px,100%),1fr));gap:16px}
.su-info-card{padding:20px;border-radius:16px;background:#FFFFFF;border:1px solid #E6DED2}
.su-info-card-label{font-size:13px;font-weight:600;letter-spacing:.12em;text-transform:uppercase;color:#8A5208}
.su-info-card-value{margin-top:6px;font-family:'Cormorant Garamond',Georgia,serif;font-weight:500;font-size:28px;line-height:1.15;color:#211A13}
.su-info-card-sub{margin-top:4px;font-size:14px;color:#574B40}
.su-about,.su-disciplines,.su-apply,.su-contact{border-top:1px solid #E6DED2}
.su-section-eyebrow{font-size:13px;font-weight:600;letter-spacing:.16em;text-transform:uppercase;color:#8A5208;margin:0 0 8px}
.su-section-title{margin:0 0 20px;font-family:'Cormorant Garamond',Georgia,serif;font-weight:500;font-size:clamp(34px,4.17vw,60px);line-height:1.05;color:#211A13}
.su-description{max-width:760px;margin:0 0 32px;font-size:18px;line-height:1.7;color:#3E342B}
.su-highlights{max-width:760px;margin:0;padding-left:1.2em;display:flex;flex-direction:column;gap:8px;font-size:17px;line-height:1.6;color:#3E342B}
.su-discs{display:flex;flex-wrap:wrap;gap:8px}
.su-disc{padding:8px 16px;border-radius:999px;border:1px solid #D6CCBE;font-size:15px;color:#3E342B}
.su-apply-inner{max-width:760px}
.su-form-card{padding:clamp(20px,3vw,32px);border-radius:20px;background:#FFFFFF;border:1px solid #E6DED2}
.su-age-notice{margin-bottom:20px;padding:12px 16px;border-radius:12px;background:#F3EEE6;font-size:15px;line-height:1.55;color:#211A13}
.su-form-error{display:none;margin-bottom:16px;padding:12px 16px;border-radius:12px;border:2px solid #B0361C;color:#B0361C;font-size:15px}
.su-form-error.show{display:block}
.su-form-section{padding:20px 0;border-top:1px solid #E6DED2}
.su-form-section:first-of-type{border-top:0;padding-top:0}
.su-form-section-title{margin-bottom:12px;font-size:17px;font-weight:600;color:#211A13}
.su-form-section-title span{font-weight:400;font-size:14px;color:#574B40}
.su-form-group{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(240px,100%),1fr));gap:0 16px}
.su-field{display:flex;flex-direction:column;gap:6px;margin-bottom:14px}
.su-field label{font-size:14px;font-weight:600;color:#3E342B}
.su-field .req{color:#B0361C}
.su-field input,.su-field select,.su-field textarea{width:100%;box-sizing:border-box;min-height:48px;padding:10px 14px;border-radius:12px;border:1px solid #CFC3B3;background:#FFFFFF;color:#211A13;font:inherit;font-size:16px}
.su-field textarea{min-height:110px;resize:vertical}
.su-field input:focus,.su-field select:focus,.su-field textarea:focus{outline:3px solid #2563EB;outline-offset:1px;border-color:#211A13}
.su-field.has-error input,.su-field.has-error select,.su-field.has-error textarea{border:2px solid #B0361C}
.su-field-error{display:none;font-size:14px;color:#B0361C}
.su-field-error.show{display:block}
.su-field-hint{font-size:14px;color:#574B40}
.su-policy-box{margin-bottom:14px;padding:14px 16px;border-radius:12px;background:#F3EEE6}
.su-policy-check{display:flex;gap:10px;align-items:flex-start;font-size:15px;line-height:1.55;color:#211A13}
.su-policy-check input{width:20px;height:20px;margin-top:2px;flex:none}
.su-policy-check a{color:#8A5208}
.su-submit-btn{width:100%;min-height:52px;margin-top:8px;border:0;border-radius:999px;background:#F2A93B;color:#211A13;font:inherit;font-size:16px;font-weight:600;cursor:pointer}
.su-submit-btn[disabled]{opacity:.6;cursor:default}
#summerApplyForm>p{margin:12px 0 0;font-size:14px;color:#574B40;text-align:center}
.su-success{display:none;text-align:center;padding:12px 0}
.su-success-icon{width:56px;height:56px;margin:0 auto 12px;border-radius:999px;display:flex;align-items:center;justify-content:center;background:#F3EEE6;color:#8A5208;font-size:28px}
.su-success-title{margin:0 0 8px;font-family:'Cormorant Garamond',Georgia,serif;font-weight:500;font-size:34px;color:#211A13}
.su-success-ref{font-family:ui-monospace,monospace;font-size:16px;color:#8A5208;margin-bottom:12px}
.su-success-msg{margin:0 0 20px;font-size:16px;line-height:1.6;color:#3E342B}
.su-closed-title{margin:0 0 8px;font-family:'Cormorant Garamond',Georgia,serif;font-weight:500;font-size:34px;color:#211A13}
.su-closed-msg{margin:0;font-size:16px;line-height:1.65;color:#3E342B}
.su-closed-msg a,.su-contact a{color:#8A5208}
.su-contact p{margin:0 0 6px;font-size:17px;color:#3E342B}
.su-contact a{font-size:18px;font-weight:600}
</style>
CSS,
]);
site_header('inner');
?>
<main id="main">

<section aria-labelledby="page-h" style="max-width: 1200px; margin: 0 auto; padding: clamp(40px, 5vw, 72px) clamp(20px, 4vw, 40px) clamp(32px, 4vw, 56px); box-sizing: border-box; display: flex; flex-direction: column; gap: 24px">
  <nav aria-label="Breadcrumb" class="fade-in" style="font-size: 14px; color: #574B40; display: flex; gap: 8px"><a href="/" class="ul" style="color: #574B40; text-decoration: none">Home</a><span aria-hidden="true">/</span><span aria-current="page">Summer</span></nav>
  <p class="fade-in" style="margin: 0; font-size: 13px; font-weight: 600; letter-spacing: .16em; text-transform: uppercase; color: #8A5208">Summer programme</p>
  <h1 id="page-h" class="fade-in" style="margin: 0; font-family: 'Cormorant Garamond', Georgia, serif; font-weight: 500; font-size: clamp(46px, 5.6vw, 84px); line-height: 1.02; letter-spacing: -.01em"><?= htmlspecialchars($progName) ?></h1>
  <p class="fade-in" style="margin: 0; max-width: 720px; font-size: 19px; line-height: 1.7; color: #3E342B"><?= htmlspecialchars($tagline ?: "An immersive music experience for young musicians aged $ageRange. Intensive tuition, performance, and community — all summer long.") ?></p>
  <div class="su-hero-chips fade-in">
    <?php if ($dateRangeDisplay): ?><span class="su-hero-chip"><?= htmlspecialchars($dateRangeDisplay) ?></span><?php endif; ?>
    <span class="su-hero-chip">Ages <?= htmlspecialchars($ageRange) ?></span>
    <?php if ($fee > 0): ?><span class="su-hero-chip"><span data-amt-usd="<?= htmlspecialchars((string) round($fee)) ?>">USD $<?= number_format($fee, 0) ?></span></span><?php endif; ?>
    <?php if ($location): ?><span class="su-hero-chip"><?= htmlspecialchars($location) ?></span><?php endif; ?>
  </div>
  <div class="su-hero-ctas fade-in">
    <?php if ($isOpen): ?>
      <a href="#apply" class="su-btn-primary">Apply now</a>
    <?php elseif ($notYet): ?>
      <span class="su-btn-primary is-static">Opens <?= htmlspecialchars(formatDate($appOpen, 'F j')) ?></span>
    <?php else: ?>
      <span class="su-btn-primary is-static">Applications closed</span>
    <?php endif; ?>
    <a href="/status" class="su-btn-ghost">Check my status</a>
  </div>
</section>

<!-- ── INFO CARDS GRID ──────────────────────────────────── -->
<section class="su-info" aria-label="Programme details">
  <div class="su-info-grid">
    <?php if ($dateRangeDisplay): ?>
    <div class="su-info-card">
      <div class="su-info-card-label">Session dates</div>
      <div class="su-info-card-value"><?= htmlspecialchars($dateRangeDisplay) ?></div>
    </div>
    <?php endif; ?>
    <div class="su-info-card">
      <div class="su-info-card-label">Age range</div>
      <div class="su-info-card-value"><?= htmlspecialchars($ageRange) ?></div>
      <div class="su-info-card-sub">Admin-verified at submission</div>
    </div>
    <?php if ($fee > 0): ?>
    <div class="su-info-card">
      <div class="su-info-card-label">Programme fee</div>
      <div class="su-info-card-value"><span data-amt-usd="<?= htmlspecialchars((string) round($fee)) ?>">USD $<?= number_format($fee, 0) ?></span></div>
      <div class="su-info-card-sub">Details via confirmation email</div>
    </div>
    <?php endif; ?>
    <?php if ($location): ?>
    <div class="su-info-card">
      <div class="su-info-card-label">Location</div>
      <div class="su-info-card-value"><?= htmlspecialchars($location) ?></div>
    </div>
    <?php endif; ?>
    <?php if ($capacity > 0): ?>
    <div class="su-info-card">
      <div class="su-info-card-label">Capacity</div>
      <div class="su-info-card-value"><?= (int)$capacity ?> participants</div>
      <div class="su-info-card-sub">Apply early — spots are limited</div>
    </div>
    <?php endif; ?>
    <?php if ($isOpen && $appClose): ?>
    <div class="su-info-card">
      <div class="su-info-card-label">Apply by</div>
      <div class="su-info-card-value"><?= htmlspecialchars(formatDate($appClose, 'F j, Y')) ?></div>
      <div class="su-info-card-sub">Applications close at end of day</div>
    </div>
    <?php elseif ($notYet && $appOpen): ?>
    <div class="su-info-card">
      <div class="su-info-card-label">Applications open</div>
      <div class="su-info-card-value"><?= htmlspecialchars(formatDate($appOpen, 'F j, Y')) ?></div>
    </div>
    <?php endif; ?>
  </div>
</section>

<!-- ── ABOUT / DESCRIPTION ──────────────────────────────── -->
<?php if ($description || $highlights): ?>
<section class="su-about" aria-label="About the programme">
  <?php if ($description): ?>
    <div class="su-section-eyebrow">About the programme</div>
    <h2 class="su-section-title">What is <?= htmlspecialchars($progName) ?>?</h2>
    <p class="su-description"><?= nl2br(htmlspecialchars($description)) ?></p>
  <?php endif; ?>
  <?php if ($highlights): ?>
    <div class="su-section-eyebrow">What you'll experience</div>
    <ul class="su-highlights" aria-label="Programme highlights">
      <?php foreach ($highlights as $h): ?>
        <li><?= htmlspecialchars($h) ?></li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</section>
<?php endif; ?>

<!-- ── DISCIPLINES ───────────────────────────────────────── -->
<?php if ($disciplines): ?>
<section class="su-disciplines" aria-label="Disciplines offered">
  <div class="su-disciplines-inner">
    <div class="su-section-eyebrow">What's on offer</div>
    <h2 class="su-section-title">Disciplines &amp; Instruments</h2>
    <div class="su-discs" role="list">
      <?php foreach ($disciplines as $d): ?>
        <span class="su-disc" role="listitem"><?= htmlspecialchars($d) ?></span>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<!-- ── APPLICATION FORM ─────────────────────────────────── -->
<section class="su-apply" id="apply" aria-label="Apply for the summer programme">
  <div class="su-apply-inner">
    <div class="su-section-eyebrow">Apply now</div>
    <h2 class="su-section-title">
      Secure your spot in<br><?= htmlspecialchars($progName) ?>
    </h2>

    <div class="su-form-card">

      <?php if ($isOpen): ?>
      <!-- ── Open: show form ── -->
      <div class="su-age-notice" role="note">
        <span>This programme is open to participants aged <strong><?= htmlspecialchars($ageRange) ?></strong>. Age is verified from your date of birth at the time of submission.</span>
      </div>

      <div class="su-form-error" id="summerFormError" role="alert"></div>

      <form id="summerApplyForm" novalidate autocomplete="off" data-min-age="<?= $minAge ?>" data-max-age="<?= $maxAge > 0 ? $maxAge : '' ?>">
        <input type="hidden" name="_honeypot" value="">

        <!-- Headshot -->
        <div class="su-form-section">
          <div class="su-form-section-title">Your photo</div>
          <div id="suHeadshotMount"></div>
          <input type="hidden" id="suHeadshotUrl" name="headshotUrl" value="">
          <div class="su-field-hint">A recent, clear front-facing photo — used for your admission file.</div>
        </div>

        <!-- Personal details -->
        <div class="su-form-section">
          <div class="su-form-section-title">Personal details</div>
          <div class="su-form-group">
            <div class="su-field">
              <label for="suFirstName">First name <span class="req">*</span></label>
              <input type="text" id="suFirstName" name="firstName" autocomplete="given-name" required maxlength="80">
              <div class="su-field-error" id="su-err-firstName">First name is required.</div>
            </div>
            <div class="su-field">
              <label for="suLastName">Last name <span class="req">*</span></label>
              <input type="text" id="suLastName" name="lastName" autocomplete="family-name" required maxlength="80">
              <div class="su-field-error" id="su-err-lastName">Last name is required.</div>
            </div>
          </div>
          <div class="su-form-group">
            <div class="su-field">
              <label for="suDob">Date of birth <span class="req">*</span></label>
              <input type="date" id="suDob" name="dob" required
                max="<?= date('Y-m-d', strtotime("-{$minAge} years")) ?>"
                <?php if ($maxAge > 0): ?>min="<?= date('Y-m-d', strtotime("-{$maxAge} years")) ?>"<?php endif; ?>>
              <div class="su-field-error" id="su-err-dob">Date of birth is required.</div>
              <div class="su-field-hint">Age must be <?= htmlspecialchars($ageRange) ?></div>
            </div>
            <div class="su-field">
              <label for="suSex">Sex</label>
              <select id="suSex" name="sex">
                <option value="">— Select —</option>
                <option value="male">Male</option>
                <option value="female">Female</option>
              </select>
            </div>
          </div>
        </div>

        <!-- Contact -->
        <div class="su-form-section">
          <div class="su-form-section-title">Contact information</div>
          <div class="su-form-group">
            <div class="su-field">
              <label for="suEmail">Email address <span class="req">*</span></label>
              <input type="email" id="suEmail" name="email" autocomplete="email" required maxlength="200">
              <div class="su-field-error" id="su-err-email">A valid email address is required.</div>
            </div>
            <div class="su-field">
              <label for="suPhone">Phone number <span class="req">*</span></label>
              <input type="tel" id="suPhone" name="phone" autocomplete="tel" required maxlength="30">
              <div class="su-field-error" id="su-err-phone">Phone number is required.</div>
            </div>
          </div>
          <div class="su-form-group">
            <div class="su-field">
              <label for="suCountry">Country</label>
              <input type="text" id="suCountry" name="country" autocomplete="country-name" maxlength="60">
            </div>
            <div class="su-field">
              <label for="suCity">City</label>
              <input type="text" id="suCity" name="city" autocomplete="address-level2" maxlength="80">
            </div>
          </div>
        </div>

        <!-- Guardian (required for minors) -->
        <div class="su-form-section" id="suGuardianSection">
          <div class="su-form-section-title">
            Guardian information
            <span id="suGuardianReqNote">(required — applicant is under 18)</span>
            <span id="suGuardianOptNote" style="display: none">(optional — applicant is 18+)</span>
          </div>
          <div class="su-form-group">
            <div class="su-field">
              <label for="suGuardianName">Guardian full name <span class="req" id="suGuardianNameReq">*</span></label>
              <input type="text" id="suGuardianName" name="guardianName" autocomplete="off" maxlength="160">
              <div class="su-field-error" id="su-err-guardianName">Guardian name is required for applicants under 18.</div>
            </div>
            <div class="su-field">
              <label for="suGuardianPhone">Guardian phone <span class="req" id="suGuardianPhoneReq">*</span></label>
              <input type="tel" id="suGuardianPhone" name="guardianPhone" autocomplete="off" maxlength="30">
              <div class="su-field-error" id="su-err-guardianPhone">Guardian phone is required for applicants under 18.</div>
            </div>
          </div>
          <div class="su-field">
            <label for="suGuardianEmail">Guardian email</label>
            <input type="email" id="suGuardianEmail" name="guardianEmail" autocomplete="off" maxlength="200">
            <div class="su-field-hint">Optional — we may use this for important updates</div>
          </div>
        </div>

        <!-- Music background -->
        <div class="su-form-section">
          <div class="su-form-section-title">Musical background</div>
          <?php if ($disciplines): ?>
          <div class="su-field">
            <label for="suDiscipline">Instrument / area of interest</label>
            <select id="suDiscipline" name="discipline">
              <option value="">Select one (optional)</option>
              <?php foreach ($disciplines as $d): ?>
                <option value="<?= htmlspecialchars($d) ?>"><?= htmlspecialchars($d) ?></option>
              <?php endforeach; ?>
              <option value="Other">Other</option>
            </select>
          </div>
          <?php else: ?>
          <div class="su-field">
            <label for="suDiscipline">Instrument / area of interest</label>
            <input type="text" id="suDiscipline" name="discipline" maxlength="100" placeholder="e.g. Piano, Drums, Voice, Theory…">
          </div>
          <?php endif; ?>
          <div class="su-field">
            <label for="suExperience">Music experience</label>
            <textarea id="suExperience" name="experience" maxlength="500" placeholder="How long have you been playing? Any lessons, performances, or styles you've explored?"></textarea>
          </div>
          <div class="su-field">
            <label for="suGoals">What do you hope to achieve?</label>
            <textarea id="suGoals" name="goals" maxlength="1000" placeholder="What excites you about this programme? What do you want to learn or improve?"></textarea>
          </div>
          <div class="su-field">
            <label for="suReferrer">How did you hear about us?</label>
            <select id="suReferrer" name="referrer">
              <option value="">Select one</option>
              <option value="social-media">Social media</option>
              <option value="friend-or-family">Friend or family</option>
              <option value="web-search">Web search</option>
              <option value="school">School / teacher</option>
              <option value="email">Email newsletter</option>
              <option value="returning">Returning from last year</option>
              <option value="other">Other</option>
            </select>
          </div>
        </div>

        <!-- Policy -->
        <div class="su-form-section">
          <div class="su-form-section-title">Agreement</div>
          <div class="su-policy-box">
            <label class="su-policy-check">
              <input type="checkbox" id="suPolicyAgreed" name="policyAgreed" required>
              <span>I confirm that the information provided is accurate and I agree to the MAM Academy <a href="/terms" target="_blank">terms &amp; policies</a>. I understand that submitting this form does not guarantee a place, and the academy will confirm enrollment separately.</span>
            </label>
            <div class="su-field-error" id="su-err-policyAgreed">You must agree to the programme policy.</div>
          </div>
          <div class="su-field">
            <label for="suPolicySig">Electronic signature <span class="req">*</span></label>
            <input type="text" id="suPolicySig" name="policySignedName" placeholder="Guardian's full name" required maxlength="160">
            <div class="su-field-error" id="su-err-policySignedName">Signature is required and must match the guardian's name.</div>
            <div class="su-field-hint" id="suPolicySigHint">Type the guardian's full name as the electronic signature</div>
          </div>
        </div>

        <button type="submit" class="su-submit-btn" id="summerSubmitBtn">
          Submit Application
        </button>
        <p>
          Your information is private and never shared without your consent.
        </p>
      </form>

      <!-- Success state (shown by JS) -->
      <div class="su-success" id="summerSuccess" role="status">
        <div class="su-success-icon" aria-hidden="true">✓</div>
        <h3 class="su-success-title">Application received!</h3>
        <div class="su-success-ref" id="summerSuccessRef"></div>
        <p class="su-success-msg">
          Thank you for applying to <strong><?= htmlspecialchars($progName) ?></strong>.
          We've sent a confirmation to your email. Our team will review your application and be in touch shortly.
        </p>
        <a href="/status" class="su-btn-primary">
          Track your application
        </a>
      </div>

      <?php elseif ($notYet): ?>
      <!-- ── Not yet open ── -->
      <div class="su-closed-notice">
        <h3 class="su-closed-title">Applications open <?= htmlspecialchars(formatDate($appOpen, 'F j, Y')) ?></h3>
        <p class="su-closed-msg">
          The application window for <?= htmlspecialchars($progName) ?> hasn't opened yet.
          Check back on <?= htmlspecialchars(formatDate($appOpen, 'F j')) ?> to apply.
          <?php if ($contactEmail): ?><br><br>Have questions? Email <a href="mailto:<?= htmlspecialchars($contactEmail) ?>"><?= htmlspecialchars($contactEmail) ?></a><?php endif; ?>
        </p>
      </div>

      <?php elseif ($closed): ?>
      <!-- ── Applications closed ── -->
      <div class="su-closed-notice">
        <h3 class="su-closed-title">Applications are now closed</h3>
        <p class="su-closed-msg">
          The application window for <?= htmlspecialchars($progName) ?> has ended.
          If you've already applied, <a href="/status">check your application status</a>.
          <?php if ($contactEmail): ?><br><br>Questions? Email <a href="mailto:<?= htmlspecialchars($contactEmail) ?>"><?= htmlspecialchars($contactEmail) ?></a><?php endif; ?>
        </p>
      </div>

      <?php else: ?>
      <!-- ── No dates configured yet ── -->
      <div class="su-closed-notice">
        <h3 class="su-closed-title">Coming soon</h3>
        <p class="su-closed-msg">
          Details for <?= htmlspecialchars($progName) ?> are being finalised.
          <?php if ($contactEmail): ?>Contact <a href="mailto:<?= htmlspecialchars($contactEmail) ?>"><?= htmlspecialchars($contactEmail) ?></a> to register your interest.<?php else: ?>Check back soon for dates and application details.<?php endif; ?>
        </p>
      </div>
      <?php endif; ?>

    </div><!-- .su-form-card -->
  </div>
</section>

<!-- ── CONTACT ───────────────────────────────────────────── -->
<?php if ($contactEmail): ?>
<section class="su-contact">
  <p>Have questions about <?= htmlspecialchars($progName) ?>?</p>
  <a href="mailto:<?= htmlspecialchars($contactEmail) ?>">
    <?= htmlspecialchars($contactEmail) ?>
  </a>
</section>
<?php endif; ?>

</main>
<script src="/assets/js/config.js?v=20261128u"></script>
<script src="/assets/js/headshot.js?v=20260817a"></script>
<script src="/assets/js/summer.js?v=20261128u"></script>
<?php site_footer(['site-ccy-bridge.js']); ?>
