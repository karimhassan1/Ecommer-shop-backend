<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Setting;
use Illuminate\Support\Facades\Cache;

/**
 * Shop wide configuration that an administrator can change at runtime.
 *
 * Values are cached so they can be read on every request (cart totals, order
 * creation) without hitting the database each time.
 */
class SettingService
{
    /** Cache key holding the whole settings map. */
    protected const CACHE_KEY = 'shop_settings';

    /**
     * Defaults, used when a key has never been saved.
     *
     * Amounts are in PKR, which has no practical use for decimals.
     *
     * @var array<string, string>
     */
    public const DEFAULTS = [
        'shop_name' => 'ARSH Wear & Fragrance',
        'currency_code' => 'PKR',
        'currency_symbol' => 'Rs',
        'shipping_fee' => '250',
        'free_shipping_threshold' => '5000',
        'cod_enabled' => '1',
        'cod_fee' => '100',
        'bank_enabled' => '1',
        'bank_details' => 'Meezan Bank · ARSH Wear & Fragrance · 01234567890123',
        'wallet_enabled' => '1',
        'wallet_number' => '0300 1234567 (Easypaisa)',
        'support_email' => 'support@arshwearfragrance.pk',
        'support_phone' => '+92 300 1234567',
    ];

    /**
     * Settings that may be edited from the admin panel, with the validation
     * type used to coerce the incoming GraphQL value.
     *
     * @var array<string, string>
     */
    public const EDITABLE = [
        'shop_name' => 'string',
        'currency_symbol' => 'string',
        'shipping_fee' => 'float',
        'free_shipping_threshold' => 'float',
        'cod_enabled' => 'bool',
        'cod_fee' => 'float',
        'bank_enabled' => 'bool',
        'bank_details' => 'string',
        'wallet_enabled' => 'bool',
        'wallet_number' => 'string',
        'support_email' => 'string',
        'support_phone' => 'string',
    ];

    /**
     * Every setting, defaults merged with the stored values.
     *
     * @return array<string, string>
     */
    public function all(): array
    {
        return Cache::rememberForever(self::CACHE_KEY, function (): array {
            $stored = Setting::query()->pluck('value', 'key')->all();

            return array_merge(self::DEFAULTS, array_filter(
                $stored,
                static fn ($value): bool => $value !== null
            ));
        });
    }

    public function get(string $key, ?string $default = null): string
    {
        return (string) ($this->all()[$key] ?? $default ?? '');
    }

    public function float(string $key, float $default = 0.0): float
    {
        return (float) ($this->all()[$key] ?? $default);
    }

    public function bool(string $key, bool $default = false): bool
    {
        return filter_var($this->all()[$key] ?? $default, FILTER_VALIDATE_BOOLEAN);
    }

    /** Shipping fee charged on every order. */
    public function shippingFee(): float
    {
        return $this->float('shipping_fee');
    }

    /**
     * Orders at or above this subtotal ship for free. `0` disables the rule.
     */
    public function freeShippingThreshold(): float
    {
        return $this->float('free_shipping_threshold');
    }

    /**
     * Shipping charged for a given subtotal, applying the free shipping rule.
     */
    public function shippingFor(float $subtotal): float
    {
        $fee = $this->shippingFee();
        $threshold = $this->freeShippingThreshold();

        if ($threshold > 0 && $subtotal >= $threshold) {
            return 0.0;
        }

        return $fee;
    }

    /**
     * Extra charged when the customer picks cash on delivery.
     */
    public function codFee(): float
    {
        return $this->bool('cod_enabled') ? $this->float('cod_fee') : 0.0;
    }

    /**
     * Payment methods currently offered, with the details the customer needs.
     *
     * @return array<int, array{value: string, label: string, fee: float, details: ?string}>
     */
    public function paymentMethods(): array
    {
        $methods = [];

        if ($this->bool('cod_enabled')) {
            $methods[] = [
                'value' => Order::PAYMENT_COD,
                'label' => (string) config('shop.payment_methods.cod'),
                'fee' => $this->float('cod_fee'),
                'details' => null,
            ];
        }

        if ($this->bool('bank_enabled')) {
            $methods[] = [
                'value' => Order::PAYMENT_BANK,
                'label' => (string) config('shop.payment_methods.bank'),
                'fee' => 0.0,
                'details' => $this->get('bank_details'),
            ];
        }

        if ($this->bool('wallet_enabled')) {
            $methods[] = [
                'value' => Order::PAYMENT_WALLET,
                'label' => (string) config('shop.payment_methods.wallet'),
                'fee' => 0.0,
                'details' => $this->get('wallet_number'),
            ];
        }

        return $methods;
    }

    /**
     * Whether a given payment method is currently allowed.
     */
    public function allowsPaymentMethod(string $method): bool
    {
        return collect($this->paymentMethods())->contains(
            static fn (array $option): bool => $option['value'] === $method
        );
    }

    /**
     * Persist one or more settings and drop the cache.
     *
     * @param  array<string, mixed>  $values
     */
    public function setMany(array $values): void
    {
        foreach ($values as $key => $value) {
            if (! array_key_exists($key, self::EDITABLE)) {
                continue;
            }

            Setting::query()->updateOrCreate(
                ['key' => $key],
                ['value' => $this->normalise($key, $value)]
            );
        }

        $this->flush();
    }

    public function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    protected function normalise(string $key, mixed $value): string
    {
        return match (self::EDITABLE[$key] ?? 'string') {
            'float' => (string) round((float) $value, 2),
            'bool' => $value ? '1' : '0',
            default => trim((string) $value),
        };
    }
}
