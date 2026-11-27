<?php

namespace Tests\Unit\Linda;

use PHPUnit\Framework\Attributes\DataProvider;

/**
 * LINDA_ENABLED and LINDA_OPEN_QUESTIONS read as booleans the way PHP's
 * filter does (security review of #327): "off", "no", "0" and "false" are
 * off, never on just because the text isn't empty.
 */
class LindaConfigSwitchesTest extends LindaTestCase
{
    private const SWITCHES = ['LINDA_ENABLED' => 'enabled', 'LINDA_OPEN_QUESTIONS' => 'open_questions'];

    protected function tearDown(): void
    {
        foreach (array_keys(self::SWITCHES) as $name) {
            unset($_ENV[$name], $_SERVER[$name]);
            putenv($name);
        }

        parent::tearDown();
    }

    /** @return array<string, array{?string, bool}> */
    public static function values(): array
    {
        return [
            'off' => ['off', false], 'no' => ['no', false], '0' => ['0', false], 'false' => ['false', false],
            'empty' => ['', false], 'unset' => [null, false],
            'on' => ['on', true], 'yes' => ['yes', true], '1' => ['1', true], 'true' => ['true', true],
        ];
    }

    #[DataProvider('values')]
    public function test_the_switches_read_as_booleans(?string $value, bool $expected): void
    {
        foreach (self::SWITCHES as $name => $key) {
            if ($value !== null) {
                $_ENV[$name] = $_SERVER[$name] = $value;
                putenv("{$name}={$value}");
            }

            $config = require base_path('config/linda.php');

            $this->assertSame($expected, $config[$key], "{$name}={$value}");
        }
    }
}
