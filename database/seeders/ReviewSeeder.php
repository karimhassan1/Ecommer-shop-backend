<?php

namespace Database\Seeders;

use App\Models\Product;
use App\Models\Review;
use App\Models\User;
use Illuminate\Database\Seeder;

class ReviewSeeder extends Seeder
{
    /**
     * A handful of approved reviews so the storefront shows ratings on a fresh
     * install. Reviews are attached to the demo customer account.
     */
    public function run(): void
    {
        $customer = User::whereEmail('customer@roserabloom.test')->first();

        if (! $customer) {
            return;
        }

        $samples = [
            [
                'product' => 'Embroidered Lawn Kurta',
                'rating' => 5,
                'title' => 'Beautifully made',
                'body' => 'The silk is heavier than I expected in the best way. It drapes perfectly and the hem is finished by hand. Worth every penny.',
            ],
            [
                'product' => 'Cotton Kurta Shalwar Set',
                'rating' => 4,
                'title' => 'Great everyday tee',
                'body' => 'Lovely weight cotton that has survived several washes without losing shape. The fit is genuinely oversized, so size down if unsure.',
            ],
            [
                'product' => 'Rose Oud Eau de Parfum',
                'rating' => 5,
                'title' => 'My new signature',
                'body' => 'Soft rose without being cloying, and it lasts a full working day. I have already been asked what I am wearing three times.',
            ],
            [
                'product' => 'Musk Al Haramain Attar',
                'rating' => 4,
                'title' => 'Roomy and sturdy',
                'body' => 'Fits a laptop, a water bottle and still closes comfortably. The leather handles are softening up nicely with use.',
            ],
        ];

        foreach ($samples as $sample) {
            $product = Product::where('name', $sample['product'])->first();

            if (! $product) {
                continue;
            }

            Review::updateOrCreate(
                ['product_id' => $product->id, 'user_id' => $customer->id],
                [
                    'rating' => $sample['rating'],
                    'title' => $sample['title'],
                    'body' => $sample['body'],
                    'is_approved' => true,
                ]
            );
        }
    }
}
