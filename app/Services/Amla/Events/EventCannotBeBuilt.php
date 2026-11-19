<?php

namespace App\Services\Amla\Events;

use RuntimeException;

/** A source row that can't become an AMLA event, and why, in words. Recorded as a failure, never thrown to a user. */
final class EventCannotBeBuilt extends RuntimeException {}
