<?php

namespace App\Http\Controllers;

use App\Services\MediaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Stores product images uploaded from the admin panel.
 *
 * The destination is whatever `config('media.disk')` points at, so the same
 * endpoint writes to the local public disk during development and to S3 in
 * production without any change here.
 */
class UploadController extends Controller
{
    public function __construct(private readonly MediaService $media) {}

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user || ! $user->can('product.create')) {
            return response()->json([
                'message' => 'You are not allowed to upload files.',
            ], 403);
        }

        $allowed = implode(',', (array) config('media.allowed_mimes', ['jpg', 'jpeg', 'png', 'webp']));
        $maxKb = (int) config('media.max_kb', 5120);

        $request->validate([
            'file' => ['required', 'file', 'image', "mimes:{$allowed}", "max:{$maxKb}"],
            'folder' => ['nullable', 'string', 'max:40'],
        ]);

        $file = $request->file('file');

        // Keep the uploads grouped per shop area so the bucket stays tidy.
        $folder = trim((string) $request->input('folder', $this->media->folder()), '/');
        $folder = preg_replace('/[^a-z0-9\-\/]/i', '', $folder) ?: $this->media->folder();

        $name = Str::uuid()->toString().'.'.strtolower($file->getClientOriginalExtension());

        $path = $this->media->put($folder, $name, $file);

        return response()->json([
            'path' => $path,
            'url' => $this->media->url($path),
            'disk' => $this->media->diskName(),
            'name' => $file->getClientOriginalName(),
            'size' => $file->getSize(),
        ], 201);
    }
}
