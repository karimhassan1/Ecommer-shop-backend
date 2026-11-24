<?php

declare(strict_types=1);

namespace App\GraphQL\Queries;

use App\Models\Review;
use App\Services\GeoService;
use App\Services\MediaService;
use App\Services\SettingService;
use App\Support\Address;
use Nodesol\LaraQL\Attributes\Query;
use Nodesol\LaraQL\Attributes\Type;

/**
 * Everything the storefront needs to render prices, shipping and the payment
 * options before a customer even signs in.
 */
#[Type(
    columns: [
        'shop_name' => 'String!',
        'currency_code' => 'String!',
        'currency_symbol' => 'String!',
        'shipping_fee' => 'Float!',
        'free_shipping_threshold' => 'Float!',
        'cod_enabled' => 'Boolean!',
        'cod_fee' => 'Float!',
        'bank_enabled' => 'Boolean!',
        'bank_details' => 'String',
        'wallet_enabled' => 'Boolean!',
        'wallet_number' => 'String',
        'payment_methods' => '[PaymentMethod!]!',
        'provinces' => '[Province!]!',
        'product_types' => '[ProductType!]!',
        'audiences' => '[ProductType!]!',
        'clothing_sizes' => '[String!]!',
        'perfume_volumes' => '[String!]!',
        'support_email' => 'String!',
        'support_phone' => 'String!',
        'review_count' => 'Int!',
        'pending_reviews' => 'Int!',
        'media' => 'MediaConfig!',
    ]
)]
#[Query(
    name: 'ShopSettings',
    query: '@field(resolver:"ShopSettings")',
    filters: [],
)]
class ShopSettings
{
    public function __construct(
        private readonly SettingService $settings,
        private readonly MediaService $media,
        private readonly GeoService $geo,
    ) {}

    /** @param array{} $args */
    public function __invoke($root, array $args)
    {
        $all = $this->settings->all();
        $isAdmin = auth()->user()?->isAdmin() ?? false;

        return [
            'shop_name' => $all['shop_name'] ?? '',
            'currency_code' => $all['currency_code'] ?? 'PKR',
            'currency_symbol' => $all['currency_symbol'] ?? 'Rs',
            'shipping_fee' => $this->settings->shippingFee(),
            'free_shipping_threshold' => $this->settings->freeShippingThreshold(),
            'cod_enabled' => $this->settings->bool('cod_enabled'),
            'cod_fee' => $this->settings->float('cod_fee'),
            'bank_enabled' => $this->settings->bool('bank_enabled'),
            'bank_details' => $all['bank_details'] ?? null,
            'wallet_enabled' => $this->settings->bool('wallet_enabled'),
            'wallet_number' => $all['wallet_number'] ?? null,
            'payment_methods' => $this->settings->paymentMethods(),
            'provinces' => $this->provinces(),
            'product_types' => collect(config('shop.product_types', []))
                ->map(fn (string $label, string $value): array => [
                    'value' => $value,
                    'label' => $label,
                ])
                ->values()
                ->all(),
            'audiences' => collect(config('shop.audiences', []))
                ->map(fn (string $label, string $value): array => [
                    'value' => $value,
                    'label' => $label,
                ])
                ->values()
                ->all(),
            'clothing_sizes' => config('shop.clothing_sizes', []),
            'perfume_volumes' => config('shop.perfume_volumes', []),
            'support_email' => $all['support_email'] ?? '',
            'support_phone' => $all['support_phone'] ?? '',
            // Only expose moderation counters to administrators.
            'review_count' => $isAdmin ? Review::count() : 0,
            'pending_reviews' => $isAdmin
                ? Review::where('is_approved', false)->count()
                : 0,
            // Where images live and the base URL they resolve against. The SPA
            // mirrors this so switching the backend to S3 needs no rebuild.
            'media' => $this->media->clientConfig(),
        ];
    }

    /**
     * Provinces with their cities, shaped for the checkout selects.
     *
     * Served from GeoService (third party country/state/city data merged over
     * the shipped list) and registered on App\Support\Address, so the browser
     * is offered exactly the list PlaceOrder validates against.
     *
     * @return array<int, array{name: string, cities: array<int, string>}>
     */
    protected function provinces(): array
    {
        $options = $this->geo->provinceOptions();

        // Register now so the rest of this request (the order the customer is
        // about to place) validates against the same catalogue the page shows.
        Address::useCatalogue(
            collect($options)->mapWithKeys(
                fn (array $province): array => [$province['name'] => $province['cities']]
            )->all()
        );

        return $options;
    }
}
