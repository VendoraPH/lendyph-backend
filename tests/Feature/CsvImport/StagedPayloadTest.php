<?php

namespace Tests\Feature\CsvImport;

use App\Services\CsvImport\CsvImportSchema;
use App\Services\CsvImport\CsvRow;
use App\Services\CsvImport\CustomerRowNormalizer;
use App\Services\CsvImport\LoanReconstructionInput;
use App\Services\CsvImport\LoanRowNormalizer;
use App\Services\CsvImport\LoanScheduleReconstructor;
use App\Services\CsvImport\NormalizedRow;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * The staged payload has to survive a MySQL JSON column unchanged.
 *
 * Bruce proved two things by probing the server directly, and both of them
 * break the obvious representation:
 *
 *   1. MySQL does not preserve object key order — it normalises keys by length
 *      then lexicographically. JSON arrays ARE preserved verbatim.
 *   2. A whole-number float loses its type: 12500.0 comes back as int 12500.
 *
 * These tests simulate both effects rather than trusting that the code is
 * written correctly.
 */
class StagedPayloadTest extends TestCase
{
    /**
     * MySQL's documented object-key normalisation: shorter keys first, then
     * lexicographic. Applied recursively, so nested objects reorder too.
     */
    private function mysqlJsonNormalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            // Arrays are preserved verbatim — order untouched.
            return array_map(fn ($item) => $this->mysqlJsonNormalize($item), $value);
        }

        $keys = array_keys($value);
        usort($keys, static fn ($a, $b): int => strlen((string) $a) <=> strlen((string) $b) ?: strcmp((string) $a, (string) $b));

        $reordered = [];

        foreach ($keys as $key) {
            $reordered[$key] = $this->mysqlJsonNormalize($value[$key]);
        }

        return $reordered;
    }

    /**
     * A full round trip through json_encode/decode plus MySQL's key reordering.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function throughMysqlJson(array $payload): array
    {
        return $this->mysqlJsonNormalize(
            json_decode((string) json_encode($payload), true, 512, JSON_THROW_ON_ERROR)
        );
    }

    /**
     * @param  array<string, string>  $values
     */
    private function customerRow(array $values = []): NormalizedRow
    {
        $cells = array_fill(0, CsvImportSchema::width(CsvImportSchema::CUSTOMERS), '');
        $defaults = ['account_no' => 'A-001', 'last_name' => 'Dela Cruz', 'first_name' => 'Juan'];

        foreach (array_merge($defaults, $values) as $key => $value) {
            $cells[CsvImportSchema::indexOf(CsvImportSchema::CUSTOMERS, $key)] = $value;
        }

        return (new CustomerRowNormalizer)->normalize(new CsvRow(1, 1, $cells));
    }

    public function test_my_mysql_simulation_really_does_reorder_object_keys_and_preserve_lists(): void
    {
        // Bruce's exact probe, so the simulation below is known to model the
        // real behaviour rather than something I invented.
        $this->assertSame(
            ['z' => 1, 'aaaa' => 2, 'LEGACY-EMG' => 7, 'LEGACY-SAL' => 12],
            $this->mysqlJsonNormalize(['LEGACY-SAL' => 12, 'LEGACY-EMG' => 7, 'z' => 1, 'aaaa' => 2]),
        );

        $list = ['LEGACY-SAL', 'LEGACY-EMG', 'z', 'aaaa'];
        $this->assertSame($list, $this->mysqlJsonNormalize($list));
    }

    public function test_a_staged_row_survives_mysql_key_reordering_with_every_value_in_its_own_field(): void
    {
        $row = $this->customerRow([
            'birthdate' => '1985-06-15',
            'gender' => 'Male',
            'civil_status' => 'Married',
            'contact_number' => '09171234567',
            'monthly_income' => '25,000.50',
            'pledge_amount' => '1,000.00',
            'spouse_first_name' => 'Maria',
        ]);

        $restored = NormalizedRow::fromPayload($this->throughMysqlJson($row->toPayload()));

        // Every field, not just a sample: a shift would still leave most
        // spot-checks passing.
        foreach (CsvImportSchema::keys(CsvImportSchema::CUSTOMERS) as $key) {
            $this->assertSame($row->value($key), $restored->value($key), "Field [{$key}] did not survive the round trip.");
        }

        $this->assertSame('Juan', $restored->value('first_name'));
        $this->assertSame('1985-06-15', $restored->value('birthdate'));
        $this->assertSame('male', $restored->value('gender'));
        $this->assertSame('Maria', $restored->value('spouse_first_name'));
    }

    public function test_the_values_are_a_json_list_because_an_object_would_be_reordered(): void
    {
        $payload = $this->customerRow()->toPayload();

        $this->assertTrue(array_is_list($payload['values']));
        $this->assertCount(22, $payload['values']);

        // The counter-example, spelled out: had `values` been keyed by column
        // name, MySQL would hand it back in a different order, and anything
        // that recovered position from iteration order would read every value
        // into the wrong field — the exact column-shift corruption this package
        // rejects whole files to prevent.
        $keyed = array_combine(CsvImportSchema::keys(CsvImportSchema::CUSTOMERS), $payload['values']);
        $reordered = $this->mysqlJsonNormalize($keyed);

        $this->assertNotSame(array_keys($keyed), array_keys($reordered));
        $this->assertSame('A-001', array_values($keyed)[0]);
        $this->assertNotSame('A-001', array_values($reordered)[0], 'Positional recovery from a keyed object is corrupt.');

        // The list is untouched by the same treatment.
        $this->assertSame($payload['values'], $this->mysqlJsonNormalize($payload['values']));
    }

    public function test_every_payload_value_is_a_string_or_null_so_json_cannot_retype_it(): void
    {
        $row = $this->customerRow(['monthly_income' => '12,500.00', 'pledge_amount' => '0']);
        $payload = $row->toPayload();

        foreach ($payload['values'] as $index => $value) {
            $this->assertTrue(
                $value === null || is_string($value),
                "Payload value at index {$index} is ".get_debug_type($value).', which JSON may retype.',
            );
        }

        // 1250000 centavos — a whole number. As a float it would come back as
        // an int; as a string it comes back as itself, and the schema decides
        // what it means.
        $this->assertSame('1250000', $payload['values'][CsvImportSchema::indexOf(CsvImportSchema::CUSTOMERS, 'monthly_income')]);

        $restored = NormalizedRow::fromPayload($this->throughMysqlJson($payload));

        $this->assertSame(1250000, $restored->value('monthly_income'));
        $this->assertIsInt($restored->value('monthly_income'));
        $this->assertSame(0, $restored->value('pledge_amount'));
        $this->assertIsInt($restored->value('pledge_amount'));
    }

    public function test_a_whole_number_float_would_have_lost_its_type_which_is_why_it_is_a_string(): void
    {
        // Bruce's second finding, demonstrated: this is what the naive
        // representation does, and it is why money is staged as a string.
        $asFloat = json_decode((string) json_encode(['amount' => 12500.0]), true);
        $this->assertIsInt($asFloat['amount']);

        $asFloatWithCentavos = json_decode((string) json_encode(['amount' => 12500.5]), true);
        $this->assertIsFloat($asFloatWithCentavos['amount']);

        $asString = json_decode((string) json_encode(['amount' => '1250000']), true);
        $this->assertIsString($asString['amount']);
    }

    public function test_rehydrating_from_an_object_instead_of_a_list_is_refused(): void
    {
        $payload = $this->customerRow()->toPayload();
        $payload['values'] = array_combine(CsvImportSchema::keys(CsvImportSchema::CUSTOMERS), $payload['values']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/JSON list/');

        NormalizedRow::fromPayload($payload);
    }

    public function test_rehydrating_a_retyped_money_value_is_refused_rather_than_coerced(): void
    {
        $payload = $this->customerRow(['monthly_income' => '12,500.00'])->toPayload();
        $payload['values'][CsvImportSchema::indexOf(CsvImportSchema::CUSTOMERS, 'monthly_income')] = 1250000;

        // Silently accepting an int here would work today and hide the day the
        // value arrives as a float instead.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/rather than a string/');

        NormalizedRow::fromPayload($payload);
    }

    public function test_a_loan_reconstructs_identically_before_and_after_staging(): void
    {
        $cells = array_fill(0, CsvImportSchema::width(CsvImportSchema::LOANS), '');
        $values = [
            'account_no' => 'A-001',
            'loan_no' => 'L-0001',
            'loan_amount' => '70,000.07',
            'loan_balance' => '30,000.03',
            'interest_rate' => '3',
            'interest_amount' => '14,700.15',
            'interest_balance' => '6,300.07',
            'loan_product' => 'Regular Loan',
            'term_in_months' => '6',
            'payment_frequency' => 'Weekly',
            'interest_type' => 'Straight (Fixed)',
            'date_released' => '2025-01-15',
            'maturity_date' => '2025-07-15',
        ];

        foreach ($values as $key => $value) {
            $cells[CsvImportSchema::indexOf(CsvImportSchema::LOANS, $key)] = $value;
        }

        $direct = (new LoanRowNormalizer)->normalize(new CsvRow(1, 1, $cells));
        $staged = NormalizedRow::fromPayload($this->throughMysqlJson($direct->toPayload()));

        $reconstructor = new LoanScheduleReconstructor;
        $before = $reconstructor->reconstruct(LoanReconstructionInput::fromNormalizedRow($direct));
        $after = $reconstructor->reconstruct(LoanReconstructionInput::fromNormalizedRow($staged));

        $this->assertTrue($after->isValid());

        // The whole point: a principal that changed PHP type between the
        // staging pass and the import pass would be a hole under the integer
        // arithmetic everything else rests on.
        $this->assertSame($before->term, $after->term);
        $this->assertSame($before->totalPrincipalDue(), $after->totalPrincipalDue());
        $this->assertSame($before->totalInterestDue(), $after->totalInterestDue());
        $this->assertSame($before->outstandingPrincipal(), $after->outstandingPrincipal());
        $this->assertSame($before->outstandingInterest(), $after->outstandingInterest());
        $this->assertSame($before->toScheduleRows(), $after->toScheduleRows());

        $this->assertSame(7000007, $after->totalPrincipalDue());
        $this->assertSame(3000003, $after->outstandingPrincipal());
        $this->assertSame(630007, $after->outstandingInterest());
    }
}
