import { usePage } from '@inertiajs/vue3';
import { isAbilityMap, roleAllows } from '@/lib/permissions';
import type { Ability } from '@/types/shared';

export function useCan() {
    const page = usePage();

    function can(ability: Ability, ...fallbacks: Ability[]): boolean {
        const map = (page.props.can ?? {}) as Record<string, unknown>;
        const shared = isAbilityMap(map);
        const role = page.props.workspace?.role ?? null;

        for (const key of [ability, ...fallbacks]) {
            const value = map[key];

            if (shared && typeof value === 'boolean') {
                return value;
            }

            if (!shared && key.includes('.') && roleAllows(role, key)) {
                return true;
            }
        }

        return false;
    }

    return { can };
}
