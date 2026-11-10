<?php

namespace Tests\Unit;

use App\Exceptions\CannotPostToTheBooksException;
use App\Services\Accounting\SettlementMethod;
use Tests\TestCase;

/**
 * A payment method with no settlement account is refused, and the refusal
 * names the method in words, never by its stored key.
 */
class SettlementMethodWordsTest extends TestCase
{
    public function test_an_unknown_method_is_named_in_words(): void
    {
        try {
            SettlementMethod::fromLendingMethod('wire_cheque');
            $this->fail('An unknown method was given a settlement account.');
        } catch (CannotPostToTheBooksException $refusal) {
            $this->assertStringStartsWith('There is no accounting settlement account defined for the payment method Wire Cheque, so ', $refusal->getMessage());
            $this->assertStringNotContainsString('wire_cheque', $refusal->getMessage());
        }
    }

    public function test_a_known_method_still_maps_to_its_role(): void
    {
        $this->assertSame('bank', SettlementMethod::fromLendingMethod('bank_transfer'));
        $this->assertSame('gcash', SettlementMethod::fromLendingMethod('gcash'));
    }
}
