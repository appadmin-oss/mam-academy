<?php
/**
 * The platform status catalogue — every state a person can be shown, in words.
 * ─────────────────────────────────────────────────────────────
 * This file is the SOURCE for three things that must agree, and used to be
 * written three times:
 *
 *   1. the vocabulary itself (which values a status column may hold),
 *   2. the CHECK constraints in db/schema-v2.sql that enforce it,
 *   3. the rows in `status_definitions` that turn a value into a sentence.
 *
 * `test_v2_clarity.php` asserts all three match. So a new status cannot be
 * added without wording it, and wording cannot drift from what the database
 * accepts — which is how v1 ended up showing learners raw strings like
 * `carried_forward`.
 *
 * ── Writing rules, because this file IS the product ──
 *  • Second person, present tense, no system vocabulary. The reader should
 *    never meet a word they would not use themselves.
 *  • `meaning` answers "what does this mean for me", in ONE sentence.
 *  • `next` answers "what do I do now", or is null — and null is a real answer
 *    worth showing ("nothing is needed from you") rather than a blank.
 *  • The guardian wording is written separately, never derived. "You have more
 *    time on this module" shown to a parent is not merely awkward, it is wrong
 *    about who is being addressed.
 *  • Staff wording is terse on purpose: they know the domain, and padding a
 *    dense operational screen with explanations is its own kind of noise.
 *
 * Entry shape, per audience: [label, meaning, next_step|null, is_actionable].
 */
declare(strict_types=1);

/** Audiences a status can be worded for. Ordered by how much context they lack. */
const MAM_V2_AUDIENCES = ['learner', 'guardian', 'staff', 'admin'];

/** The shared tone palette. A status must not be green on one screen and red on another. */
const MAM_V2_TONES = ['neutral', 'positive', 'progress', 'attention', 'warning', 'critical'];

/**
 * Entities whose statuses a learner or guardian actually reads. Wording for
 * BOTH is mandatory here (enforced by test). Operational tables — jobs, outbox,
 * webhook deliveries — are deliberately absent: nobody outside the team sees
 * them, and inventing learner wording for `dead_letter` would be theatre.
 */
const MAM_V2_LEARNER_FACING = [
    'membership_stage', 'application', 'enrolment', 'class_session', 'attendance',
    'module_progress', 'invoice', 'payment', 'accountability_charge',
];

function mam_v2_status_catalogue(): array
{
    return [

    // ── Where a person is in their journey with the academy ──────────────────
    'membership_stage' => [
        'prospect' => ['tone' => 'neutral', 'sort' => 10, 'terminal' => false,
            'learner'  => ['Getting to know us', 'You are on our list but have not applied yet.', 'Start an application whenever you are ready.', true],
            'guardian' => ['Getting to know us', 'They are on our list but have not applied yet.', 'Start an application whenever you are ready.', true],
            'staff'    => ['Prospect', 'Known contact, no application.', null, false]],
        'applicant' => ['tone' => 'progress', 'sort' => 20, 'terminal' => false,
            'learner'  => ['Applying', 'Your application is with us.', 'Watch your email — we will be in touch about next steps.', false],
            'guardian' => ['Applying', 'Their application is with us.', 'Watch your email — we will be in touch about next steps.', false],
            'staff'    => ['Applicant', 'Application open.', null, false]],
        'admitted' => ['tone' => 'positive', 'sort' => 30, 'terminal' => false,
            'learner'  => ['Offered a place', 'You have been offered a place.', 'Accept your place and settle your first payment to confirm it.', true],
            'guardian' => ['Offered a place', 'They have been offered a place.', 'Accept the place and settle the first payment to confirm it.', true],
            'staff'    => ['Admitted', 'Offer made, not yet enrolled.', null, false]],
        'enrolled' => ['tone' => 'positive', 'sort' => 40, 'terminal' => false,
            'learner'  => ['Enrolled', 'Your place is confirmed and your classes are set.', 'Check your timetable and get ready for your first class.', true],
            'guardian' => ['Enrolled', 'Their place is confirmed and their classes are set.', 'Check the timetable so you know when classes run.', true],
            'staff'    => ['Enrolled', 'Confirmed, awaiting first session.', null, false]],
        'active' => ['tone' => 'positive', 'sort' => 50, 'terminal' => false,
            'learner'  => ['Learning with us', 'You are currently studying with us.', null, false],
            'guardian' => ['Learning with us', 'They are currently studying with us.', null, false],
            'staff'    => ['Active', 'Attending.', null, false]],
        'paused' => ['tone' => 'attention', 'sort' => 60, 'terminal' => false,
            'learner'  => ['Paused', 'Your studies are on hold. Your progress is saved and waiting for you.', 'Tell us when you are ready to come back and we will find you a class.', true],
            'guardian' => ['Paused', 'Their studies are on hold. Their progress is saved.', 'Let us know when they are ready to return.', true],
            'staff'    => ['Paused', 'Temporary hold; progress retained.', null, false]],
        'alumnus' => ['tone' => 'positive', 'sort' => 70, 'terminal' => true,
            'learner'  => ['Graduated', 'You have completed your programme with us.', 'Your certificates stay available here for good.', true],
            'guardian' => ['Graduated', 'They have completed their programme with us.', 'Their certificates stay available here for good.', true],
            'staff'    => ['Alumnus', 'Completed programme.', null, false]],
        'withdrawn' => ['tone' => 'neutral', 'sort' => 80, 'terminal' => true,
            'learner'  => ['No longer enrolled', 'You are not currently studying with us.', 'You are welcome back — get in touch and we will pick up where you left off.', true],
            'guardian' => ['No longer enrolled', 'They are not currently studying with us.', 'They are welcome back — get in touch whenever suits you.', true],
            'staff'    => ['Withdrawn', 'Left the academy.', null, false]],
        'rejected' => ['tone' => 'neutral', 'sort' => 90, 'terminal' => true,
            'learner'  => ['Not this time', 'We were not able to offer you a place on this occasion.', 'You can apply again for the next intake, and we will tell you what would strengthen it.', true],
            'guardian' => ['Not this time', 'We were not able to offer a place on this occasion.', 'They can apply again for the next intake.', true],
            'staff'    => ['Rejected', 'Not admitted.', null, false]],
    ],

    // ── The intake funnel ────────────────────────────────────────────────────
    'application' => [
        'draft' => ['tone' => 'neutral', 'sort' => 10, 'terminal' => false,
            'learner'  => ['Not sent yet', 'You have started this application but not sent it to us.', 'Finish the remaining questions and send it — we cannot see it until you do.', true],
            'guardian' => ['Not sent yet', 'This application has been started but not sent to us.', 'Finish the remaining questions and send it.', true],
            'staff'    => ['Draft', 'Not submitted; invisible to review.', null, false]],
        'submitted' => ['tone' => 'progress', 'sort' => 20, 'terminal' => false,
            'learner'  => ['Received', 'We have your application. Nothing has gone wrong — it is waiting to be read.', 'Nothing right now. We aim to respond within five working days.', false],
            'guardian' => ['Received', 'We have their application and it is waiting to be read.', 'Nothing right now. We aim to respond within five working days.', false],
            'staff'    => ['Submitted', 'Awaiting first review.', 'Assign a reviewer.', true]],
        'under_review' => ['tone' => 'progress', 'sort' => 30, 'terminal' => false,
            'learner'  => ['Being read', 'Someone from the academy is reading your application now.', 'Nothing right now. We will contact you either way.', false],
            'guardian' => ['Being read', 'Someone from the academy is reading their application now.', 'Nothing right now. We will contact you either way.', false],
            'staff'    => ['Under review', 'With a reviewer.', null, false]],
        'interview_scheduled' => ['tone' => 'attention', 'sort' => 40, 'terminal' => false,
            'learner'  => ['Audition booked', 'Your audition or interview is booked.', 'Check the date and time, and look at the preparation checklist before you come.', true],
            'guardian' => ['Audition booked', 'Their audition or interview is booked.', 'Check the date and time, and help them prepare using the checklist.', true],
            'staff'    => ['Interview scheduled', 'Auditer booked.', null, false]],
        'offered' => ['tone' => 'positive', 'sort' => 50, 'terminal' => false,
            'learner'  => ['You have an offer', 'We would like to offer you a place.', 'Accept your offer to hold your place. Offers do expire, so the date is on your letter.', true],
            'guardian' => ['They have an offer', 'We would like to offer them a place.', 'Accept the offer to hold the place before the date on the letter.', true],
            'staff'    => ['Offered', 'Offer issued, awaiting response.', null, false]],
        'accepted' => ['tone' => 'positive', 'sort' => 60, 'terminal' => true,
            'learner'  => ['Place accepted', 'You have accepted your place. Welcome.', 'Look out for your enrolment details and your first invoice.', false],
            'guardian' => ['Place accepted', 'The place has been accepted. Welcome.', 'Look out for the enrolment details and the first invoice.', false],
            'staff'    => ['Accepted', 'Converted; provision enrolment.', null, false]],
        'waitlisted' => ['tone' => 'attention', 'sort' => 70, 'terminal' => false,
            'learner'  => ['On the waiting list', 'The class you want is full. You are in the queue and we will offer you the next free place.', 'Nothing to do — we will contact you the moment a place opens.', false],
            'guardian' => ['On the waiting list', 'The class is full. They are in the queue for the next free place.', 'Nothing to do — we will contact you the moment a place opens.', false],
            'staff'    => ['Waitlisted', 'Queued against a full cohort.', null, false]],
        'rejected' => ['tone' => 'neutral', 'sort' => 80, 'terminal' => true,
            'learner'  => ['Not this time', 'We could not offer you a place on this occasion. The reason is below — it is not a judgement on your ability.', 'You are welcome to apply again for the next intake.', true],
            'guardian' => ['Not this time', 'We could not offer a place on this occasion. The reason is below.', 'They are welcome to apply again for the next intake.', true],
            'staff'    => ['Rejected', 'Declined; reason required.', null, false]],
        'withdrawn' => ['tone' => 'neutral', 'sort' => 90, 'terminal' => true,
            'learner'  => ['Withdrawn', 'This application was withdrawn and is no longer being considered.', 'You can start a fresh application at any time.', true],
            'guardian' => ['Withdrawn', 'This application was withdrawn and is no longer being considered.', 'You can start a fresh application at any time.', true],
            'staff'    => ['Withdrawn', 'Closed by applicant or staff.', null, false]],
    ],

    // ── A place on a specific class ──────────────────────────────────────────
    'enrolment' => [
        'pending' => ['tone' => 'progress', 'sort' => 10, 'terminal' => false,
            'learner'  => ['Place held', 'Your place is held while we confirm your payment.', 'Complete your payment to confirm it. Held places do release if payment does not arrive.', true],
            'guardian' => ['Place held', 'Their place is held while payment is confirmed.', 'Complete the payment to confirm it — held places do release.', true],
            'staff'    => ['Pending', 'Seat reserved, unpaid.', null, false]],
        'active' => ['tone' => 'positive', 'sort' => 20, 'terminal' => false,
            'learner'  => ['Enrolled', 'You are on this class and it appears in your timetable.', null, false],
            'guardian' => ['Enrolled', 'They are on this class and it appears in their timetable.', null, false],
            'staff'    => ['Active', 'On roster.', null, false]],
        'completed' => ['tone' => 'positive', 'sort' => 30, 'terminal' => true,
            'learner'  => ['Finished', 'You have completed this class.', 'Your result and any certificate are on your record.', true],
            'guardian' => ['Finished', 'They have completed this class.', 'The result and any certificate are on their record.', true],
            'staff'    => ['Completed', 'Finished with an outcome.', null, false]],
        'withdrawn' => ['tone' => 'neutral', 'sort' => 40, 'terminal' => true,
            'learner'  => ['Left this class', 'You are no longer on this class. The reason is recorded below.', 'If this looks wrong, tell us and we will check it.', true],
            'guardian' => ['Left this class', 'They are no longer on this class. The reason is recorded below.', 'If this looks wrong, tell us and we will check it.', true],
            'staff'    => ['Withdrawn', 'Off roster; seat freed.', null, false]],
        'transferred' => ['tone' => 'neutral', 'sort' => 50, 'terminal' => true,
            'learner'  => ['Moved to another class', 'You were moved to a different class. Your progress moved with you — nothing was lost.', 'Your new class is in your timetable.', true],
            'guardian' => ['Moved to another class', 'They were moved to a different class, and their progress moved with them.', 'The new class is in their timetable.', true],
            'staff'    => ['Transferred', 'Moved to another cohort.', null, false]],
        'waitlisted' => ['tone' => 'attention', 'sort' => 60, 'terminal' => false,
            'learner'  => ['In the queue', 'This class is full. You are in the queue and keep your position.', 'Nothing to do — we will contact you when a place opens.', false],
            'guardian' => ['In the queue', 'This class is full. They are in the queue and keep their position.', 'Nothing to do — we will contact you when a place opens.', false],
            'staff'    => ['Waitlisted', 'Queued; not seat-occupying.', null, false]],
    ],

    // ── An individual class ──────────────────────────────────────────────────
    'class_session' => [
        'scheduled' => ['tone' => 'neutral', 'sort' => 10, 'terminal' => false,
            'learner'  => ['Coming up', 'This class is going ahead as planned.', 'Check in from 30 minutes before it starts.', true],
            'guardian' => ['Coming up', 'This class is going ahead as planned.', null, false],
            'staff'    => ['Scheduled', 'Not yet delivered.', null, false]],
        'held' => ['tone' => 'positive', 'sort' => 20, 'terminal' => true,
            'learner'  => ['Done', 'This class has taken place.', null, false],
            'guardian' => ['Done', 'This class has taken place.', null, false],
            'staff'    => ['Held', 'Delivered.', 'Submit the progress report within two hours.', true]],
        'cancelled' => ['tone' => 'warning', 'sort' => 30, 'terminal' => true,
            'learner'  => ['Cancelled', 'This class is not going ahead. The reason is shown below.', 'You do not need to attend. If a replacement is arranged it will appear in your timetable.', true],
            'guardian' => ['Cancelled', 'This class is not going ahead. The reason is shown below.', 'They do not need to attend. Any replacement will appear in their timetable.', true],
            'staff'    => ['Cancelled', 'Not delivered; reason required.', null, false]],
        'rescheduled' => ['tone' => 'attention', 'sort' => 40, 'terminal' => true,
            'learner'  => ['Moved', 'This class was moved to a different time.', 'Check your timetable for the new time — it has already been updated.', true],
            'guardian' => ['Moved', 'This class was moved to a different time.', 'Check their timetable for the new time.', true],
            'staff'    => ['Rescheduled', 'Superseded by a new session.', null, false]],
    ],

    // ── Whether someone was there ────────────────────────────────────────────
    'attendance' => [
        'present' => ['tone' => 'positive', 'sort' => 10, 'terminal' => true,
            'learner'  => ['Present', 'You were in this class.', null, false],
            'guardian' => ['Present', 'They were in this class.', null, false],
            'staff'    => ['Present', 'Full attendance.', null, false]],
        'late' => ['tone' => 'attention', 'sort' => 20, 'terminal' => true,
            'learner'  => ['Late', 'You joined this class after it started. You were still marked as attending.', 'Arriving on time protects your practice minutes — and repeated lateness can attract a charge.', true],
            'guardian' => ['Late', 'They joined after the class started, and were still marked as attending.', 'Repeated lateness can attract a charge, so it is worth a word.', true],
            'staff'    => ['Late', 'Attended, after start.', null, false]],
        'absent' => ['tone' => 'warning', 'sort' => 30, 'terminal' => true,
            'learner'  => ['Missed', 'You were not in this class.', 'Ask about a make-up session, and tell your instructor if something got in the way.', true],
            'guardian' => ['Missed', 'They were not in this class.', 'A make-up session may be available — ask their instructor.', true],
            'staff'    => ['Absent', 'No attendance recorded.', null, false]],
        'excused' => ['tone' => 'neutral', 'sort' => 40, 'terminal' => true,
            'learner'  => ['Excused', 'You missed this class and the academy agreed the reason. It does not count against you.', null, false],
            'guardian' => ['Excused', 'They missed this class with an agreed reason. It does not count against them.', null, false],
            'staff'    => ['Excused', 'Authorised absence.', null, false]],
        'left_early' => ['tone' => 'attention', 'sort' => 50, 'terminal' => true,
            'learner'  => ['Left early', 'You were in this class but left before the end.', 'If something went wrong, tell your instructor so it can be recorded properly.', true],
            'guardian' => ['Left early', 'They were in this class but left before the end.', 'If something went wrong, let their instructor know.', true],
            'staff'    => ['Left early', 'Partial attendance.', null, false]],
        'technical_failure' => ['tone' => 'neutral', 'sort' => 60, 'terminal' => true,
            'learner'  => ['Technical problem', 'You could not join or stay in this class because something failed on the line. This is not held against you.', 'If it keeps happening, tell us — we can switch you to a lower-bandwidth setup.', true],
            'guardian' => ['Technical problem', 'A technical failure stopped them joining or staying. It is not held against them.', 'If it keeps happening, tell us and we can adjust the setup.', true],
            'staff'    => ['Technical failure', 'Non-attendance, not attributable.', null, false]],
        'rescheduled' => ['tone' => 'neutral', 'sort' => 70, 'terminal' => true,
            'learner'  => ['Class was moved', 'This class did not run at the original time, so there is nothing to attend here.', 'The replacement is in your timetable.', true],
            'guardian' => ['Class was moved', 'This class did not run at the original time.', 'The replacement is in their timetable.', true],
            'staff'    => ['Rescheduled', 'Session moved; no mark due.', null, false]],
    ],

    // ── How a learner is doing on a piece of the curriculum ──────────────────
    'module_progress' => [
        'not_started' => ['tone' => 'neutral', 'sort' => 10, 'terminal' => false,
            'learner'  => ['Not started', 'You have not begun this yet.', 'It opens when your class reaches it — no need to do anything early.', false],
            'guardian' => ['Not started', 'They have not begun this yet.', null, false],
            'staff'    => ['Not started', 'No activity.', null, false]],
        'available' => ['tone' => 'progress', 'sort' => 20, 'terminal' => false,
            'learner'  => ['Ready for you', 'This is open and waiting for you.', 'Open it and read what is being asked before your next class.', true],
            'guardian' => ['Ready for them', 'This is open and waiting for them.', 'A nudge to start it before the next class helps.', true],
            'staff'    => ['Available', 'Released to learner.', null, false]],
        'preparation_pending' => ['tone' => 'attention', 'sort' => 30, 'terminal' => false,
            'learner'  => ['Preparation due', 'There is preparation to do before your next class.', 'Complete it before you attend — the class builds on it.', true],
            'guardian' => ['Preparation due', 'There is preparation to do before their next class.', 'The class builds on it, so it is worth checking it is done.', true],
            'staff'    => ['Prep pending', 'Pre-class work outstanding.', null, false]],
        'in_progress' => ['tone' => 'progress', 'sort' => 40, 'terminal' => false,
            'learner'  => ['In progress', 'You are working on this.', 'Keep going — you can pick up where you left off.', true],
            'guardian' => ['In progress', 'They are working on this.', null, false],
            'staff'    => ['In progress', 'Started, not submitted.', null, false]],
        'submitted' => ['tone' => 'progress', 'sort' => 50, 'terminal' => false,
            'learner'  => ['Handed in', 'Your work is with your instructor.', 'Nothing to do — you will be told when it has been marked.', false],
            'guardian' => ['Handed in', 'Their work is with their instructor.', 'Nothing to do — you will be told when it has been marked.', false],
            'staff'    => ['Submitted', 'Awaiting assessment.', 'Assess and return feedback.', true]],
        'under_assessment' => ['tone' => 'progress', 'sort' => 60, 'terminal' => false,
            'learner'  => ['Being marked', 'Your instructor is marking this now.', 'Nothing to do.', false],
            'guardian' => ['Being marked', 'Their instructor is marking this now.', 'Nothing to do.', false],
            'staff'    => ['Under assessment', 'In marking.', null, false]],
        'developing' => ['tone' => 'progress', 'sort' => 70, 'terminal' => false,
            'learner'  => ['Coming along', 'You are building this skill. This is a normal place to be, not a fail.', 'Work through your instructor\'s feedback and try it again.', true],
            'guardian' => ['Coming along', 'They are building this skill. This is a normal stage, not a failure.', 'Practising the specific point in the feedback is what moves it on.', true],
            'staff'    => ['Developing', 'Below proficient.', null, false]],
        'proficient' => ['tone' => 'positive', 'sort' => 80, 'terminal' => false,
            'learner'  => ['You have got this', 'You can do this reliably.', null, false],
            'guardian' => ['They have got this', 'They can do this reliably.', null, false],
            'staff'    => ['Proficient', 'Meets the standard.', null, false]],
        'advanced' => ['tone' => 'positive', 'sort' => 90, 'terminal' => false,
            'learner'  => ['Beyond the standard', 'You are working above what this level asks for.', 'Ask your instructor about extension work — you are ready for it.', true],
            'guardian' => ['Beyond the standard', 'They are working above what this level asks for.', 'Extension work may suit them — worth asking their instructor.', true],
            'staff'    => ['Advanced', 'Above standard.', null, false]],
        'mastered' => ['tone' => 'positive', 'sort' => 100, 'terminal' => true,
            'learner'  => ['Mastered', 'You have fully mastered this.', null, false],
            'guardian' => ['Mastered', 'They have fully mastered this.', null, false],
            'staff'    => ['Mastered', 'Top band.', null, false]],
        'carried_forward' => ['tone' => 'progress', 'sort' => 110, 'terminal' => false,
            'learner'  => ['More time given', 'This moves with you to next week rather than counting against you.', 'Have another go before your next class, and ask if anything is unclear.', true],
            'guardian' => ['Extra time given', 'Their instructor has given more time on this. It is a normal part of the pace, not a failure.', 'Nothing is needed from you.', false],
            'staff'    => ['Carried forward', 'Rolled to a later week; history appended.', null, false]],
        'remedial' => ['tone' => 'attention', 'sort' => 120, 'terminal' => false,
            'learner'  => ['Extra support', 'You have been given focused support on this. It means we are helping, not that you are behind for good.', 'Come to the support session — it is short and it is aimed at exactly this.', true],
            'guardian' => ['Extra support', 'They have been given focused support on this.', 'Getting them to the support session is the main thing.', true],
            'staff'    => ['Remedial', 'Intervention in place.', null, false]],
        'completed' => ['tone' => 'positive', 'sort' => 130, 'terminal' => true,
            'learner'  => ['Complete', 'You have finished this.', null, false],
            'guardian' => ['Complete', 'They have finished this.', null, false],
            'staff'    => ['Completed', 'Closed out.', null, false]],
        'exempted' => ['tone' => 'neutral', 'sort' => 140, 'terminal' => true,
            'learner'  => ['Not required', 'You do not need to do this one — it was waived for you.', null, false],
            'guardian' => ['Not required', 'They do not need to do this one — it was waived.', null, false],
            'staff'    => ['Exempted', 'Waived; excluded from mastery weighting.', null, false]],
    ],

    // ── What is owed ─────────────────────────────────────────────────────────
    'invoice' => [
        'draft' => ['tone' => 'neutral', 'sort' => 10, 'terminal' => false,
            'learner'  => ['Not issued', 'This is being prepared and is not payable yet.', 'Nothing to do — you will be told when it is ready.', false],
            'guardian' => ['Not issued', 'This is being prepared and is not payable yet.', 'Nothing to do — you will be told when it is ready.', false],
            'staff'    => ['Draft', 'Not issued.', null, false]],
        'issued' => ['tone' => 'attention', 'sort' => 20, 'terminal' => false,
            'learner'  => ['Due', 'This is owed. The due date is shown below.', 'Pay it before the due date to keep your place.', true],
            'guardian' => ['Due', 'This is owed. The due date is shown below.', 'Pay it before the due date to keep their place.', true],
            'staff'    => ['Issued', 'Outstanding.', null, false]],
        'part_paid' => ['tone' => 'attention', 'sort' => 30, 'terminal' => false,
            'learner'  => ['Partly paid', 'Some of this has been paid. The remaining balance is shown below.', 'Pay the balance to clear it.', true],
            'guardian' => ['Partly paid', 'Some of this has been paid. The remaining balance is shown below.', 'Pay the balance to clear it.', true],
            'staff'    => ['Part paid', 'Partially allocated.', null, false]],
        'paid' => ['tone' => 'positive', 'sort' => 40, 'terminal' => true,
            'learner'  => ['Paid', 'This is settled in full. Thank you.', 'Your receipt is available to download.', true],
            'guardian' => ['Paid', 'This is settled in full. Thank you.', 'Your receipt is available to download.', true],
            'staff'    => ['Paid', 'Fully allocated.', null, false]],
        'void' => ['tone' => 'neutral', 'sort' => 50, 'terminal' => true,
            'learner'  => ['Cancelled', 'This was cancelled and you do not owe it. The reason is below.', 'Nothing to do. If you already paid, a refund is on its way.', false],
            'guardian' => ['Cancelled', 'This was cancelled and is not owed. The reason is below.', 'Nothing to do. If it was already paid, a refund is on its way.', false],
            'staff'    => ['Void', 'Cancelled; reason required.', null, false]],
        'written_off' => ['tone' => 'neutral', 'sort' => 60, 'terminal' => true,
            'learner'  => ['Cleared by the academy', 'The academy has cleared this. You do not owe it.', null, false],
            'guardian' => ['Cleared by the academy', 'The academy has cleared this. It is not owed.', null, false],
            'staff'    => ['Written off', 'Bad debt; ledger adjusted.', null, false]],
    ],

    // ── What was paid ────────────────────────────────────────────────────────
    'payment' => [
        'pending' => ['tone' => 'progress', 'sort' => 10, 'terminal' => false,
            'learner'  => ['Going through', 'Your payment has been started and we are waiting for your bank to confirm it.', 'Do not pay again — this usually settles within a few minutes.', false],
            'guardian' => ['Going through', 'The payment has been started and we are waiting for the bank to confirm it.', 'Do not pay again — this usually settles within a few minutes.', false],
            'staff'    => ['Pending', 'Awaiting provider confirmation.', null, false]],
        'succeeded' => ['tone' => 'positive', 'sort' => 20, 'terminal' => true,
            'learner'  => ['Paid', 'Your payment went through.', 'Your receipt is available to download.', true],
            'guardian' => ['Paid', 'The payment went through.', 'Your receipt is available to download.', true],
            'staff'    => ['Succeeded', 'Settled and allocable.', null, false]],
        'failed' => ['tone' => 'critical', 'sort' => 30, 'terminal' => true,
            'learner'  => ['Did not go through', 'This payment failed. The reason from your bank is shown below — it is worth reading before you try again.', 'Fix what the message says, or use a different method. Nothing has been taken from you.', true],
            'guardian' => ['Did not go through', 'This payment failed. The reason from the bank is shown below.', 'Fix what the message says, or use a different method. Nothing has been taken.', true],
            'staff'    => ['Failed', 'Declined; reason stored.', null, false]],
        'refunded' => ['tone' => 'neutral', 'sort' => 40, 'terminal' => true,
            'learner'  => ['Refunded', 'This payment was returned to you in full.', 'Refunds can take a few working days to appear on your statement.', false],
            'guardian' => ['Refunded', 'This payment was returned in full.', 'Refunds can take a few working days to appear on the statement.', false],
            'staff'    => ['Refunded', 'Fully reversed.', null, false]],
        'partially_refunded' => ['tone' => 'neutral', 'sort' => 50, 'terminal' => true,
            'learner'  => ['Partly refunded', 'Part of this payment was returned to you. The amount is shown below.', 'Refunds can take a few working days to appear on your statement.', false],
            'guardian' => ['Partly refunded', 'Part of this payment was returned. The amount is shown below.', 'Refunds can take a few working days to appear on the statement.', false],
            'staff'    => ['Partly refunded', 'Partially reversed.', null, false]],
        'disputed' => ['tone' => 'warning', 'sort' => 60, 'terminal' => false,
            'learner'  => ['Being queried', 'Your bank has raised a query about this payment and it is being looked into.', 'We will contact you. Please do not pay again until it is resolved.', false],
            'guardian' => ['Being queried', 'The bank has raised a query about this payment.', 'We will contact you. Please do not pay again until it is resolved.', false],
            'staff'    => ['Disputed', 'Chargeback open.', 'Respond before the provider deadline.', true]],
    ],

    // ── Punctuality and accountability charges (§15) ─────────────────────────
    'accountability_charge' => [
        'pending' => ['tone' => 'neutral', 'sort' => 10, 'terminal' => false,
            'learner'  => ['Being decided', 'You asked us to look at this charge and we have not decided yet. You do not need to pay it while we do.', 'Nothing to do — we will tell you the outcome.', false],
            'guardian' => ['Being decided', 'A review of this charge is open. It is not payable while we decide.', 'Nothing to do — we will tell you the outcome.', false],
            'staff'    => ['Pending', 'Exception open; not owed meanwhile.', 'Decide the request.', true]],
        'outstanding' => ['tone' => 'attention', 'sort' => 20, 'terminal' => false,
            'learner'  => ['Owed', 'This charge is owed. The reason it was raised is shown below.', 'Pay it, or ask us to review it if you think it is wrong.', true],
            'guardian' => ['Owed', 'This charge is owed. The reason it was raised is shown below.', 'Pay it, or ask us to review it if you think it is wrong.', true],
            'staff'    => ['Outstanding', 'Owed.', null, false]],
        'paid' => ['tone' => 'positive', 'sort' => 30, 'terminal' => true,
            'learner'  => ['Settled', 'This charge has been paid.', null, false],
            'guardian' => ['Settled', 'This charge has been paid.', null, false],
            'staff'    => ['Paid', 'Cleared.', null, false]],
        'waived' => ['tone' => 'positive', 'sort' => 40, 'terminal' => true,
            'learner'  => ['Cancelled', 'We have cancelled this charge, for the reason shown below, so you do not owe it.', null, false],
            'guardian' => ['Cancelled', 'This charge has been cancelled for the reason shown below and is not owed.', null, false],
            'staff'    => ['Waived', 'Cancelled; note required.', null, false]],
        'disputed' => ['tone' => 'warning', 'sort' => 50, 'terminal' => false,
            'learner'  => ['You have queried this', 'You have told us you think this charge is wrong. It still counts as owed while we look into it.', 'Nothing to do — we will come back to you with a decision and a reason.', false],
            'guardian' => ['Queried', 'This charge has been queried. It still counts as owed while it is looked into.', 'Nothing to do — we will come back with a decision and a reason.', false],
            'staff'    => ['Disputed', 'Contested; still owed.', 'Decide and record a reason.', true]],
        'reversed' => ['tone' => 'neutral', 'sort' => 60, 'terminal' => true,
            'learner'  => ['Taken back', 'This charge was raised in error and has been removed from your account.', null, false],
            'guardian' => ['Taken back', 'This charge was raised in error and has been removed from their account.', null, false],
            'staff'    => ['Reversed', 'Raised in error.', null, false]],
    ],

    ];
}
