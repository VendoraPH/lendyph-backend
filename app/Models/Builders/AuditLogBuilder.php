<?php

namespace App\Models\Builders;

use App\Exceptions\AuditLogIsAppendOnlyException;
use App\Models\AuditLog;
use Closure;
use Illuminate\Database\Eloquent\Builder;

/**
 * The query builder for audit_logs: inserts and reads only.
 *
 * AuditLog refuses update and delete on a single row; this refuses the bulk
 * forms, which never reach the model (`AuditLog::query()->update()`,
 * `$loan->auditLogs()->delete()`, `AuditLog::truncate()`, an upsert that would
 * overwrite a row). Each throws before any SQL runs.
 *
 * Raw `DB::table('audit_logs')` writes bypass Eloquent entirely; the only one
 * that updates is TimezoneShift, the one-shot UTC to Asia/Manila command that
 * has already run on every deployment.
 *
 * @extends Builder<AuditLog>
 */
class AuditLogBuilder extends Builder
{
    public function update(array $values): never
    {
        throw AuditLogIsAppendOnlyException::bulk('update');
    }

    public function upsert(array $values, $uniqueBy, $update = null): never
    {
        throw AuditLogIsAppendOnlyException::bulk('upsert');
    }

    public function touch($column = null): never
    {
        throw AuditLogIsAppendOnlyException::bulk('touch');
    }

    public function increment($column, $amount = 1, array $extra = []): never
    {
        throw AuditLogIsAppendOnlyException::bulk('increment');
    }

    public function decrement($column, $amount = 1, array $extra = []): never
    {
        throw AuditLogIsAppendOnlyException::bulk('decrement');
    }

    public function incrementEach(array $columns, array $extra = []): never
    {
        throw AuditLogIsAppendOnlyException::bulk('incrementEach');
    }

    public function decrementEach(array $columns, array $extra = []): never
    {
        throw AuditLogIsAppendOnlyException::bulk('decrementEach');
    }

    public function updateOrCreate(array $attributes, Closure|array $values = []): never
    {
        throw AuditLogIsAppendOnlyException::bulk('updateOrCreate');
    }

    public function updateOrInsert(array $attributes, array|callable $values = []): never
    {
        throw AuditLogIsAppendOnlyException::bulk('updateOrInsert');
    }

    public function updateFrom(array $values): never
    {
        throw AuditLogIsAppendOnlyException::bulk('updateFrom');
    }

    public function delete(): never
    {
        throw AuditLogIsAppendOnlyException::bulk('delete');
    }

    public function forceDelete(): never
    {
        throw AuditLogIsAppendOnlyException::bulk('forceDelete');
    }

    public function truncate(): never
    {
        throw AuditLogIsAppendOnlyException::bulk('truncate');
    }
}
