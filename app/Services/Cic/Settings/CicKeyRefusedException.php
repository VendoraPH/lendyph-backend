<?php

namespace App\Services\Cic\Settings;

use RuntimeException;

/**
 * An uploaded CIC public key that can't be used, with the reason in words
 * (CicKeyInspector). The upload answers 422 with this message.
 */
final class CicKeyRefusedException extends RuntimeException {}
