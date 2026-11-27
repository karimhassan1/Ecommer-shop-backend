<?php

namespace Database\Seeders;

use App\Models\Color;
use App\Models\FabricType;
use App\Models\Size;
use App\Models\Volume;
use Illuminate\Database\Seeder;

/**
 * Starting values for the master variant lists.
 *
 * Everything here is editable from the admin panel afterwards; this only saves
 * typing on a fresh install.
 */
class VariantMasterSeeder extends Seeder
{
    public function run(): void
    {
        // The clothing "Fabric Type" filter list. Perfume never uses one.
        $fabrics = [
            'Wash & Wear',
            'Cotton',
            'Boski',
            'Khaddar',
            'Karandi',
            'Linen',
            'Wool',
            'Blended Fabrics',
        ];

        foreach ($fabrics as $index => $name) {
            FabricType::updateOrCreate(
                ['name' => $name],
                ['sort_order' => $index, 'is_active' => true]
            );
        }

        $colors = [
            ['Rose Pink', '#e8a0bf'],
            ['Ivory', '#f5f0e1'],
            ['Midnight', '#1f2430'],
            ['Maroon', '#7b1f2b'],
            ['Emerald', '#0f7a46'],
            ['Olive', '#708238'],
            ['Sand', '#d9c9a3'],
            ['Charcoal', '#36454f'],
            ['White', '#ffffff'],
            ['Sky Blue', '#a9cce3'],
        ];

        foreach ($colors as $index => [$name, $hex]) {
            Color::updateOrCreate(
                ['name' => $name],
                ['hex_code' => $hex, 'sort_order' => $index, 'is_active' => true]
            );
        }

        foreach (['XS', 'S', 'M', 'L', 'XL', 'XXL'] as $index => $name) {
            Size::updateOrCreate(
                ['name' => $name],
                ['sort_order' => $index, 'is_active' => true]
            );
        }

        $volumes = [
            ['6 ml', 6],
            ['12 ml', 12],
            ['30 ml', 30],
            ['50 ml', 50],
            ['100 ml', 100],
        ];

        foreach ($volumes as $index => [$label, $ml]) {
            Volume::updateOrCreate(
                ['label' => $label],
                ['ml' => $ml, 'sort_order' => $index, 'is_active' => true]
            );
        }
    }
}
