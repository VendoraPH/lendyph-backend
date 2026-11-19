<?php

namespace App\Services\Linda\Model;

/** A model's written answer and the usage it was billed. */
final class LindaWriting
{
    public function __construct(
        public readonly string $text,
        public readonly LindaUsage $usage,
    ) {}
}
