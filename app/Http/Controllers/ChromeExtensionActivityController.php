<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ChromeExtensionActivityController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->attributes->get('chrome_extension_user');

        $activity = AuditLog::query()
            ->where('user_id', $user->id)
            ->where('action', 'like', 'chrome_extension.%')
            ->latest('created_at')
            ->limit((int) config('chrome_extension.activity_page_size'))
            ->get(['action', 'created_at'])
            ->map(fn (AuditLog $log): array => [
                'action' => $log->action,
                'occurred_at' => $log->created_at?->toIso8601String(),
            ])
            ->values();

        return response()->json(['data' => $activity]);
    }
}
