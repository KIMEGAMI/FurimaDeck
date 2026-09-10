<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\MaintenanceModeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class MaintenanceController extends Controller
{
    public function __construct(
        private readonly MaintenanceModeService $maintenanceMode
    ) {}

    public function index(Request $request): View
    {
        $this->authorizeAdmin($request);

        return view('admin.maintenance', [
            'enabled' => $this->maintenanceMode->enabled(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $this->authorizeAdmin($request);

        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
        ]);

        $this->maintenanceMode->setEnabled((bool) $validated['enabled']);

        return redirect()
            ->route('admin.maintenance.index')
            ->with('status', (bool) $validated['enabled'] ? 'メンテナンスモードを有効にしました。' : 'メンテナンスモードを解除しました。');
    }

    private function authorizeAdmin(Request $request): void
    {
        abort_unless($request->user()?->isAdmin(), 403);
    }
}
