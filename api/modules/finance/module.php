<?php
/**
 * Module manifest: finance.
 *
 * Money: minor-unit helpers, Paystack and Flutterwave, settlement, payment guards, booking pricing, application invoices, fee invoices and aid, invoice review, the wallet, promotions and the store.
 *
 * `libs` lists this module's files in api/modules/finance/lib/, in load order.
 * `depends` names the modules this one calls. A call to a module not named
 * here fails test_module_boundaries.php, so new coupling is a deliberate
 * edit to this line. See api/modules/README.md.
 */
declare(strict_types=1);

return [
    'name'    => 'finance',
    'depends' => ['academics', 'admissions', 'comms', 'identity', 'integrations', 'kernel', 'learning'],
    'libs'    => [
        'money',
        'paystack',
        'flutterwave',
        'booking-pricing',
        'payments',
        'payment-guards',
        'invoicing',
        'fees',
        'fee-review',
        'wallet',
        'promo',
        'store',
    ],
];
