<?php

namespace Tests\Feature\Phase6\LoanOrigination\PublicIntake;

use App\Models\Branch;
use App\Models\LoanProduct;
use App\Models\OriginationLink;
use App\Models\OriginationNotice;
use App\Models\OriginationProductSetting;
use App\Models\OriginationSetting;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;

/**
 * Shared set-up for the public application tests (LO-B2): the server switch
 * on, the test-code mode on for one allow-listed staff number, online
 * applications on, the three required notices published, one product
 * offered online and a link for the main branch.
 */
trait PublicApplyHelpers
{
    /** The allow-listed staff number, as typed. */
    protected string $staffMobile = '09171234567';

    public function enableIntake(array $linkAttributes = []): OriginationLink
    {
        config([
            'loan_origination.enabled' => true,
            'loan_origination_intake.otp.test_codes' => true,
            'reminders.allow_list' => [$this->staffMobile],
            'reminders.allow_all_recipients' => false,
        ]);

        OriginationSetting::current()->forceFill(['online_applications_enabled' => true])->save();
        $this->publishNotices();

        $product = $this->onlineProduct();

        $link = OriginationLink::query()->create([
            'name' => 'Facebook page',
            'token' => OriginationLink::newToken(),
            'branch_id' => Branch::query()->orderBy('id')->value('id'),
            'active' => true,
            'display_name' => 'Binhs Coop',
            'contact_phone' => '0281234567',
            'contact_email' => 'loans@example.test',
            ...$linkAttributes,
        ]);
        $link->loanProducts()->sync([$product->id]);

        return $link;
    }

    public function publishNotices(string $version = '2026.1'): void
    {
        foreach (OriginationNotice::REQUIRED_TYPES as $type) {
            if (OriginationNotice::query()->where('type', $type)->where('version', $version)->exists()) {
                continue;
            }

            $body = "Draft {$type} text";
            OriginationNotice::query()->create([
                'type' => $type, 'version' => $version, 'title' => null, 'body' => $body,
                'body_hash' => hash('sha256', $body), 'is_final' => false, 'published_at' => now(),
            ]);
        }
    }

    public function onlineProduct(array $attributes = []): LoanProduct
    {
        $product = LoanProduct::factory()->create([
            'min_amount' => 5000, 'max_amount' => 50000, 'min_term' => 3, 'max_term' => 12, 'term' => 6, 'term_unit' => 'months',
            'frequencies' => ['monthly', 'semi_monthly'],
            ...$attributes,
        ]);

        OriginationProductSetting::query()->create([
            'loan_product_id' => $product->id, 'online' => true, 'required_fields' => [], 'required_documents' => [],
        ]);

        return $product;
    }

    public function linkProduct(OriginationLink $link): LoanProduct
    {
        return $link->loanProducts()->firstOrFail();
    }

    /** @param  array<string, mixed>  $extra */
    public function requestCode(OriginationLink $link, ?string $mobile = null, string $purpose = 'apply', array $extra = []): TestResponse
    {
        return $this->postJson("/api/public-apply/{$link->token}/otp", [
            'channel' => 'sms', 'mobile' => $mobile ?? $this->staffMobile, 'purpose' => $purpose, ...$extra,
        ]);
    }

    /** @param  array<string, mixed>  $extra */
    public function verifyCode(OriginationLink $link, string $code, ?string $mobile = null, string $purpose = 'apply', array $extra = []): TestResponse
    {
        return $this->postJson("/api/public-apply/{$link->token}/otp/verify", [
            'channel' => 'sms', 'mobile' => $mobile ?? $this->staffMobile, 'purpose' => $purpose, 'code' => $code,
            'privacy_notice_version' => '2026.1', ...$extra,
        ]);
    }

    /** A new application on $link: the session token. */
    public function startApplication(OriginationLink $link, string $purpose = 'apply', array $extra = []): string
    {
        $code = $this->requestCode($link, null, $purpose, $extra)->assertOk()->json('data.test_mode.code');

        return (string) $this->verifyCode($link, (string) $code, null, $purpose, $extra)->assertOk()->json('data.session_token');
    }

    /** @return array<string, string> */
    public function applicantSession(string $token): array
    {
        return ['X-Application-Session' => $token];
    }

    /** A complete draft for an employed applicant. */
    public function completeDraft(int $productId): array
    {
        return [
            'personal' => [
                'first_name' => 'Juana', 'middle_name' => '', 'last_name' => 'Dela Cruz', 'suffix' => '',
                'date_of_birth' => '1990-05-04', 'gender' => 'female', 'civil_status' => 'single', 'nationality' => 'Filipino',
                'region' => 'NCR', 'province' => 'Metro Manila', 'city' => 'Quezon City', 'barangay' => 'Bagumbayan',
                'street_address' => '12 Mabini St', 'postal_code' => '1110', 'years_at_residence' => '3',
                'residence_ownership' => 'rented', 'dependents' => '2', 'membership_number' => '', 'branch_id' => null,
            ],
            'employment' => [
                'income_source' => 'employed', 'employer_name' => 'Acme', 'employment_type' => 'regular', 'job_title' => 'Clerk',
                'years_employed' => '4', 'employer_address' => '', 'gross_monthly_income' => '25,000', 'net_monthly_income' => '21000',
                'other_regular_income' => '', 'salary_frequency' => 'monthly',
            ],
            'expenses' => [
                'housing' => '5000', 'utilities' => '1500', 'food' => '6000', 'transportation' => '1000', 'education' => '',
                'family_support' => '', 'other_essential' => '', 'credit_card_payments' => '', 'other_debt_payments' => '',
                'other_commitments' => '', 'has_existing_loans' => false, 'existing_loans' => [],
            ],
            'loan' => ['loan_product_id' => $productId, 'amount' => '20000', 'purpose' => 'Tuition', 'term' => '6', 'frequency' => 'monthly', 'release_method' => 'cash'],
        ];
    }

    public function png(string $name = 'id.png', int $seed = 1): UploadedFile
    {
        $image = imagecreatetruecolor(4, 4);
        imagefill($image, 0, 0, imagecolorallocate($image, $seed % 255, 10, 10));
        ob_start();
        imagepng($image);

        return UploadedFile::fake()->createWithContent($name, (string) ob_get_clean());
    }

    /** @return array<string, mixed> */
    public function consents(array $overrides = []): array
    {
        return [
            'privacy_notice_version' => '2026.1', 'terms_version' => '2026.1', 'declaration_version' => '2026.1',
            'credit_authorization_version' => null, 'marketing_opt_in' => false, ...$overrides,
        ];
    }
}
