<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class PwaController extends Controller
{
    public function manifest(): JsonResponse
    {
        return response()->json([
            'name' => config('pwa.name'),
            'short_name' => config('pwa.short_name'),
            'description' => config('pwa.description'),
            'start_url' => config('pwa.start_url'),
            'scope' => config('pwa.scope'),
            'display' => config('pwa.display'),
            'theme_color' => config('pwa.theme_color'),
            'background_color' => config('pwa.background_color'),
            'orientation' => 'portrait-primary',
            'icons' => [
                [
                    'src' => asset('images/icons/icon-192.png'),
                    'sizes' => '192x192',
                    'type' => 'image/png',
                    'purpose' => 'any maskable',
                ],
                [
                    'src' => asset('images/icons/icon-512.png'),
                    'sizes' => '512x512',
                    'type' => 'image/png',
                    'purpose' => 'any maskable',
                ],
            ],
        ], 200, [
            'Content-Type' => 'application/manifest+json; charset=UTF-8',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function serviceWorker(): Response
    {
        return response()
            ->view('pwa.service-worker', [
                'cacheName' => config('pwa.cache_name'),
                'offlineTitle' => 'FurimaDeck',
                'offlineMessage' => '現在オフラインです。通信状況を確認してから再度お試しください。',
                'offlineRetryLabel' => '再接続する',
            ], 200)
            ->header('Content-Type', 'application/javascript; charset=UTF-8')
            ->header('Service-Worker-Allowed', '/');
    }
}
