<?php
/**
 * Admissions helpers (Application proposal — A1+). The applicant-facing view of
 * an application: a privacy-safe status lookup (reference + matching email) that
 * maps the raw application/payment state to a friendly stage + next step, so the
 * journey no longer dead-ends at "submitted".
 */
declare(strict_types=1);

/**
 * Map the raw application status + latest payment status to a friendly stage.
 * Returns ['stage','label','next'].
 */
function mam_application_stage(string $status, string $paymentStatus): array {
    $s    = strtolower(trim($status));
    $paid = strtolower(trim($paymentStatus)) === 'succeeded';

    if ($s === 'enrolled') {
        return ['stage' => 'enrolled', 'label' => 'Enrolled', 'next' => 'You\'re in — sign in to your student portal to begin.'];
    }
    if ($s === 'accepted') {
        return ['stage' => 'accepted', 'label' => 'Accepted', 'next' => 'Congratulations! Watch your email for enrolment and onboarding details.'];
    }
    if ($s === 'declined' || $s === 'rejected') {
        return ['stage' => 'closed', 'label' => 'Decision made', 'next' => 'Thank you for applying — please check your email for details.'];
    }
    if ($paid) {
        return ['stage' => 'confirmed', 'label' => 'Seat confirmed', 'next' => 'Your booking fee is in. We\'ll be in touch about your MAM Auditer assessment and the next steps.'];
    }
    return ['stage' => 'received', 'label' => 'Application received', 'next' => 'Confirm your seat with the one-time booking fee — use the link in your confirmation email, or reopen your application to pay.'];
}

/**
 * Privacy-safe applicant status lookup. Requires BOTH the reference and the
 * matching email (so applications aren't enumerable by reference alone).
 * Returns the friendly status array, or null when not found / mismatched.
 */
function mam_application_status(string $reference, string $email): ?array {
    $reference = trim($reference);
    $email     = strtolower(trim($email));
    if ($reference === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) return null;

    $row = mam_db_row(
        "SELECT a.reference, a.pathway, a.mode, a.status, a.created_at, a.total_usd,
                c.first_name,
                (SELECT pp.status FROM payments pp WHERE pp.application_id = a.id ORDER BY pp.id DESC LIMIT 1) AS payment_status
           FROM applications a
           JOIN customers c ON c.id = a.customer_id
          WHERE a.reference = ? AND LOWER(c.email) = ?",
        [$reference, $email]
    );
    if (!$row) return null;

    $stage = mam_application_stage((string)$row['status'], (string)($row['payment_status'] ?? ''));
    return [
        'reference'      => $row['reference'],
        'first_name'     => $row['first_name'],
        'pathway'        => $row['pathway'],
        'mode'           => $row['mode'],
        'status'         => $row['status'],
        'payment_status' => $row['payment_status'],
        'submitted_at'   => $row['created_at'],
        'stage'          => $stage['stage'],
        'stage_label'    => $stage['label'],
        'next_step'      => $stage['next'],
    ];
}
