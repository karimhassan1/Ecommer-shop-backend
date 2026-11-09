<?php declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\GraphQL\Support\AuthorizesAdmin;
use App\Models\ProductVariant;
use GraphQL\Error\UserError;
use Illuminate\Support\Facades\DB;
use Nodesol\LaraQL\Attributes\Mutation;

/**
 * Admin only: choose which variant is shown by default on the product page.
 */
#[Mutation(
    name: ' ',
    query: '@field(resolver:"SetDefaultVariant") @guard @permission(require: ["product.update"])',
    inputs: [
        'variant_id' => 'ID! @rules(apply:["required"])',
    ],
    return_type: 'ProductVariant!',
)]
class SetDefaultVariant
{
    use AuthorizesAdmin;

    /** @param array{} $args */
    public function __invoke($root, array $args)
    {
        /** @var ProductVariant|null $variant */
        $variant = ProductVariant::find($args['variant_id']);

        if (! $variant) {
            throw new UserError('That variant could not be found.');
        }

        DB::transaction(function () use ($variant): void {
            // Exactly one default per product.
            $variant->product->variants()
                ->whereKeyNot($variant->id)
                ->update(['is_default' => false]);

            $variant->forceFill(['is_default' => true])->save();
        });

        return $variant->fresh(['color', 'size', 'volume', 'images']);
    }
}
