<?php

namespace App\Services\Amla\Screening;

use App\Models\AmlaWatchlistEntry;
use App\Models\AmlaWatchlistVersion;
use App\Models\User;
use App\Services\Amla\Foundation\AmlaLog;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Keeps uploaded watchlists (AMLA design §4.11). An upload is read
 * (WatchlistParser), kept as the list's new version with its entries, and
 * supersedes the version in force, all in one transaction under a lock on
 * that version. The new version waits to be rescreened against everyone; the
 * every-minute screening does it while monitoring is on. The file itself is
 * not stored: only its SHA-256 and what was read from it.
 */
final class Watchlists
{
    private const INSERT_CHUNK = 500;

    public function __construct(
        private readonly WatchlistParser $parser,
        private readonly AmlaLog $log,
    ) {}

    /** @throws ValidationException 422 in words */
    public function upload(User $user, string $source, UploadedFile $file, ?string $label): AmlaWatchlistVersion
    {
        $contents = (string) file_get_contents($file->getRealPath());
        $parsed = AmlaWatchlistVersion::SOURCES[$source]['format'] === 'xml' ? $this->parser->un($contents) : $this->parser->csv($contents);
        $sha256 = hash('sha256', $contents);

        return DB::transaction(function () use ($user, $source, $parsed, $sha256, $label) {
            $current = AmlaWatchlistVersion::query()->where('source', $source)->where('status', AmlaWatchlistVersion::ACTIVE)->lockForUpdate()->first();

            if ($current !== null && $current->file_sha256 === $sha256) {
                throw ValidationException::withMessages(['file' => 'This file is the list already in force.']);
            }

            $current?->forceFill(['status' => AmlaWatchlistVersion::SUPERSEDED, 'superseded_at' => now()])->save();

            $version = AmlaWatchlistVersion::query()->create([
                'source' => $source,
                'version_label' => self::label($label, $parsed['label']),
                'file_sha256' => $sha256,
                'entry_count' => count($parsed['entries']),
                'status' => AmlaWatchlistVersion::ACTIVE,
                'rescreen_status' => AmlaWatchlistVersion::RESCREEN_PENDING,
                'uploaded_by' => $user->id,
            ]);

            $now = now();

            foreach (array_chunk($parsed['entries'], self::INSERT_CHUNK) as $chunk) {
                AmlaWatchlistEntry::query()->insert(array_map(fn (array $entry) => [
                    'version_id' => $version->id,
                    'reference' => $entry['reference'],
                    'kind' => $entry['kind'],
                    'full_name' => $entry['full_name'],
                    'aliases' => json_encode($entry['aliases']),
                    'birth_years' => json_encode($entry['birth_years']),
                    'nationalities' => json_encode($entry['nationalities']),
                    'fingerprint' => self::fingerprint($source, $entry),
                    'created_at' => $now,
                ], $chunk));
            }

            $this->log->record('watchlist_uploaded', $user, 'watchlist_version', $version->id, ['source' => $source]);

            return $version;
        });
    }

    /**
     * The label typed on upload, else the one in the file (the UN list's
     * date), else none: screens read "Version #n" beside the upload time, so
     * no "Uploaded <time>" label is made up.
     */
    private static function label(?string $typed, ?string $fromFile): ?string
    {
        $label = trim((string) ($typed ?: $fromFile));

        return $label === '' ? null : mb_substr($label, 0, 150);
    }

    /**
     * The same listing across versions: its list, reference, kind, names and
     * birth years, as compared. A listing whose data changed gets a new one.
     *
     * @param  array{reference: string, kind: string, full_name: string, aliases: list<string>, birth_years: list<int>}  $entry
     */
    public static function fingerprint(string $source, array $entry): string
    {
        $names = array_map(fn (string $name) => NameNormaliser::normalise($name), [$entry['full_name'], ...$entry['aliases']]);
        sort($names);
        $years = $entry['birth_years'];
        sort($years);

        return hash('sha256', json_encode([$source, $entry['reference'], $entry['kind'], array_values(array_unique($names)), $years]));
    }
}
