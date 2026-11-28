<?php require __DIR__ . '/partials/site/shell.php'; ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<meta name="color-scheme" content="dark">
<meta name="robots" content="index, follow">
<title>Teach at MAM — Instructor Application | MAM Academy</title>
<meta name="description" content="Apply to teach at MAM Academy. Choose the courses and levels you are qualified to teach, tell us about your training and experience, and book your faculty interview.">
<link rel="canonical" href="https://academy.mamcentre.org/instructor-apply">
<link rel="icon" type="image/svg+xml" href="mam-academy-logo.svg">
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=Cinzel:wght@600;700&family=Cormorant+Garamond:ital,wght@0,400;0,600;0,700;1,400&family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" referrerpolicy="no-referrer">
<link rel="stylesheet" href="assets/css/main.css?v=20260920">
<link rel="stylesheet" href="assets/css/apply.css?v=20260918a">
</head>
<?php /* The site-wide lead surfaces are opted OUT here. `lead.js` skips its
         CTA bar on the student apply page via a path test that reads
         `/apply`, and `/instructor-apply` does not match it — so a
         prospective TEACHER was shown a sticky "Next intake is open —
         five-step application" bar for the STUDENT wizard, overlapping
         this form's own controls. Wrong audience, wrong step count, and
         it covers the matrix on a phone.

         `data-fac-app` is the v2 rework's own hook. It hides the announce
         bar and the 80px site nav (apply.css), which together with the
         44px card radius and the 3px gradient rule were MEASURED pushing
         the first question below the fold at 390px. The header partial is
         still included: it also carries the sitewide JSON-LD entity, which
         is not chrome. */ ?>
<body data-no-lead-cta data-no-lead-exit data-fac-app data-fac-state="loading">
<a href="#main" class="skip-link">Skip to main content</a>

<?php include __DIR__ . '/partials/header.html'; ?>

<main id="main">
  <section class="apply-shell" aria-label="Instructor application">

    <!-- App bar. One row: where you are, and the way out. The draft is already
         saved on every keystroke, so "Save & exit" is an honest label for a
         link home rather than a button that does something extra. -->
    <div class="fac-appbar js-only" id="facAppbar">
      <div class="fac-appbar-left">
        <?php /* The site nav is hidden on this page, so this is the ONLY way
                 home and the only branding. It has to be here in every state:
                 a candidate refused at the gate was otherwise left on a bare
                 wall of text with no logo and nowhere to go. Below 1024px the
                 logo and title show only OUTSIDE the form — inside it the
                 design is explicit that the wordmark is gone from the form
                 chrome, and the bar carries the step position instead. */ ?>
        <a class="fac-appbar-home" href="/">
          <img class="fac-appbar-logo" src="mam-academy-logo.svg" alt="" width="30" height="30">
          <span class="fac-appbar-title">Faculty application</span>
        </a>
        <span class="fac-appbar-sep" aria-hidden="true">·</span>
        <button type="button" class="fac-back" id="facBack" hidden>
          <i class="fa-solid fa-arrow-left" aria-hidden="true"></i>
          <span class="sr-only">Back to the previous step</span>
        </button>
        <span class="fac-appbar-pos" id="facPos" hidden>1 of 4</span>
      </div>
      <a class="fac-exit" href="/" id="facExit" hidden>Save &amp; exit</a>
    </div>

    <!-- Progress. 2px, replacing the seven-dot stepper. `aria-current="step"`
         on the desktop step list is what a screen reader reads as position;
         this line is decoration, hence aria-hidden. -->
    <div class="fac-prog js-only" id="facProgWrap" aria-hidden="true" hidden><span class="fac-prog-fill" id="facProgFill" style="width:25%"></span></div>

    <!--
      Gate. Three states, resolved by JS against GET /api/faculty-apply:
        · open applications on            → the wizard, email typed by the candidate
        · a valid ?token=… invite         → the wizard, email fixed by the invite
        · invite-only and no valid token  → an explanation and a way to ask
      The wizard stays hidden until one of them resolves, so a candidate never
      fills a form that was never going to accept them.
    -->
    <?php /* MEASURED with scripting off: the page rendered a spinner reading
             "Loading the application…" above a stepper that does nothing, and
             sat there. The whole wizard is JS — the matrix is built from the
             live catalogue — so the honest answer is to say so and give a way
             through, not to imply something is still loading. */ ?>
    <noscript>
      <div class="fac-gate">
        <i class="fa-solid fa-circle-info" aria-hidden="true"></i>
        <p><strong>This application needs JavaScript.</strong> It builds the list of courses and levels from what the Academy currently teaches, so it cannot be shown as a plain page.</p>
        <p>Please turn JavaScript on and reload — or <a href="/contact?topic=Faculty">email the faculty team</a> and we will send you the questions directly. We would rather hear from you than lose you to a browser setting.</p>
      </div>
    </noscript>

    <div class="fac-gate js-only" id="facGate" role="status" aria-live="polite">
      <i class="fa-solid fa-circle-notch fa-spin" aria-hidden="true"></i>
      Loading the application…
    </div>

    <?php /* `novalidate` for the same reason the student wizard carries it:
             every step stays in the DOM while hidden, so a `required` control
             on a hidden step makes the form unsubmittable with "An invalid form
             control is not focusable" — an error the candidate can neither see
             nor fix. validateStep() gates each step; the attributes remain as a
             machine-readable description and a screen-reader "required". */ ?>
    <form id="facForm" novalidate autocomplete="on" hidden>
    <div class="fac-layout">
      <div class="fac-col">

      <!-- Draft notice. A multi-step application is abandoned without one; the
           student wizard has the same affordance and the same class. -->
      <div class="resume-banner" id="facResume" hidden>
        <i class="fa-solid fa-floppy-disk" aria-hidden="true"></i>
        <span>We saved your draft on this device. <button type="button" id="facDiscard">Start over</button></span>
      </div>

      <!-- ─────────────────────────────── STEP 1 — Courses ──────────────── -->
      <div class="step active" data-step="0">
        <div class="fac-col">
          <h2 class="fac-h" id="facH0">What do you teach?</h2>
          <?php /* The old page's four-line paragraph is gone. Its one
                   load-bearing idea survives where there is room for it —
                   desktop only, in the repo's own words. */ ?>
          <p class="fac-lede">Claim what you can genuinely teach rather than everything on the list — it is what we match you to a class with.</p>

          <div class="fac-list" id="facMatrix" role="group" aria-labelledby="facH0"></div>
          <div class="fac-err" id="err-subjects"><i class="fa-solid fa-circle-exclamation" aria-hidden="true"></i><span>Choose at least one course you can teach</span></div>
        </div>
      </div>

      <!-- ─────────────────────────────── STEP 2 — You ──────────────────── -->
      <div class="step" data-step="1">
        <div class="fac-col">
          <h2 class="fac-h" id="facH1">How do we reach you?</h2>

          <div class="fac-fields">
            <label class="fac-field" for="f-name">
              <span class="fac-field-label">Full name <span class="req" aria-hidden="true">*</span></span>
              <input type="text" id="f-name" class="fac-input" maxlength="160" autocomplete="name" placeholder="Adaeze Obi" required>
              <span class="fac-err" id="err-f-name"><i class="fa-solid fa-circle-exclamation" aria-hidden="true"></i><span>Enter your full name</span></span>
            </label>

            <label class="fac-field" for="f-email">
              <span class="fac-field-label">Email <span class="req" aria-hidden="true">*</span></span>
              <input type="email" id="f-email" class="fac-input" maxlength="190" autocomplete="email" placeholder="you@example.com" required>
              <span class="fac-help" id="f-email-help">Becomes your portal sign-in if you join.</span>
              <span class="fac-err" id="err-f-email"><i class="fa-solid fa-circle-exclamation" aria-hidden="true"></i><span>Enter a valid email address</span></span>
            </label>

            <label class="fac-field" for="f-phone">
              <span class="fac-field-label">Phone</span>
              <input type="tel" id="f-phone" class="fac-input" maxlength="40" autocomplete="tel" placeholder="+234 …">
            </label>

            <label class="fac-field" for="f-location">
              <span class="fac-field-label">City</span>
              <input type="text" id="f-location" class="fac-input" maxlength="190" autocomplete="address-level2" placeholder="Yaba, Lagos">
            </label>
          </div>

          <?php /* Moved here from the old step 1: it is a logistics question,
                   not a teaching one. */ ?>
          <fieldset class="fac-block">
            <legend class="fac-legend">You can teach</legend>
            <div class="fac-opts" id="facModes"></div>
          </fieldset>

          <!-- Replaces a 220px dashed dropzone. The uploader beneath is
               untouched — same mount, same element, same `#hsStatus`
               role="status" region. -->
          <div class="fac-shot">
            <button type="button" class="fac-shot-toggle" id="facShotToggle" aria-expanded="false" aria-controls="facShotPanel">
              <span class="fac-shot-icon" id="facShotIcon"><i class="fa-solid fa-camera" aria-hidden="true"></i></span>
              <span class="fac-shot-label">Add a headshot</span>
              <span class="fac-shot-state" id="facShotState">Optional</span>
            </button>
            <div class="fac-shot-panel" id="facShotPanel" hidden>
              <div class="headshot-mount" id="facHeadshot"></div>
              <label class="fac-field" for="f-shot">
                <span class="fac-field-label">Or paste an image link</span>
                <input type="url" id="f-shot" class="fac-input" maxlength="500" placeholder="https://…">
              </label>
            </div>
          </div>
        </div>
      </div>

      <!-- ─────────────────────────────── STEP 3 — Track record ─────────── -->
      <div class="step" data-step="2">
        <div class="fac-col">
          <h2 class="fac-h" id="facH2">Tell us about your teaching</h2>

          <label class="fac-field" for="f-bio">
            <span class="fac-field-label">Your biography <span class="req" aria-hidden="true">*</span></span>
            <textarea id="f-bio" class="fac-textarea" maxlength="4000" rows="5" required placeholder="Where you trained, where you have taught, students you have taken through exams…"></textarea>
            <span class="fac-help">Becomes your faculty profile.</span>
            <span class="fac-err" id="err-f-bio"><i class="fa-solid fa-circle-exclamation" aria-hidden="true"></i><span>Please write a fuller biography (at least 40 characters)</span></span>
          </label>

          <div class="fac-pair">
            <label class="fac-field" for="f-years">
              <span class="fac-field-label">Years teaching</span>
              <input type="number" id="f-years" class="fac-input" min="0" max="60" inputmode="numeric" placeholder="6">
            </label>
            <label class="fac-field" for="f-hours">
              <span class="fac-field-label">Hours a week</span>
              <input type="number" id="f-hours" class="fac-input" min="0" max="80" inputmode="numeric" placeholder="10">
            </label>
          </div>

          <?php /* The legend is the fieldset's FIRST child, with the timezone
                   inside it. Nested in a wrapper div it is not the fieldset's
                   caption at all — browsers render it, but the group loses its
                   accessible name, and it is invalid besides. */ ?>
          <fieldset class="fac-block">
            <legend class="fac-legend fac-legend-row">
              <span>When could you teach?</span>
              <span class="fac-meta">Lagos time</span>
            </legend>
            <div class="fac-week" id="facAvail"></div>
          </fieldset>

          <?php /* The handoff cuts five questions from this step and is
                   explicit that they must not be dropped from the PRODUCT:
                   "move them to the post-submit interview booking, or restore
                   them behind a 'Add more detail (optional)' disclosure on this
                   step." This is that disclosure — the questions keep their
                   ids, their validation and their place in the API payload,
                   and they are simply off the critical path. The optional
                   programmes chip group, which the four-step design has no slot
                   for, lives here rather than being left half-wired. */ ?>
          <details class="fac-disc" id="facMore">
            <summary><i class="fa-solid fa-plus" aria-hidden="true"></i>Add more detail (optional)</summary>
            <div class="fac-disc-body">
              <label class="fac-field" for="f-quals">
                <span class="fac-field-label">Qualifications, grades and certifications</span>
                <textarea id="f-quals" class="fac-textarea" maxlength="2000" rows="4" placeholder="BMus (University of Lagos); Trinity ATCL Piano Recital; ABRSM Grade 8 Theory…"></textarea>
              </label>

              <label class="fac-field" for="f-philosophy">
                <span class="fac-field-label">How do you teach?</span>
                <textarea id="f-philosophy" class="fac-textarea" maxlength="4000" rows="4" placeholder="How you start with a nervous beginner, what you think a good lesson feels like…"></textarea>
                <span class="fac-help">The thing we most enjoy reading.</span>
              </label>

              <label class="fac-field" for="f-cv">
                <span class="fac-field-label">CV / résumé link</span>
                <input type="url" id="f-cv" class="fac-input" maxlength="500" placeholder="https://…">
              </label>

              <label class="fac-field" for="f-links">
                <span class="fac-field-label">Links — portfolio, recordings, socials</span>
                <input type="text" id="f-links" class="fac-input" maxlength="1000" placeholder="Separate several with spaces">
              </label>

              <label class="fac-field" for="f-start">
                <span class="fac-field-label">Earliest you could start</span>
                <input type="date" id="f-start" class="fac-input">
                <span class="fac-err" id="err-f-start"><i class="fa-solid fa-circle-exclamation" aria-hidden="true"></i><span>Use the date picker, or leave it blank</span></span>
              </label>

              <fieldset class="fac-block">
                <legend class="fac-legend">Programmes you could teach on</legend>
                <div class="fac-opts fac-opts-flow" id="facProgrammes"></div>
                <span class="fac-help">Leave it blank if you are happy to be considered for any.</span>
              </fieldset>
            </div>
          </details>
        </div>
      </div>

      <!-- ─────────────────────────────── STEP 4 — References ───────────── -->
      <div class="step" data-step="3">
        <div class="fac-col">
          <h2 class="fac-h" id="facH3">Two people we can call</h2>

          <?php /* The 164-word gold panel is now one sentence plus a
                   disclosure. What stays on the visible surface is the
                   commitment itself: safer-recruitment guidance is explicit
                   that the commitment and the fact of pre-employment checks
                   belong where the candidate actually reads them, not in a
                   policy nobody opens.

                   Note what is NOT asked for anywhere on this form: a
                   certificate number, a document scan, or anything about an
                   offence. A verifier records the OUTCOME of a check against
                   the instructor's record; the evidence is filed offline,
                   deliberately (vetting_records stores no check content). */ ?>
          <?php /* A DIV, not a <p>. `<details>` is flow content, not phrasing
                   content, so the parser CLOSES an open <p> when it reaches it —
                   MEASURED: "What we check" left the sentence and rendered as a
                   block on its own line, 144px down, whatever `display:inline`
                   said. The disclosure has to sit in an element that may legally
                   contain it. */ ?>
          <div class="fac-note">Both references are taken up before any offer. Contacts only — never certificates or document numbers.
            <details class="fac-inline-disc">
              <summary>What we check</summary>
              <div class="fac-inline-body">
                <p><strong>MAM Academy is committed to safeguarding children.</strong> Every instructor is checked before they teach: we take up both of your references, confirm your identity, and require current safeguarding clearances. An offer always follows those checks — never precedes them.</p>
                <p>We ask for your referees' contact details here, and nothing else. <strong>Please do not upload certificates or enter any document numbers</strong> — we collect that evidence in person, and record only that a check was completed and by whom.</p>
              </div>
            </details>
          </div>

          <div class="fac-fields">
            <label class="fac-field" for="r1-name">
              <span class="fac-field-label">Name <span class="req" aria-hidden="true">*</span></span>
              <input type="text" id="r1-name" class="fac-input" maxlength="160" required placeholder="Mr Tunde Bello">
            </label>
            <label class="fac-field" for="r1-rel">
              <span class="fac-field-label">How do they know you?</span>
              <input type="text" id="r1-rel" class="fac-input" maxlength="120" placeholder="Head of Music, my last school">
            </label>
            <label class="fac-field" for="r1-contact">
              <span class="fac-field-label">Email or phone</span>
              <input type="text" id="r1-contact" class="fac-input" maxlength="190" placeholder="tunde@school.ng">
            </label>
          </div>

          <?php /* Folded away until referee 1 is under way, and still REQUIRED
                   before submit — `err-referees` is unchanged. This is
                   progressive disclosure, not a relaxation. JS opens it as soon
                   as referee 2 has content, so a restored draft never hides an
                   answer the candidate already gave. */ ?>
          <details class="fac-add" id="facRef2">
            <summary>
              <i class="fa-solid fa-plus" aria-hidden="true"></i>
              <span class="fac-add-label">Add second referee</span>
            </summary>
            <div class="fac-add-body">
              <label class="fac-field" for="r2-name">
                <span class="fac-field-label">Name <span class="req" aria-hidden="true">*</span></span>
                <input type="text" id="r2-name" class="fac-input" maxlength="160" required>
              </label>
              <label class="fac-field" for="r2-rel">
                <span class="fac-field-label">How do they know you?</span>
                <input type="text" id="r2-rel" class="fac-input" maxlength="120">
              </label>
              <label class="fac-field" for="r2-contact">
                <span class="fac-field-label">Email or phone</span>
                <input type="text" id="r2-contact" class="fac-input" maxlength="190">
              </label>
            </div>
          </details>
          <div class="fac-err" id="err-referees"><i class="fa-solid fa-circle-exclamation" aria-hidden="true"></i><span>Please give us two referees we can contact — at least their names</span></div>

          <?php /* ⚠️ The consent WORDING is the currently-approved wording, not
                   the handoff's trimmed draft. The handoff's own open question
                   4 asks the Designated Safeguarding Officer to re-approve the
                   trim, and its draft drops "I have read and" from the policy
                   limb and narrows "any safeguarding concern" to "any concern"
                   — both substantive. The LAYOUT is the handoff's: no card, no
                   uppercase heading, 24px box, 14px/1.6 text. Swap the strings
                   once the DSO has signed off; nothing else needs to change. */ ?>
          <div class="fac-consents">
            <label class="fac-consent" for="f-safeack">
              <input type="checkbox" id="f-safeack" required>
              <span class="fac-consent-box" aria-hidden="true"><i class="fa-solid fa-check"></i></span>
              <span class="fac-consent-text">I understand that MAM Academy will carry out safeguarding checks — including taking up both references — before I teach any student, and I consent to those checks.</span>
            </label>
            <div class="fac-err" id="err-safeack"><i class="fa-solid fa-circle-exclamation" aria-hidden="true"></i><span>We cannot accept an application without the safeguarding consent</span></div>
            <label class="fac-consent" for="f-polack">
              <input type="checkbox" id="f-polack" required>
              <span class="fac-consent-box" aria-hidden="true"><i class="fa-solid fa-check"></i></span>
              <span class="fac-consent-text">I have read and agree to abide by the Academy's child-protection and code-of-conduct policies, and I will report any safeguarding concern to the Designated Safeguarding Officer.</span>
            </label>
            <div class="fac-err" id="err-polack"><i class="fa-solid fa-circle-exclamation" aria-hidden="true"></i><span>We cannot accept an application without the policy agreement</span></div>
          </div>

          <!-- Honeypot: humans never see this. A class rather than an inline
               style — `style="position:absolute;left:-9999px"` is the one thing
               a strict CSP cannot allow. -->
          <div class="hp-field" aria-hidden="true"><label>Website<input type="text" id="f-web" tabindex="-1" autocomplete="off"></label></div>

          <div class="fac-err" id="err-submit"><i class="fa-solid fa-circle-exclamation" aria-hidden="true"></i><span id="err-submit-text">Something went wrong. Please try again.</span></div>
        </div>
      </div>

      <!-- The action bar. Fixed on touch, a static actions row above a rule on
           desktop — the handoff's two states, not two components. -->
      <div class="fac-bar">
        <span class="fac-bar-status" id="facBarStatus"></span>
        <button type="submit" class="fac-next" id="facNext">
          <span id="facNextLabel">Next</span>
          <i class="fa-solid fa-arrow-right" id="facNextIcon" aria-hidden="true"></i>
        </button>
      </div>

      </div><!-- /.fac-col -->

      <!-- Running summary. Desktop only: the extra width buys a summary, not a
           second column of questions. -->
      <aside class="fac-aside" aria-label="Your progress">
        <div class="fac-steps" id="facSteps"></div>
        <div class="fac-sofar" id="facSoFar" hidden>
          <span class="fac-sofar-head">So far</span>
          <div id="facSoFarList"></div>
          <p class="fac-sofar-foot">Highest level is enough — everything below it is assumed.</p>
        </div>
      </aside>

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
<script src="assets/js/config.js?v=20261128u"></script>
<script src="assets/js/headshot.js?v=20260817a" defer></script>
<script src="assets/js/main.js?v=20260920"></script>
<script src="assets/js/instructor-apply.js?v=20260918a" defer></script>
</body>
</html>
