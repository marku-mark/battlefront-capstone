<?php

namespace App\Http\Controllers\Administration;

use App\Http\Controllers\Controller;
use App\Services\AdministrationEvidenceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdministrationEvidenceController extends Controller
{
    public function store(Request $request, string $subjectType, int $subjectId, AdministrationEvidenceService $evidence): RedirectResponse
    {
        $validated = $request->validate([
            'files' => ['required', 'array', 'min:1', 'max:5'],
            'files.*' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'extensions:jpg,jpeg,png,webp,pdf', 'max:5120'],
        ]);
        $evidence->store($request->user(), $subjectType, $subjectId, $validated['files']);

        return back();
    }

    public function show(Request $request, int $evidence, AdministrationEvidenceService $service): StreamedResponse
    {
        $record = DB::table('administration_evidence')->where('id', $evidence)->first();
        if ($record === null) {
            abort(404);
        }
        $service->authorize($request->user(), $record->subject_type, (int) $record->subject_id, false);
        $disk = Storage::disk(AdministrationEvidenceService::diskNameForPath($record->path));
        if (! $disk->exists($record->path)) {
            abort(404);
        }

        if (in_array($record->mime_type, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            return $disk->response($record->path, $record->original_name, [
                'Content-Type' => $record->mime_type,
                'Cache-Control' => 'private, no-store',
                'X-Content-Type-Options' => 'nosniff',
            ]);
        }

        return $disk->download($record->path, $record->original_name);
    }
}
