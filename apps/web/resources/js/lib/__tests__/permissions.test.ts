import { describe, expect, it } from 'vitest';
import { isAbilityMap, ROLE_ABILITIES, roleAllows } from '@/lib/permissions';

describe('role ability matrix', () => {
    it('gives the owner everything the admin has plus deletion and billing', () => {
        for (const ability of ROLE_ABILITIES.admin) {
            expect(roleAllows('owner', ability), ability).toBe(true);
        }

        expect(roleAllows('owner', 'workspace.delete')).toBe(true);
        expect(roleAllows('owner', 'billing.manage')).toBe(true);
        expect(roleAllows('admin', 'workspace.delete')).toBe(false);
        expect(roleAllows('admin', 'billing.manage')).toBe(false);
    });

    it('scopes managers to department work', () => {
        expect(roleAllows('manager', 'incidents.manage')).toBe(true);
        expect(roleAllows('manager', 'incidents.view_department')).toBe(true);
        expect(roleAllows('manager', 'incidents.view_all')).toBe(false);
        expect(roleAllows('manager', 'bot.manage')).toBe(false);
        expect(roleAllows('manager', 'audit.view')).toBe(false);
        expect(roleAllows('manager', 'knowledge.approve')).toBe(false);
    });

    it('keeps employees to their own records', () => {
        expect(ROLE_ABILITIES.employee).toEqual([
            'assistant.use',
            'assistant.history',
            'incidents.create',
            'incidents.view_own',
            'tasks.create',
            'tasks.view_own',
            'integrations.manage',
        ]);
        expect(roleAllows('employee', 'questions.answer')).toBe(false);
    });

    it('rejects unknown roles', () => {
        expect(roleAllows(null, 'assistant.use')).toBe(false);
        expect(roleAllows('member', 'assistant.use')).toBe(false);
    });

    it('tells the shared ability map from a page permission object', () => {
        expect(isAbilityMap({ 'audit.view': true })).toBe(true);
        expect(isAbilityMap({ export: true })).toBe(false);
        expect(isAbilityMap(null)).toBe(false);
    });
});
