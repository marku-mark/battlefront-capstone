<?php

namespace App\Http\Controllers\Administration;

use App\Http\Controllers\Controller;
use App\Repositories\Reporting\AuditLogRepository;
use App\Services\AuditLogPresenter;
use App\Services\Reporting\AuditLogExportService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use stdClass;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AuditLogController extends Controller
{
    public function index(Request $request, AuditLogPresenter $presenter, AuditLogRepository $logs): Response
    {
        $filters = $this->filters($request);

        return Inertia::render('Administration/Reports/AuditLog', [
            'filters' => $filters,
            'entries' => $logs->query($filters, $request->user()->can('products.cost'))->paginate(25)->withQueryString()
                ->through(fn (stdClass $entry): array => $presenter->present($entry, $request->user())),
            ...$logs->lookups(),
        ]);
    }

    public function export(Request $request, AuditLogExportService $export): StreamedResponse
    {
        $filters = $this->filters($request);

        return response()->streamDownload(fn () => $export->writeCsv($filters), 'audit-log.csv', ['Content-Type' => 'text/csv']);
    }

    /** @return array<string, mixed> */
    private function filters(Request $request): array
    {
        return $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'action' => ['nullable', 'string', 'max:100'],
            'actor_id' => ['nullable', 'integer', 'min:1'],
            'actor_type' => ['nullable', 'string', 'in:customer,administrator,system'],
            'subject_type' => ['nullable', 'string', 'max:100'],
            'subject_id' => ['nullable', 'integer', 'min:1'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
    }
}
