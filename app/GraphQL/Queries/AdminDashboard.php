<?php declare(strict_types=1);

namespace App\GraphQL\Queries;

use App\Models\ContactMessage;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use GraphQL\Error\UserError;
use Nodesol\LaraQL\Attributes\Query;
use Nodesol\LaraQL\Attributes\Type;

/**
 * Numbers displayed on the admin dashboard.
 */
#[Type(
    // Explicit: `columns_override` merges with the reflected *properties*, and
    // this resolver class has none — only a method — so the dashboard numbers
    // have to be declared outright.
    columns: [
        'products' => 'Int!',
        'active_products' => 'Int!',
        'out_of_stock_products' => 'Int!',
        'orders' => 'Int!',
        'pending_orders' => 'Int!',
        'customers' => 'Int!',
        'unread_messages' => 'Int!',
        'revenue' => 'Float!',
        'revenue_this_month' => 'Float!',
    ]
)]
#[Query(
    name: 'AdminDashboard',
    query: '@field(resolver:"AdminDashboard") @guard @permission(require: ["dashboard.view"])',
    filters: [],
)]
class AdminDashboard
{
    /** @param array{} $args */
    public function __invoke($root, array $args)
    {
        $user = auth()->user();

        if (! $user?->can('dashboard.view')) {
            throw new UserError('You are not allowed to view the dashboard.');
        }

        // A seller's dashboard describes their own shop, not the whole store.
        $isSeller = $user->isSeller();

        $products = fn () => Product::query()
            ->when($isSeller, fn ($query) => $query->where('seller_id', $user->id));

        $orders = fn () => Order::query()
            ->when(
                $isSeller,
                fn ($query) => $query->whereHas(
                    'items.product',
                    fn ($product) => $product->where('seller_id', $user->id)
                )
            )
            ->whereNotNull('total_amount');

        return [
            'products' => $products()->count(),
            'active_products' => $products()->where('is_active', true)->count(),
            'out_of_stock_products' => $products()->whereDoesntHave(
                'variants',
                fn ($query) => $query->where('stock', '>', 0)->where('is_active', true)
            )->count(),
            'orders' => $orders()->count(),
            'pending_orders' => (clone $orders())->where('status', Order::STATUS_PENDING)->count(),
            // A seller never sees the customer book; the store side does.
            'customers' => $isSeller ? 0 : User::where('is_admin', false)->count(),
            'unread_messages' => $isSeller ? 0 : ContactMessage::where('is_read', false)->count(),
            'revenue' => (float) $orders()->sum('total_amount'),
            'revenue_this_month' => (float) $orders()
                ->whereBetween('created_at', [now()->startOfMonth(), now()])
                ->sum('total_amount'),
        ];
    }
}
