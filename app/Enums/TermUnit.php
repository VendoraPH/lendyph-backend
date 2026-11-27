<?php

namespace App\Enums;

/**
 * The unit a loan's `term` is a length in.
 *
 * `months` is the default and what every loan created before this column
 * existed means: a monthly loan's term of 6 is six months.
 */
enum TermUnit: string
{
    case Months = 'months';
    case Days = 'days';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }

    public static function rule(): string
    {
        return 'in:'.implode(',', self::values());
    }
}
