<?php

namespace App\Models;

use App\Traits\AmlaKeptForever;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Evidence on an AMLA case (AMLA design §4.14): a file on the private disk
 * under a random name, attached to the case, never to the member. Its
 * original name and description can hold personal data, so both are
 * encrypted and neither reaches the AMLA log. Kept as written.
 *
 * @property int $id
 * @property int $case_id
 * @property string $file_path
 * @property string $original_name
 * @property string|null $description
 * @property string|null $mime_type
 * @property int $file_size
 * @property string $sha256
 * @property int $uploaded_by
 */
class AmlaCaseEvidence extends Model
{
    use AmlaKeptForever;

    public const UPDATED_AT = null;

    protected $table = 'amla_case_evidence';

    protected $fillable = ['case_id', 'file_path', 'original_name', 'description', 'mime_type', 'file_size', 'sha256', 'uploaded_by'];

    protected function casts(): array
    {
        return [
            'case_id' => 'integer',
            'original_name' => 'encrypted',
            'description' => 'encrypted',
            'file_size' => 'integer',
            'uploaded_by' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
