<?php

namespace App\Services\Cic\Records;

/** A reported loan's CIC classification, as CicContractClassifier works it out. */
final readonly class CicContractClassification
{
    public function __construct(
        public string $structure,
        public string $recordType,
        public bool $overridesRecordType,
        public bool $hasMapping,
        public ?string $mappingRecordType,
        public bool $mappingMismatch,
        public ?string $contractType,
        public ?string $transactionType,
        public ?string $purposeCode,
    ) {}
}
