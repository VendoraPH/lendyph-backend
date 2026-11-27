<?php

namespace App\Services\Cda\Sources;

use App\Models\CdaOfficer;
use App\Services\Cda\Registers\RegisterWords;
use App\Services\Cda\Registers\TrainingCompliance;
use Illuminate\Support\Collection;

/**
 * The officers register as report figures (CDA design §2, §6).
 *
 * - An officer counts for a range when the term overlaps it, whatever the
 *   status (one who resigned mid-year still served); deleted rows never
 *   count.
 * - A linked officer's name and sex are the member's, read now; a finalized
 *   report keeps them in its snapshot. `officers.list` gives the sex in
 *   words ("Female"), with the stored key in `sex_key`, as it does the
 *   position (`position`, `position_key`).
 * - `officers.count` counts people, so a member holding two positions counts
 *   once (an unlinked officer is one person per row). Groups: the board
 *   (chairperson, vice chairperson, members), committees, and management
 *   (secretary, treasurer, general manager and staff).
 * - `trainings.summary` is one row per officer with the training compliance
 *   indicator, and `meta.compliance` the share of officers with required
 *   trainings who completed them.
 */
final class OfficersSource implements CdaSource
{
    public const GROUPS = [
        'board' => ['chairperson', 'vice_chairperson', 'board_member'],
        'committee' => ['committee_chairperson', 'committee_member'],
        'management' => ['secretary', 'treasurer', 'general_manager', 'manager', 'bookkeeper', 'other'],
    ];

    private const LABEL = 'Officers register';

    public function __construct(private readonly TrainingCompliance $compliance) {}

    public function resolve(string $key, array $params, SourceContext $context): SourceResult
    {
        return match ($key) {
            'officers.current' => $this->current($params, $context),
            'officers.list' => $this->list($params, $context),
            'officers.count' => $this->count($params, $context),
            'trainings.summary' => $this->trainings($params, $context),
            default => throw SourceUnavailable::unknownKey($key, 'officers'),
        };
    }

    /** @param  array<string, mixed>  $params */
    private function current(array $params, SourceContext $context): SourceResult
    {
        $asOf = $context->date($params['as_of']);
        $officer = CdaOfficer::query()->with('member')->servingOn($asOf)->where('position', $params['position'])
            ->orderByDesc('term_start')->orderByDesc('id')->first();

        return new SourceResult(
            $officer?->displayName(),
            SourceResult::labelFor(RegisterWords::position($params['position']).' · '.self::LABEL, $asOf),
            'text',
            $asOf,
        );
    }

    /** @param  array<string, mixed>  $params */
    private function list(array $params, SourceContext $context): SourceResult
    {
        [$from, $to] = [$context->date($params['from']), $context->date($params['to'])];

        $rows = $this->serving($from, $to)->map(fn (CdaOfficer $officer) => [
            'name' => $officer->displayName(),
            'sex' => RegisterWords::sex($officer->displaySex()),
            'sex_key' => $officer->displaySex(),
            'position' => RegisterWords::position($officer->position),
            'position_key' => $officer->position,
            'committee' => $officer->committee,
            'position_title' => $officer->position_title,
            'election_date' => $officer->election_date?->toDateString(),
            'term_start' => $officer->term_start->toDateString(),
            'term_end' => $officer->term_end?->toDateString(),
            'status' => $officer->status,
        ])->values()->all();

        return new SourceResult($rows, SourceResult::labelFor(self::LABEL, $to), 'table', $to);
    }

    /** @param  array<string, mixed>  $params */
    private function count(array $params, SourceContext $context): SourceResult
    {
        [$from, $to] = [$context->date($params['from']), $context->date($params['to'])];
        $group = $params['group'] ?? 'all';
        $sex = $params['sex'] ?? null;

        $people = $this->serving($from, $to)
            ->filter(fn (CdaOfficer $officer) => $group === 'all' || in_array($officer->position, self::GROUPS[$group] ?? [], true))
            ->filter(fn (CdaOfficer $officer) => $sex === null || $officer->displaySex() === $sex)
            ->map(fn (CdaOfficer $officer) => $officer->borrower_id !== null ? "member:{$officer->borrower_id}" : "officer:{$officer->id}")
            ->unique();

        $what = trim(implode(' ', array_filter([$sex === null ? null : ucfirst($sex), $group === 'all' ? null : $group, 'officers'])));

        return new SourceResult($people->count(), SourceResult::labelFor(ucfirst($what).' · '.self::LABEL, $to), 'count', $to);
    }

    /** @param  array<string, mixed>  $params */
    private function trainings(array $params, SourceContext $context): SourceResult
    {
        [$from, $to] = [$context->date($params['from']), $context->date($params['to'])];
        $officers = $this->serving($from, $to, withTrainings: true);
        $rows = [];
        $complete = 0;
        $incomplete = 0;

        foreach ($officers as $officer) {
            $result = $this->compliance->evaluate($officer, $from, $to);
            $complete += $result['status'] === TrainingCompliance::COMPLETE ? 1 : 0;
            $incomplete += $result['status'] === TrainingCompliance::INCOMPLETE ? 1 : 0;

            $rows[] = [
                'officer' => $officer->displayName(),
                'position' => RegisterWords::position($officer->position),
                'required' => count($result['required']),
                'completed' => count($result['completed']),
                'hours' => RegisterWords::hundredths($result['hours_centi']),
                'status' => TrainingCompliance::STATUS_WORDS[$result['status']],
                'status_key' => $result['status'],
                'missing' => $result['missing'],
            ];
        }

        $required = $complete + $incomplete;

        return new SourceResult($rows, SourceResult::labelFor('Officer trainings · '.self::LABEL, $to), 'table', $to, meta: [
            'compliance' => [
                'officers_required' => $required,
                'complete' => $complete,
                'incomplete' => $incomplete,
                'percent' => $required === 0 ? null : RegisterWords::percent($complete, $required),
            ],
        ]);
    }

    /** @return Collection<int, CdaOfficer> in position order, then term start */
    private function serving(string $from, string $to, bool $withTrainings = false): Collection
    {
        $order = array_flip(CdaOfficer::POSITIONS);

        return CdaOfficer::query()
            ->with($withTrainings ? ['member', 'trainings'] : ['member'])
            ->servingBetween($from, $to)
            ->orderBy('term_start')->orderBy('id')
            ->get()
            ->sortBy(fn (CdaOfficer $officer) => $order[$officer->position] ?? count($order), SORT_NUMERIC, false)
            ->values();
    }
}
