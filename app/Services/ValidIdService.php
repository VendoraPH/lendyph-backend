<?php

namespace App\Services;

use App\Models\Borrower;
use App\Models\CoMaker;
use App\Models\Document;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Valid IDs, for everyone who holds one: borrowers and co-makers.
 *
 * A valid ID has no table of its own. It is a Document on the owner's
 * `documents()` morph with `type = valid_id`, the kind of ID in `label` (or
 * `others` plus `custom_type_name`), the number in `id_number`, and one row per
 * side — `front` and `back` for the paired upload, no side for the legacy
 * single file. The file is on the PRIVATE disk and is only reachable through
 * the expiring signed link Document::url mints; see FileController.
 *
 * Borrowers had this first. Co-makers were given the same model rather than
 * columns of their own, so there is one implementation and it is this one:
 * each controller action decides who may call it — a borrower upload may also
 * arrive on a public-registration submission token, a co-maker upload never
 * can — and then delegates here, through RespondsWithValidIds. What the two URL
 * families accept and answer is therefore the same by construction, which the
 * frontend relies on: it drives co-maker IDs with its borrower client code,
 * changing only the URL.
 *
 * Nothing here branches on the kind of owner except directoryFor().
 */
class ValidIdService
{
    /**
     * The `documents.type` every valid ID is stored under.
     */
    public const DOCUMENT_TYPE = 'valid_id';

    /**
     * The private-disk directory an owner's valid-ID files are written to.
     *
     * One directory per owner, so deleting the owner can remove it whole — see
     * BorrowerPurgeService and CoMakerController::destroy(). The `borrower`
     * segment is part of every stored borrower path on every deployment, so it
     * can never be renamed.
     */
    public static function directoryFor(Borrower|CoMaker $owner): string
    {
        $segment = match (true) {
            $owner instanceof Borrower => 'borrower',
            $owner instanceof CoMaker => 'co_maker',
        };

        return "documents/valid_id/{$segment}/{$owner->getKey()}";
    }

    /**
     * Validate a valid-ID upload and store it against $owner.
     *
     * Two request shapes, mutually exclusive: the legacy single `file`, stored
     * as one document with no side, or `front_file` with an optional
     * `back_file`, stored as one document per side. The rows are written in one
     * transaction, and if it fails the files already written are deleted again
     * — the filesystem has no rollback of its own.
     *
     * @return array{legacy: bool, documents: list<Document>}
     *
     * @throws ValidationException
     */
    public function store(Request $request, Borrower|CoMaker $owner): array
    {
        // Mutual exclusion: legacy single-file shape vs new front/back shape
        if ($request->hasFile('file') && $request->hasFile('front_file')) {
            throw ValidationException::withMessages([
                'file' => 'Use either `file` (legacy) or `front_file`/`back_file`, not both.',
            ]);
        }

        $request->validate([
            'type' => ['required', 'string', 'max:100'],
            'custom_type_name' => ['nullable', 'string', 'max:100', 'required_if:type,others'],
            'id_number' => ['nullable', 'string', 'max:100'],
            'file' => ['required_without:front_file', 'file', 'max:10240', 'mimes:jpg,jpeg,png,pdf'],
            'front_file' => ['required_without:file', 'file', 'max:10240', 'mimes:jpg,jpeg,png,pdf'],
            'back_file' => ['nullable', 'file', 'max:10240', 'mimes:jpg,jpeg,png,pdf'],
        ]);

        $type = $request->input('type');
        $customTypeName = $request->input('custom_type_name');
        $idNumber = $request->input('id_number');
        $isLegacy = $request->hasFile('file');
        $directory = self::directoryFor($owner);
        $storedPaths = [];
        $documents = [];

        try {
            DB::transaction(function () use ($owner, $request, $directory, $type, $customTypeName, $idNumber, $isLegacy, &$storedPaths, &$documents) {
                if ($isLegacy) {
                    $documents[] = $this->storeFile($owner, $directory, $request->file('file'), $type, $customTypeName, $idNumber, null, $storedPaths);
                } else {
                    $documents[] = $this->storeFile($owner, $directory, $request->file('front_file'), $type, $customTypeName, $idNumber, 'front', $storedPaths);
                    if ($request->hasFile('back_file')) {
                        $documents[] = $this->storeFile($owner, $directory, $request->file('back_file'), $type, $customTypeName, $idNumber, 'back', $storedPaths);
                    }
                }
            });
        } catch (\Throwable $e) {
            // Roll back any files written to disk before the DB failure
            foreach ($storedPaths as $path) {
                Storage::disk('private')->delete($path);
            }
            throw $e;
        }

        return ['legacy' => $isLegacy, 'documents' => $documents];
    }

    /**
     * The owner's valid IDs, one entry per ID rather than one per file.
     *
     * Documents sharing (label, id_number) are one ID, so a front and a back
     * pair up. An entry's `id` is its front document's id, which is the value
     * delete() takes.
     *
     * @return Collection<int, array{id: int, type: string|null, custom_type_name: string|null, id_number: string|null, front_url: string, back_url: string|null, created_at: Carbon|null}>
     */
    public function grouped(Borrower|CoMaker $owner): Collection
    {
        $documents = $owner->documents()
            ->where('type', self::DOCUMENT_TYPE)
            ->orderBy('id')
            ->get();

        $groups = $documents->groupBy(fn (Document $doc) => $doc->label.'|'.($doc->id_number ?? ''));

        return $groups->map(function ($group) {
            $front = $group->firstWhere('side', 'front')
                ?? $group->firstWhere('side', null)
                ?? $group->first();
            $back = $group->firstWhere('side', 'back');

            return [
                'id' => $front->id,
                'type' => $front->label,
                'custom_type_name' => $front->custom_type_name,
                'id_number' => $front->id_number,
                'front_url' => $front->url,
                'back_url' => $back?->url,
                'created_at' => $front->created_at,
            ];
        })->values();
    }

    /**
     * Delete one of the owner's valid IDs: the document named, and every
     * document sharing its (label, id_number) — i.e. its other side.
     *
     * Only ever the owner's own valid IDs. The lookup goes through the owner's
     * `documents()` morph, so it is scoped by the morph TYPE as well as the id
     * — borrower and co-maker ids are separate sequences and routinely collide
     * — and by `type = valid_id`. Anything else is a 404 indistinguishable
     * from an id that does not exist: another owner's ID, a co-maker's ID asked
     * for under its borrower's URL or the reverse, or an ordinary document of
     * the same owner.
     *
     * @throws ModelNotFoundException<Document>
     */
    public function delete(Borrower|CoMaker $owner, int $validIdId): void
    {
        $anchor = $owner->documents()
            ->where('type', self::DOCUMENT_TYPE)
            ->whereKey($validIdId)
            ->firstOrFail();

        $pair = $owner->documents()
            ->where('type', self::DOCUMENT_TYPE)
            ->where('label', $anchor->label)
            ->where(function ($q) use ($anchor) {
                if ($anchor->id_number === null) {
                    $q->whereNull('id_number');
                } else {
                    $q->where('id_number', $anchor->id_number);
                }
            })
            ->get();

        DB::transaction(function () use ($pair) {
            foreach ($pair as $doc) {
                Storage::disk('private')->delete($doc->file_path);
                $doc->delete();
            }
        });
    }

    /**
     * @param  list<string>  $storedPaths  every path written so far, removed again if the transaction fails
     */
    private function storeFile(Borrower|CoMaker $owner, string $directory, UploadedFile $file, string $type, ?string $customTypeName, ?string $idNumber, ?string $side, array &$storedPaths): Document
    {
        $path = $file->store($directory, 'private');
        $storedPaths[] = $path;

        return $owner->documents()->create([
            'type' => self::DOCUMENT_TYPE,
            'label' => $type,
            'custom_type_name' => $customTypeName,
            'id_number' => $idNumber,
            'side' => $side,
            'file_path' => $path,
            'original_filename' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'file_size' => $file->getSize(),
        ]);
    }
}
