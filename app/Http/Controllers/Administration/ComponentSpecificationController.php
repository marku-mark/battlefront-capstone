<?php

namespace App\Http\Controllers\Administration;

use App\Http\Controllers\Controller;
use App\Http\Requests\Administration\SaveComponentSpecificationRequest;
use App\Models\Product;
use App\Models\ProductComponentProfile;
use App\Services\PcBuilder\ComponentReadinessService;
use App\Services\PcBuilder\ComponentSpecificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ComponentSpecificationController extends Controller
{
    public function index(ComponentReadinessService $readiness): Response
    {
        $products = Product::query()->orderBy('name')->get(['id', 'product_code', 'name', 'brand']);
        $profiles = $readiness->specificationProfiles();

        return Inertia::render('Administration/ComponentSpecifications/Index', [
            'products' => $products,
            'profiles' => $profiles,
            'candidates' => $readiness->candidates(),
        ]);
    }

    public function store(SaveComponentSpecificationRequest $request, ComponentSpecificationService $service): JsonResponse
    {
        $profile = $service->saveDraft($request->specificationData());

        return response()->json([
            'id' => $profile->id,
            'product_id' => $profile->product_id,
            'component_type' => $profile->component_type,
            'manufacturer_part_number' => $profile->manufacturer_part_number,
            'hardware_revision' => $profile->hardware_revision,
            'review_status' => $profile->review_status,
            'edit_version' => $profile->edit_version,
            'revision' => $profile->current_revision,
        ], 201);
    }

    public function approve(Request $request, ProductComponentProfile $componentSpecification, ComponentSpecificationService $service): JsonResponse
    {
        $validated = $request->validate(['edit_version' => ['required', 'integer', 'min:1']]);
        $profile = $service->approve($componentSpecification, $request->user(), (int) $validated['edit_version']);

        return response()->json([
            'id' => $profile->id,
            'product_id' => $profile->product_id,
            'review_status' => $profile->review_status,
            'edit_version' => $profile->edit_version,
            'revision' => $profile->current_revision,
        ]);
    }
}
