<?php

namespace App\Http\Middleware;

use App\Models\ExtensionDevice;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateChromeExtension
{
    public function handle(Request $request, Closure $next, string $scope = ''): Response
    {
        $plainTextToken = $request->bearerToken();
        if (! is_string($plainTextToken) || strlen($plainTextToken) !== 64) {
            return response()->json(['message' => '拡張機能との連携が必要です。'], 401);
        }

        $device = ExtensionDevice::query()
            ->with('user')
            ->where('token_hash', hash('sha256', $plainTextToken))
            ->where('expires_at', '>', now())
            ->whereNull('revoked_at')
            ->first();

        if ($device === null || $device->user === null || ($scope !== '' && ! in_array($scope, $device->scopes ?? [], true))) {
            return response()->json(['message' => '拡張機能との連携が必要です。'], 401);
        }

        $device->forceFill(['last_used_at' => now()])->save();
        $request->attributes->set('chrome_extension_device', $device);
        $request->attributes->set('chrome_extension_user', $device->user);

        return $next($request);
    }
}
