<?php declare(strict_types=1);

namespace App\Support;

/**
 * Pakistani addresses.
 *
 * One source of truth for the province -> city list used by the storefront
 * checkout, the customer profile and the order validation. The data itself
 * lives in `config/pakistan.php` so it can be edited without touching code.
 */
final class Address
{
    /**
     * Province name => cities, in display order.
     *
     * @return array<string, array<int, string>>
     */
    public static function provinces(): array
    {
        return (array) config('pakistan', []);
    }

    /**
     * Every province, shaped for the `Province` GraphQL type / the q-selects.
     *
     * @return array<int, array{name: string, cities: array<int, string>}>
     */
    public static function provinceOptions(): array
    {
        $provinces = [];

        foreach (self::provinces() as $name => $cities) {
            $provinces[] = [
                'name' => (string) $name,
                'cities' => array_values((array) $cities),
            ];
        }

        return $provinces;
    }

    /** City names belonging to a province (case sensitive, as stored). */
    public static function citiesFor(?string $province): array
    {
        return (array) (self::provinces()[$province] ?? []);
    }

    public static function hasProvince(?string $province): bool
    {
        return $province !== null
            && $province !== ''
            && array_key_exists($province, self::provinces());
    }

    /**
     * Resolve a province name typed in any letter case.
     *
     * Old orders and profile rows may have been saved before the list existed,
     * so matching is done on a normalised value instead of an exact string.
     */
    public static function canonicalProvince(?string $province): ?string
    {
        $needle = self::normalise((string) $province);

        if ($needle === '') {
            return null;
        }

        foreach (array_keys(self::provinces()) as $name) {
            if (self::normalise((string) $name) === $needle) {
                return (string) $name;
            }
        }

        return null;
    }

    /** Is this city part of the given province? */
    public static function hasCity(?string $province, ?string $city): bool
    {
        $province = self::canonicalProvince($province);

        if ($province === null) {
            return false;
        }

        $needle = self::normalise((string) $city);

        if ($needle === '') {
            return false;
        }

        foreach (self::citiesFor($province) as $option) {
            if (self::normalise($option) === $needle) {
                return true;
            }
        }

        return false;
    }

    /** Canonical city name inside a province, or null when it is not listed. */
    public static function canonicalCity(?string $province, ?string $city): ?string
    {
        $province = self::canonicalProvince($province);

        if ($province === null) {
            return null;
        }

        $needle = self::normalise((string) $city);

        foreach (self::citiesFor($province) as $option) {
            if (self::normalise($option) === $needle) {
                return $option;
            }
        }

        return null;
    }

    private static function normalise(string $value): string
    {
        $value = str_replace(['-', '_', '/'], ' ', $value);

        return strtolower(trim(preg_replace('/\s+/', ' ', $value) ?? ''));
    }
}
