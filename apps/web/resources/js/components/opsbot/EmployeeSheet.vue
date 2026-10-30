<script setup lang="ts">
import { useForm, usePage } from '@inertiajs/vue3';
import { CircleAlert, Star } from '@lucide/vue';
import { computed, watch } from 'vue';
import SegmentedControl from '@/components/common/SegmentedControl.vue';
import FilterSelect from '@/components/opsbot/FilterSelect.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetFooter,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { Spinner } from '@/components/ui/spinner';
import { useCan } from '@/composables/useCan';
import { endpoints } from '@/lib/endpoints';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { DepartmentRef, Employee, WorkspaceRole } from '@/types/opsbot';

const props = defineProps<{
    employee: Employee | null;
    departments: DepartmentRef[];
}>();

const open = defineModel<boolean>('open', { default: false });
const emit = defineEmits<{ created: [name: string] }>();

const page = usePage();
const { can } = useCan();

type EmployeeForm = {
    name: string;
    position: string;
    phone: string;
    department_ids: number[];
    primary_department_id: number | null;
    locale: 'ru' | 'en';
    role: WorkspaceRole | null;
};

const form = useForm<EmployeeForm>({
    name: '',
    position: '',
    phone: '',
    department_ids: [],
    primary_department_id: null,
    locale: 'ru',
    role: null,
});

watch(open, (value) => {
    if (!value) {
        return;
    }

    form.clearErrors();
    form.name = props.employee?.name ?? '';
    form.position = props.employee?.position ?? '';
    form.phone = '';
    form.department_ids = props.employee?.departments.map((d) => d.id) ?? [];
    form.primary_department_id = props.employee?.primary_department_id ?? null;
    form.locale = page.props.locale === 'en' ? 'en' : 'ru';
    form.role = props.employee?.role ?? null;
});

function toggleDepartment(id: number, checked: boolean): void {
    form.department_ids = checked
        ? [...new Set([...form.department_ids, id])]
        : form.department_ids.filter((item) => item !== id);

    if (!form.department_ids.includes(form.primary_department_id ?? -1)) {
        form.primary_department_id = form.department_ids[0] ?? null;
    }
}

const localeOptions = computed(() =>
    page.props.availableLocales.map((option) => ({
        value: option.code,
        label: option.label,
    })),
);

const editing = computed(() => props.employee !== null);
const webAccount = computed(() => props.employee?.kind === 'web');

const roleOptions = computed(() =>
    (['employee', 'manager', 'admin'] as WorkspaceRole[]).map((role) => ({
        value: role,
        label: t(`roles.${role}`),
    })),
);

const roleModel = computed({
    get: () => form.role,
    set: (value: string | number | null | undefined) => {
        form.role = (value as WorkspaceRole | null) ?? null;
    },
});

function submit(): void {
    const payload = (data: EmployeeForm) => {
        const base: Record<string, unknown> = {
            name: data.name.trim(),
            position: data.position.trim() || null,
            department_ids: data.department_ids,
            primary_department_id: data.primary_department_id,
            locale: data.locale,
        };

        if (data.phone.trim()) {
            base.phone = data.phone.trim();
        }

        if (!editing.value && !data.phone.trim()) {
            base.phone = null;
        }

        if (
            editing.value &&
            can('members.manage') &&
            webAccount.value &&
            data.role &&
            data.role !== props.employee?.role
        ) {
            base.role = data.role;
        }

        return base;
    };

    if (props.employee) {
        form.transform(payload).patch(
            endpoints.employees.update(props.employee.id).url,
            {
                preserveScroll: true,
                onSuccess: () => (open.value = false),
            },
        );

        return;
    }

    const name = form.name.trim();

    form.transform(payload).post(endpoints.employees.store().url, {
        preserveScroll: true,
        onSuccess: () => {
            open.value = false;
            emit('created', name);
        },
    });
}
</script>

<template>
    <Sheet v-model:open="open">
        <SheetContent
            side="right"
            class="w-full gap-0 overflow-y-auto sm:max-w-lg"
        >
            <SheetHeader class="px-5 pt-5">
                <SheetTitle>{{
                    editing
                        ? t('ui.employees.form.edit_title')
                        : t('ui.employees.form.create_title')
                }}</SheetTitle>
                <SheetDescription>{{
                    t('ui.employees.form.hint')
                }}</SheetDescription>
            </SheetHeader>
            <form
                id="employee-form"
                class="grid gap-5 px-5 py-4"
                @submit.prevent="submit"
            >
                <div class="grid gap-2">
                    <Label for="employee-name">{{
                        t('ui.employees.form.name')
                    }}</Label>
                    <Input
                        id="employee-name"
                        v-model="form.name"
                        required
                        maxlength="120"
                        autocomplete="off"
                        :aria-invalid="Boolean(form.errors.name)"
                        data-test="employee-form-name"
                    />
                    <p
                        v-if="form.errors.name"
                        class="inline-flex items-center gap-1 text-xs text-destructive"
                    >
                        <CircleAlert class="size-3.5" aria-hidden="true" />
                        {{ form.errors.name }}
                    </p>
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="grid gap-2">
                        <Label for="employee-position">{{
                            t('ui.employees.form.position')
                        }}</Label>
                        <Input
                            id="employee-position"
                            v-model="form.position"
                            maxlength="120"
                        />
                    </div>
                    <div class="grid gap-2">
                        <Label for="employee-phone">{{
                            t('ui.employees.form.phone')
                        }}</Label>
                        <Input
                            id="employee-phone"
                            v-model="form.phone"
                            type="tel"
                            inputmode="tel"
                            maxlength="32"
                            :placeholder="
                                editing && employee?.phone_masked
                                    ? employee.phone_masked
                                    : '+7 900 000-00-00'
                            "
                            aria-describedby="employee-phone-hint"
                        />
                        <p
                            id="employee-phone-hint"
                            class="text-xs text-muted-foreground"
                        >
                            {{
                                form.errors.phone ??
                                t('ui.employees.form.phone_hint')
                            }}
                        </p>
                    </div>
                </div>

                <fieldset
                    class="grid gap-2"
                    data-test="employee-form-departments"
                >
                    <legend class="mb-1 text-sm font-semibold">
                        {{ t('ui.employees.form.departments') }}
                    </legend>
                    <p
                        v-if="!departments.length"
                        class="text-sm text-muted-foreground"
                    >
                        {{ t('ui.employees.form.no_departments') }}
                    </p>
                    <div
                        v-for="department in departments"
                        :key="department.id"
                        :class="
                            cn(
                                'flex min-h-11 items-center gap-3 rounded-2xl border px-3',
                                form.department_ids.includes(department.id)
                                    ? 'border-primary bg-primary-soft'
                                    : '',
                            )
                        "
                    >
                        <Checkbox
                            :id="`dep-${department.id}`"
                            :model-value="
                                form.department_ids.includes(department.id)
                            "
                            @update:model-value="
                                (value) =>
                                    toggleDepartment(
                                        department.id,
                                        value === true,
                                    )
                            "
                        />
                        <Label
                            :for="`dep-${department.id}`"
                            class="flex-1 font-medium"
                            >{{ department.name }}</Label
                        >
                        <button
                            v-if="form.department_ids.includes(department.id)"
                            type="button"
                            role="radio"
                            :aria-checked="
                                form.primary_department_id === department.id
                            "
                            :class="
                                cn(
                                    'inline-flex min-h-9 items-center gap-1 rounded-chip px-2 text-xs font-semibold',
                                    form.primary_department_id === department.id
                                        ? 'bg-sun-soft text-sun-strong'
                                        : 'text-muted-foreground hover:bg-surface',
                                )
                            "
                            data-test="employee-form-primary"
                            @click="form.primary_department_id = department.id"
                        >
                            <Star
                                :class="[
                                    'size-3.5',
                                    form.primary_department_id === department.id
                                        ? 'fill-current'
                                        : '',
                                ]"
                                aria-hidden="true"
                            />
                            {{ t('ui.employees.primary_department') }}
                        </button>
                    </div>
                    <p
                        v-if="form.errors.department_ids"
                        class="text-xs text-destructive"
                    >
                        {{ form.errors.department_ids }}
                    </p>
                </fieldset>

                <div class="grid gap-2">
                    <Label>{{ t('ui.employees.form.language') }}</Label>
                    <SegmentedControl
                        v-model="form.locale"
                        :label="t('ui.employees.form.language')"
                        :options="localeOptions"
                        class="self-start"
                    />
                </div>

                <div v-if="editing && can('members.manage')" class="grid gap-2">
                    <Label for="employee-role">{{
                        t('ui.employees.form.role')
                    }}</Label>
                    <FilterSelect
                        id="employee-role"
                        v-model="roleModel"
                        :nullable="false"
                        :label="t('ui.employees.form.role')"
                        :options="roleOptions"
                        class="sm:w-full"
                        :disabled="!webAccount"
                    />
                    <p v-if="!webAccount" class="text-xs text-muted-foreground">
                        {{ t('ui.employees.form.role_web_only') }}
                    </p>
                </div>
            </form>
            <SheetFooter class="border-t px-5 py-4">
                <Button type="button" variant="ghost" @click="open = false">{{
                    t('ui.common.cancel')
                }}</Button>
                <Button
                    type="submit"
                    form="employee-form"
                    :disabled="form.processing || form.name.trim().length < 2"
                    data-test="employee-form-submit"
                >
                    <Spinner v-if="form.processing" />
                    {{
                        editing
                            ? t('ui.common.save')
                            : t('ui.employees.form.create')
                    }}
                </Button>
            </SheetFooter>
        </SheetContent>
    </Sheet>
</template>
