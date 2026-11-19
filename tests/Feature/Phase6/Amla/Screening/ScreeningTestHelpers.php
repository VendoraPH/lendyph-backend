<?php

namespace Tests\Feature\Phase6\Amla\Screening;

use App\Models\Borrower;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Phase6\Amla\AmlaTestHelpers;

/**
 * Shared set-up for the AMLA screening and orders tests (AMLA-B5): sample
 * watchlists (the UN consolidated list's XML shape and the CSV template),
 * uploads, and the head-office users who work them (never super_admin).
 */
trait ScreeningTestHelpers
{
    use AmlaTestHelpers;

    /** `amla:screening` with head-office access. */
    protected function screener(): User
    {
        return $this->amlaUser(['amla:screening', 'branches:view_all']);
    }

    /** `amla:sanctions` with head-office access. */
    protected function sanctionsOfficer(): User
    {
        return $this->amlaUser(['amla:sanctions', 'branches:view_all']);
    }

    /** @param  array<string, mixed>  $attributes */
    protected function member(array $attributes = [], ?Branch $branch = null): Borrower
    {
        return Borrower::factory()->create([
            'branch_id' => ($branch ?? $this->branch)->id,
            'status' => 'active',
            ...$attributes,
        ]);
    }

    /**
     * The UN consolidated list's XML shape, cut down: individuals with name
     * parts, aliases and birth dates, and an entity.
     *
     * @param  list<array{ref: string, first: string, second?: string, third?: string, aliases?: list<string>, dob?: string, year?: string}>  $individuals
     * @param  list<array{ref: string, name: string}>  $entities
     */
    protected function unXml(array $individuals, array $entities = [], string $generated = '2026-11-18T10:00:00-05:00'): string
    {
        $people = '';

        foreach ($individuals as $person) {
            $aliases = '';

            foreach ($person['aliases'] ?? [] as $alias) {
                $aliases .= "<INDIVIDUAL_ALIAS><QUALITY>Good</QUALITY><ALIAS_NAME>{$alias}</ALIAS_NAME></INDIVIDUAL_ALIAS>";
            }

            $birth = isset($person['dob'])
                ? "<INDIVIDUAL_DATE_OF_BIRTH><TYPE_OF_DATE>EXACT</TYPE_OF_DATE><DATE>{$person['dob']}</DATE></INDIVIDUAL_DATE_OF_BIRTH>"
                : (isset($person['year']) ? "<INDIVIDUAL_DATE_OF_BIRTH><TYPE_OF_DATE>APPROXIMATELY</TYPE_OF_DATE><YEAR>{$person['year']}</YEAR></INDIVIDUAL_DATE_OF_BIRTH>" : '');

            $people .= '<INDIVIDUAL><DATAID>'.crc32($person['ref']).'</DATAID>'
                ."<REFERENCE_NUMBER>{$person['ref']}</REFERENCE_NUMBER>"
                ."<FIRST_NAME>{$person['first']}</FIRST_NAME>"
                .'<SECOND_NAME>'.($person['second'] ?? '').'</SECOND_NAME>'
                .'<THIRD_NAME>'.($person['third'] ?? '').'</THIRD_NAME>'
                ."<NATIONALITY><VALUE>Philippines</VALUE></NATIONALITY>{$aliases}{$birth}</INDIVIDUAL>";
        }

        $groups = '';

        foreach ($entities as $entity) {
            $groups .= '<ENTITY><DATAID>'.crc32($entity['ref']).'</DATAID>'
                ."<REFERENCE_NUMBER>{$entity['ref']}</REFERENCE_NUMBER><FIRST_NAME>{$entity['name']}</FIRST_NAME></ENTITY>";
        }

        return "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<CONSOLIDATED_LIST dateGenerated=\"{$generated}\">"
            ."<INDIVIDUALS>{$people}</INDIVIDUALS><ENTITIES>{$groups}</ENTITIES></CONSOLIDATED_LIST>";
    }

    /** @param  list<list<string>>  $rows */
    protected function csvList(array $rows, string $header = 'reference,type,full_name,aliases,birth_year,nationality'): string
    {
        return $header."\n".implode("\n", array_map(fn (array $row) => implode(',', $row), $rows))."\n";
    }

    protected function upload(User $user, string $source, string $contents, string $filename, ?string $label = null): TestResponse
    {
        return $this->actingAs($user)->post("/api/amla/screening/lists/{$source}/versions", [
            'file' => UploadedFile::fake()->createWithContent($filename, $contents),
            'version_label' => $label,
        ], ['Accept' => 'application/json']);
    }
}
