<?php

namespace App\Http\Controllers;

use App\Actions\PayMongo\RecordPayMongoWebhook;
use App\Services\PayMongo\VerifyPayMongoWebhook;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PayMongoWebhookController extends Controller
{
    public function __invoke(Request $request, VerifyPayMongoWebhook $verifier, RecordPayMongoWebhook $recorder): JsonResponse
    {
        $event = $verifier->handle($request);

        if ($event === null) {
            return response()->json(['message' => 'Invalid webhook.'], 400);
        }

        $recorded = $recorder->execute($event);

        return response()->json(['received' => true], $recorded ? 202 : 200);
    }
}
