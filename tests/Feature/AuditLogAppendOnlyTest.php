<?php

use App\Exceptions\AuditLogIsAppendOnlyException;
use App\Models\AuditLog;
use App\Models\Role;
use App\Services\AuditLogService;
use Tests\TestCase;

uses(TestCase::class);

/*
 * Audit rows are append-only. The model refuses every instance-level update
 * and delete, quiet variants included, and writes nothing.
 */

beforeEach(function () {
    $this->row = AuditLogService::log('probe', null, null, ['a' => 1], 'Original description');
});

it('still creates audit rows', function () {
    expect(AuditLog::whereKey($this->row->id)->value('description'))->toBe('Original description');
});

it('refuses to update an audit row', function (Closure $write) {
    expect(fn () => $write($this->row))->toThrow(AuditLogIsAppendOnlyException::class);

    expect(AuditLog::findOrFail($this->row->id)->description)->toBe('Original description');
})->with([
    'update()' => [fn (AuditLog $row) => $row->update(['description' => 'Rewritten'])],
    'save()' => [function (AuditLog $row) {
        $row->description = 'Rewritten';
        $row->save();
    }],
    'saveQuietly()' => [function (AuditLog $row) {
        $row->description = 'Rewritten';
        $row->saveQuietly();
    }],
    'forceFill()->save()' => [fn (AuditLog $row) => $row->forceFill(['description' => 'Rewritten'])->save()],
    'increment()' => [fn (AuditLog $row) => $row->increment('auditable_id')],
]);

it('refuses to delete an audit row', function (Closure $write) {
    expect(fn () => $write($this->row))->toThrow(AuditLogIsAppendOnlyException::class);

    expect(AuditLog::whereKey($this->row->id)->exists())->toBeTrue();
})->with([
    'delete()' => [fn (AuditLog $row) => $row->delete()],
    'deleteQuietly()' => [fn (AuditLog $row) => $row->deleteQuietly()],
    'forceDelete()' => [fn (AuditLog $row) => $row->forceDelete()],
    'destroy()' => [fn (AuditLog $row) => AuditLog::destroy($row->id)],
]);

it('lets an unchanged save through, since it writes nothing', function () {
    expect($this->row->save())->toBeTrue();
});

it('refuses bulk writes on audit rows through the query builder', function (Closure $write) {
    $count = AuditLog::count();

    expect(fn () => $write($this->row))->toThrow(AuditLogIsAppendOnlyException::class);

    expect(AuditLog::count())->toBe($count)
        ->and(AuditLog::findOrFail($this->row->id)->description)->toBe('Original description');
})->with([
    'query()->update()' => [fn (AuditLog $row) => AuditLog::query()->update(['description' => 'Rewritten'])],
    'where()->update()' => [fn (AuditLog $row) => AuditLog::whereKey($row->id)->update(['description' => 'Rewritten'])],
    'query()->delete()' => [fn (AuditLog $row) => AuditLog::query()->delete()],
    'where()->forceDelete()' => [fn (AuditLog $row) => AuditLog::whereKey($row->id)->forceDelete()],
    'increment()' => [fn (AuditLog $row) => AuditLog::whereKey($row->id)->increment('auditable_id')],
    'decrement()' => [fn (AuditLog $row) => AuditLog::whereKey($row->id)->decrement('auditable_id')],
    'incrementEach()' => [fn (AuditLog $row) => AuditLog::whereKey($row->id)->incrementEach(['auditable_id' => 1])],
    'touch()' => [fn (AuditLog $row) => AuditLog::whereKey($row->id)->touch()],
    'upsert()' => [fn (AuditLog $row) => AuditLog::query()->upsert([['id' => $row->id, 'action' => 'probe', 'description' => 'Rewritten']], ['id'], ['description'])],
    'updateOrInsert()' => [fn (AuditLog $row) => AuditLog::query()->updateOrInsert(['id' => $row->id], ['description' => 'Rewritten'])],
    'updateOrCreate()' => [fn (AuditLog $row) => AuditLog::query()->updateOrCreate(['id' => $row->id], ['description' => 'Rewritten'])],
    'truncate()' => [fn (AuditLog $row) => AuditLog::truncate()],
]);

it('refuses bulk writes through a model\'s auditLogs() relation', function () {
    $role = Role::create(['name' => 'relation_probe', 'guard_name' => 'web', 'is_system' => false]);
    expect($role->auditLogs()->count())->toBeGreaterThan(0);

    expect(fn () => $role->auditLogs()->update(['description' => 'Rewritten']))->toThrow(AuditLogIsAppendOnlyException::class)
        ->and(fn () => $role->auditLogs()->delete())->toThrow(AuditLogIsAppendOnlyException::class);

    expect($role->auditLogs()->count())->toBeGreaterThan(0);
});
