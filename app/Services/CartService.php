<?php

namespace App\Services;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use GraphQL\Error\UserError;
use Illuminate\Support\Facades\DB;

/**
 * All cart related business rules live here so the GraphQL resolvers stay thin.
 *
 * Stock is tracked per variant when a product has variants (sizes/colours or
 * perfume volumes), and on the product itself otherwise.
 */
class CartService
{
    public function __construct(private readonly SettingService $settings) {}

    /**
     * Return the cart of the user, creating it when missing.
     */
    public function for(User $user): Cart
    {
        return $user->cartOrCreate();
    }

    /**
     * Add a product to the cart, merging quantities when the same variant is
     * already in the cart.
     */
    public function addItem(
        User $user,
        int $productId,
        int $quantity = 1,
        ?int $variantId = null,
    ): Cart {
        if ($quantity < 1) {
            throw new UserError('Quantity must be at least 1.');
        }

        /** @var Product|null $product */
        $product = Product::with('variants')->find($productId);

        if (! $product) {
            throw new UserError('This product no longer exists.');
        }

        if (! $product->is_active) {
            throw new UserError('This product is currently unavailable.');
        }

        $variant = $this->resolveVariant($product, $variantId);
        $available = $this->availableStock($product, $variant);

        if ($available < 1) {
            throw new UserError('This item is out of stock.');
        }

        $cart = $this->for($user);

        $existing = $cart->cartItem()
            ->where('product_id', $product->id)
            ->where('product_variant_id', $variant?->id)
            ->first();

        $requested = $quantity + ($existing?->quantity ?? 0);

        if ($available < $requested) {
            $label = $variant?->label ? " ({$variant->label})" : '';

            throw new UserError(
                "Only {$available} item(s) of {$product->name}{$label} are in stock."
            );
        }

        if ($existing) {
            $existing->update(['quantity' => $requested]);
        } else {
            $cart->cartItem()->create([
                'product_id' => $product->id,
                'product_variant_id' => $variant?->id,
                'quantity' => $quantity,
            ]);
        }

        return $this->load($cart);
    }

    /**
     * Change the quantity of one cart line owned by the user.
     */
    public function updateItem(User $user, int $cartItemId, int $quantity): CartItem
    {
        if ($quantity < 1) {
            throw new UserError('Quantity must be at least 1.');
        }

        $item = $this->ownedItem($user, $cartItemId);
        $available = $this->availableStock($item->product, $item->variant);

        if ($available < $quantity) {
            throw new UserError("Only {$available} item(s) are available.");
        }

        $item->update(['quantity' => $quantity]);

        return $item->fresh(['product', 'variant']);
    }

    public function removeItem(User $user, int $cartItemId): int
    {
        $item = $this->ownedItem($user, $cartItemId);
        $item->delete();

        return $this->for($user)->cartItem()->count();
    }

    public function clear(User $user): void
    {
        $this->for($user)->cartItem()->delete();
    }

    /**
     * Merge a guest cart (kept in the browser) into the authenticated cart.
     *
     * @param  array<int, array{product_id: int|string, product_variant_id?: int|string|null, quantity: int|string}>  $lines
     */
    public function mergeGuest(User $user, array $lines): Cart
    {
        foreach ($lines as $line) {
            $productId = (int) ($line['product_id'] ?? 0);
            $quantity = max(0, (int) ($line['quantity'] ?? 0));
            $variantId = isset($line['product_variant_id'])
                ? (int) $line['product_variant_id']
                : null;

            if ($productId <= 0 || $quantity === 0) {
                continue;
            }

            // Skip products that vanished instead of failing the whole merge.
            $this->addItemQuietly($user, $productId, $quantity, $variantId);
        }

        return $this->load($this->for($user));
    }

    /**
     * Turn the cart of the user into an order.
     */
    public function checkout(User $user, array $data): Order
    {
        $cart = $this->for($user);

        if ($cart->cartItem()->count() === 0) {
            throw new UserError('Your cart is empty.');
        }

        $paymentMethod = (string) ($data['payment_method'] ?? Order::PAYMENT_COD);

        if (! $this->settings->allowsPaymentMethod($paymentMethod)) {
            throw new UserError('That payment method is currently unavailable.');
        }

        return DB::transaction(function () use ($user, $cart, $data, $paymentMethod): Order {
            // Everything the snapshot needs is loaded up front: the product
            // (name, type) and the variant with its labels, so writing an order
            // line never fires a query per row.
            $lines = $cart->cartItem()
                ->with([
                    'product',
                    'variant.color',
                    'variant.size',
                    'variant.volume',
                ])
                ->get();

            $subtotal = (float) $lines->sum(
                fn (CartItem $item): float => $item->quantity * $this->unitPrice($item)
            );

            // Shipping comes from the admin panel and is snapshotted so
            // historical totals never change when the setting is updated.
            $shipping = $this->settings->shippingFor($subtotal);

            // Cash on delivery carries a small handling charge in Pakistan.
            $codFee = $paymentMethod === Order::PAYMENT_COD
                ? $this->settings->codFee()
                : 0.0;

            $order = Order::create([
                'user_id' => $user->id,
                'name' => $data['name'] ?? $user->name,
                'phone' => $data['phone'] ?? $user->phone,
                'email' => $data['email'] ?? $user->email,
                'province' => $data['province'],
                'city' => $data['city'],
                'street' => $data['street'],
                // Optional in Pakistan: stored as NULL when the customer leaves
                // it blank (see the postal_code migration).
                'postal_code' => ($data['postal_code'] ?? null) ?: null,
                'notes' => $data['notes'] ?? null,
                'status' => Order::STATUS_PENDING,
                'payment_method' => $paymentMethod,
                'payment_status' => Order::PAYMENT_UNPAID,
                'subtotal' => $subtotal,
                'shipping_amount' => $shipping,
                'cod_fee' => $codFee,
                'total_amount' => $subtotal + $shipping + $codFee,
            ]);

            foreach ($lines as $line) {
                $variant = $line->variant;

                // Snapshot the whole line, not just the price: the product can
                // be renamed, re-priced or deleted later, and this row has to
                // keep describing the sale as it actually happened. `price` is
                // what was paid per unit; `list_price` keeps the regular price
                // so the discount can be shown on the receipt afterwards.
                $unitPrice = $this->unitPrice($line);
                $listPrice = (float) ($variant?->price ?? $unitPrice);

                OrderItem::create([
                    'order_id' => $order->id,
                    'product_id' => $line->product_id,
                    'product_variant_id' => $variant?->id,
                    'product_name' => $line->product?->name,
                    'product_type' => $line->product?->type,
                    'sku' => $variant?->sku,
                    // Snapshot labels so the receipt stays readable if the
                    // variant is later renamed or deactivated.
                    'color_name' => $variant?->color?->name,
                    'size_label' => $variant?->size?->name ?? $variant?->volume?->label,
                    'quantity' => $line->quantity,
                    'price' => $unitPrice,
                    'list_price' => $listPrice > 0 ? $listPrice : $unitPrice,
                    'discount_amount' => max(0.0, ($listPrice > 0 ? $listPrice : $unitPrice) - $unitPrice),
                ]);

                $this->decrementStock($line);
            }

            $cart->cartItem()->delete();

            return $order->fresh(['items.product', 'items.variant', 'user']);
        });
    }

    /**
     * Cart with everything the API needs to render totals.
     */
    public function load(Cart $cart): Cart
    {
        return $cart->fresh([
            'cartItem.product',
            'cartItem.variant.color',
            'cartItem.variant.size',
            'cartItem.variant.volume',
            'cartItem.variant.images',
        ]);
    }

    /**
     * Validate the requested variant belongs to the product.
     *
     * Every product has variants, so a cart line always names one. A product
     * whose variants are all inactive is treated as unavailable.
     */
    protected function resolveVariant(Product $product, ?int $variantId): ?ProductVariant
    {
        if ($product->variants->isEmpty()) {
            throw new UserError('This product is currently unavailable.');
        }

        if (! $variantId) {
            throw new UserError(
                $product->isPerfume()
                    ? 'Please choose a volume first.'
                    : 'Please choose a colour and size first.'
            );
        }

        /** @var ProductVariant|null $variant */
        $variant = $product->variants->firstWhere('id', $variantId);

        if (! $variant || ! $variant->is_active) {
            throw new UserError('That option is no longer available.');
        }

        return $variant;
    }

    protected function availableStock(Product $product, ?ProductVariant $variant): int
    {
        return (int) ($variant?->stock ?? 0);
    }

    /** What the customer actually pays for the chosen variant. */
    protected function unitPrice(CartItem $item): float
    {
        return (float) ($item->variant?->final_price ?? 0);
    }

    protected function decrementStock(CartItem $line): void
    {
        $variant = $line->variant;

        if ($variant) {
            $variant->decrement('stock', min($line->quantity, $variant->stock));
        }
    }

    protected function addItemQuietly(
        User $user,
        int $productId,
        int $quantity,
        ?int $variantId,
    ): void {
        try {
            $this->addItem($user, $productId, $quantity, $variantId);
        } catch (UserError) {
            // Out of stock or deleted products are simply dropped on merge.
        }
    }

    protected function ownedItem(User $user, int $cartItemId): CartItem
    {
        $cart = $this->for($user);

        /** @var CartItem|null $item */
        $item = $cart->cartItem()->with(['product', 'variant'])->whereKey($cartItemId)->first();

        if (! $item) {
            throw new UserError('This cart item could not be found.');
        }

        return $item;
    }
}
