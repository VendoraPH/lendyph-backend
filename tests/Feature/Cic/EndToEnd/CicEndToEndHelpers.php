<?php

namespace Tests\Feature\Cic\EndToEnd;

use Tests\Feature\Cic\Records\CicRecordsFixtures;
use Tests\Feature\Cic\Submissions\CicSubmissionsTestHelpers;

/**
 * The records slice's members and loans with the submissions slice's GnuPG
 * key pair and decryption, for the end-to-end test. Staff come from the
 * submissions helpers, which add head-office access.
 */
trait CicEndToEndHelpers
{
    use CicRecordsFixtures, CicSubmissionsTestHelpers {
        CicSubmissionsTestHelpers::cicUser insteadof CicRecordsFixtures;
    }
}
