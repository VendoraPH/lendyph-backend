<?php

namespace App\Models;

use App\Traits\AmlaKeptForever;
use Illuminate\Database\Eloquent\Model;

/**
 * A CTR/STR reporting format version (task §14). Version `internal_v1` is an
 * internal record filed by hand on the AMLC portal: the GoTRACS file adapter
 * waits for the verified specification, so `file_available` is false and no
 * prescribed file is ever generated. Kept forever.
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property bool $file_available
 * @property bool $to_verify
 * @property string $notes
 */
class AmlaReportFormat extends Model
{
    use AmlaKeptForever;

    public const UPDATED_AT = null;

    public const INTERNAL_V1 = 'internal_v1';

    protected $fillable = ['code', 'name', 'file_available', 'to_verify', 'notes'];

    protected function casts(): array
    {
        return ['file_available' => 'boolean', 'to_verify' => 'boolean', 'created_at' => 'datetime'];
    }
}
