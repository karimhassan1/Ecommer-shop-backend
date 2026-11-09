<?php declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\GraphQL\Queries\ShopSettings;
use App\GraphQL\Support\AuthorizesAdmin;
use App\Services\SettingService;
use Nodesol\LaraQL\Attributes\Mutation;

/**
 * Admin only: change shop wide settings — shipping, payment options and store
 * details. Every input is optional so the panel can save one field at a time.
 */
#[Mutation(
    name: ' ',
    query: '@field(resolver:"UpdateShopSettings") @guard @permission(require: ["setting.update"])',
    inputs: [
        'shop_name' => 'String @rules(apply:["max:120"])',
        'currency_symbol' => 'String @rules(apply:["max:8"])',
        'shipping_fee' => 'Float @rules(apply:["numeric","min:0","max:1000000"])',
        'free_shipping_threshold' => 'Float @rules(apply:["numeric","min:0","max:10000000"])',
        'cod_enabled' => 'Boolean',
        'cod_fee' => 'Float @rules(apply:["numeric","min:0","max:100000"])',
        'bank_enabled' => 'Boolean',
        'bank_details' => 'String @rules(apply:["max:500"])',
        'wallet_enabled' => 'Boolean',
        'wallet_number' => 'String @rules(apply:["max:120"])',
        'support_email' => 'String @rules(apply:["email","max:180"])',
        'support_phone' => 'String @rules(apply:["max:60"])',
    ],
    return_type: 'ShopSettings!',
)]
class UpdateShopSettings
{
    use AuthorizesAdmin;

    public function __construct(private readonly SettingService $settings) {}

    /** @param array{} $args */
    public function __invoke($root, array $args)
    {
        $this->settings->setMany(array_filter(
            $args,
            static fn ($value): bool => $value !== null
        ));

        return app(ShopSettings::class)($root, []);
    }
}
