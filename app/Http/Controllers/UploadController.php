<?php

namespace App\Http\Controllers;

use App\Services\MediaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Stores and removes images uploaded from the admin panel.
 *
 * The destination is whatever `config('media.disk')` points at, so the same
 * endpoint writes to the local public disk during development and to the Oracle
 * Cloud / S3 bucket in production without any change here.
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

    /**
     * Delete a picture that was uploaded but never attached to anything.
     *
     * Without this, picking a file, changing your mind and saving left the
     * object behind in the bucket forever. The admin forms call it when a
     * freshly uploaded picture is removed before the form is submitted; pictures
     * already referenced by a row are cleaned up by that model's own hooks.
     *
     * The path is checked so only the media folder can be touched: a caller must
     * not be able to use this to delete an arbitrary object.
     */
    public function destroy(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user || (! $user->can('product.create') && ! $user->can('setting.update'))) {
            return response()->json([
                'message' => 'You are not allowed to delete files.',
            ], 403);
        }

        $request->validate([
            'path' => ['required', 'string', 'max:2048'],
        ]);

        $path = (string) $request->input('path');
        $root = trim($this->media->folder(), '/');

        // Only files inside the configured media folder may be removed, and a
        // traversal attempt is refused outright.
        if (str_contains($path, '..') || ! str_starts_with(ltrim($path, '/'), $root.'/')) {
            return response()->json([
                'message' => 'That file cannot be deleted from here.',
            ], 422);
        }

        $this->media->forget($path);

        return response()->json([
            'deleted' => true,
            'path' => $path,
        ]);
    }
}
