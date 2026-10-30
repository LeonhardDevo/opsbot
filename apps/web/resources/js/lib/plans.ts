import type {
    BillingInterval,
    LimitEntry,
    LimitKey,
    Plan,
    PlanKey,
} from '@/types/opsbot';

export const PLAN_KEYS: readonly PlanKey[] = [
    'free',
    'starter',
    'pro',
    'business',
];

export const METERED_KEYS: readonly LimitKey[] = [
    'seats',
    'documents',
    'storage_mb',
    'ai_queries_monthly',
];

export const DOWNGRADE_BLOCKERS: readonly LimitKey[] = [
    'seats',
    'documents',
    'storage_mb',
];

export function planRank(key: PlanKey): number {
    return PLAN_KEYS.indexOf(key);
}

export function planPriceMinor(plan: Plan, interval: BillingInterval): number {
    return interval === 'year'
        ? plan.price_yearly_minor
        : plan.price_monthly_minor;
}

export function monthlyMinor(plan: Plan, interval: BillingInterval): number {
    return interval === 'year'
        ? Math.round(plan.price_yearly_minor / 12)
        : plan.price_monthly_minor;
}

export function minorToAmount(minor: number): number {
    return minor / 100;
}

export type PlanBlocker = { key: LimitKey; used: number; limit: number };

export function planBlockers(
    plan: Plan,
    usage: Partial<Record<LimitKey, LimitEntry>>,
): PlanBlocker[] {
    const blockers: PlanBlocker[] = [];

    for (const key of DOWNGRADE_BLOCKERS) {
        const limit = plan.limits[key];
        const used = usage[key]?.used ?? 0;

        if (limit !== null && limit !== undefined && used > limit) {
            blockers.push({ key, used, limit });
        }
    }

    return blockers;
}

export function nextPlan(
    plans: readonly Plan[],
    current: PlanKey,
): Plan | null {
    return (
        [...plans]
            .sort((a, b) => planRank(a.key) - planRank(b.key))
            .find((plan) => planRank(plan.key) > planRank(current)) ?? null
    );
}

export function yearlyDiscountMonths(plan: Plan): number {
    if (plan.price_monthly_minor <= 0) {
        return 0;
    }

    return Math.round(12 - plan.price_yearly_minor / plan.price_monthly_minor);
}

export function countdownParts(
    target: string | null,
    now: Date = new Date(),
): { days: number; hours: number; past: boolean } | null {
    if (!target) {
        return null;
    }

    const end = new Date(target).getTime();

    if (Number.isNaN(end)) {
        return null;
    }

    const diff = end - now.getTime();
    const hoursTotal = Math.max(0, Math.floor(diff / 3_600_000));

    return {
        days: Math.floor(hoursTotal / 24),
        hours: hoursTotal % 24,
        past: diff <= 0,
    };
}

export const EUR_COUNTRIES: readonly string[] = [
    'AT',
    'BE',
    'BG',
    'HR',
    'CY',
    'CZ',
    'DK',
    'EE',
    'FI',
    'FR',
    'DE',
    'GR',
    'HU',
    'IE',
    'IT',
    'LV',
    'LT',
    'LU',
    'MT',
    'NL',
    'PL',
    'PT',
    'RO',
    'SK',
    'SI',
    'ES',
    'SE',
];

export const COUNTRIES: readonly string[] = [
    'RU',
    'US',
    'GB',
    'CA',
    'AU',
    'NZ',
    'IE',
    'DE',
    'FR',
    'ES',
    'IT',
    'NL',
    'BE',
    'AT',
    'PT',
    'PL',
    'CZ',
    'SK',
    'HU',
    'RO',
    'BG',
    'GR',
    'HR',
    'SI',
    'LT',
    'LV',
    'EE',
    'FI',
    'SE',
    'DK',
    'LU',
    'MT',
    'CY',
    'NO',
    'CH',
    'IS',
    'RS',
    'ME',
    'TR',
    'AE',
    'IL',
    'KZ',
    'UZ',
    'KG',
    'AM',
    'GE',
    'AZ',
    'BY',
    'MD',
    'IN',
    'SG',
    'JP',
    'KR',
    'BR',
    'MX',
    'AR',
    'ZA',
];

export type Market = {
    currency: 'USD' | 'EUR' | 'RUB';
    gateway: 'stripe' | 'yookassa';
};

export function marketFor(country: string | null | undefined): Market {
    const code = (country ?? '').toUpperCase();

    if (code === 'RU') {
        return { currency: 'RUB', gateway: 'yookassa' };
    }

    if (EUR_COUNTRIES.includes(code)) {
        return { currency: 'EUR', gateway: 'stripe' };
    }

    return { currency: 'USD', gateway: 'stripe' };
}

export function countryName(code: string, locale: string): string {
    try {
        return (
            new Intl.DisplayNames([locale], { type: 'region' }).of(code) ?? code
        );
    } catch {
        return code;
    }
}
