<?php

declare(strict_types=1);

namespace App\Billing;

use App\Enums\BillingInterval;
use App\Enums\Currency;
use App\Enums\LimitKey;

final class PlanCatalog
{
    private const array LIMIT_FEATURES = [
        'seats' => LimitKey::Seats,
        'documents' => LimitKey::Documents,
        'ai_queries' => LimitKey::AiQueriesMonthly,
    ];

    /** @return list<string> */
    public function keys(): array
    {
        /** @var array<string, mixed> $plans */
        $plans = config('billing.plans', []);

        return array_keys($plans);
    }

    /** @return list<string> */
    public function paidKeys(): array
    {
        return array_values(array_filter(
            $this->keys(),
            fn (string $key): bool => $this->amount($key, BillingInterval::Month, $this->currency()) > 0,
        ));
    }

    public function exists(string $key): bool
    {
        return in_array($key, $this->keys(), true);
    }

    public function isPaid(string $key): bool
    {
        return in_array($key, $this->paidKeys(), true);
    }

    /** @return array<string, int|null> */
    public function limits(string $key): array
    {
        /** @var array<string, int|null> $limits */
        $limits = config("billing.plans.{$key}.limits", []);
        $all = [];

        foreach (LimitKey::cases() as $limit) {
            $all[$limit->value] = array_key_exists($limit->value, $limits) ? $limits[$limit->value] : null;
        }

        return $all;
    }

    public function limit(string $key, string $limit): ?int
    {
        $limits = $this->limits($key);

        return array_key_exists($limit, $limits) ? $limits[$limit] : null;
    }

    /** @return list<string> */
    public function featureKeys(string $key): array
    {
        /** @var list<string> $features */
        $features = config("billing.plans.{$key}.features", []);

        return $features;
    }

    public function hasFeature(string $key, string $feature): bool
    {
        return in_array($feature, $this->featureKeys($key), true);
    }

    public function amount(string $key, BillingInterval $interval, string $currency): int
    {
        return (int) config("billing.plans.{$key}.prices.".strtoupper($currency).".{$interval->value}", 0);
    }

    public function hasPrice(string $key, BillingInterval $interval, string $currency): bool
    {
        return is_int(config("billing.plans.{$key}.prices.".strtoupper($currency).".{$interval->value}"));
    }

    public function price(string $key, BillingInterval $interval, Currency $currency): int
    {
        return $this->amount($key, $interval, $currency->value);
    }

    /** @return array<string, array{monthly_minor: int, yearly_minor: int}> */
    public function prices(string $key): array
    {
        $prices = [];

        foreach (Currency::cases() as $currency) {
            $prices[$currency->value] = [
                'monthly_minor' => $this->price($key, BillingInterval::Month, $currency),
                'yearly_minor' => $this->price($key, BillingInterval::Year, $currency),
            ];
        }

        return $prices;
    }

    /** @return list<string> */
    public function currencies(): array
    {
        $currencies = [];

        foreach ($this->keys() as $key) {
            /** @var array<string, mixed> $prices */
            $prices = config("billing.plans.{$key}.prices", []);
            $currencies = [...$currencies, ...array_keys($prices)];
        }

        return array_values(array_unique(array_map(strtoupper(...), $currencies)));
    }

    public function currency(): string
    {
        $currency = strtoupper((string) config('billing.currency', 'USD'));

        return in_array($currency, $this->currencies(), true) ? $currency : 'USD';
    }

    public function lookupKey(string $key, BillingInterval $interval, string $currency): string
    {
        return strtolower(self::catalogName().'_'.$key.'_'.$interval->value.'_'.$currency);
    }

    public function productId(string $key): string
    {
        return strtolower(self::catalogName().'_'.$key);
    }

    /** @return array{plan: string, interval: BillingInterval, currency: string}|null */
    public function findByLookupKey(string $lookupKey): ?array
    {
        foreach ($this->keys() as $key) {
            foreach (BillingInterval::cases() as $interval) {
                foreach ($this->currencies() as $currency) {
                    if ($this->lookupKey($key, $interval, $currency) === $lookupKey) {
                        return ['plan' => $key, 'interval' => $interval, 'currency' => $currency];
                    }
                }
            }
        }

        return null;
    }

    /** @return list<array<string, mixed>> */
    public function all(Currency|string|null $currency = null): array
    {
        return array_map(fn (string $key): array => $this->toContract($key, $currency), $this->keys());
    }

    /** @return array<string, mixed> */
    public function toContract(string $key, Currency|string|null $currency = null): array
    {
        $code = $this->currencyCode($currency);

        return [
            'key' => $key,
            'name' => (string) __("billing.plans.{$key}.name"),
            'description' => (string) __("billing.plans.{$key}.description"),
            'prices' => $this->prices($key),
            'currency' => $code,
            'price_monthly_minor' => $this->amount($key, BillingInterval::Month, $code),
            'price_yearly_minor' => $this->amount($key, BillingInterval::Year, $code),
            'features' => $this->features($key),
            'limits' => $this->limits($key),
            'highlighted' => (bool) config("billing.plans.{$key}.highlighted", false),
        ];
    }

    /** @return list<string> */
    public function features(string $key): array
    {
        $limits = $this->limits($key);
        $bullets = [];

        foreach (self::LIMIT_FEATURES as $feature => $limitKey) {
            $value = $limits[$limitKey->value] ?? null;
            $bullets[] = $value === null
                ? (string) __("billing.features.{$feature}_unlimited")
                : trans_choice("billing.features.{$feature}", $value, ['count' => number_format($value, 0, '.', ' ')]);
        }

        foreach ($this->featureKeys($key) as $feature) {
            $bullets[] = (string) __("billing.features.{$feature}");
        }

        if (isset($limits['storage_mb'])) {
            $bullets[] = (string) __('billing.features.storage', ['size' => self::formatStorage($limits['storage_mb'])]);
        }

        if (isset($limits['max_pages_per_document'])) {
            $bullets[] = trans_choice('billing.features.max_pages', $limits['max_pages_per_document'], ['count' => $limits['max_pages_per_document']]);
        }

        return $bullets;
    }

    public static function major(int $minor): int|float
    {
        return $minor % 100 === 0 ? intdiv($minor, 100) : $minor / 100;
    }

    public static function catalogName(): string
    {
        $name = preg_replace('/[^a-z0-9]+/', '_', strtolower((string) config('billing.stripe.catalog', 'opsbot')));

        return trim((string) $name, '_') ?: 'opsbot';
    }

    public static function formatStorage(int $megabytes): string
    {
        return $megabytes >= 1024
            ? (string) __('billing.units.gb', ['value' => rtrim(rtrim(number_format($megabytes / 1024, 1, '.', ''), '0'), '.')])
            : (string) __('billing.units.mb', ['value' => $megabytes]);
    }

    private function currencyCode(Currency|string|null $currency): string
    {
        if ($currency instanceof Currency) {
            return $currency->value;
        }

        $code = strtoupper((string) $currency);

        return in_array($code, $this->currencies(), true) ? $code : $this->currency();
    }
}
