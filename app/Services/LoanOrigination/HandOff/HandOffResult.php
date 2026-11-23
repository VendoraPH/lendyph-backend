<?php

namespace App\Services\LoanOrigination\HandOff;

use App\Models\Loan;
use App\Models\OriginationApplication;

/** The application after its hand-off, and the loan it now follows. */
final class HandOffResult
{
    public function __construct(
        public readonly OriginationApplication $application,
        public readonly Loan $loan,
    ) {}
}
