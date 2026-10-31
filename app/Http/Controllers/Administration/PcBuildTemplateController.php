<?php

namespace App\Http\Controllers\Administration;

use App\Http\Controllers\Controller;
use App\Http\Requests\Administration\SavePcBuildTemplateRequest;
use App\Models\PcBuildTemplate;
use App\Services\PcBuilder\PcBuildTemplateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PcBuildTemplateController extends Controller
{
    public function index(PcBuildTemplateService $service): Response
    {
        $products = $service->administrationProducts();

        return Inertia::render('Administration/PcBuildTemplates/Index', [
            'templates' => $service->adminList()->map(fn (PcBuildTemplate $template): array => $service->presentForAdministration($template))->all(),
            'products' => $products,
        ]);
    }

    public function store(SavePcBuildTemplateRequest $request, PcBuildTemplateService $service): JsonResponse
    {
        return response()->json($service->presentForAdministration($service->save($request->user(), $request->templateData())), 201);
    }

    public function update(SavePcBuildTemplateRequest $request, PcBuildTemplate $pcBuildTemplate, PcBuildTemplateService $service): JsonResponse
    {
        return response()->json($service->presentForAdministration($service->save($request->user(), $request->templateData(), $pcBuildTemplate)));
    }

    public function destroy(Request $request, PcBuildTemplate $pcBuildTemplate, PcBuildTemplateService $service): JsonResponse
    {
        abort_unless($request->user()?->can('products.manage'), 403);
        $service->delete($pcBuildTemplate);

        return response()->json(['deleted' => true]);
    }
}
