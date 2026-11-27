<?php

/**
 * Who voided a payment, by name.
 *
 * `voided_by` is the user's id; the name is `voided_by_user.name`. The payment
 * history and the receipt read the name, so every endpoint they use has to
 * send it. A screen showing "Voided by 1" is showing the id.
 */

use App\Models\Repayment;
use App\Models\User;
use App\Services\RepaymentService;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

uses(TestCase::class, SetupLendyPH::class);

beforeEach(function () {
    $this->seedAndLogin();

    $this->voider = User::factory()->create(['branch_id' => $this->branch->id, 'first_name' => 'Ana', 'last_name' => 'Bautista']);
    $this->voider->assignRole('super_admin');

    $this->loan = $this->createReleasedLoan();
    $this->repayment = app(RepaymentService::class)->processRepayment(
        $this->loan->fresh(), 5000, now()->toDateString(), $this->admin,
    );
});

/** The repayment voided by the second user, through the endpoint. */
function voidAsSecondUser($test): array
{
    $response = $test->actingAs($test->voider)
        ->patchJson("/api/repayments/{$test->repayment->id}/void", ['void_reason' => 'Keyed twice'])
        ->assertOk();

    $test->actingAs(User::where('username', 'super_admin')->first());

    return $response->json('data');
}

it('names the user on the void response', function () {
    $data = voidAsSecondUser($this);

    expect($data['status'])->toBe('voided')
        ->and($data['voided_by'])->toBe($this->voider->id)
        ->and($data['voided_by_user'])->toBe(['id' => $this->voider->id, 'name' => $this->voider->full_name]);
});

it('names the user on the receipt endpoint', function () {
    voidAsSecondUser($this);

    $this->getJson("/api/repayments/{$this->repayment->id}")
        ->assertOk()
        ->assertJsonPath('data.voided_by', $this->voider->id)
        ->assertJsonPath('data.voided_by_user.id', $this->voider->id)
        ->assertJsonPath('data.voided_by_user.name', 'Ana Bautista');
});

it('names the user on the payment history list', function () {
    voidAsSecondUser($this);

    $row = collect($this->getJson('/api/repayments?status=voided&per_page=100')->assertOk()->json('data'))
        ->firstWhere('id', $this->repayment->id);

    expect($row['voided_by'])->toBe($this->voider->id)
        ->and($row['voided_by_user'])->toBe(['id' => $this->voider->id, 'name' => 'Ana Bautista']);
});

it("names the user on a loan's payment list", function () {
    voidAsSecondUser($this);

    $row = collect($this->getJson("/api/loans/{$this->loan->id}/repayments")->assertOk()->json('data'))
        ->firstWhere('id', $this->repayment->id);

    expect($row['voided_by_user'])->toBe(['id' => $this->voider->id, 'name' => 'Ana Bautista']);
});

it('sends no voiding user for a payment nobody voided', function () {
    $row = $this->getJson("/api/repayments/{$this->repayment->id}")->assertOk()->json('data');

    expect($row['status'])->toBe('completed')
        ->and($row['voided_by'])->toBeNull()
        ->and($row['voided_by_user'])->toBeNull();
    expect(Repayment::find($this->repayment->id)->voided_at)->toBeNull();
});
