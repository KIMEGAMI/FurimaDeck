<?php

namespace App\Http\Controllers;

use App\Models\ExtensionDevice;
use App\Models\ExtensionPairing;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ChromeExtensionPairingController extends Controller
{
    public function start(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code_challenge' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{43}$/'],
            'redirect_uri' => ['required', 'url:https', 'max:255'],
            'device_name' => ['required', 'string', 'min:1', 'max:100'],
        ]);

        $redirect = parse_url($validated['redirect_uri']);
        $host = is_array($redirect) ? ($redirect['host'] ?? '') : '';
        $path = is_array($redirect) ? ($redirect['path'] ?? '') : '';
        $extensionId = (string) config('chrome_extension.extension_id', '');
        $hostMatchesExtension = preg_match('/^([a-p]{32})\.chromiumapp\.org$/', $host, $matches) === 1;
        $expectedIdIsValid = preg_match('/^[a-p]{32}$/', $extensionId) === 1;
        $extensionIsTrusted = $hostMatchesExtension
            && ($extensionId !== ''
                ? $expectedIdIsValid && hash_equals($extensionId, $matches[1])
                : config('app.env') !== 'production');

        if (! $extensionIsTrusted || $path !== '/pair') {
            throw ValidationException::withMessages(['redirect_uri' => 'Chrome拡張の認証先URLが正しくありません。']);
        }

        $pairing = ExtensionPairing::query()->create([
            'id' => (string) Str::uuid(),
            'code_challenge' => $validated['code_challenge'],
            'redirect_uri' => $validated['redirect_uri'],
            'device_name' => $validated['device_name'],
            'expires_at' => now()->addMinutes((int) config('chrome_extension.pairing_lifetime_minutes')),
        ]);

        return response()->json([
            'pairing_id' => $pairing->id,
            'approval_url' => $request->getSchemeAndHttpHost().'/chrome-extension/pair/'.$pairing->id,
            'expires_in' => (int) config('chrome_extension.pairing_lifetime_minutes') * 60,
        ], 201);
    }

    public function complete(Request $request, AuditLogger $auditLogger): JsonResponse
    {
        $validated = $request->validate([
            'pairing_id' => ['required', 'uuid'],
            'code' => ['required', 'string', 'size:64', 'regex:/^[a-f0-9]{64}$/'],
            'verifier' => ['required', 'string', 'size:64', 'regex:/^[a-f0-9]{64}$/'],
        ]);

        $result = DB::transaction(function () use ($validated): ?array {
            $pairing = ExtensionPairing::query()->lockForUpdate()->find($validated['pairing_id']);
            if ($pairing === null
                || $pairing->expires_at->isPast()
                || $pairing->approved_at === null
                || $pairing->exchanged_at !== null
                || ! hash_equals($pairing->code_challenge, rtrim(strtr(base64_encode(hash('sha256', $validated['verifier'], true)), '+/', '-_'), '='))
                || ! is_string($pairing->approval_code_hash)
                || ! hash_equals($pairing->approval_code_hash, hash('sha256', $validated['code']))) {
                return null;
            }

            $user = $pairing->approvedUser;
            if ($user === null) {
                return null;
            }

            $plainTextToken = bin2hex(random_bytes(32));
            $device = ExtensionDevice::query()->create([
                'user_id' => $user->id,
                'name' => $pairing->device_name,
                'token_hash' => hash('sha256', $plainTextToken),
                'scopes' => ExtensionDevice::SCOPES,
                'last_used_at' => now(),
                'expires_at' => now()->addDays((int) config('chrome_extension.access_token_lifetime_days')),
            ]);

            $pairing->forceFill(['exchanged_at' => now(), 'approval_code_hash' => null])->save();

            return [
                'access_token' => $plainTextToken,
                'user' => ['name' => $user->name],
                'user_id' => $user->id,
                'device_id' => $device->id,
            ];
        });

        if ($result === null) {
            return response()->json(['message' => '連携コードが無効か、期限切れです。もう一度連携してください。'], 422);
        }

        $auditLogger->log(User::query()->find($result['user_id']), ExtensionDevice::class, $result['device_id'], 'chrome_extension.device_paired');
        unset($result['user_id'], $result['device_id']);

        return response()->json($result);
    }

    public function show(Request $request, string $pairingId): View
    {
        $pairing = ExtensionPairing::query()->findOrFail($pairingId);
        abort_unless($pairing->expires_at->isFuture() && $pairing->approved_at === null, 410);

        return view('chrome_extension.pair', ['pairing' => $pairing]);
    }

    public function approve(Request $request, string $pairingId): RedirectResponse
    {
        $pairing = ExtensionPairing::query()->findOrFail($pairingId);
        abort_unless($pairing->expires_at->isFuture() && $pairing->approved_at === null, 410);

        $approvalCode = bin2hex(random_bytes(32));
        $pairing->forceFill([
            'approved_user_id' => $request->user()->id,
            'approval_code_hash' => hash('sha256', $approvalCode),
            'approved_at' => now(),
        ])->save();

        return redirect()->away($pairing->redirect_uri.'#pairing_id='.rawurlencode($pairing->id).'&code='.rawurlencode($approvalCode));
    }

    public function revoke(Request $request, AuditLogger $auditLogger): JsonResponse
    {
        $device = $request->attributes->get('chrome_extension_device');
        $device->forceFill(['revoked_at' => now()])->save();
        $auditLogger->log($request->attributes->get('chrome_extension_user'), ExtensionDevice::class, $device->id, 'chrome_extension.device_revoked');

        return response()->json(['revoked' => true]);
    }

    public function devices(Request $request): View
    {
        return view('chrome_extension.devices', [
            'devices' => $request->user()->extensionDevices()->whereNull('revoked_at')->where('expires_at', '>', now())->latest()->get(),
        ]);
    }

    public function revokeDevice(Request $request, int $deviceId, AuditLogger $auditLogger): RedirectResponse
    {
        $device = $request->user()->extensionDevices()->whereNull('revoked_at')->findOrFail($deviceId);
        $device->forceFill(['revoked_at' => now()])->save();
        $auditLogger->log($request->user(), ExtensionDevice::class, $device->id, 'chrome_extension.device_revoked');

        return back()->with('status', 'Chrome拡張の連携を解除しました。');
    }
}
