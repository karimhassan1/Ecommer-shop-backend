<?php

/*
|--------------------------------------------------------------------------
| API routes
|--------------------------------------------------------------------------
|
| These are mounted WITHOUT the `api` prefix (see bootstrap/app.php) so the
| paths read as `/api/uploads`. They are stateless and authenticated with a
| Sanctum bearer token, which is what the SPA sends.
|
*/

use App\Http\Controllers\UploadController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function (): void {
    Route::post('/api/uploads', [UploadController::class, 'store'])
        ->name('uploads.store');
});
