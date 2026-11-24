<?php declare(strict_types=1);

namespace App\Services;

use App\Support\Address;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The province / city list the checkout offers.
 *
 * The shop ships a hand written list (`config/pakistan.php`) and the selects
 * used to be fed from it only. That list is fine as an offline fallback but it
 * is never complete: new tehsils appear, names are spelled differently and a
 * customer cannot pick a town nobody typed in.
 *
 * This service therefore asks a third party geo API for every state
 * (province / territory) of Pakistan together with its cities, caches the
 * answer and merges it over the shipped list:
 *
 *   - the API wins for names and city membership
 *   - the shipped list fills in anything the API missed, so a provider outage
 *     (or a rate limit) can never empty the dropdown
 *
 * The merged result is registered on App\Support\Address, which is what
 * `PlaceOrder` and `UpdateProfile` validate against — so what the browser is
 * offered and what the API accepts are always the same list.
 *
 * Configure the provider in `config/services.php` (`geo`); `GEO_ENABLED=false`
 * turns the network call off entirely and the shipped list is used.
 */
class GeoService
{
    /** Cache key holding the merged catalogue. */
    public const CACHE_KEY = 'geo.pakistan.catalogue';

    /**
     * Province => cities, in display order.
     *
     * @return array<string, array<int, string>>
     */
    public function catalogue(): array
    {
        return Cache::remember(
            self::CACHE_KEY,
            (int) config('services.geo.cache_ttl', 86400),
            fn (): array => $this->merge($this->remoteCatalogue(), Address::provinces()),
        );
    }

    /**
     * Provinces with their cities, shaped for the `Province` GraphQL type.
     *
     * @return array<int, array{name: string, cities: array<int, string>}>
     */
    public function provinceOptions(): array
    {
        $options = [];

        foreach ($this->catalogue() as $name => $cities) {
            $options[] = [
                'name' => (string) $name,
                'cities' => array_values((array) $cities),
            ];
        }

        return $options;
    }

    /**
     * Register the merged catalogue on App\Support\Address for this request.
     *
     * Called from the GraphQL entry points so validation, the selects and the
     * profile form all read the same list without each one hitting the cache.
     */
    public function bootAddressCatalogue(): void
    {
        Address::useCatalogue($this->catalogue());
    }

    /** Drop the cached copy, e.g. after switching the provider. */
    public function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * Ask the provider for Pakistan's states and their cities.
     *
     * Returns an empty array on any failure: the caller merges the shipped list
     * on top, so a provider outage degrades to the previous behaviour instead of
     * breaking checkout. The failure is logged so a wrong key or a stale plan is
     * visible in the log rather than silently ignored.
     *
     * @return array<string, array<int, string>>
     */
    protected function remoteCatalogue(): array
    {
        if (! (bool) config('services.geo.enabled', true)) {
            return [];
        }

        $base = rtrim((string) config('services.geo.base_url', 'https://countriesnow.space/api/v0.1'), '/');
        $country = (string) config('services.geo.country', 'Pakistan');

        try {
            $states = Http::timeout((int) config('services.geo.timeout', 8))
                ->acceptJson()
                ->post($base.'/countries/states', ['country' => $country]);

            $rows = $states->json('data.states');

            if (! $states->successful() || ! is_array($rows)) {
                Log::warning('Geo provider returned no states for '.$country.'.', [
                    'status' => $states->status(),
                ]);

                return [];
            }

            // The city list is fetched per state, so the requests go out as one
            // concurrent pool: doing them sequentially meant a round trip per
            // province and a very slow first checkout.
            $names = collect($rows)
                ->map(static fn ($state): string => trim((string) ($state['name'] ?? '')))
                ->filter()
                ->unique()
                ->values();

            $responses = Http::pool(fn ($pool) => $names
                ->map(fn (string $name) => $pool->as('state-'.$name)
                    ->timeout((int) config('services.geo.timeout', 8))
                    ->acceptJson()
                    ->post($base.'/countries/state/cities', [
                        'country' => $country,
                        'state' => $name,
                    ]))
                ->all());

            $catalogue = [];

            foreach ($names as $name) {
                $response = $responses['state-'.$name] ?? null;
                $cities = [];

                if ($response instanceof \Illuminate\Http\Client\Response && $response->successful()) {
                    $data = $response->json('data');

                    if (is_array($data)) {
                        $cities = collect($data)
                            ->map(static fn ($city): string => trim((string) $city))
                            ->filter()
                            ->unique()
                            ->values()
                            ->all();
                    }
                }

                $catalogue[$name] = $cities;
            }

            return $catalogue;
        } catch (Throwable $exception) {
            Log::warning('Geo provider could not be reached; using the shipped list.', [
                'error' => $exception->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Merge a remote catalogue over the shipped one.
     *
     * Both directions matter:
     *   - a province only the provider knows is added
     *   - a province only the shipped list knows keeps its cities
     *   - inside a province the two city lists are united and sorted
     *
     * The shipped order is preserved for provinces it already contains so the
     * selects do not reshuffle on every deploy; provider-only provinces are
     * appended in the order the provider listed them.
     *
     * @param  array<string, array<int, string>>  $remote
     * @param  array<string, array<int, string>>  $local
     * @return array<string, array<int, string>>
     */
    public function merge(array $remote, array $local): array
    {
        if ($remote === []) {
            return $local;
        }

        $merged = [];

        foreach ($local as $name => $cities) {
            $key = $this->matchKey($remote, (string) $name);
            $remoteCities = $key === null ? [] : $remote[$key];

            $merged[$key ?? (string) $name] = $this->normaliseCities(
                array_merge((array) $cities, $remoteCities)
            );

            if ($key !== null) {
                unset($remote[$key]);
            }
        }

        foreach ($remote as $name => $cities) {
            if (isset($merged[$name])) {
                continue;
            }

            // A provider row without cities would render an empty select, so it
            // is skipped rather than added.
            if (array_filter((array) $cities) === []) {
                continue;
            }

            $merged[(string) $name] = $this->normaliseCities((array) $cities);
        }

        return $merged;
    }

    /**
     * Find the provider's spelling of a province we already know.
     *
     * The two sources disagree on "Islamabad Capital Territory" vs "Islamabad"
     * and on punctuation, so matching is done on a normalised value.
     *
     * @param  array<string, array<int, string>>  $remote
     */
    protected function matchKey(array $remote, string $name): ?string
    {
        $needle = $this->normalise($name);

        foreach (array_keys($remote) as $candidate) {
            if ($this->normalise((string) $candidate) === $needle) {
                return (string) $candidate;
            }
        }

        return null;
    }

    /**
     * De-duplicate and sort a city list, ignoring case.
     *
     * @param  array<int, mixed>  $cities
     * @return array<int, string>
     */
    protected function normaliseCities(array $cities): array
    {
        $seen = [];

        foreach ($cities as $city) {
            $label = trim((string) $city);

            if ($label === '') {
                continue;
            }

            $seen[$this->normalise($label)] ??= $label;
        }

        ksort($seen);

        return array_values($seen);
    }

    protected function normalise(string $value): string
    {
        $value = str_replace(['-', '_', '/', '.'], ' ', $value);

        return strtolower(trim(preg_replace('/\s+/', ' ', $value) ?? ''));
    }
}
