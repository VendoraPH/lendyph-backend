<?php

namespace Tests\Unit;

use App\Rules\UniqueRoleDisplayName;
use Normalizer;
use PHPUnit\Framework\TestCase;
use Symfony\Polyfill\Intl\Normalizer\Normalizer as PolyfillNormalizer;

/**
 * CI installs ext-intl, but production may run without it, in which case the
 * global Normalizer is symfony/polyfill-intl-normalizer. These cases call the
 * polyfill directly and check it folds and composes role labels exactly as
 * the Normalizer the app is running with does, so the lookalike and accent
 * rules hold either way.
 *
 * No database and no application boot.
 */
class RoleLabelNormaliserTest extends TestCase
{
    public function test_the_polyfill_folds_compatibility_forms_like_the_running_normalizer(): void
    {
        $cases = [
            "\u{FF21}\u{FF44}\u{FF4D}\u{FF49}\u{FF4E}" => 'Admin',
            "\u{1D400}\u{1D41D}\u{1D426}\u{1D422}\u{1D427}" => 'Admin',
            "super\u{FF3F}admin" => 'super_admin',
            "\u{FF34}\u{FF45}\u{FF4C}\u{FF4C}\u{FF45}\u{FF52}" => 'Teller',
        ];

        foreach ($cases as $input => $folded) {
            $this->assertSame($folded, PolyfillNormalizer::normalize($input, PolyfillNormalizer::FORM_KC));
            $this->assertSame($folded, Normalizer::normalize($input, Normalizer::FORM_KC));
            $this->assertSame(UniqueRoleDisplayName::normalise($folded), UniqueRoleDisplayName::normalise($input));
        }
    }

    public function test_the_polyfill_composes_a_decomposed_accent_like_the_running_normalizer(): void
    {
        $decomposed = "Jose\u{0301} Cashier";
        $precomposed = "Jos\u{00E9} Cashier";

        $this->assertSame($precomposed, PolyfillNormalizer::normalize($decomposed, PolyfillNormalizer::FORM_C));
        $this->assertSame($precomposed, UniqueRoleDisplayName::compose("  {$decomposed} "));
        $this->assertSame("Desk \u{0301}Clerk", PolyfillNormalizer::normalize("Desk \u{0301}Clerk", PolyfillNormalizer::FORM_C));
    }

    public function test_invalid_utf8_is_never_compared_as_raw_bytes(): void
    {
        $this->assertFalse(PolyfillNormalizer::normalize("Admin\xFF", PolyfillNormalizer::FORM_KC));
        $this->assertNull(UniqueRoleDisplayName::normalise("Admin\xFF"));
        $this->assertNull(UniqueRoleDisplayName::compose("Admin\xFF"));
        $this->assertTrue(UniqueRoleDisplayName::readsAsAnotherRole([], "Admin\xFF"));
    }
}
