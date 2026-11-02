<?php

use App\Exceptions\AuditLogIsAppendOnlyException;
use App\Models\AuditLog;
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
