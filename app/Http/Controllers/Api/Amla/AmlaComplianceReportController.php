<?php

namespace App\Http\Controllers\Api\Amla;

use App\Http\Controllers\Controller;
use App\Models\AmlaExport;
use App\Services\Amla\Foundation\AmlaAccess;
use App\Services\Amla\Foundation\AmlaLog;
use App\Services\Amla\Reports\AmlaReports;
use App\Services\Amla\Reports\ComplianceReport;
use App\Services\Amla\Reports\DashboardCounts;
use App\Services\Amla\Reports\ReportExports;
use App\Services\Amla\Reports\ReportFilters;
use App\Services\BranchAccess;
use App\Services\SignedFileLink;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The AMLA reports, their exports and the dashboard counts (AMLA-B7; task
 * §16). Any AMLA key opens the catalog; each report then needs its own keys
 * (the confidential ones head-office access too), checked before anything is
 * built. Every view, export and download is in the AMLA log. A download is
 * a signed link re-checked against the user it was made for.
 */
class AmlaComplianceReportController extends Controller
{
    public function __construct(
        private readonly AmlaAccess $access,
        private readonly AmlaReports $reports,
        private readonly AmlaLog $log,
        private readonly BranchAccess $branches,
    ) {}

    #[OA\Get(path: '/api/amla/compliance-reports', summary: 'AMLA reports the caller may open', description: 'Needs any AMLA key. The ten reports, only those whose keys the caller holds.', tags: ['AMLA'], security: [['sanctum' => []]], responses: [new OA\Response(response: 200, description: 'The catalog')])]
    public function index(Request $request): JsonResponse
    {
        $this->access->authorize($request->user(), AmlaAccess::PERMISSIONS);

        return response()->json(['success' => true, 'data' => array_map(fn (ComplianceReport $report) => [
            'key' => $report->key(), 'title' => $report->title(), 'description' => $report->description(),
            'uses_period' => $report->usesPeriod(), 'head_office' => $report->headOffice(),
        ], $this->reports->visibleTo($request->user()))]);
    }

    #[OA\Get(path: '/api/amla/compliance-reports/{report}', summary: 'One AMLA report', description: 'Needs the report\'s keys. `from`, `to` (YYYY-MM-DD; this month by default), `branch_id`. Rows and totals as displayed (₱ with two decimals). Logged.', tags: ['AMLA'], security: [['sanctum' => []]], responses: [new OA\Response(response: 200, description: 'The report')])]
    public function show(Request $request, string $report): JsonResponse
    {
        [$found, $filters] = $this->open($request, $report);
        $table = $this->reports->build($found, $request->user(), $filters);
        $this->log->record('amla_report_viewed', $request->user(), details: ['report' => $found->key(), 'rows' => count($table->rows), ...$filters->toArray()]);

        return response()->json(['success' => true, 'data' => [
            'key' => $found->key(),
            'title' => $found->title(),
            'period' => $table->periodLabel(),
            'draft' => $table->isDraft(),
            'columns' => $table->columns,
            'rows' => $table->displayRows(),
            'totals' => $table->displayTotals(),
        ]]);
    }

    #[OA\Post(path: '/api/amla/compliance-reports/{report}/exports', summary: 'Export an AMLA report', description: 'Needs the report\'s keys. `format` (pdf, xlsx, csv) and the report\'s filters. Made on the server, DRAFT while its period is open, logged; answers a signed download link bound to the caller.', tags: ['AMLA'], security: [['sanctum' => []]], responses: [new OA\Response(response: 201, description: 'The export')])]
    public function export(Request $request, string $report, ReportExports $exports): JsonResponse
    {
        [$found, $filters] = $this->open($request, $report, ['format' => ['required', 'in:pdf,xlsx,csv']]);
        $export = $exports->make($request->user(), $found, $filters, (string) $request->input('format'));

        return response()->json(['success' => true, 'data' => [
            'id' => (int) $export->id, 'format' => $export->format, 'is_draft' => (bool) $export->is_draft, 'rows' => (int) $export->row_count,
            'download_url' => $exports->link($export),
        ]], 201);
    }

    #[OA\Get(path: '/api/amla/exports/{id}/download', summary: 'Download an AMLA report export', description: 'Signed, and re-checked: only the user it was made for, whose credentials haven\'t changed and who still holds the report\'s keys (403, logged, otherwise). Logged.', tags: ['AMLA'], security: [['sanctum' => []]], responses: [new OA\Response(response: 200, description: 'The file')])]
    public function download(Request $request, int $id, ReportExports $exports): StreamedResponse
    {
        $user = $request->user();
        $this->access->authorize($user, AmlaAccess::PERMISSIONS);
        $export = AmlaExport::query()->whereKey($id)->first() ?? abort(404);
        $report = $this->reports->find($export->report_key) ?? abort(404);

        if (! SignedFileLink::bindingIsStillValid($request) || (int) $request->query('u') !== $user->id || $export->user_id !== $user->id) {
            $this->access->refuse($user, 'export_link_not_yours', 'This download link is no longer valid for you. Export the report again.');
        }

        if (! $report->canSee($user)) {
            $this->access->refuse($user, 'export_report_not_allowed', 'This report needs '.AmlaAccess::words($report->permissions()).($report->headOffice() ? ' and head-office access' : '').'.');
        }

        abort_unless(Storage::disk(ReportExports::DISK)->exists($export->file_path), 404);
        $this->log->record('amla_report_export_downloaded', $user, 'amla_export', $export->id, ['report' => $export->report_key, 'format' => $export->format]);

        return Storage::disk(ReportExports::DISK)->download($export->file_path, $exports->downloadName($export), [
            'Content-Type' => AmlaExport::FORMATS[$export->format],
            'Cache-Control' => 'private, max-age=0, no-store',
        ]);
    }

    #[OA\Get(path: '/api/amla/dashboard/counts', summary: 'AMLA dashboard counts', description: 'Needs any AMLA key; each block needs its own key (null otherwise) and follows branch scope: alerts by priority, CTR candidates, cases, filings due, the CDD worklist by status. Logged.', tags: ['AMLA'], security: [['sanctum' => []]], responses: [new OA\Response(response: 200, description: 'The counts')])]
    public function counts(Request $request, DashboardCounts $counts): JsonResponse
    {
        $this->access->authorize($request->user(), AmlaAccess::PERMISSIONS);
        $this->log->record('amla_dashboard_viewed', $request->user());

        return response()->json(['success' => true, 'data' => $counts->for($request->user())]);
    }

    /**
     * Any AMLA key, then the report (404 when unknown), then its own keys,
     * then the filters.
     *
     * @param  array<string, list<string>>  $extra
     * @return array{0: ComplianceReport, 1: ReportFilters}
     */
    private function open(Request $request, string $key, array $extra = []): array
    {
        $user = $request->user();
        $this->access->authorize($user, AmlaAccess::PERMISSIONS);
        $report = $this->reports->find($key) ?? abort(404);

        if ($report->headOffice()) {
            $this->access->authorizeHeadOffice($user, $report->permissions());
        } else {
            $this->access->authorize($user, $report->permissions());
        }

        $input = $request->validate([
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:from'],
            'branch_id' => ['sometimes', 'integer', 'min:1'],
            ...$extra,
        ], ['format.in' => 'Pick PDF, Excel or CSV.', 'to.after_or_equal' => 'The end date can\'t be before the start date.']);
        $this->branches->authorizeBranchFilter($user, $input['branch_id'] ?? null);

        return [$report, ReportFilters::from($input)];
    }
}
