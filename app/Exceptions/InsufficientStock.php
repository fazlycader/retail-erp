<?php

namespace App\Exceptions;

use App\Models\ProductVariant;
use App\Support\Quantity;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

class InsufficientStock extends RuntimeException implements Responsable
{
    public static function forVariant(ProductVariant $variant, int $locationId, int $requestedMilli, int $availableMilli): self
    {
        return new self(
            sprintf(
                'Insufficient stock for %s: requested %s, available %s.',
                $variant->sku,
                Quantity::format($requestedMilli),
                Quantity::format($availableMilli),
            ),
        );
    }

    public function toResponse($request): Response
    {
        /** @var Request $request */
        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['message' => $this->getMessage()], 422);
        }

        return back()->withInput()->with('error', $this->getMessage());
    }
}
