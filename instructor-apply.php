<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="color-scheme" content="dark">
<meta name="robots" content="index, follow">
<title>Teach at MAM — Instructor Application | MAM Academy</title>
<meta name="description" content="Apply to teach at MAM Academy. Choose the courses and levels you are qualified to teach, tell us about your training and experience, and book your faculty interview.">
<link rel="canonical" href="https://academy.mamcentre.org/instructor-apply">
<link rel="icon" type="image/svg+xml" href="mam-academy-logo.svg">
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=Cinzel:wght@600;700&family=Cormorant+Garamond:ital,wght@0,400;0,600;0,700;1,400&family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" referrerpolicy="no-referrer">
<link rel="stylesheet" href="assets/css/main.css?v=20260820a">
<link rel="stylesheet" href="assets/css/apply.css?v=20260917a">
</head>
<?php /* The site-wide lead surfaces are opted OUT here. `lead.js` skips its
         CTA bar on the student apply page via a path test that reads
         `/apply`, and `/instructor-apply` does not match it — so a
         prospective TEACHER was shown a sticky "Next intake is open —
         five-step application" bar for the STUDENT wizard, overlapping
         this form's own controls. Wrong audience, wrong step count, and
         it covers the matrix on a phone. */ ?>
<body data-no-lead-cta data-no-lead-exit>
<a href="#main" class="skip-link">Skip to main content</a>

<?php include __DIR__ . '/partials/header.html'; ?>

<main id="main">
  <section class="apply-shell" aria-label="Instructor application">

    <!--
      Gate. Three states, resolved by JS against GET /api/faculty-apply:
        · open applications on            → the wizard, email typed by the candidate
        · a valid ?token=… invite         → the wizard, email fixed by the invite
        · invite-only and no valid token  → an explanation and a way to ask
      The wizard stays hidden until one of them resolves, so a candidate never
      fills a form that was never going to accept them.
    -->
    <div class="fac-gate" id="facGate" role="status" aria-live="polite">
      <i class="fa-solid fa-circle-notch fa-spin" aria-hidden="true"></i>
      Loading the application…
    </div>

    <!-- Progress. `aria-current="step"` is what a screen reader reads as
         position; the visible dot is decoration. -->
    <nav class="apply-stepper" id="facProgress" aria-label="Application progress" hidden>
      <button type="button" class="apply-progress-step active" data-step="0" aria-current="step"><span class="step-dot"><span>1</span></span><span class="step-name">Teaching</span></button>
      <button type="button" class="apply-progress-step" data-step="1"><span class="step-dot"><span>2</span></span><span class="step-name">About you</span></button>
      <button type="button" class="apply-progress-step" data-step="2"><span class="step-dot"><span>3</span></span><span class="step-name">Training</span></button>
      <button type="button" class="apply-progress-step" data-step="3"><span class="step-dot"><span>4</span></span><span class="step-name">Experience</span></button>
      <button type="button" class="apply-progress-step" data-step="4"><span class="step-dot"><span>5</span></span><span class="step-name">Availability</span></button>
      <button type="button" class="apply-progress-step" data-step="5"><span class="step-dot"><span>6</span></span><span class="step-name">Safeguarding</span></button>
      <button type="button" class="apply-progress-step" data-step="6"><span class="step-dot"><span>7</span></span><span class="step-name">Review</span></button>
    </nav>

    <!-- Draft notice. A seven-step application is abandoned without one; the
         student wizard has the same affordance and the same class. -->
    <div class="resume-banner" id="facResume" hidden>
      <i class="fa-solid fa-floppy-disk" aria-hidden="true"></i>
      <span>We saved your draft on this device. <button type="button" id="facDiscard">Start over</button></span>
    </div>

    <?php /* `novalidate` for the same reason the student wizard carries it:
             every step stays in the DOM while hidden, so a `required` control
             on a hidden step makes the form unsubmittable with "An invalid form
             control is not focusable" — an error the candidate can neither see
             nor fix. validateStep() gates each step; the attributes remain as a
             machine-readable description and a screen-reader "required". */ ?>
    <form id="facForm" novalidate autocomplete="on" hidden>
    <div class="apply-main">

      <!-- ─────────────────────────────── STEP 1 — Teaching ─────────────── -->
      <div class="step active" data-step="0">
        <div class="step-head">
          <div class="step-num">Step 1 of 7 · Teaching</div>
          <h2 class="step-title">What do you teach?</h2>
          <p class="step-desc">Choose every course you are qualified to teach, and the levels you can take a student to. This is the heart of your application — it is what we match you to a class with, so claim what you can genuinely teach rather than everything on the list.</p>
        </div>

        <div class="field">
          <span class="field-label" id="facMatrixLabel">Courses &amp; levels <span class="req">*</span></span>
          <div class="fac-matrix" id="facMatrix" role="group" aria-labelledby="facMatrixLabel"></div>
          <div class="fac-count" id="facMatrixCount">Nothing chosen yet.</div>
          <div class="field-error" id="err-subjects"><i class="fa-solid fa-circle-exclamation"></i><span>Choose at least one course you can teach</span></div>
        </div>

        <fieldset class="field">
          <legend class="field-label">Programmes you could teach on</legend>
          <div class="fac-levels" id="facProgrammes"></div>
          <div class="field-help">Optional. Leave it blank if you are happy to be considered for any.</div>
        </fieldset>

        <fieldset class="field">
          <legend class="field-label">How can you teach?</legend>
          <div class="fac-levels" id="facModes"></div>
          <div class="field-help">Optional — onsite in Lagos, online, or both.</div>
        </fieldset>

        <div class="step-actions">
          <span></span>
          <button type="button" class="btn-step btn-step-next" data-next>Continue <i class="fa-solid fa-arrow-right" aria-hidden="true"></i></button>
        </div>
      </div>

      <!-- ─────────────────────────────── STEP 2 — About you ────────────── -->
      <div class="step" data-step="1">
        <div class="step-head">
          <div class="step-num">Step 2 of 7 · About you</div>
          <h2 class="step-title">Who are you?</h2>
          <p class="step-desc">How we reach you, and the photograph that becomes your faculty profile if you join us.</p>
        </div>

        <div class="field-row">
          <div class="field">
            <label class="field-label" for="f-name">Full name <span class="req">*</span></label>
            <input type="text" id="f-name" class="field-input" maxlength="160" autocomplete="name" placeholder="e.g. Adaeze Obi" required>
            <div class="field-error" id="err-f-name"><i class="fa-solid fa-circle-exclamation"></i><span>Enter your full name</span></div>
          </div>
          <div class="field">
            <label class="field-label" for="f-email">Email <span class="req">*</span></label>
            <input type="email" id="f-email" class="field-input" maxlength="190" autocomplete="email" placeholder="you@example.com" required>
            <div class="field-help" id="f-email-help">We reply here, and it becomes your portal sign-in if you join.</div>
            <div class="field-error" id="err-f-email"><i class="fa-solid fa-circle-exclamation"></i><span>Enter a valid email address</span></div>
          </div>
        </div>

        <div class="field-row">
          <div class="field">
            <label class="field-label" for="f-phone">Phone</label>
            <input type="tel" id="f-phone" class="field-input" maxlength="40" autocomplete="tel" placeholder="+234 …">
          </div>
          <div class="field">
            <label class="field-label" for="f-location">Where are you based?</label>
            <input type="text" id="f-location" class="field-input" maxlength="190" placeholder="e.g. Yaba, Lagos" autocomplete="address-level2">
            <div class="field-help">City is enough. It tells us whether onsite teaching is realistic for you.</div>
          </div>
        </div>

        <div class="field">
          <span class="field-label">Professional headshot</span>
          <div class="headshot-mount" id="facHeadshot"></div>
          <label class="field-label sr-only" for="f-shot">Or paste an image link</label>
          <input type="url" id="f-shot" class="field-input" maxlength="500" placeholder="…or paste an https image link">
          <div class="field-help">A clear, front-facing photo. Optional now — we will ask before you are published.</div>
        </div>

        <div class="step-actions">
          <button type="button" class="btn-step btn-step-back" data-back><i class="fa-solid fa-arrow-left" aria-hidden="true"></i> Back</button>
          <button type="button" class="btn-step btn-step-next" data-next>Continue <i class="fa-solid fa-arrow-right" aria-hidden="true"></i></button>
        </div>
      </div>

      <!-- ─────────────────────────────── STEP 3 — Training ─────────────── -->
      <div class="step" data-step="2">
        <div class="step-head">
          <div class="step-num">Step 3 of 7 · Training</div>
          <h2 class="step-title">Your training and credentials</h2>
          <p class="step-desc">Formal qualifications help, and they are not the whole picture — many fine teachers are conservatoire-trained and many are not. Tell us what you have.</p>
        </div>

        <div class="field-row">
          <div class="field">
            <label class="field-label" for="f-years">Years teaching</label>
            <input type="number" id="f-years" class="field-input" min="0" max="60" inputmode="numeric" placeholder="e.g. 6">
          </div>
          <div class="field">
            <label class="field-label" for="f-cv">CV / résumé link</label>
            <input type="url" id="f-cv" class="field-input" maxlength="500" placeholder="https://…">
            <div class="field-help">Google Drive, Dropbox or your own site. https links only.</div>
          </div>
        </div>

        <div class="field">
          <label class="field-label" for="f-quals">Qualifications, grades and certifications</label>
          <textarea id="f-quals" class="field-textarea" maxlength="2000" rows="5" placeholder="e.g. BMus (University of Lagos); Trinity ATCL Piano Recital; ABRSM Grade 8 Theory; 2 years Orff certification…"></textarea>
          <div class="field-help">Degrees, diplomas, Trinity/ABRSM grades, teaching certificates — whatever applies.</div>
        </div>

        <div class="step-actions">
          <button type="button" class="btn-step btn-step-back" data-back><i class="fa-solid fa-arrow-left" aria-hidden="true"></i> Back</button>
          <button type="button" class="btn-step btn-step-next" data-next>Continue <i class="fa-solid fa-arrow-right" aria-hidden="true"></i></button>
        </div>
      </div>

      <!-- ─────────────────────────────── STEP 4 — Experience ───────────── -->
      <div class="step" data-step="3">
        <div class="step-head">
          <div class="step-num">Step 4 of 7 · Experience</div>
          <h2 class="step-title">Your teaching, in your words</h2>
          <p class="step-desc">The panel reads this properly. Write it the way you would talk about your work.</p>
        </div>

        <div class="field">
          <label class="field-label" for="f-bio">Professional biography <span class="req">*</span></label>
          <textarea id="f-bio" class="field-textarea" maxlength="4000" rows="7" required placeholder="Your training, where you have taught, the students you have taken through exams or performances, anything you have recorded or produced…"></textarea>
          <div class="field-help">At least 40 characters — write it as the bio we would publish on your faculty profile.</div>
          <div class="field-error" id="err-f-bio"><i class="fa-solid fa-circle-exclamation"></i><span>Please write a fuller biography (at least 40 characters)</span></div>
        </div>

        <div class="field">
          <label class="field-label" for="f-philosophy">How do you teach?</label>
          <textarea id="f-philosophy" class="field-textarea" maxlength="4000" rows="5" placeholder="How you start with a nervous beginner, how you handle a student who has not practised, what you think a good lesson feels like…"></textarea>
          <div class="field-help">Optional, and the thing we most enjoy reading.</div>
        </div>

        <div class="field">
          <label class="field-label" for="f-links">Links — portfolio, recordings, socials</label>
          <input type="text" id="f-links" class="field-input" maxlength="1000" placeholder="Separate several with spaces">
        </div>

        <div class="step-actions">
          <button type="button" class="btn-step btn-step-back" data-back><i class="fa-solid fa-arrow-left" aria-hidden="true"></i> Back</button>
          <button type="button" class="btn-step btn-step-next" data-next>Continue <i class="fa-solid fa-arrow-right" aria-hidden="true"></i></button>
        </div>
      </div>

      <!-- ─────────────────────────────── STEP 5 — Availability ─────────── -->
      <div class="step" data-step="4">
        <div class="step-head">
          <div class="step-num">Step 5 of 7 · Availability</div>
          <h2 class="step-title">When could you teach?</h2>
          <p class="step-desc">A rough shape is fine — we build timetables around what our instructors can actually sustain, and nothing here is a commitment.</p>
        </div>

        <fieldset class="field">
          <legend class="field-label">Typical week</legend>
          <div class="fac-avail">
            <div class="fac-avail-grid" id="facAvail"></div>
          </div>
          <div class="field-help">Tap the blocks you are usually free. Times are Lagos (WAT).</div>
        </fieldset>

        <div class="field-row">
          <div class="field">
            <label class="field-label" for="f-hours">Hours a week you could take</label>
            <input type="number" id="f-hours" class="field-input" min="0" max="80" inputmode="numeric" placeholder="e.g. 10">
          </div>
          <div class="field">
            <label class="field-label" for="f-start">Earliest you could start</label>
            <input type="date" id="f-start" class="field-input">
            <div class="field-error" id="err-f-start"><i class="fa-solid fa-circle-exclamation"></i><span>Use the date picker, or leave it blank</span></div>
          </div>
        </div>

        <div class="step-actions">
          <button type="button" class="btn-step btn-step-back" data-back><i class="fa-solid fa-arrow-left" aria-hidden="true"></i> Back</button>
          <button type="button" class="btn-step btn-step-next" data-next>Continue <i class="fa-solid fa-arrow-right" aria-hidden="true"></i></button>
        </div>
      </div>

      <!-- ─────────────────────────────── STEP 6 — Safeguarding ─────────── -->
      <div class="step" data-step="5">
        <div class="step-head">
          <div class="step-num">Step 6 of 7 · Safeguarding</div>
          <h2 class="step-title">References and safeguarding</h2>
          <p class="step-desc">We teach children, often one to one. This part is not a formality, and we would rather be plain about it than bury it in a policy.</p>
        </div>

        <!-- Safer-recruitment guidance is explicit that the commitment and the
             fact of pre-employment checks belong where the candidate actually
             reads them. Note what is NOT asked for anywhere on this form: a
             certificate number, a document scan, or anything about an offence.
             A verifier records the OUTCOME of a check against your record; the
             evidence itself is filed offline, deliberately. -->
        <div class="fac-safenote">
          <p><i class="fa-solid fa-shield-heart" aria-hidden="true"></i><strong>MAM Academy is committed to safeguarding children.</strong> Every instructor is checked before they teach: we take up both of your references, confirm your identity, and require current safeguarding clearances. An offer always follows those checks — never precedes them.</p>
          <p>We ask for your referees' contact details here, and nothing else. <strong>Please do not upload certificates or enter any document numbers</strong> — we collect that evidence in person, and record only that a check was completed and by whom.</p>
        </div>

        <div class="fac-referee">
          <div class="fac-referee-head">Referee 1 <span class="req">*</span></div>
          <div class="field-row">
            <div class="field">
              <label class="field-label" for="r1-name">Name <span class="req">*</span></label>
              <input type="text" id="r1-name" class="field-input" maxlength="160" required>
            </div>
            <div class="field">
              <label class="field-label" for="r1-rel">How do they know you?</label>
              <input type="text" id="r1-rel" class="field-input" maxlength="120" placeholder="e.g. Head of Music, my last school">
            </div>
          </div>
          <div class="field-row">
            <div class="field">
              <label class="field-label" for="r1-org">Organisation</label>
              <input type="text" id="r1-org" class="field-input" maxlength="190">
            </div>
            <div class="field">
              <label class="field-label" for="r1-email">Email</label>
              <input type="email" id="r1-email" class="field-input" maxlength="190">
            </div>
          </div>
          <div class="field">
            <label class="field-label" for="r1-phone">Phone</label>
            <input type="tel" id="r1-phone" class="field-input" maxlength="40">
          </div>
        </div>

        <div class="fac-referee">
          <div class="fac-referee-head">Referee 2 <span class="req">*</span></div>
          <div class="field-row">
            <div class="field">
              <label class="field-label" for="r2-name">Name <span class="req">*</span></label>
              <input type="text" id="r2-name" class="field-input" maxlength="160" required>
            </div>
            <div class="field">
              <label class="field-label" for="r2-rel">How do they know you?</label>
              <input type="text" id="r2-rel" class="field-input" maxlength="120">
            </div>
          </div>
          <div class="field-row">
            <div class="field">
              <label class="field-label" for="r2-org">Organisation</label>
              <input type="text" id="r2-org" class="field-input" maxlength="190">
            </div>
            <div class="field">
              <label class="field-label" for="r2-email">Email</label>
              <input type="email" id="r2-email" class="field-input" maxlength="190">
            </div>
          </div>
          <div class="field">
            <label class="field-label" for="r2-phone">Phone</label>
            <input type="tel" id="r2-phone" class="field-input" maxlength="40">
          </div>
        </div>
        <div class="field-error" id="err-referees"><i class="fa-solid fa-circle-exclamation"></i><span>Please give us two referees we can contact — at least their names</span></div>

        <label class="consent" for="f-safeack">
          <input type="checkbox" id="f-safeack" required>
          <span class="consent-text">I understand that MAM Academy will carry out safeguarding checks — including taking up both references — before I teach any student, and I consent to those checks. <span class="req">*</span></span>
        </label>
        <div class="field-error" id="err-safeack"><i class="fa-solid fa-circle-exclamation"></i><span>We cannot accept an application without this</span></div>

        <label class="consent" for="f-polack">
          <input type="checkbox" id="f-polack" required>
          <span class="consent-text">I have read and agree to abide by the Academy's child-protection and code-of-conduct policies, and I will report any safeguarding concern to the Designated Safeguarding Officer. <span class="req">*</span></span>
        </label>
        <div class="field-error" id="err-polack"><i class="fa-solid fa-circle-exclamation"></i><span>We cannot accept an application without this</span></div>

        <!-- Honeypot: humans never see this. -->
        <div class="hp-field" aria-hidden="true"><label>Website<input type="text" id="f-web" tabindex="-1" autocomplete="off"></label></div>

        <div class="step-actions">
          <button type="button" class="btn-step btn-step-back" data-back><i class="fa-solid fa-arrow-left" aria-hidden="true"></i> Back</button>
          <button type="button" class="btn-step btn-step-next" data-next>Review <i class="fa-solid fa-arrow-right" aria-hidden="true"></i></button>
        </div>
      </div>

      <!-- ─────────────────────────────── STEP 7 — Review ───────────────── -->
      <div class="step" data-step="6">
        <div class="step-head">
          <div class="step-num">Step 7 of 7 · Review</div>
          <h2 class="step-title">Check it over</h2>
          <p class="step-desc">Every line is editable — use the step numbers above, or the links here, and nothing you have typed is lost.</p>
        </div>

        <div id="facReview" class="review-grid"></div>

        <div class="field-error" id="err-submit"><i class="fa-solid fa-circle-exclamation"></i><span id="err-submit-text">Something went wrong. Please try again.</span></div>

        <div class="step-actions">
          <button type="button" class="btn-step btn-step-back" data-back><i class="fa-solid fa-arrow-left" aria-hidden="true"></i> Back</button>
          <button type="submit" class="btn-step btn-step-next" id="facSubmit">Submit application <i class="fa-solid fa-paper-plane" aria-hidden="true"></i></button>
        </div>
      </div>

    </div>
    </form>

    <!-- Success -->
    <div class="apply-success" id="facDone" hidden>
      <div class="success-emoji" aria-hidden="true">🎼</div>
      <h2 class="success-h">Application received — one step left</h2>
      <p class="success-p">Your application is with the faculty panel, and we have emailed you a copy. <strong>Now book your interview</strong> — the panel meets every instructor before an offer, and two of us will be there.</p>
      <p class="success-ref" id="facRef"></p>
      <div class="success-actions">
        <a class="btn btn-primary" id="facBookBtn" href="/book"><i class="fa-solid fa-calendar-check" aria-hidden="true"></i> Book your interview</a>
        <a class="btn btn-secondary" href="/"><i class="fa-solid fa-house" aria-hidden="true"></i> Back to home</a>
      </div>
    </div>

    <!-- Step changes are announced here. Moving focus alone is not enough:
         a screen reader that follows focus into a heading does not say which
         step of how many, which is the whole point of a stepper. -->
    <p class="sr-only" role="status" aria-live="polite" id="facAnnounce"></p>

  </section>
</main>

<?php include __DIR__ . '/partials/footer.html'; ?>
<script src="assets/js/config.js?v=20260807a"></script>
<script src="assets/js/headshot.js?v=20260917a" defer></script>
<script src="assets/js/main.js?v=20260820a"></script>
<script src="assets/js/instructor-apply.js?v=20260917a" defer></script>
</body>
</html>
