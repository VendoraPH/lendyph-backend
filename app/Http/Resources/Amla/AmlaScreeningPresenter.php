<?php

namespace App\Http\Resources\Amla;

use App\Models\AmlaOrder;
use App\Models\AmlaOrderHold;
use App\Models\AmlaScreeningMatch;
use App\Models\AmlaWatchlistEntry;
use App\Models\AmlaWatchlistVersion;
use App\Models\Borrower;
use App\Models\CoMaker;
use App\Models\GCashNonMember;
use App\Models\SavingsHold;

/**
 * How the AMLA screening and order endpoints present their rows (AMLA-B5):
 * statuses, sources, kinds and authorities in words beside their keys, money
 * as two-decimal strings, users as `{id, name}`. A subject reads by its type,
 * name and code only.
 */
final class AmlaScreeningPresenter
{
    /** @var array<string, string> */
    public const SUBJECT_TYPES = ['member' => 'Member', 'walk_in' => 'Walk-in', 'co_maker' => 'Co-maker'];

    /** @return array<string, mixed> */
    public static function version(AmlaWatchlistVersion $version): array
    {
        return [
            'id' => (int) $version->id,
            'source' => $version->source,
            'source_words' => AmlaWatchlistVersion::SOURCES[$version->source]['words'] ?? $version->source,
            'version_label' => $version->version_label,
            'entry_count' => $version->entry_count,
            'status' => $version->status,
            'status_words' => $version->status === AmlaWatchlistVersion::ACTIVE ? 'In force' : 'Superseded',
            'rescreen_pending' => $version->rescreen_status === AmlaWatchlistVersion::RESCREEN_PENDING,
            'rescreened_at' => $version->rescreened_at,
            'uploaded_by' => AmlaPresenter::user($version->uploader),
            'uploaded_at' => $version->created_at,
            'superseded_at' => $version->superseded_at,
        ];
    }

    /** @return array<string, mixed> */
    public static function entry(AmlaWatchlistEntry $entry, string $source, bool $full): array
    {
        $data = [
            'id' => (int) $entry->id,
            'source' => $source,
            'source_words' => AmlaWatchlistVersion::SOURCES[$source]['words'] ?? $source,
            'reference' => $entry->reference,
            'kind' => $entry->kind,
            'kind_words' => AmlaWatchlistEntry::KINDS[$entry->kind] ?? $entry->kind,
            'full_name' => $entry->full_name,
        ];

        if ($full) {
            $data['aliases'] = $entry->aliases;
            $data['birth_years'] = $entry->birth_years;
            $data['nationalities'] = $entry->nationalities;
        }

        return $data;
    }

    /** @return array{type: string, type_words: string, id: int, name: string|null, code: string|null} */
    public static function subject(string $type, int $id): array
    {
        $record = match ($type) {
            'member' => Borrower::query()->find($id),
            'walk_in' => GCashNonMember::query()->withTrashed()->find($id),
            'co_maker' => CoMaker::query()->find($id),
            default => null,
        };

        $name = match (true) {
            $record instanceof GCashNonMember => (string) $record->full_name,
            $record instanceof Borrower, $record instanceof CoMaker => trim(implode(' ', array_filter([$record->first_name, $record->middle_name, $record->last_name, $record->suffix]))),
            default => null,
        };

        return [
            'type' => $type,
            'type_words' => self::SUBJECT_TYPES[$type] ?? $type,
            'id' => $id,
            'name' => $name,
            'code' => match (true) {
                $record instanceof Borrower => $record->borrower_code,
                $record instanceof CoMaker => $record->co_maker_code,
                default => null,
            },
        ];
    }

    /** @return array<string, mixed> */
    public static function match(AmlaScreeningMatch $match, bool $full): array
    {
        $data = [
            'id' => (int) $match->id,
            'status' => $match->status,
            'status_words' => AmlaScreeningMatch::STATUSES[$match->status] ?? $match->status,
            'score' => $match->score,
            'threshold' => $match->threshold,
            'subject' => self::subject($match->subject_type, $match->subject_id),
            'entry' => self::entry($match->entry, $match->source, $full),
            'found_at' => $match->created_at,
        ];

        if ($full) {
            $data['reasons'] = $match->reasons;
            $data['version'] = ['id' => (int) $match->version_id, 'label' => $match->version?->version_label];
            $data['decided_by'] = AmlaPresenter::user($match->decider);
            $data['decided_at'] = $match->decided_at;
            $data['decision_reason'] = $match->decision_reason;
        }

        return $data;
    }

    /** @return array<string, mixed> */
    public static function order(AmlaOrder $order, bool $full): array
    {
        $member = $order->borrower;
        $data = [
            'id' => (int) $order->id,
            'reference' => $order->reference,
            'authority' => $order->authority,
            'authority_words' => AmlaOrder::AUTHORITIES[$order->authority] ?? $order->authority,
            'received_on' => $order->received_on->toDateString(),
            'return_due_on' => $order->return_due_on?->toDateString(),
            'status' => $order->status,
            'status_words' => AmlaOrder::STATUSES[$order->status] ?? $order->status,
            'member' => $member === null ? null : ['id' => (int) $member->id, 'name' => (string) $member->full_name, 'code' => $member->borrower_code],
            'holds' => $order->holds()->with('account', 'hold')->orderBy('id')->get()->map(fn (AmlaOrderHold $link) => [
                'savings_hold_id' => (int) $link->savings_hold_id,
                'account_id' => (int) $link->account_id,
                'account_number' => $link->account?->account_number,
                'amount' => (string) $link->amount,
                'status' => $link->hold?->status,
                'status_words' => $link->hold === null ? null : ucfirst(SavingsHold::STATUS_WORDS[$link->hold->status] ?? $link->hold->status),
            ])->values()->all(),
            'placed_by' => AmlaPresenter::user($order->placer),
            'placed_at' => $order->created_at,
        ];

        if ($full) {
            $data['required_action'] = $order->required_action;
            $data['details'] = $order->details;
            $data['responsible_officer'] = AmlaPresenter::user($order->responsibleOfficer);
            $data['released_by'] = AmlaPresenter::user($order->releaser);
            $data['released_at'] = $order->released_at;
            $data['release_reason'] = $order->release_reason;
            $data['release_reference'] = $order->release_reference;
        }

        return $data;
    }
}
