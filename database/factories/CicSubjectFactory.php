<?php

namespace Database\Factories;

use App\Models\Borrower;
use App\Models\CicSubject;
use App\Models\CoMaker;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A member subject by default, numbered with the member's code (BRW-), as the
 * capture numbers them; coMaker() for a co-maker subject (CMK-).
 *
 * @extends Factory<CicSubject>
 */
class CicSubjectFactory extends Factory
{
    protected $model = CicSubject::class;

    public function definition(): array
    {
        return [
            'subject_type' => CicSubject::TYPE_MEMBER,
            'borrower_id' => Borrower::factory(),
            'co_maker_id' => null,
            'subject_no' => fn (array $attributes) => Borrower::query()->whereKey($attributes['borrower_id'])->value('borrower_code'),
            'first_captured_at' => now(),
        ];
    }

    public function forMember(Borrower $borrower): static
    {
        return $this->state([
            'subject_type' => CicSubject::TYPE_MEMBER,
            'borrower_id' => $borrower->id,
            'co_maker_id' => null,
            'subject_no' => $borrower->borrower_code,
        ]);
    }

    public function coMaker(?CoMaker $coMaker = null): static
    {
        return $this->state(fn () => [
            'subject_type' => CicSubject::TYPE_CO_MAKER,
            'borrower_id' => null,
            'co_maker_id' => $coMaker?->id ?? CoMaker::factory(),
            'subject_no' => fn (array $attributes) => CoMaker::query()->whereKey($attributes['co_maker_id'])->value('co_maker_code'),
        ]);
    }
}
