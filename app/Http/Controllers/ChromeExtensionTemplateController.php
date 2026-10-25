<?php

namespace App\Http\Controllers;

use App\Models\ExtensionMessageTemplate;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ChromeExtensionTemplateController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->attributes->get('chrome_extension_user');

        return response()->json(['data' => $user->extensionMessageTemplates()
            ->orderBy('purpose')
            ->orderBy('name')
            ->limit(100)
            ->get(['id', 'name', 'purpose', 'body', 'updated_at'])
            ->map(fn (ExtensionMessageTemplate $template): array => [
                'id' => $template->id,
                'name' => $template->name,
                'purpose' => $template->purpose,
                'body' => $template->body,
                'updated_at' => $template->updated_at?->toIso8601String(),
            ])
            ->values()]);
    }

    public function store(Request $request, AuditLogger $auditLogger): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'min:1', 'max:80'],
            'purpose' => ['required', 'string', 'in:'.implode(',', ExtensionMessageTemplate::PURPOSES)],
            'body' => ['required', 'string', 'min:1', 'max:4000'],
        ]);
        $user = $request->attributes->get('chrome_extension_user');
        $template = $user->extensionMessageTemplates()->create($validated);
        $auditLogger->log($user, ExtensionMessageTemplate::class, $template->id, 'chrome_extension.template_created');

        return response()->json(['data' => $this->serialize($template)], 201);
    }

    public function update(Request $request, int $template, AuditLogger $auditLogger): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'min:1', 'max:80'],
            'purpose' => ['required', 'string', 'in:'.implode(',', ExtensionMessageTemplate::PURPOSES)],
            'body' => ['required', 'string', 'min:1', 'max:4000'],
        ]);
        $user = $request->attributes->get('chrome_extension_user');
        $record = $user->extensionMessageTemplates()->findOrFail($template);
        $record->update($validated);
        $auditLogger->log($user, ExtensionMessageTemplate::class, $record->id, 'chrome_extension.template_updated');

        return response()->json(['data' => $this->serialize($record->fresh())]);
    }

    public function destroy(Request $request, int $template, AuditLogger $auditLogger): JsonResponse
    {
        $user = $request->attributes->get('chrome_extension_user');
        $record = $user->extensionMessageTemplates()->findOrFail($template);
        $id = $record->id;
        $record->delete();
        $auditLogger->log($user, ExtensionMessageTemplate::class, $id, 'chrome_extension.template_deleted');

        return response()->json(['deleted' => true]);
    }

    /** @return array{id: int, name: string, purpose: string, body: string, updated_at: ?string} */
    private function serialize(ExtensionMessageTemplate $template): array
    {
        return [
            'id' => $template->id,
            'name' => $template->name,
            'purpose' => $template->purpose,
            'body' => $template->body,
            'updated_at' => $template->updated_at?->toIso8601String(),
        ];
    }
}
