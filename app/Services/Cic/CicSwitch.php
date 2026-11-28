<?php

namespace App\Services\Cic;

use App\Services\MenuVisibility\MenuVisibility;

/**
 * The CIC module's switches (`config/cic.php`): the backend switch, the
 * staging-only test captures, and the Menu Visibility key `cic`.
 */
final class CicSwitch
{
    /** The Menu Visibility key (Settings → Menu Visibility, "CIC Reporting"). */
    public const MENU_KEY = 'cic';

    public const PRODUCTION_REFUSAL = 'CIC_TEST_CAPTURES must be off in production: a test capture is dated today on any day, so it is never a real month-end figure.';

    public function __construct(private readonly MenuVisibility $visibility) {}

    /** The backend switch, `CIC_ENABLED`. */
    public function enabled(): bool
    {
        return config('cic.enabled') === true;
    }

    /** Whether manual captures dated today are allowed (staging only). */
    public function testCaptures(): bool
    {
        return config('cic.test_captures') === true;
    }

    /** Whether Settings → Menu Visibility hides CIC Reporting. */
    public function hidden(): bool
    {
        return in_array(self::MENU_KEY, $this->visibility->hidden(), true);
    }

    /** The CSDF version every file is written in: fixed at "1.0" in v1. */
    public static function formatVersion(): string
    {
        return (string) config('cic.format_version', '1.0');
    }
}
