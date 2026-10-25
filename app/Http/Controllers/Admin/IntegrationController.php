<?php

namespace App\Http\Controllers\Admin;

use App\Facades\Integrations;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\IntegrationCredential;
use App\Services\Integrations\CredentialRegistry;
use App\Services\Woo\WooApiException;
use App\Services\Woo\WooClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings → Integrations (admins, ported from the dental system). Values are WRITE-ONLY: secrets
 * show masked ("••••" + last 4) and an edit starts from an empty box; every change keeps the
 * previous value for a one-step undo. Audited without the values.
 */
class IntegrationController extends Controller
{
    public function index(): Response
    {
        $rows = IntegrationCredential::query()->with('updatedBy:id,name')->get()->keyBy('key_name');

        return Inertia::render('Admin/Settings/Integrations', [
            'groups' => collect(CredentialRegistry::PROVIDERS)->map(function (array $provider, string $id) use ($rows) {
                $keys = collect(CredentialRegistry::CREDENTIALS)->filter(fn ($d) => $d['provider'] === $id)->keys();
                $set = $keys->filter(fn ($k) => $rows->get($k)?->isSet());

                return [
                    'key' => $id,
                    'label' => $provider['label'],
                    'description' => $provider['description'],
                    'state' => $set->count() === $keys->count() ? 'configured' : ($set->isEmpty() ? 'not_configured' : 'partial'),
                    'credentials' => $keys->map(function ($key) use ($rows) {
                        $row = $rows->get($key);
                        $definition = CredentialRegistry::CREDENTIALS[$key];

                        return [
                            'key' => $key,
                            'description' => $definition['description'],
                            'secret' => $definition['secret'],
                            'set' => (bool) $row?->isSet(),
                            'masked' => $row?->masked(),
                            'can_undo' => $row && ($row->previous_changed_at !== null || $row->previous_value !== null),
                            'updated_at' => $row?->updated_at?->toIso8601String(),
                            'updated_by' => $row ? ($row->updatedBy?->name ?? ($row->isSet() ? 'imported from .env' : null)) : null,
                            'guide' => Str::markdown($row?->guide ?? $definition['guide'], ['html_input' => 'escape', 'allow_unsafe_links' => false]),
                        ];
                    })->values(),
                ];
            })->values(),
            'webhookUrl' => route('woo.webhook'),
        ]);
    }

    public function update(Request $request, string $key): RedirectResponse
    {
        abort_unless(CredentialRegistry::definition($key) !== null, 404);
        $data = $request->validate(['value' => ['required', 'string', 'max:4000']], ['value.required' => 'Enter the new value (to remove it instead, use "Clear").']);

        if ($key === 'WOO_STORE_URL' && ! str_starts_with(trim($data['value']), 'https://')) {
            return back()->withErrors(['value' => 'The shop address must start with https://.']);
        }

        Integrations::set($key, $data['value'], $request->user());
        AuditLog::record('integrations.updated', null, "Changed {$key}", null, null, $request->user());

        return back()->with('success', 'Saved: '.CredentialRegistry::CREDENTIALS[$key]['description'].'. "Undo" puts the old value back.');
    }

    public function clear(Request $request, string $key): RedirectResponse
    {
        abort_unless(CredentialRegistry::definition($key) !== null, 404);
        Integrations::set($key, null, $request->user());
        AuditLog::record('integrations.cleared', null, "Cleared {$key}", null, null, $request->user());

        return back()->with('success', 'Cleared. "Undo" puts it back.');
    }

    public function undo(Request $request, string $key): RedirectResponse
    {
        abort_unless(CredentialRegistry::definition($key) !== null, 404);
        Integrations::undo($key, $request->user());
        AuditLog::record('integrations.undone', null, "Undid the last change to {$key}", null, null, $request->user());

        return back()->with('success', 'The previous value is back.');
    }

    public function test(WooClient $woo): JsonResponse
    {
        try {
            $result = $woo->ping();

            return response()->json(['ok' => true, 'message' => 'Connected to WooCommerce'.($result['currency'] ? " (currency {$result['currency']})" : '').'.']);
        } catch (WooApiException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }
    }
}
