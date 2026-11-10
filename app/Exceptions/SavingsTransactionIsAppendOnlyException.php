<?php

namespace App\Exceptions;

/**
 * A savings_transactions row was asked to change. The subledger is
 * append-only: a mistake is corrected by a reversal row, never by editing or
 * deleting the original (savings design §1).
 */
class SavingsTransactionIsAppendOnlyException extends SavingsRecordIsAppendOnlyException {}
