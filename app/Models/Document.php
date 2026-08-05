<?php

namespace App\Models;

use App\Http\Controllers\Api\FileController;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\URL;

class Document extends Model
{
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
        return Attribute::get(fn () => URL::temporarySignedRoute(
            'files.document',
            now()->addMinutes(FileController::LINK_TTL_MINUTES),
            ['document' => $this->getKey()],
        ));
    }
}
