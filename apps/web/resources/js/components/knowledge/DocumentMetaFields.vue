<script setup lang="ts">
import { computed } from 'vue';
import ChipToggleGroup from '@/components/common/ChipToggleGroup.vue';
import SegmentedControl from '@/components/common/SegmentedControl.vue';
import TagsInput from '@/components/common/TagsInput.vue';
import InputError from '@/components/InputError.vue';
import FilterSelect from '@/components/opsbot/FilterSelect.vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { t, tOr } from '@/lib/i18n';
import type { DepartmentRef, DocumentRef } from '@/types/opsbot';

export type DocumentMeta = {
    title: string;
    department_ids: number[];
    roles: string[];
    tags: string[];
    version: string;
    language: 'ru' | 'en' | 'auto';
    supersedes_document_id: number | null;
};

const props = withDefaults(
    defineProps<{
        departments: DepartmentRef[];
        roles: Array<{ value: string; label: string }>;
        errors: Partial<Record<string, string>>;
        idPrefix: string;
        supersedable?: DocumentRef[];
        lockDepartments?: boolean;
    }>(),
    { supersedable: () => [], lockDepartments: false },
);

const meta = defineModel<DocumentMeta>({ required: true });

const departmentOptions = computed(() =>
    props.departments.map((department) => ({
        value: String(department.id),
        label: department.name,
    })),
);

const selectedDepartments = computed({
    get: () => meta.value.department_ids.map(String),
    set: (value: string[]) => {
        meta.value = { ...meta.value, department_ids: value.map(Number) };
    },
});

const roleOptions = computed(() =>
    props.roles.map((role) => ({
        value: role.value,
        label: tOr(`roles.${role.value}`, role.label),
    })),
);

const supersedes = computed({
    get: () => meta.value.supersedes_document_id,
    set: (value: string | number | null | undefined) => {
        meta.value = {
            ...meta.value,
            supersedes_document_id: value ? Number(value) : null,
        };
    },
});

const fieldError = (key: string): string | undefined =>
    props.errors[key] ??
    Object.entries(props.errors).find(([name]) =>
        name.startsWith(`${key}.`),
    )?.[1];
</script>

<template>
    <div class="grid gap-5">
        <div class="grid gap-2">
            <Label :for="`${idPrefix}-title`">{{
                t('ui.knowledge.fields.title')
            }}</Label>
            <Input
                :id="`${idPrefix}-title`"
                v-model="meta.title"
                name="title"
                maxlength="250"
                :placeholder="t('ui.knowledge.fields.title_placeholder')"
                :aria-invalid="Boolean(errors.title)"
            />
            <InputError :message="errors.title" />
        </div>

        <div class="grid gap-2">
            <span class="text-sm font-medium">{{
                t('ui.knowledge.fields.departments')
            }}</span>
            <ChipToggleGroup
                v-if="departmentOptions.length"
                v-model="selectedDepartments"
                :options="departmentOptions"
                :label="t('ui.knowledge.fields.departments')"
            />
            <p class="text-xs text-muted-foreground">
                {{
                    lockDepartments
                        ? t('ui.knowledge.fields.departments_required')
                        : t('ui.knowledge.fields.departments_hint')
                }}
            </p>
            <InputError :message="fieldError('department_ids')" />
        </div>

        <div class="grid gap-2">
            <span class="text-sm font-medium">{{
                t('ui.knowledge.fields.roles')
            }}</span>
            <ChipToggleGroup
                v-model="meta.roles"
                :options="roleOptions"
                :label="t('ui.knowledge.fields.roles')"
            />
            <p class="text-xs text-muted-foreground">
                {{ t('ui.knowledge.fields.roles_hint') }}
            </p>
            <InputError :message="fieldError('roles')" />
        </div>

        <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
            <div class="grid gap-2">
                <Label :for="`${idPrefix}-version`">{{
                    t('ui.knowledge.fields.version')
                }}</Label>
                <Input
                    :id="`${idPrefix}-version`"
                    v-model="meta.version"
                    name="version"
                    maxlength="40"
                    placeholder="v1.2"
                    class="font-mono"
                    :aria-invalid="Boolean(errors.version)"
                />
                <InputError :message="errors.version" />
            </div>
            <div class="grid gap-2">
                <Label>{{ t('ui.knowledge.fields.language') }}</Label>
                <SegmentedControl
                    v-model="meta.language"
                    :label="t('ui.knowledge.fields.language')"
                    :options="[
                        {
                            value: 'auto',
                            label: t('ui.knowledge.languages.auto'),
                        },
                        { value: 'ru', label: t('ui.knowledge.languages.ru') },
                        { value: 'en', label: t('ui.knowledge.languages.en') },
                    ]"
                    class="self-start"
                />
            </div>
        </div>

        <div class="grid gap-2">
            <Label :for="`${idPrefix}-tags`">{{
                t('ui.knowledge.fields.tags')
            }}</Label>
            <TagsInput
                :id="`${idPrefix}-tags`"
                v-model="meta.tags"
                name="tags"
                :placeholder="t('ui.knowledge.fields.tags_placeholder')"
            />
            <InputError :message="fieldError('tags')" />
        </div>

        <div v-if="supersedable.length" class="grid gap-2">
            <Label :for="`${idPrefix}-supersedes`">{{
                t('ui.knowledge.fields.supersedes')
            }}</Label>
            <FilterSelect
                :id="`${idPrefix}-supersedes`"
                v-model="supersedes"
                :label="t('ui.knowledge.fields.supersedes')"
                :all-label="t('ui.knowledge.fields.supersedes_none')"
                :options="
                    supersedable.map((doc) => ({
                        value: doc.id,
                        label: doc.version
                            ? `${doc.title} · ${doc.version}`
                            : doc.title,
                    }))
                "
                class="sm:w-full"
            />
            <InputError :message="errors.supersedes_document_id" />
        </div>
    </div>
</template>
