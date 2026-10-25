<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CustomSpec;
use App\Services\Production\CustomSpecService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CustomSpecController extends Controller
{
    public function save(Request $request, CustomSpecService $specs): JsonResponse|RedirectResponse
    {
        $this->authorizeSpecEdit($request);

        $data = $request->validate([
            'id' => ['nullable', 'integer', 'exists:custom_specs,id'],
            'base_product_id' => ['nullable', 'integer', 'exists:products,id'],
            'width_mm' => ['nullable', 'integer', 'min:1', 'max:20000'],
            'depth_mm' => ['nullable', 'integer', 'min:1', 'max:20000'],
            'height_mm' => ['nullable', 'integer', 'min:1', 'max:20000'],
            'material' => ['nullable', 'string', 'max:120'],
            'fabric_code' => ['nullable', 'string', 'max:80'],
            'colour_code' => ['nullable', 'string', 'max:80'],
            'finish' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'lead_time_days' => ['nullable', 'integer', 'min:1', 'max:365'],
        ]);

        $existing = isset($data['id']) ? CustomSpec::query()->findOrFail((int) $data['id']) : null;
        $spec = $specs->save($existing, $data, $request->user());
        $spec->load('baseProduct:id,name');

        if ($request->wantsJson()) {
            return response()->json(['spec' => $spec->toPayload()]);
        }

        return back()->with('success', 'Custom specification saved.');
    }

    public function uploadAttachment(Request $request, CustomSpec $spec, CustomSpecService $specs): JsonResponse|RedirectResponse
    {
        $this->authorizeSpecEdit($request);

        $request->validate([
            'file' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:8192'],
        ]);

        try {
            $spec = $specs->addAttachment($spec, $request->file('file'));
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['file' => $e->getMessage()]);
        }

        $spec->load('baseProduct:id,name');

        if ($request->wantsJson()) {
            return response()->json(['spec' => $spec->toPayload()]);
        }

        return back()->with('success', 'Reference file uploaded.');
    }

    public function removeAttachment(Request $request, CustomSpec $spec, int $index, CustomSpecService $specs): JsonResponse|RedirectResponse
    {
        $this->authorizeSpecEdit($request);

        $spec = $specs->removeAttachment($spec, $index);
        $spec->load('baseProduct:id,name');

        if ($request->wantsJson()) {
            return response()->json(['spec' => $spec->toPayload()]);
        }

        return back()->with('success', 'Reference file removed.');
    }

    public function approve(Request $request, CustomSpec $spec, CustomSpecService $specs): JsonResponse|RedirectResponse
    {
        $this->authorizeSpecEdit($request);

        $data = $request->validate([
            'approved_by_name' => ['required', 'string', 'max:120'],
            'approved_on' => ['required', 'date'],
            'signed_quote' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:8192'],
        ]);

        $spec = $specs->approve(
            $spec,
            $data['approved_by_name'],
            $data['approved_on'],
            $request->file('signed_quote'),
            $request->user(),
        );
        $spec->load('baseProduct:id,name');

        if ($request->wantsJson()) {
            return response()->json(['spec' => $spec->toPayload()]);
        }

        return back()->with('success', 'Customer approval recorded.');
    }

    public function privateFile(Request $request): StreamedResponse
    {
        $user = $request->user();
        abort_unless($user && ($user->can('manage-sales') || $user->can('work-on-jobs')), 403);

        $path = ltrim((string) $request->query('path', ''), '/');
        abort_if(
            $path === ''
            || str_contains($path, '..')
            || (! str_starts_with($path, CustomSpecService::DIR.'/') && ! str_starts_with($path, 'job-stages/')),
            404
        );

        $disk = Storage::disk('local');
        abort_unless($disk->exists($path), 404);

        return $disk->response($path);
    }

    private function authorizeSpecEdit(Request $request): void
    {
        $user = $request->user();
        abort_unless($user && ($user->can('manage-sales') || $user->can('manage-workshop')), 403);
    }
}

