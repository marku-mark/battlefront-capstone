<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreChatbotMessageRequest;
use App\Models\User;
use App\Services\Chatbot\ChatbotConversation;
use Illuminate\Http\JsonResponse;

class ChatbotController extends Controller
{
    public function store(StoreChatbotMessageRequest $request, ChatbotConversation $chatbot): JsonResponse
    {
        $validated = $request->validated();

        /** @var User|null $customer */
        $customer = $request->user();
        $result = $chatbot->respond($validated['message'], $customer, $validated['context_token'] ?? null, $request->session()->getId());

        return response()->json([
            'message' => $result['message'],
            'presentation' => $result['presentation'],
            'source' => $result['source'],
            'context_token' => $result['context_token'],
            'language' => $result['language'],
        ]);
    }
}
