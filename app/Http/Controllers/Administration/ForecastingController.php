<?php

namespace App\Http\Controllers\Administration;

use App\Http\Controllers\Controller;
use App\Http\Requests\Administration\ForecastingIndexRequest;
use App\Http\Requests\Administration\GenerateForecastRequest;
use App\Models\Product;
use App\Services\Reporting\ForecastingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class ForecastingController extends Controller
{
    public function index(ForecastingIndexRequest $request, ForecastingService $forecasting): Response
    {
        return Inertia::render('Administration/Forecasting', $forecasting->page($request->validated()));
    }

    public function store(GenerateForecastRequest $request, ForecastingService $forecasting): RedirectResponse|JsonResponse
    {
        $product = Product::query()->findOrFail($request->integer('product_id'));
        $result = $forecasting->generate($product);

        if ($request->expectsJson()) {
            return response()->json($result)->header('Cache-Control', 'private, no-store');
        }

        Inertia::flash('forecast_result', $result);

        return to_route('administration.forecasting.index', ['product_id' => $product->id]);
    }
}
