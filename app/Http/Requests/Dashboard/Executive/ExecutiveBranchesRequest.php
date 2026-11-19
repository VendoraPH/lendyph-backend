<?php

namespace App\Http\Requests\Dashboard\Executive;

/**
 * GET /dashboard/executive/branches: the executive dashboard plus the reports
 * its rows come from. The books' columns also need `accounting:view`,
 * applied by the service.
 */
class ExecutiveBranchesRequest extends ExecutiveDashboardRequest
{
    public const REFUSAL = 'The branch figures need access to the executive dashboard and to reports.';

    public function authorize(): bool
    {
        return parent::authorize() && $this->user()->can('reports:view');
    }
}
