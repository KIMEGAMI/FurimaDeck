<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ChromeExtensionImageController extends Controller
{
    public function show(Request $request, int $productId, int $imageId): StreamedResponse
    {
        $user = $request->attributes->get('chrome_extension_user');
        $product = $user->products()->findOrFail($productId);
        $image = $product->images()->findOrFail($imageId);
        $path = $image->derived_path ?? $image->original_path;
        abort_unless(Storage::disk($image->storageDisk())->exists($path), 404);

        $response = Storage::disk($image->storageDisk())->response($path, basename($path), [
            'Content-Type' => $image->mime_type,
            'X-Content-Type-Options' => 'nosniff',
        ]);
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store', true);

        return $response;
    }
}
