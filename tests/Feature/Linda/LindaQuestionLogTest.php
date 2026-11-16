<?php

namespace Tests\Feature\Linda;

use App\Models\LindaQuestion;
use App\Models\User;
use App\Traits\Auditable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * `linda_questions`, Linda's log (Linda v1 plan, "Privacy and the log"): who,
 * when, the source, the intent, the period, the branch scope, the members
 * listed, the outcome, the model, the tokens, the cost and the time taken.
 * Never the question or the answer, and not Auditable.
 */
class LindaQuestionLogTest extends TestCase
{
    public function test_it_has_exactly_the_planned_columns_and_none_for_text(): void
    {
        $this->assertEqualsCanonicalizing([
            'id', 'user_id', 'asked_at', 'source', 'intent', 'answer_function', 'outcome', 'error_code',
            'period_from', 'period_to', 'branch_scope', 'branch_id', 'member_ids', 'model',
            'input_tokens', 'output_tokens', 'cache_read_tokens', 'cache_write_tokens', 'cost', 'duration_ms',
            'created_at', 'updated_at',
        ], Schema::getColumnListing('linda_questions'));
    }

    public function test_asked_at_and_user_with_asked_at_are_indexed(): void
    {
        $indexes = collect(Schema::getIndexes('linda_questions'))->pluck('columns')->all();

        $this->assertContains(['asked_at'], $indexes);
        $this->assertContains(['user_id', 'asked_at'], $indexes);
    }

    public function test_the_model_is_not_auditable(): void
    {
        $this->assertNotContains(Auditable::class, class_uses_recursive(LindaQuestion::class));
    }

    public function test_it_casts_its_columns_and_defaults_the_counters(): void
    {
        $user = User::factory()->create();

        $row = LindaQuestion::query()->create([
            'user_id' => $user->id,
            'asked_at' => '2026-11-16 09:30:00',
            'source' => LindaQuestion::SOURCE_MATCHER,
            'intent' => 'collection_summary',
            'outcome' => 'answered',
            'period_from' => '2026-11-01',
            'period_to' => '2026-11-30',
            'branch_scope' => [3, 5],
            'member_ids' => [11, 12],
            'duration_ms' => 42,
        ])->refresh();

        $this->assertInstanceOf(CarbonInterface::class, $row->asked_at);
        $this->assertSame('2026-11-01', $row->period_from->toDateString());
        $this->assertSame([3, 5], $row->branch_scope);
        $this->assertSame([11, 12], $row->member_ids);
        $this->assertSame(0, $row->input_tokens);
        $this->assertSame(0, $row->cache_write_tokens);
        $this->assertSame('0.000000', $row->cost);
        $this->assertSame(42, $row->duration_ms);
        $this->assertTrue($row->user->is($user));
    }

    public function test_a_null_branch_scope_means_every_branch_and_survives_a_round_trip(): void
    {
        $row = LindaQuestion::query()->create([
            'asked_at' => now(),
            'source' => LindaQuestion::SOURCE_NONE,
            'branch_scope' => null,
            'duration_ms' => 1,
        ])->refresh();

        $this->assertNull($row->branch_scope);
        $this->assertNull($row->member_ids);
    }

    public function test_deleting_the_user_keeps_the_row_without_them(): void
    {
        $user = User::factory()->create();
        $row = LindaQuestion::query()->create([
            'user_id' => $user->id, 'asked_at' => now(), 'source' => LindaQuestion::SOURCE_MODEL, 'duration_ms' => 5,
        ]);

        $user->forceDelete();

        $this->assertNull($row->refresh()->user_id);
    }
}
