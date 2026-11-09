<?php

namespace App\Models;

use App\Services\SignedFileLink;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
        'uploaded_by',
    ];

    public function documentable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * The staff user who uploaded the file. Set on accounting attachments
     * only; member documents leave it null.
     *
     * @return BelongsTo<User, $this>
     */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
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

    /**
     * Every `{document}` route parameter resolves only to a document whose
     * owner the signed-in user may see: a borrower or loan in their branches,
     * or a co-maker whose borrower is. Another branch's document answers 404.
     *
     * Without a signed-in user the binding is unscoped, which is what keeps
     * the signed `/files/documents/{document}` links working: the signature
     * is their authorisation.
     */
    public function resolveRouteBindingQuery($query, $value, $field = null)
    {
        $query = parent::resolveRouteBindingQuery($query, $value, $field);
        $user = request()->user();

        if (! $user instanceof User) {
            return $query;
        }

        return $query->whereHasMorph(
            'documentable',
            [Borrower::class, CoMaker::class, Loan::class],
            fn ($owner, string $type) => match ($type) {
                CoMaker::class => $owner->whereHas('borrower', fn ($q) => $q->visibleTo($user)),
                default => $owner->visibleTo($user),
            },
        );
    }
}
