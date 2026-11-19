<?php

namespace App\Services\LoanOrigination\Intake;

use App\Http\Middleware\TrustFrontendProxy;
use App\Models\Branch;
use App\Models\BrandingSetting;
use App\Models\LoanProduct;
use App\Models\OriginationLink;
use App\Models\OriginationNotice;
use App\Models\OriginationProductSetting;
use App\Models\OriginationSetting;
use App\Services\LoanOrigination\Files\OriginationFileStore;
use App\Services\LoanOrigination\OriginationNotices;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * What a public link shows and offers (origination decisions 1, 6, 9 and 16):
 * the landing page, whether it takes applications (and why not, in words),
 * its products, its branches and the documents asked for.
 *
 * A link takes applications only while online applications are on in the
 * settings, the link is on and not expired, the privacy notice, terms and
 * declaration are published, the server can tell visitors apart (the
 * frontend proxy's secret is set), at least one product is offered online, and a
 * code can reach the applicant (ApplicantOtp::channels()). The server switch
 * (LOAN_ORIGINATION_ENABLED) is checked before any of this: while it is off
 * no public route answers at all.
 */
final class PublicPortal
{
    public const SETTINGS_OFF = 'Online applications are closed right now. Please contact the office.';

    public const LINK_OFF = 'This application link has been turned off. Please contact the office.';

    public const LINK_EXPIRED = 'This application link has expired. Please contact the office.';

    public const NOT_READY = 'Online applications aren\'t ready yet. Please contact the office.';

    public const NO_PRODUCTS = 'No loan products are offered online right now.';

    public const CLOSED = 'This link isn\'t taking applications right now. Please contact the office.';

    public const NO_VISITOR_ADDRESS = 'Online applications can\'t open yet: the server can\'t tell visitors apart, so it can\'t limit misuse. Please contact the office.';

    private const PROXY_WARNING_KEY = 'loan-origination:proxy-secret-warning';

    public function __construct(
        private readonly ApplicantOtp $otp,
        private readonly OriginationNotices $notices,
    ) {}

    /** Why $link takes no application now, in words, or null when it does. */
    public function closedReason(OriginationLink $link): ?string
    {
        return match (true) {
            ! OriginationSetting::current()->online_applications_enabled => self::SETTINGS_OFF,
            $link->status() === OriginationLink::STATUS_DISABLED => self::LINK_OFF,
            $link->status() === OriginationLink::STATUS_EXPIRED => self::LINK_EXPIRED,
            ! $this->knowsVisitorAddresses() => self::NO_VISITOR_ADDRESS,
            $this->notices->missingRequired() !== [] => self::NOT_READY,
            $this->products($link)->isEmpty() => self::NO_PRODUCTS,
            $this->otp->channels($link) === [] => ApplicantOtp::NO_CHANNEL,
            default => null,
        };
    }

    /**
     * Whether the frontend proxy's secret is set (TrustFrontendProxy), so
     * per-IP limits see each visitor's own address. Without it every visitor
     * shares the frontend's, so links stay closed; the first refusal logs a
     * warning once.
     */
    public function knowsVisitorAddresses(): bool
    {
        if (strlen((string) config('trustedproxy.proxy_secret', '')) >= TrustFrontendProxy::MIN_SECRET_LENGTH) {
            return true;
        }

        if (Cache::add(self::PROXY_WARNING_KEY, true)) {
            Log::warning('Online applications are closed: LENDYPH_PROXY_SECRET is not set, so every visitor would share one address for the rate limits.');
        }

        return false;
    }

    /**
     * The products $link offers: its own list (every product when it lists
     * none), active and turned on for online applications.
     *
     * @return Collection<int, LoanProduct>
     */
    public function products(OriginationLink $link): Collection
    {
        $listed = $link->loanProducts()->pluck('loan_products.id')->all();

        return LoanProduct::query()
            ->where('status', 'active')
            ->whereIn('id', OriginationProductSetting::query()->where('online', true)->select('loan_product_id'))
            ->when($listed !== [], fn ($query) => $query->whereIn('id', $listed))
            ->orderBy('name')->orderBy('id')
            ->get();
    }

    public function offers(OriginationLink $link, ?int $productId): bool
    {
        return $productId !== null && $this->products($link)->contains('id', $productId);
    }

    /**
     * The branches an applicant may pick: the link's own, else every active
     * branch.
     *
     * @return Collection<int, Branch>
     */
    public function branches(OriginationLink $link): Collection
    {
        return Branch::query()
            ->where('status', Branch::STATUS_ACTIVE)
            ->when($link->branch_id !== null, fn ($query) => $query->whereKey($link->branch_id))
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    /**
     * Every document type the settings list, each marked required when the
     * settings or the chosen product's settings require it.
     *
     * @return list<array{type: string, label: string, description: ?string, required: bool}>
     */
    public function documentTypes(?int $productId = null): array
    {
        $required = $productId === null ? [] : (array) (OriginationProductSetting::query()->where('loan_product_id', $productId)->first()?->required_documents ?? []);

        return array_values(array_map(fn (array $type) => [
            'type' => (string) $type['type'],
            'label' => (string) $type['label'],
            'description' => $type['description'] ?? null,
            'required' => (bool) $type['required'] || in_array($type['type'], $required, true),
        ], OriginationSetting::current()->document_types ?? []));
    }

    /** The application's branch: the link's, else the applicant's pick, else the default. */
    public function branchFor(OriginationLink $link, ?int $chosen): ?int
    {
        return $link->branch_id ?? $chosen ?? OriginationSetting::current()->default_branch_id;
    }

    /** @return array<string, mixed> */
    public function present(OriginationLink $link): array
    {
        $settings = OriginationSetting::current();
        $branding = BrandingSetting::current();
        $closed = $this->closedReason($link);
        $notices = $this->notices->currentAll();
        $name = $link->display_name ?? $branding->organization_name ?? config('app.name');

        return [
            'organization' => [
                'name' => $name,
                'logo_url' => $branding->logo_url,
                'contact_phone' => $link->contact_phone ?? $branding->organization_contact,
                'contact_email' => $link->contact_email,
                'address' => $branding->organization_address,
            ],
            'accepting' => $closed === null,
            'closed_reason' => $closed,
            'welcome_heading' => "Apply for a loan with {$name}",
            'intro' => null,
            'eligibility' => null,
            'estimated_minutes' => $settings->estimated_minutes,
            'privacy_notice' => self::notice($notices['privacy_notice']),
            'terms' => self::notice($notices['terms']),
            'credit_authorization' => self::notice($notices['credit_authorization']),
            'declaration' => self::notice($notices['declaration']),
            'products' => $this->products($link)->map(fn (LoanProduct $product) => self::product($product))->values()->all(),
            'documents' => $this->documentTypes(),
            'branches' => $this->branches($link)->map(fn (Branch $b) => ['id' => $b->id, 'name' => $b->name])->values()->all(),
            'accepted_file_types' => array_values(array_intersect(
                array_map('strtolower', (array) $settings->accepted_file_types),
                ['jpg', 'jpeg', 'jpe', 'jfif', 'png', 'pdf'],
            )),
            'max_upload_mb' => min(OriginationFileStore::MAX_MB, max(1, (int) $settings->max_upload_mb)),
            'otp_channels' => $this->otp->channels($link),
        ];
    }

    /** The office's own contact line, from the branding. */
    public function officeContact(): ?string
    {
        return BrandingSetting::current()->organization_contact;
    }

    /** Counts a landing-page visit, with no audit row and no other change. */
    public function countVisit(OriginationLink $link): void
    {
        OriginationLink::query()->whereKey($link->id)->increment('visits');
    }

    /** @return ?array{version: string, text: string, final: bool} */
    private static function notice(?OriginationNotice $notice): ?array
    {
        return $notice === null ? null : ['version' => $notice->version, 'text' => $notice->body, 'final' => (bool) $notice->is_final];
    }

    /** @return array<string, mixed> */
    private static function product(LoanProduct $product): array
    {
        $setting = OriginationProductSetting::query()->where('loan_product_id', $product->id)->first();
        $frequencies = array_values(array_filter((array) ($product->frequencies ?? []), 'is_string'));

        return [
            'id' => $product->id,
            'name' => $product->name,
            'description' => $product->description,
            'eligible_applicants' => null,
            'min_amount' => $product->min_amount === null ? null : (float) $product->min_amount,
            'max_amount' => $product->max_amount === null ? null : (float) $product->max_amount,
            'min_term' => $product->min_term,
            'max_term' => $product->max_term,
            'term_unit' => $product->term_unit?->value ?? 'months',
            'payment_frequencies' => $frequencies !== [] ? $frequencies : array_values(array_filter([(string) $product->frequency])),
            'interest_method' => $product->interest_method,
            'interest_rate' => (float) $product->interest_rate,
            'interest_rate_frequency' => $product->interest_rate_frequency?->value,
            'eligibility' => $setting?->eligibility,
            'instructions' => $setting?->instructions,
        ];
    }
}
