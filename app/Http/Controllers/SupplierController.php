<?php

namespace App\Http\Controllers;

use App\Models\Supplier;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class SupplierController extends Controller
{
    public function index(Request $request): View
    {
        return view('suppliers.index', ['suppliers' => $request->user()->suppliers()->withCount('products')->orderBy('name')->paginate(50)]);
    }

    public function store(Request $request, AuditLogger $auditLogger): RedirectResponse
    {
        $supplier = $request->user()->suppliers()->create($this->validated($request));
        $auditLogger->log($request->user(), Supplier::class, $supplier->id, 'supplier.created', null, $supplier->getAttributes(), $request->ip());

        return back()->with('success', '仕入先を登録しました。');
    }

    public function update(Request $request, Supplier $supplier, AuditLogger $auditLogger): RedirectResponse
    {
        abort_unless($supplier->user_id === $request->user()->id, 404);
        $before = $supplier->getAttributes();
        $supplier->update($this->validated($request));
        $auditLogger->log($request->user(), Supplier::class, $supplier->id, 'supplier.updated', $before, $supplier->fresh()->getAttributes(), $request->ip());

        return back()->with('success', '仕入先を更新しました。');
    }

    public function destroy(Request $request, Supplier $supplier, AuditLogger $auditLogger): RedirectResponse
    {
        abort_unless($supplier->user_id === $request->user()->id, 404);
        $request->validate(['confirm_deletion' => ['accepted']]);
        if ($supplier->products()->exists()) {
            return back()->with('error', '商品に使用されている仕入先は削除できません。');
        }

        $before = $supplier->getAttributes();
        $supplier->delete();
        $auditLogger->log($request->user(), Supplier::class, $supplier->id, 'supplier.deleted', $before, null, $request->ip());

        return back()->with('success', '仕入先を削除しました。');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in(Supplier::TYPES)],
            'memo' => ['nullable', 'string', 'max:3000'],
        ]);
    }
}
