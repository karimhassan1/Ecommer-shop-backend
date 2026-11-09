<?php

namespace Database\Seeders;

use App\Models\Setting;
use App\Services\SettingService;
use Illuminate\Database\Seeder;

class SettingsSeeder extends Seeder
{
    /**
     * Persist the default shop settings so the admin panel has real values to
     * edit on a fresh install.
     */
    public function run(): void
    {
        foreach (SettingService::DEFAULTS as $key => $value) {
            Setting::updateOrCreate(['key' => $key], ['value' => $value]);
        }

        app(SettingService::class)->flush();
    }
}
