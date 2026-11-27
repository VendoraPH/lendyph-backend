<?php

namespace App\Exceptions;

/**
 * A loan origination history row (a notice, a consent, a note, a status
 * change, an assessment rule revision) was asked to change. Those rows are
 * append-only: a correction is a new row. Only the retention erasure removes
 * them, through the database's own cascade from the application.
 */
class OriginationRecordIsAppendOnlyException extends SavingsRecordIsAppendOnlyException {}
