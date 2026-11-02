<?php

namespace App\Models;

use App\Services\SignedFileLink;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Document extends Model
{
    /**
     * The longest original filename kept (the column's length).
     */
    public const ORIGINAL_FILENAME_MAX_LENGTH = 255;

    /**
     * An uploaded file's client-supplied name as it may be stored: valid UTF-8
     * and within the column. A multipart filename can carry any bytes, and a
     * raw one made the insert fail with a 500.
     */
    public static function cleanOriginalFilename(string $name): string
    {
        return mb_substr(mb_scrub($name, 'UTF-8'), 0, self::ORIGINAL_FILENAME_MAX_LENGTH, 'UTF-8');
    }

    protected $fillable = [
        'documentable_type',
        'documentable_id',
        'type',
        'label',
        'custom_type_name',
        'id_number',
        'side',
        'file_path',
        'original_filename',
        'mime_type',
        'file_size',
    ];

    public function documentable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * A temporary signed link to the file.
     *
     * The file itself is on the private disk and is not web-reachable, so this
     * link is the only way in. It expires — a URL that leaks (browser history,
     * a forwarded screenshot, a proxy log) stops working shortly after.
     */
    protected function url(): Attribute
    {
        return Attribute::get(fn () => SignedFileLink::to(
            'files.document',
            ['document' => $this->getKey()],
        ));
    }
}
