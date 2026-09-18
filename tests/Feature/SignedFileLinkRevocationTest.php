<?php

use App\Models\Borrower;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

uses(TestCase::class);

/**
 * A password reset must take away the signed KYC links the account already
 * holds, not just its tokens.
 *
 * Signed file URLs carry their entire credential in the signature — they have
 * to, because <img src> cannot send an Authorization header — so
 * `$user->tokens()->delete()` could not reach one that had already been handed
 * out. An administrator resetting a compromised account left that account's
 * outstanding links streaming borrower identity documents for the remainder of
 * their 30-minute window.
 */
beforeEach(function () {
    Storage::fake('private');

    $this->admin = User::where('username', 'super_admin')->first();

    $this->staff = User::factory()->create(['branch_id' => 1, 'status' => 'active']);
    $this->staff->syncRoles(['loan_officer']);

    $path = 'borrowers/photos/1/photo.jpg';
    Storage::disk('private')->put($path, 'photo-bytes');
    $this->borrower = Borrower::factory()->create(['branch_id' => 1, 'photo_path' => $path]);

    $this->resetPasswordOf = function (User $user) {
        $this->actingAs($this->admin)
            ->postJson("/api/users/{$user->id}/reset-password", [
                'password' => 'brandnewpass123',
                'password_confirmation' => 'brandnewpass123',
            ])->assertOk();
    };
});

/** Mint a link exactly the way a serialised API response does: as $user. */
function linkMintedBy(User $user, Borrower $borrower): string
{
    Auth::setUser($user);
    $url = $borrower->fresh()->photo_url;
    Auth::forgetGuards();

    return $url;
}

it('serves a link while the minting account is untouched', function () {
    $link = linkMintedBy($this->staff, $this->borrower);

    $this->get($link)->assertOk();
});

it('kills a link that was minted before a password reset', function () {
    $link = linkMintedBy($this->staff, $this->borrower);

    // Sanity: live before the reset, so the assertion below is about the reset
    // and not about a link that never worked.
    $this->get($link)->assertOk();

    ($this->resetPasswordOf)($this->staff);
    Auth::forgetGuards();

    $this->get($link)->assertForbidden();
});

it('leaves other accounts links alone', function () {
    // Revocation must be per-account. Resetting one person's password cannot
    // blank every image in the product for everyone else.
    $other = User::factory()->create(['branch_id' => 1, 'status' => 'active']);
    $other->syncRoles(['loan_officer']);

    $staffLink = linkMintedBy($this->staff, $this->borrower);
    $otherLink = linkMintedBy($other, $this->borrower);

    ($this->resetPasswordOf)($this->staff);
    Auth::forgetGuards();

    $this->get($staffLink)->assertForbidden();
    $this->get($otherLink)->assertOk();
});

it('refuses a link whose binding has been edited', function () {
    // The binding is inside the signed payload, so rewriting it to a
    // still-current version is a signature failure, not a bypass.
    $link = linkMintedBy($this->staff, $this->borrower);

    ($this->resetPasswordOf)($this->staff);
    Auth::forgetGuards();

    $forged = preg_replace('/fv=\d+/', 'fv='.$this->staff->fresh()->file_link_version, $link);

    expect($forged)->not->toBe($link);
    $this->get($forged)->assertForbidden();
});

it('refuses a link repointed at another user', function () {
    $link = linkMintedBy($this->staff, $this->borrower);

    ($this->resetPasswordOf)($this->staff);
    Auth::forgetGuards();

    $forged = preg_replace('/u=\d+/', 'u='.$this->admin->id, $link);

    expect($forged)->not->toBe($link);
    $this->get($forged)->assertForbidden();
});

it('still mints working links after the reset', function () {
    // The counter revokes outstanding links; it must not brick the account.
    ($this->resetPasswordOf)($this->staff);
    Auth::forgetGuards();

    $this->get(linkMintedBy($this->staff->fresh(), $this->borrower))->assertOk();
});

it('leaves an anonymous applicants link unbound', function () {
    // Public registration answers an applicant who has no account and no
    // password that could ever be reset, so there is nothing to bind to. The
    // link is unbound and expires on time, as before. Documented here so the
    // carve-out is deliberate and visible rather than an oversight.
    $created = $this->postJson('/api/borrowers', [
        'status' => 'pending',
        'branch_id' => 1,
        'first_name' => 'Anon',
        'last_name' => 'Applicant',
        'contact_number' => '09170000001',
        'email' => 'anon.applicant@example.com',
        'address' => '1 Mango St',
    ])->assertCreated();

    $borrowerId = $created->json('data.id');
    $submissionToken = $created->json('data.submission_token');

    $photoUrl = $this->postJson(
        "/api/borrowers/{$borrowerId}/photo",
        ['photo' => UploadedFile::fake()->image('me.jpg')],
        ['X-Submission-Token' => $submissionToken],
    )->assertOk()->json('photo_url');

    expect($photoUrl)->not->toContain('u=')
        ->and($photoUrl)->not->toContain('fv=');

    $this->get($photoUrl)->assertOk();
});
