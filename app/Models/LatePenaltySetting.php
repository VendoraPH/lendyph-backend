<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The day the late penalty became a monthly charge on this deployment (the
 * 2026-11-11 migration stores it). A period already late before it keeps its
 * one charge and adds one for each full month after it; every other period is
 * charged once per month late (RepaymentService::penaltyStateAsOf()). A deployment
 * with no row has no period from before the switch.
 */
class LatePenaltySetting extends Model
{
    public $timestamps = false;

    protected $fillable = ['monthly_from'];

    protected function casts(): array
    {
        return ['monthly_from' => 'date'];
    }

    /** Where monthlyFrom() keeps the date it read, for the rest of the request. */
    private const MEMO = self::class.'.monthly_from';

    protected static function booted(): void
    {
        static::saved(fn () => app()->forgetInstance(self::MEMO));
        static::deleted(fn () => app()->forgetInstance(self::MEMO));
    }

    /**
     * The switch date, or null when this deployment has none. Read once per
     * request (the loans list asks for every row), and read again after the
     * row changes.
     */
    public static function monthlyFrom(): ?Carbon
    {
        if (! app()->bound(self::MEMO)) {
            $date = static::query()->value('monthly_from');
            app()->instance(self::MEMO, ['date' => $date === null ? null : Carbon::parse($date)->startOfDay()]);
        }

        return app(self::MEMO)['date']?->copy();
    }
}
