<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Chat\ChatAssistant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ChatController extends Controller
{
    public function __construct(private ChatAssistant $assistant) {}

    public function greeting(): JsonResponse
    {
        return response()->json(['data' => $this->assistant->welcome()]);
    }

    public function message(Request $request): JsonResponse
    {
        $request->validate(['message' => ['required', 'string', 'max:'.ChatAssistant::MAX_LENGTH]]);

        return response()->json(['data' => $this->assistant->respond((string) $request->input('message'), $request)]);
    }
}
