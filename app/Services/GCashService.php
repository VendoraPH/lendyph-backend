<?php

namespace App\Services;

use App\Models\GCashTier;
use App\Models\GCashTransaction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class GCashService
{
    public function createTransaction(array $payload, User $actor): GCashTransaction
    {
        $type = $payload['type'];
        $amount = round((float) $payload['amount'], 2);
        $isPending = (bool) ($payload['is_pending'] ?? false);

        return DB::transaction(function () use ($type, $amount, $payload, $actor, $isPending) {
            $tier = $this->resolveTier($amount);

            if ($duplicate = $this->detectDuplicate($payload['borrower_id'], $type, $amount)) {
                throw new HttpResponseException(response()->json([
                    'message' => 'Possible duplicate transaction within the last 60 seconds.',
                    'data' => ['existing_id' => $duplicate->id],
                ], 409));
            }

            $charge = (float) ($type === 'cash_in' ? $tier->cash_in_rate : $tier->cash_out_rate);
            $total = $type === 'cash_in'
                ? round($amount + $charge, 2)
                : round($amount - $charge, 2);

            $status = match (true) {
                $type === 'cash_out' => 'completed',
                $type === 'cash_in' && $isPending => 'pending',
                default => 'paid',
            };

            $now = now();

            $tx = GCashTransaction::create([
                'reference_no' => $this->generateReferenceNo($now),
                'transaction_date' => $now,
                'type' => $type,
                'amount' => $amount,
                'charge_amount' => $charge,
                'total_amount' => $total,
                'status' => $status,
                'borrower_id' => $payload['borrower_id'],
                'transactor_user_id' => $actor->id,
                'remarks' => $payload['remarks'] ?? null,
            ]);

            AuditLogService::log(
                action: 'created',
                auditable: $tx,
                newValues: $tx->toArray(),
                description: "GCash {$type} transaction {$tx->reference_no} created (status={$status})",
            );

            return $tx;
        });
    }

    public function markPaid(GCashTransaction $tx, User $actor): GCashTransaction
    {
        if ($tx->type !== 'cash_in' || $tx->status !== 'pending') {
            throw ValidationException::withMessages([
                'status' => ['Only pending Cash In transactions can be marked as paid.'],
            ]);
        }

        $old = ['status' => $tx->status];

        $tx->update([
            'status' => 'paid',
            'paid_at' => now(),
            'paid_by_user_id' => $actor->id,
        ]);

        AuditLogService::log(
            action: 'status_changed',
            auditable: $tx,
            oldValues: $old,
            newValues: [
                'status' => $tx->status,
                'paid_at' => $tx->paid_at?->toIso8601String(),
                'paid_by_user_id' => $tx->paid_by_user_id,
            ],
            description: "GCash {$tx->reference_no} marked as paid",
        );

        return $tx->fresh();
    }

    public function replaceTiers(array $tiers): Collection
    {
        $sorted = collect($tiers)->sortBy('display_order')->values();

        $sorted->each(function (array $tier, int $i) use ($sorted) {
            if ($tier['max_amount'] <= $tier['min_amount']) {
                throw ValidationException::withMessages([
                    'tiers' => ['Each tier must have max_amount > min_amount.'],
                ]);
            }

            if ($i > 0 && $sorted[$i - 1]['max_amount'] >= $tier['min_amount']) {
                throw ValidationException::withMessages([
                    'tiers' => ['Tier ranges must not overlap.'],
                ]);
            }
        });

        return DB::transaction(function () use ($sorted) {
            GCashTier::query()->delete();

            foreach ($sorted as $tier) {
                GCashTier::create([
                    'min_amount' => $tier['min_amount'],
                    'max_amount' => $tier['max_amount'],
                    'cash_in_rate' => $tier['cash_in_rate'],
                    'cash_out_rate' => $tier['cash_out_rate'],
                    'display_order' => $tier['display_order'],
                ]);
            }

            $fresh = GCashTier::orderBy('display_order')->get();

            AuditLogService::log(
                action: 'updated',
                auditable: null,
                newValues: ['tiers' => $fresh->toArray()],
                description: 'GCash tiers replaced ('.$fresh->count().' rows)',
            );

            return $fresh;
        });
    }

    public function incomeReport(string $startDate, string $endDate): array
    {
        $query = GCashTransaction::query()
            ->where('status', '!=', 'pending')
            ->whereBetween('transaction_date', [
                Carbon::parse($startDate)->startOfDay(),
                Carbon::parse($endDate)->endOfDay(),
            ]);

        $transactions = (clone $query)->orderBy('transaction_date')->get();
        $total = (float) (clone $query)->sum('charge_amount');

        return [
            'total_income' => round($total, 2),
            'start_date' => $startDate,
            'end_date' => $endDate,
            'transactions' => $transactions,
        ];
    }

    public function pendingReport(): Collection
    {
        return GCashTransaction::query()
            ->where('type', 'cash_in')
            ->where('status', 'pending')
            ->orderBy('transaction_date')
            ->get();
    }

    private function resolveTier(float $amount): GCashTier
    {
        $tier = GCashTier::query()
            ->where('min_amount', '<=', $amount)
            ->where('max_amount', '>=', $amount)
            ->first();

        if (! $tier) {
            throw ValidationException::withMessages([
                'amount' => ["No tier matches amount {$amount}. Check the configured tier ranges."],
            ]);
        }

        return $tier;
    }

    private function generateReferenceNo(Carbon $date): string
    {
        $datePart = $date->format('Ymd');
        $prefix = "GC-{$datePart}-";

        $last = GCashTransaction::query()
            ->where('reference_no', 'like', $prefix.'%')
            ->lockForUpdate()
            ->orderByDesc('reference_no')
            ->value('reference_no');

        $next = $last
            ? (int) substr($last, strlen($prefix)) + 1
            : 1;

        return $prefix.str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }

    private function detectDuplicate(int $borrowerId, string $type, float $amount): ?GCashTransaction
    {
        return GCashTransaction::query()
            ->where('borrower_id', $borrowerId)
            ->where('type', $type)
            ->where('amount', $amount)
            ->where('transaction_date', '>=', now()->subSeconds(60))
            ->orderByDesc('id')
            ->first();
    }
}
