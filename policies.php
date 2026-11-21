<?php
/**
 * Class policies — on the 2026-11 site shell (partials/site/), as safeguarding.php is.
 * Wording unchanged from the previous design. The previous page hid the three
 * policies behind tabs and a page-local currency switcher; here every policy is on
 * the page (#pol-fees, #online, #onsite, #handbook), fines stay in naira (the
 * contractual base) and fees carry data-amt-usd for the site's one currency choice.
 */
declare(strict_types=1);
require __DIR__ . '/partials/site/shell.php';

site_head(['title' => 'Class Policies — MAM Academy', 'description' => 'MAM Academy class policies and student & instructor handbook — learning model, attendance, assignments, conduct, and payments.', 'path' => '/policies']);
site_header('inner');
ob_start();
?>
<main id="main">
<?php
site_doc_open('Class policies', 'Class policies',
    'The agreements that keep every MAM class focused, fair and rewarding. You also accept the relevant policy when you apply, and we email you a signed copy.');

site_doc_section('overview', 'About these policies',
    <<<'HTML'
    <p><strong>Effective 28 August 2025.</strong> Governed by the laws of the Federal Republic of Nigeria. These policies apply together with our <a href="/terms">Terms of Service</a> and <a href="/privacy">Privacy Policy</a>.</p>
    <p>MAM Academy is committed to cultivating a structured, respectful and inspiring learning environment for every student. The policies below define the standards we uphold and the commitments we ask every student to honour. They are carefully designed to protect your learning experience, ensure fairness across all programmes, and give our instructors the conditions they need to teach at the highest level. Please read them carefully — you formally accept the applicable policy when you enrol, and we send you a confirmed copy by email.</p>
    <p>Fines are fixed in Nigerian Naira, the contractual base. Fees are shown in dollars, or in the currency you choose at the foot of the page.</p>
    HTML);

site_doc_section('pol-fees', 'Fees, Registration & Certification',
    <<<'HTML'
    <p style="font-family: 'Cormorant Garamond', Georgia, serif; font-style: italic; font-size: 22px; color: #574B40">Applies to every student — online and onsite</p>
    <ol style="margin: 0; padding-left: 1.4em; display: flex; flex-direction: column; gap: 14px">
    <li><strong>Registration &amp; Onboarding.</strong> A one-time, non-refundable registration fee of <span data-amt-usd="5">$5</span> is required to open your student file and begin the onboarding process. This fee covers administrative processing, welcome materials and your initial placement into the Academy system. It is payable once and does not recur.</li>
    <li><strong>Mode of Learning.</strong> Every student is classified at enrolment as either an <strong>Onsite</strong> or <strong>Online / Home</strong> learner. Your mode determines your programme structure, fee schedule and the policies applicable to your study. Your confirmed mode and fee are presented at checkout before payment is taken.</li>
    <li><strong>Certification Levels.</strong> MAM Academy operates a four-tier progression framework: <strong>Level 1 — Pre-Certification</strong> (foundational skills), <strong>Level 2 — Certification I</strong> (intermediate competency), <strong>Level 3 — Pre-Diploma</strong> (advanced musicianship) and <strong>Level 4 — Diploma</strong> (professional mastery). All students progress sequentially through the tiers; your entry point is determined by the MAM Auditer placement assessment, conducted prior to or at the commencement of your programme.</li>
    <li><strong>Programme Options.</strong> MAM Academy offers two principal programmes. The <strong>Certified Musician</strong> programme (from <span data-amt-usd="240">$240</span>) provides intensive training in one chosen major specialisation — Contemporary, Classical or African Music — delivered at one hour per week over twelve weeks, available online or onsite. The <strong>Grand Musician</strong> programme (from <span data-amt-usd="313">$313</span>) covers all three major specialisations concurrently, at one hour per specialisation weekly over twelve weeks, available online or onsite. Programme fees are confirmed at checkout.</li>
    <li><strong>Supporting Courses.</strong> All students are required to undertake four supporting courses alongside their major programme: <em>Rhythm</em>, <em>APE (Art of Performance Excellence)</em>, <em>Meta Music</em> and <em>Theory of Music</em> (informed by the Trinity College London syllabus). Of these four, any two are fee-bearing and any two are complimentary. Specific course allocations are confirmed during enrolment.</li>
    <li><strong>Standard Programme Format.</strong> The standard delivery format for the Certified Musician and Grand Musician programmes is twelve weeks at one hour per major course weekly, totalling twelve instructional hours per specialisation. Additional supporting courses are thirty minutes each in duration and are priced at fifty percent of the prevailing major-course fee.</li>
    <li><strong>Crash Programme.</strong> The Crash Programme is a six-week accelerated format delivering the same core curriculum as the standard twelve-week programme within a condensed timeframe. It is available both online and onsite and is designed for students with scheduling constraints or prior foundational knowledge. All policies applicable to the standard programme apply equally to the Crash Programme.</li>
    <li><strong>Learning Resources.</strong> Prescribed manuscripts are priced at <span data-amt-usd="5">$5</span> each; handout materials carry a minimum production fee; and any required textbooks or supplementary materials are sold separately at the listed price. All learning resources are additional to programme fees and are not included in the cost of tuition unless explicitly stated otherwise.</li>
    <li><strong>One-Time Events &amp; Special Sessions.</strong> The Academy periodically offers stand-alone intensive events — including <a href="/power-play">Power Play with Just-Joe Adeola</a>, professional masterclasses, Cultural Architecture sessions and short-form music intensives — typically spanning one to two days. These are booked and invoiced separately from regular programme enrolments. Attendance at these events does not substitute for or count towards regular programme hours or assessments.</li>
    <li><strong>Payment Policy &amp; Non-Refundable Fees.</strong> All payments to MAM Academy — including registration, programme tuition, supporting-course fees and resource charges — are final and non-refundable once your programme or onboarding process has commenced. This policy reflects the Academy's commitment of instructor time, studio resources and administrative capacity from the moment enrolment is confirmed. Exceptions apply only where applicable law expressly grants you a cancellation or refund right. Please refer to our <a href="/terms">Terms of Service</a> for the complete refund position and a statement of your statutory rights.</li>
    </ol>
    HTML);

site_doc_section('online', 'Policy for Online / Home Classes',
    <<<'HTML'
    <p style="font-family: 'Cormorant Garamond', Georgia, serif; font-style: italic; font-size: 22px; color: #574B40">…crafting the Timeless into Time</p>
    <ol style="margin: 0; padding-left: 1.4em; display: flex; flex-direction: column; gap: 14px">
    <li><strong>Conducive Learning Environment.</strong> Students are required to ensure that their learning environment is quiet, properly arranged and free from unnecessary interruptions for the full duration of every class. Adequate lighting, a stable internet connection and an accessible instrument or workstation must be in place before the class commences. A poor environment significantly diminishes the quality of instruction and may result in the class being paused or rescheduled at the student's expense.</li>
    <li><strong>No Calls or Phone Use During Class.</strong> Mobile phones must be set to silent or switched off before each session begins. Answering calls, engaging in messaging or using a phone for any unrelated purpose during class time is strictly prohibited. Compliance with this policy preserves the instructor's ability to deliver uninterrupted tuition and reflects the professional standards expected of every MAM student.</li>
    <li><strong>Class Readiness &amp; Punctuality.</strong> Students must be fully prepared — instruments tuned, materials at hand and connection tested — at least fifteen minutes before the scheduled start time. Classes commence promptly; students who join late without prior notification will incur a late-arrival fine of ₦500. Where a delay is unavoidable, the instructor must be notified via WhatsApp or email at least two hours before the session. A maximum of two late arrivals is permitted per programme cycle before further disciplinary action is considered.</li>
    <li><strong>Assignment Compliance.</strong> All assignments set by the instructor must be submitted by the agreed deadline. Assignments are a core component of the MAM learning framework and are designed to consolidate each week's lesson content. Late submissions — without a prior, approved extension — will attract a fine of ₦1,000 per occurrence. Habitual non-submission may be treated as a breach of the student's programme obligations.</li>
    <li><strong>Attendance &amp; Absence Notification.</strong> Regular, consistent attendance is essential to your progress and to the integrity of your programme. If you are unable to attend a scheduled class, you must notify your instructor by phone or email at least twenty-four hours in advance. A maximum of two unplanned absences is permitted during a programme cycle. Any absence without adequate prior notice, or exceeding the permitted limit, will incur a fine of ₦1,000 per occurrence and may affect your eligibility for certification.</li>
    <li><strong>Class Rescheduling.</strong> Requests to reschedule a session are considered only when submitted at least twenty-four hours before the class is due to begin. All approved reschedules are subject to an inconvenience fee of ₦2,000, payable prior to the rescheduled session being confirmed. The Academy cannot guarantee the availability of the same instructor for a rescheduled session.</li>
    <li><strong>Weekly Progress Tracking &amp; Instructor Assignment.</strong> MAM Academy maintains a progressive, written record of each student's development through weekly lesson remarks compiled by the instructor after every session. These remarks document technique, engagement, progress against the curriculum and any areas requiring focused attention. Based on this ongoing assessment, the Academy reserves the right to reassign a student to a different instructor at any time, without prior notice, where it is determined that such a change would better serve the student's learning outcomes, pace or programme requirements. Students are encouraged to focus on the curriculum and trust the Academy's professional judgement in instructor assignment.</li>
    <li><strong>Certification.</strong> A certificate of completion is awarded to students who successfully pass the programme's revision exercises and final examination. The certificate is issued by MAM Academy upon confirmation of satisfactory performance across both assessments and will reflect the student's level, specialisation and the year of completion. Certificates are not issued where a student withdraws, fails to complete assessments or is found to have breached programme policies.</li>
    <li><strong>Daily Practice Assessment.</strong> The Academy designates a daily practice window during which students may request a brief online review of their independent practice. These sessions are advisory, not instructional, and attendance is at the student's discretion. However, consistent engagement with daily practice is a strong predictor of progress and is actively encouraged as part of the MAM approach to development.</li>
    <li><strong>Device Requirements.</strong> For online classes, students are required to have two internet-connected devices available during each session: one positioned to the left of the instrument, directed at the student's hands and instrument, and one directed at the student's face for clear visual communication. This dual-device configuration allows the instructor to observe both technique and engagement simultaneously, which is fundamental to the quality of online tuition at MAM Academy.</li>
    <li><strong>Payment Policy.</strong> All fees paid to MAM Academy — including registration, tuition, supporting-course fees and resource charges — are non-refundable once your programme or onboarding has commenced. This reflects the Academy's commitment of instructor time and resources from the moment enrolment is confirmed. Exceptions apply solely where applicable law expressly confers a refund or cancellation right. Please see our <a href="/terms">Terms of Service</a> for full details.</li>
    </ol>
    HTML);

site_doc_section('onsite', 'Policy for Onsite Classes',
    <<<'HTML'
    <p style="font-family: 'Cormorant Garamond', Georgia, serif; font-style: italic; font-size: 22px; color: #574B40">…crafting the Timeless into Time</p>
    <ol style="margin: 0; padding-left: 1.4em; display: flex; flex-direction: column; gap: 14px">
    <li><strong>Punctuality &amp; Arrival Protocol.</strong> Students must arrive at the Academy premises at least thirty minutes before their scheduled class, in a calm and composed state of readiness. This allows time for instrument preparation, any necessary warm-up and the orderly transition between sessions. Arriving at or after the class start time without prior notification will incur a punctuality fine of ₦1,000. Where late arrival is unavoidable, the student must notify the Academy by phone or message at least two hours before the class is due to begin.</li>
    <li><strong>Attendance &amp; Advance Notice.</strong> Regular attendance is a core obligation of every enrolled student. If you are unable to attend a scheduled session for any reason, you must notify the Academy in writing — by email or WhatsApp — at least twenty-four hours in advance. Failure to provide timely notice will incur an absence fine of ₦2,000. Repeated or unexplained absences will be reviewed by the Academic Director and may result in forfeiture of missed sessions without the right to make them up.</li>
    <li><strong>Assignment Compliance &amp; Daily Practice.</strong> All assignments must be completed and submitted by the deadline communicated by the instructor. Late submission incurs a fine of ₦1,000 per occurrence. As part of the Academy's commitment to consistent progression, students are expected to document their daily practice and assignment progress through a brief one-minute video submission. These recordings support the instructor's weekly assessment and ensure that learning continues productively between sessions.</li>
    <li><strong>Mobile Phone Policy.</strong> All mobile phones must be switched off or set to silent before entering the classroom or studio. The use of phones during instructional time — including messaging, social media, photography or calls — is strictly prohibited. The Academy's learning environment is designed to be distraction-free; breaches of this policy may result in removal from the session and a formal disciplinary note being placed on the student's record.</li>
    <li><strong>Decorum &amp; Participation.</strong> Students are expected to conduct themselves with respect, professionalism and a genuine commitment to learning at all times whilst on Academy premises. Active, attentive participation in every class activity is required. Disruptive behaviour, disrespect towards instructors or fellow students, or consistent disengagement will be addressed by the Academy and may result in suspension from the programme without refund of fees.</li>
    <li><strong>Weekly Progress Tracking &amp; Instructor Assignment.</strong> MAM Academy maintains a progressive, written record of each student's development through weekly lesson remarks compiled by the instructor following every session. These remarks form part of the student's official academic record and inform decisions about curriculum pacing, level progression and support requirements. Based on this ongoing assessment, the Academy reserves the right to reassign a student to a different instructor at any time, without prior notice, where such a change is judged to be in the student's best educational interest. Instructor assignment is an academic decision made solely by the Academy's management.</li>
    <li><strong>Certification.</strong> A certificate of completion is awarded upon the successful completion of a thorough revision session followed by a formal examination. The certificate reflects the student's discipline, level of achievement and year of completion and is issued under the authority of MAM Academy. Students who fail to appear for assessments, withdraw from the programme or are found to have breached Academy policies during the programme cycle are not eligible for certification.</li>
    <li><strong>Daily Practice Commitment.</strong> Structured, purposeful daily practice is the foundation of musical development and is an expectation of every student enrolled with MAM Academy. Students should set aside dedicated practice time each day to consolidate and internalise the concepts taught in class. The Academy's instructors monitor practice progress as part of the weekly lesson remark system, and consistent practice forms a component of the student's overall assessment.</li>
    <li><strong>Payment Policy.</strong> All fees paid to MAM Academy — including registration, tuition, supporting-course fees and resource charges — are non-refundable once your programme or onboarding has commenced, as the Academy commits instructor time, studio availability and administrative resources from the moment enrolment is confirmed. Exceptions apply only where applicable law expressly provides a refund or cancellation right. Please see our <a href="/terms">Terms of Service</a> for the complete position and a statement of your statutory rights.</li>
    </ol>
    HTML);

site_doc_section('handbook', 'Student & Instructor Handbook',
    <<<'HTML'
    <p><strong>Effective 28 August 2025 &nbsp;·&nbsp; MAM Academy, Lagos</strong></p>
    <p>This Handbook sets out the academic standards, personal responsibilities, and conduct expectations that apply to every student and instructor at MAM Academy. It is binding from your first session. Ignorance of these provisions is not a defence in any conduct or compliance matter.</p>
    <h3 style="margin: 16px 0 0; font-family: 'Cormorant Garamond', Georgia, serif; font-weight: 600; font-size: 28px; line-height: 1.1; color: #211A13">Part I — Learning Model &amp; Contact Sessions</h3>
    <h4 style="margin: 8px 0 0; font-size: 18px; font-weight: 600; color: #211A13">§1 — The Student-Led Guided Learning Model</h4>
    <p>MAM Academy operates a <strong>student-led guided learning model</strong>. Sessions are a space to demonstrate, deepen, and refine what you have already prepared — not to encounter material for the first time. Every student must undertake meaningful preparation before arriving at each session.</p>
    <ul><li><strong>70%</strong> — Student-led engagement per session</li><li><strong>30%</strong> — Instructor guidance &amp; facilitation</li></ul>
    <p style="padding: 4px 0 4px 20px; border-left: 3px solid #C27A12">Your first obligation is to your own study. MAM Academy equips and challenges you — it does not substitute for the independent work that happens between sessions.</p>
    <h4 style="margin: 8px 0 0; font-size: 18px; font-weight: 600; color: #211A13">§2 — Contact Session Types</h4>
    <ul>
    <li><strong>Weekly Contact Sessions</strong> — the primary learning session, student-led and instructor-facilitated</li>
    <li><strong>Review Sessions</strong> — periodic assessments of progress across the current module</li>
    <li><strong>Follow-Up / Clarification Sessions</strong> — 20–30 minute slots booked by the student for targeted questions after independent study; these are not full teaching sessions and require their own preparation</li>
    </ul>
    <h4 style="margin: 8px 0 0; font-size: 18px; font-weight: 600; color: #211A13">§3 — Session Conduct</h4>
    <ul>
    <li>Demonstrate preparation on the week's material</li>
    <li>Lead discussion and practical exploration of the topic</li>
    <li>Present questions that require the instructor's expertise — not questions answerable by self-study</li>
    <li>Apply feedback in real time and document action points</li>
    <li>Maintain discipline: punctuality, attentiveness, no mobile-device use except for reference</li>
    <li>Communicate at least 24 hours in advance if unable to attend</li>
    </ul>
    <p style="padding: 4px 0 4px 20px; border-left: 3px solid #C27A12"><strong>AI Use Policy</strong> — You may use AI tools for reference and exploration, but not as a substitute for independent study or practice. Work submitted or demonstrated in sessions must represent your own genuine effort. Presenting AI-generated output as personal work is a disciplinary matter.</p>
    <h4 style="margin: 8px 0 0; font-size: 18px; font-weight: 600; color: #211A13">§4 — Theory-Based Courses</h4>
    <p>Written or recorded submissions for theory-based modules must be made within <strong>48 hours</strong> of the session in which the work was set. Late submissions may be marked zero at the instructor's discretion.</p>
    <ul><li><strong>48h</strong> — Submission window for theory-based module work</li></ul>
    <h3 style="margin: 16px 0 0; font-family: 'Cormorant Garamond', Georgia, serif; font-weight: 600; font-size: 28px; line-height: 1.1; color: #211A13">Part II — Student Responsibilities</h3>
    <p>Enrolment at MAM Academy carries obligations beyond attendance. Each student commits to the following from their first session:</p>
    <ul>
    <li><strong>Pre-session preparation</strong> — study materials, listen to references, and practise required exercises before every session</li>
    <li><strong>Portal use</strong> — access the student portal regularly; check schedules, submitted work, results, and announcements</li>
    <li><strong>Academic honesty</strong> — all submitted work must be your own; no plagiarism or misrepresentation of AI-assisted content</li>
    <li><strong>Respectful conduct</strong> — treat instructors, classmates, and staff with courtesy at all times, in session and online</li>
    <li><strong>Materials access</strong> — secure all required textbooks, instruments and digital tools before programme commencement, or within the first week</li>
    <li><strong>Punctuality</strong> — arrive on time; late arrival of more than 10 minutes counts as an absence unless the instructor permits otherwise</li>
    <li><strong>Communication</strong> — notify your instructor and the Academy at least 24 hours before any absence; extended absence requires written notice</li>
    <li><strong>Feedback acceptance</strong> — receive instructor feedback with openness; challenge respectfully through proper channels, not in the moment of delivery</li>
    <li><strong>Academy values</strong> — uphold the principles of discipline, honour and respect that define the MAM community</li>
    <li><strong>Financial obligations</strong> — maintain your fee account in good standing; contact the Academy promptly if you anticipate difficulty meeting a payment</li>
    </ul>
    <h3 style="margin: 16px 0 0; font-family: 'Cormorant Garamond', Georgia, serif; font-weight: 600; font-size: 28px; line-height: 1.1; color: #211A13">Part III — Attendance</h3>
    <ul><li><strong>75%</strong> — Minimum attendance per module cycle</li></ul>
    <p>Students must attend a minimum of <strong>75% of scheduled sessions</strong> within each module cycle. Falling below this threshold results in:</p>
    <ul>
    <li>Ineligibility for module completion certification for that cycle</li>
    <li>A formal attendance notice issued by the Academy</li>
    <li>A mandatory review meeting before re-enrolment in the next cycle</li>
    <li>Continued non-attendance may be treated as withdrawal; fees forfeited per the Payments policy</li>
    </ul>
    <p style="padding: 4px 0 4px 20px; border-left: 3px solid #C27A12">Attendance is recorded by your instructor at the close of each session. QR self-check-in supplements instructor records, but the instructor's mark is final.</p>
    <h3 style="margin: 16px 0 0; font-family: 'Cormorant Garamond', Georgia, serif; font-weight: 600; font-size: 28px; line-height: 1.1; color: #211A13">Part IV — Assignments &amp; Instruction Compliance</h3>
    <h4 style="margin: 8px 0 0; font-size: 18px; font-weight: 600; color: #211A13">§5 — Assignment Policy</h4>
    <ul>
    <li>Submit by the deadline communicated at the time of setting</li>
    <li>Submit via the student portal unless the instructor specifies otherwise in writing</li>
    <li>Present work that reflects genuine personal effort — not copied, AI-generated, or reproduced from another student's submission</li>
    <li>Accept and action feedback; re-submission of revised work may be required at the instructor's discretion</li>
    </ul>
    <h4 style="margin: 8px 0 0; font-size: 18px; font-weight: 600; color: #211A13">§6 — Instruction Compliance</h4>
    <p>Students are required to follow all reasonable academic and administrative instructions issued by MAM Academy staff. Persistent non-compliance is a disciplinary matter handled through the Corrective Measures framework below.</p>
    <h3 style="margin: 16px 0 0; font-family: 'Cormorant Garamond', Georgia, serif; font-weight: 600; font-size: 28px; line-height: 1.1; color: #211A13">Part V — Corrective Measures</h3>
    <p>MAM Academy addresses conduct and compliance concerns through a structured, proportionate framework. Each instance is documented in the student's record.</p>
    <ol style="margin: 0; padding-left: 1.4em; display: flex; flex-direction: column; gap: 14px"><li><strong>1st instance:</strong> <strong>Verbal advisory</strong> — a private conversation with the instructor or Academy coordinator. A written record is made. No formal penalty.</li><li><strong>2nd instance:</strong> <strong>Written warning + fine of ₦500</strong> — a formal written notice is issued and the fine is posted to the student's account.</li><li><strong>3rd instance:</strong> <strong>Written warning + fine of ₦1,000</strong> — a second formal notice is issued; a review meeting with Academy management may be required.</li><li><strong>4th+ instance:</strong> <strong>Fine of ₦3,000 and possible suspension</strong> — the Academy reserves the right to suspend the student pending a full conduct review.</li></ol>
    <p style="padding: 4px 0 4px 20px; border-left: 3px solid #C27A12">Suspension removes the student from all scheduled sessions and freezes portal access for the duration of the review. Fees are not paused or refunded during a suspension period.</p>
    <p>Severe single instances — including physical aggression, theft, harassment, or deliberate submission of another student's work — may bypass the graduated ladder and result in immediate suspension or expulsion without refund.</p>
    <h3 style="margin: 16px 0 0; font-family: 'Cormorant Garamond', Georgia, serif; font-weight: 600; font-size: 28px; line-height: 1.1; color: #211A13">Part VI — Payments &amp; Withdrawal</h3>
    <h4 style="margin: 8px 0 0; font-size: 18px; font-weight: 600; color: #211A13">§7 — Payment Policy</h4>
    <p>Programme fees must be paid in full — or the agreed instalment settled — before or on the first day of each programme cycle. Overdue accounts attract a late-payment notice; continued non-payment may result in suspension of portal access and exclusion from sessions until the account is settled.</p>
    <ul>
    <li>The booking fee is separate from tuition and is not credited against it</li>
    <li>Agreed payment plans must be honoured on the agreed schedule</li>
    <li>Receipts and account statements are available at any time in the student portal under <strong>Payments</strong></li>
    </ul>
    <h4 style="margin: 8px 0 0; font-size: 18px; font-weight: 600; color: #211A13">§8 — Refund &amp; Withdrawal</h4>
    <p style="padding: 4px 0 4px 20px; border-left: 3px solid #C27A12"><strong>Fees are non-refundable once a programme has commenced</strong> — this applies to programme fees, registration fees, and the booking fee. See <a href="/terms">Terms of Service §3</a> for the full refund policy and your statutory rights.</p>
    <p>As an alternative to withdrawal, enrolled students may apply to pause their programme for up to four weeks per cycle. Approval is at the Academy's discretion and must be requested in writing before the pause begins. Write to <a href="mailto:academy@mamcentre.org">academy@mamcentre.org</a>.</p>
    <h3 style="margin: 16px 0 0; font-family: 'Cormorant Garamond', Georgia, serif; font-weight: 600; font-size: 28px; line-height: 1.1; color: #211A13">Part VII — Instructor Responsibilities</h3>
    <p>Instructors at MAM Academy are mentors, facilitators, and role models. By accepting an instructional appointment, each instructor commits to:</p>
    <ul>
    <li><strong>Professionalism</strong> — punctuality, preparation, and discipline at every session; conduct consistent with the Academy's standards at all times</li>
    <li><strong>Fair mentoring</strong> — equal commitment to every student regardless of background, level or pace of progress</li>
    <li><strong>Faithful facilitation</strong> — honour the 30% instructor role; resist the temptation to front-load sessions with lecture content</li>
    <li><strong>Objective assessment</strong> — grade and provide feedback on student work impartially and constructively; apply Academy rubrics consistently</li>
    <li><strong>Record keeping</strong> — mark attendance promptly and submit session notes and grades by the deadlines published in the instructor portal</li>
    <li><strong>Independent learning support</strong> — actively encourage students to develop autonomy; do not create intellectual or practical dependency</li>
    <li><strong>Academy values</strong> — model discipline, honour and respect; address student misconduct through proper channels rather than informally</li>
    <li><strong>Reputation</strong> — do not make public statements about students, other instructors, or the Academy that could cause reputational harm</li>
    </ul>
    <p style="padding: 4px 0 4px 20px; border-left: 3px solid #C27A12">Instructor appointments are reviewed periodically. Persistent non-compliance with these responsibilities may result in the Academy terminating the instructional arrangement.</p>
    <h3 style="margin: 16px 0 0; font-family: 'Cormorant Garamond', Georgia, serif; font-weight: 600; font-size: 28px; line-height: 1.1; color: #211A13">Part VIII — General Provisions</h3>
    <h4 style="margin: 8px 0 0; font-size: 18px; font-weight: 600; color: #211A13">§9 — Amendments</h4>
    <p>MAM Academy reserves the right to amend this Handbook at any time. Material changes will be communicated through the student and instructor portals with at least seven days' notice before taking effect. Continued enrolment or instructional appointment after the effective date constitutes acceptance of the revised terms.</p>
    <h4 style="margin: 8px 0 0; font-size: 18px; font-weight: 600; color: #211A13">§10 — Failure to Read</h4>
    <p>All enrolled students and instructors are considered to have read and understood this Handbook from their first session. The Academy will not accept ignorance of these provisions as a mitigating factor in any conduct or compliance matter covered here.</p>
    <p style="padding: 4px 0 4px 20px; border-left: 3px solid #C27A12"><strong>Statement of Commitment</strong> — Joining MAM Academy is a voluntary choice and a meaningful commitment. We hold ourselves to the same standards we ask of you: quality instruction, honest feedback, and a learning environment built on mutual respect.</p>
    <p>Questions about this Handbook: <a href="mailto:academy@mamcentre.org">academy@mamcentre.org</a></p>
    HTML);

site_doc_section('commitment', 'Our commitment',
    <<<'HTML'
    <p>By enrolling you join us in crafting a timeless legacy of growth, excellence and achievement.</p>
    <p><strong>Academic Director</strong> · MAM Centre, 2 Oremeji Street, Egbeda, Lagos · <a href="mailto:academy@mamcentre.org">academy@mamcentre.org</a> · +234-704-444-6517</p>
    HTML);

?>
</main>
<?php
echo (string) ob_get_clean();
site_footer();
