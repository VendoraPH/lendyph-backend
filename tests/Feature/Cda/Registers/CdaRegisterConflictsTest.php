<?php

namespace Tests\Feature\Cda\Registers;

use App\Models\CdaMediationCase;
use App\Models\CdaOfficer;
use App\Models\CdaOfficerTraining;
use App\Models\CdaSocialProgram;
use App\Services\Cda\CdaAccess;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use PDOException;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;
use Throwable;

/**
 * Register updates and deletes under contention (C3 review M4): a deadlock
 * or lock wait answers 409 in words, never 500. A mediation write that fails
 * for any other reason never carries the case's text (the SQL bindings) into
 * the exception that gets logged.
 */
class CdaRegisterConflictsTest extends TestCase
{
    use RegisterHelpers, SetupLendyPH;

    private const DEADLOCK = 'SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock; try restarting transaction';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        $this->freezeClock();
        $this->period();
        $this->actingAs($this->cdaUser([CdaAccess::MANAGE_OFFICERS, CdaAccess::MANAGE_SOCIAL, CdaAccess::MANAGE_MEDIATION, CdaAccess::VIEW_MEDIATION]));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * Makes the model's next save or delete fail once, as the database would.
     *
     * @param  class-string<Model>  $model
     * @param  list<mixed>  $bindings
     */
    private function failNext(string $model, string $event, string $message, array $bindings = []): void
    {
        $armed = true;

        $model::$event(function () use (&$armed, $message, $bindings): void {
            if ($armed) {
                $armed = false;

                throw new QueryException('mysql', 'update `t` set `x` = ?', $bindings, new PDOException($message));
            }
        });
    }

    public function test_a_deadlock_on_an_update_or_delete_answers_409_in_words(): void
    {
        $officer = CdaOfficer::factory()->create();
        $training = CdaOfficerTraining::factory()->create();
        $program = CdaSocialProgram::factory()->create(['period_id' => $this->period()->id]);
        $case = CdaMediationCase::factory()->create(['filed_on' => '2026-03-01']);

        $writes = [
            [CdaOfficer::class, 'updating', fn () => $this->putJson("/api/cda/officers/{$officer->id}", ['remarks' => 'x'])],
            [CdaOfficer::class, 'deleting', fn () => $this->deleteJson("/api/cda/officers/{$officer->id}")],
            [CdaOfficerTraining::class, 'updating', fn () => $this->putJson("/api/cda/trainings/{$training->id}", ['hours' => '3'])],
            [CdaOfficerTraining::class, 'deleting', fn () => $this->deleteJson("/api/cda/trainings/{$training->id}")],
            [CdaSocialProgram::class, 'updating', fn () => $this->putJson("/api/cda/social-programs/{$program->id}", ['location' => 'Plaza'])],
            [CdaSocialProgram::class, 'deleting', fn () => $this->deleteJson("/api/cda/social-programs/{$program->id}")],
            [CdaMediationCase::class, 'updating', fn () => $this->putJson("/api/cda/mediation-cases/{$case->id}", ['meetings_count' => 2])],
            [CdaMediationCase::class, 'deleting', fn () => $this->deleteJson("/api/cda/mediation-cases/{$case->id}")],
        ];

        foreach ($writes as [$model, $event, $send]) {
            $this->failNext($model, $event, self::DEADLOCK);

            $send()->assertConflict()->assertJsonPath('message', 'Another change to this register was saved at the same time. Reload and try again.');
        }
    }

    public function test_a_failed_mediation_write_never_carries_the_case_text(): void
    {
        $case = CdaMediationCase::factory()->create(['filed_on' => '2026-03-01']);
        $this->failNext(CdaMediationCase::class, 'updating', 'SQLSTATE[22001]: String data, right truncated', ['Juan Dela Cruz', 'Boundary dispute']);
        $this->withoutExceptionHandling();

        try {
            $this->putJson("/api/cda/mediation-cases/{$case->id}", ['complainant' => 'Juan Dela Cruz', 'nature' => 'Boundary dispute']);
            $this->fail('The write was expected to fail.');
        } catch (Throwable $e) {
            $logged = (string) $e;
            $this->assertStringNotContainsString('Juan Dela Cruz', $logged);
            $this->assertStringNotContainsString('Boundary dispute', $logged);
            $this->assertStringContainsString('22001', $e->getMessage());
        }
    }
}
