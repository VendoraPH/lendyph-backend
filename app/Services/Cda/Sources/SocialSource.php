<?php

namespace App\Services\Cda\Sources;

use App\Http\Resources\Cda\CdaPresenter;
use App\Models\CdaSocialProgram;
use App\Services\Accounting\Money;
use App\Services\Cda\Registers\RegisterWords;
use Illuminate\Database\Eloquent\Builder;

/**
 * The period's social programs as report figures (CDA design §2, §6): the
 * social audit, the ABR and CAPR read them.
 *
 * Only programs held count (ongoing or completed): planned and cancelled
 * ones are left out of the list and every sum. Money is added in centavos
 * and sent as two-decimal strings.
 */
final class SocialSource implements CdaSource
{
    /** @var list<string> */
    public const HELD_STATUSES = ['ongoing', 'completed'];

    private const LABEL = 'Social programs register';

    /** @var array<string, string> measure => column */
    private const MONEY = ['budget' => 'budget', 'actual_expense' => 'actual_expense', 'cdf_amount' => 'cdf_amount'];

    public function resolve(string $key, array $params, SourceContext $context): SourceResult
    {
        return match ($key) {
            'social_programs.list' => $this->list($context),
            'social_programs.summary' => $this->summary($params, $context),
            default => throw SourceUnavailable::unknownKey($key, 'social'),
        };
    }

    private function list(SourceContext $context): SourceResult
    {
        $rows = $this->held($context)->orderBy('held_on')->orderBy('id')->get()->map(fn (CdaSocialProgram $program) => [
            'name' => $program->name,
            'category' => RegisterWords::socialCategory($program->category),
            'category_key' => $program->category,
            'held_on' => $program->held_on?->toDateString(),
            'ended_on' => $program->ended_on?->toDateString(),
            'location' => $program->location,
            'target_beneficiaries' => $program->target_beneficiaries,
            'actual_beneficiaries' => $program->actual_beneficiaries,
            'budget' => $program->budget === null ? null : self::money($program->budget),
            'actual_expense' => self::money($program->actual_expense),
            'funding_source' => RegisterWords::fundingSource($program->funding_source),
            'cdf_amount' => self::money($program->cdf_amount),
            'status' => $program->status,
        ])->all();

        $end = $context->date('period_end');

        return new SourceResult($rows, SourceResult::labelFor(self::LABEL, $end), 'table', $end);
    }

    /** @param  array<string, mixed>  $params */
    private function summary(array $params, SourceContext $context): SourceResult
    {
        $measure = $params['measure'];
        $query = $this->held($context)->when($params['category'] ?? null, fn (Builder $q, string $category) => $q->where('category', $category));
        $end = $context->date('period_end');
        $label = SourceResult::labelFor(self::LABEL, $end);

        return match ($measure) {
            'count' => new SourceResult($query->count(), $label, 'count', $end),
            'beneficiaries' => new SourceResult((int) $query->sum('actual_beneficiaries'), $label, 'count', $end),
            default => new SourceResult(
                CdaPresenter::money(self::centavos($query->pluck(self::MONEY[$measure])->all())),
                $label,
                'money',
                $end,
            ),
        };
    }

    /** @return Builder<CdaSocialProgram> */
    private function held(SourceContext $context): Builder
    {
        return CdaSocialProgram::query()->where('period_id', $context->period->id)->whereIn('status', self::HELD_STATUSES);
    }

    /**
     * Pesos added in whole centavos; an empty amount adds nothing.
     *
     * @param  list<mixed>  $values
     */
    public static function centavos(array $values): int
    {
        return array_sum(array_map(fn ($value) => $value === null ? 0 : (int) Money::toCentavos((string) $value), $values));
    }

    private static function money(mixed $value): string
    {
        return CdaPresenter::money((int) Money::toCentavos((string) $value));
    }
}
