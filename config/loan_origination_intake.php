<?php

/*
|--------------------------------------------------------------------------
| Loan origination: the public application's codes and sessions (LO-B2)
|--------------------------------------------------------------------------
|
| No SMS provider is chosen yet (origination question 1), so a code reaches
| an applicant only through a reminder SMS driver that actually delivers,
| and only to a number the reminders allow-list permits. Until one exists,
| no link takes an application.
|
| `LOAN_ORIGINATION_OTP_TEST_CODES` (off by default) is the staging test-code
| mode: a code asked for by a number listed in REMINDERS_ALLOW_LIST (staff
| numbers) is shown on screen as "Test mode: not sent" instead of being sent;
| any other number gets nothing. The app refuses to boot in production with
| it on (LoanOriginationServiceProvider).
|
| `LOAN_ORIGINATION_OTP_DAILY_CAP` is the most codes that reach someone the
| deployment hands out in a Manila day, against SMS pumping, and
| `LOAN_ORIGINATION_OTP_DAILY_CAP_PER_MOBILE` the most one mobile asks for.
| Test codes are shown only on links marked as a test.
*/

return [
    'otp' => [
        'test_codes' => filter_var(env('LOAN_ORIGINATION_OTP_TEST_CODES', false), FILTER_VALIDATE_BOOL),
        'daily_cap' => (int) env('LOAN_ORIGINATION_OTP_DAILY_CAP', 300),
        'daily_cap_per_mobile' => (int) env('LOAN_ORIGINATION_OTP_DAILY_CAP_PER_MOBILE', 10),
        'minutes' => 5,
        'max_attempts' => 5,
        'resend_seconds' => 60,
    ],

    'sessions' => [
        'idle_minutes' => 30,
        'max_minutes' => 240,
    ],
];
