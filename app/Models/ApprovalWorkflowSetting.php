<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ApprovalWorkflowSetting extends Model
{
    protected $fillable = [
        'type',
        'steps',
    ];

    protected function casts(): array
    {
        return [
            'steps' => 'array',
        ];
    }

    public const TYPE_NORMAL = 'normal';

    public const TYPE_POLICY_EXCEPTION = 'policy_exception';

    public const DEFAULT_NORMAL_STEPS = [
        ['id' => 'loan-processor', 'name' => 'Loan Processor', 'role' => 'loan_processor', 'kind' => 'submit'],
        ['id' => 'manager', 'name' => 'Manager', 'role' => 'manager', 'kind' => 'approve'],
        ['id' => 'chairwoman', 'name' => 'BOD Chairwoman', 'role' => 'bod1', 'kind' => 'approve'],
        ['id' => 'general-bookkeeper', 'name' => 'General Bookkeeper', 'role' => 'general_bookkeeper', 'kind' => 'release'],
    ];

    public const DEFAULT_POLICY_EXCEPTION_STEPS = [
        ['id' => 'loan-processor', 'name' => 'Loan Processor', 'role' => 'loan_processor', 'kind' => 'submit'],
        ['id' => 'manager', 'name' => 'Manager', 'role' => 'manager', 'kind' => 'approve'],
        ['id' => 'bod1', 'name' => 'BOD1', 'role' => 'bod1', 'kind' => 'approve'],
        ['id' => 'bod2', 'name' => 'BOD2', 'role' => 'bod2', 'kind' => 'approve'],
        ['id' => 'bod3', 'name' => 'BOD3', 'role' => 'bod3', 'kind' => 'approve'],
        ['id' => 'bod4', 'name' => 'BOD4', 'role' => 'bod4', 'kind' => 'approve'],
        ['id' => 'bod5', 'name' => 'BOD5', 'role' => 'bod5', 'kind' => 'approve'],
        ['id' => 'bod6', 'name' => 'BOD6', 'role' => 'bod6', 'kind' => 'approve'],
        ['id' => 'bod7', 'name' => 'BOD7', 'role' => 'bod7', 'kind' => 'approve'],
        ['id' => 'cashier', 'name' => 'Cashier', 'role' => 'cashier', 'kind' => 'release'],
    ];

    public static function defaultStepsFor(string $type): array
    {
        return $type === self::TYPE_POLICY_EXCEPTION
            ? self::DEFAULT_POLICY_EXCEPTION_STEPS
            : self::DEFAULT_NORMAL_STEPS;
    }
}
