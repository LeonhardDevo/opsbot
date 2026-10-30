<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { CircleAlert, Route } from '@lucide/vue';
import { watchDebounced } from '@vueuse/core';
import { computed, ref, watch } from 'vue';
import SegmentedControl from '@/components/common/SegmentedControl.vue';
import FilterSelect from '@/components/opsbot/FilterSelect.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { endpoints, withQuery } from '@/lib/endpoints';
import { jsonRequest } from '@/lib/http';
import { t } from '@/lib/i18n';
import { defaultSeverity, INCIDENT_CATEGORIES } from '@/lib/opsbot';
import { categoryIcon } from '@/lib/opsbot-icons';
import { cn } from '@/lib/utils';
import type {
    DepartmentRef,
    IncidentCategory,
    RoutingPreview,
    Severity,
    UserRef,
} from '@/types/opsbot';

const props = defineProps<{
    departments: DepartmentRef[];
    assignees: UserRef[];
    canAssign: boolean;
}>();

const open = defineModel<boolean>('open', { default: false });

const form = useForm<{
    category: IncidentCategory;
    severity: Severity;
    description: string;
    client_ref: string;
    department_id: string | number | null;
    assignee_id: string | number | null;
}>({
    category: 'damage',
    severity: defaultSeverity('damage'),
    description: '',
    client_ref: '',
    department_id: null,
    assignee_id: null,
});

const severityTouched = ref(false);
const preview = ref<RoutingPreview | null>(null);

watch(open, (value) => {
    if (value) {
        form.reset();
        form.clearErrors();
        severityTouched.value = false;
        void loadPreview();
    }
});

watch(
    () => form.category,
    (category) => {
        if (!severityTouched.value) {
            form.severity = defaultSeverity(category);
        }
    },
);

const severity = computed({
    get: () => form.severity,
    set: (value: Severity) => {
        severityTouched.value = true;
        form.severity = value;
    },
});

async function loadPreview(): Promise<void> {
    try {
        preview.value = await jsonRequest<RoutingPreview>(
            withQuery(endpoints.departments.preview().url, {
                category: form.category,
                department_id: form.department_id,
            }),
        );
    } catch {
        preview.value = null;
    }
}

watchDebounced(
    () => [form.category, form.department_id],
    () => {
        if (open.value) {
            void loadPreview();
        }
    },
    { debounce: 200 },
);

const MAX = 2000;

function submit(): void {
    form.transform((data) => ({
        category: data.category,
        severity: data.severity,
        description: data.description.trim(),
        client_ref: data.client_ref.trim() || null,
        department_id: data.department_id ? Number(data.department_id) : null,
        assignee_id: data.assignee_id ? Number(data.assignee_id) : null,
    })).post(endpoints.incidents.store().url, {
        onSuccess: () => {
            open.value = false;
        },
    });
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="max-h-[92svh] overflow-y-auto sm:max-w-2xl">
            <DialogHeader>
                <DialogTitle>{{ t('ui.incidents.create_title') }}</DialogTitle>
                <DialogDescription>{{
                    t('ui.incidents.create_hint')
                }}</DialogDescription>
            </DialogHeader>
            <form class="grid gap-5" @submit.prevent="submit">
                <fieldset class="grid gap-2">
                    <legend class="mb-2 text-sm font-semibold">
                        {{ t('ui.incidents.fields.category') }}
                    </legend>
                    <div
                        role="radiogroup"
                        :aria-label="t('ui.incidents.fields.category')"
                        class="grid grid-cols-2 gap-2 sm:grid-cols-3"
                        data-test="incident-form-category"
                    >
                        <button
                            v-for="category in INCIDENT_CATEGORIES"
                            :key="category"
                            type="button"
                            role="radio"
                            :aria-checked="form.category === category"
                            :data-value="category"
                            :class="
                                cn(
                                    'press flex min-h-14 items-center gap-2 rounded-2xl border bg-surface px-3 text-left text-sm font-medium',
                                    form.category === category
                                        ? 'border-primary bg-primary-soft text-primary-strong'
                                        : 'hover:bg-surface-2',
                                )
                            "
                            @click="form.category = category"
                        >
                            <component
                                :is="categoryIcon[category]"
                                class="size-5 shrink-0"
                                aria-hidden="true"
                            />
                            {{ t(`incidents.category.${category}`) }}
                        </button>
                    </div>
                </fieldset>

                <div class="grid gap-2">
                    <Label>{{ t('ui.incidents.fields.severity') }}</Label>
                    <SegmentedControl
                        v-model="severity"
                        :label="t('ui.incidents.fields.severity')"
                        :options="[
                            {
                                value: 'low',
                                label: t('incidents.severity.low'),
                            },
                            {
                                value: 'medium',
                                label: t('incidents.severity.medium'),
                            },
                            {
                                value: 'high',
                                label: t('incidents.severity.high'),
                            },
                        ]"
                        class="self-start"
                        data-test="incident-form-severity"
                    />
                </div>

                <div class="grid gap-2">
                    <Label for="incident-description">{{
                        t('ui.incidents.fields.description')
                    }}</Label>
                    <Textarea
                        id="incident-description"
                        v-model="form.description"
                        rows="4"
                        :maxlength="MAX"
                        :aria-invalid="Boolean(form.errors.description)"
                        aria-describedby="incident-description-error"
                        data-test="incident-form-description"
                    />
                    <p
                        id="incident-description-error"
                        class="flex justify-between text-xs text-muted-foreground"
                    >
                        <span
                            v-if="form.errors.description"
                            class="inline-flex items-center gap-1 text-destructive"
                        >
                            <CircleAlert class="size-3.5" aria-hidden="true" />
                            {{ form.errors.description }}
                        </span>
                        <span v-else />
                        <span class="tabular-nums"
                            >{{ form.description.length }} / {{ MAX }}</span
                        >
                    </p>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="grid gap-2">
                        <Label for="incident-client">{{
                            t('ui.incidents.fields.client_ref')
                        }}</Label>
                        <Input
                            id="incident-client"
                            v-model="form.client_ref"
                            maxlength="120"
                        />
                        <p class="text-xs text-muted-foreground">
                            {{ t('ui.incidents.client_ref_hint') }}
                        </p>
                    </div>
                    <div class="grid gap-2">
                        <Label for="incident-department">{{
                            t('ui.common.department')
                        }}</Label>
                        <FilterSelect
                            id="incident-department"
                            v-model="form.department_id"
                            :label="t('ui.common.department')"
                            :all-label="t('ui.common.no_department')"
                            :options="
                                departments.map((d) => ({
                                    value: d.id,
                                    label: d.name,
                                }))
                            "
                            class="sm:w-full"
                        />
                    </div>
                </div>

                <div class="grid gap-2">
                    <Label for="incident-assignee">{{
                        t('ui.incidents.fields.assignee')
                    }}</Label>
                    <FilterSelect
                        v-if="canAssign"
                        id="incident-assignee"
                        v-model="form.assignee_id"
                        :label="t('ui.incidents.fields.assignee')"
                        :all-label="t('ui.incidents.by_routing')"
                        :options="
                            assignees.map((u) => ({
                                value: u.id,
                                label: u.name,
                            }))
                        "
                        class="sm:w-full"
                    />
                    <p
                        class="inline-flex items-center gap-1.5 text-sm text-muted-foreground"
                        aria-live="polite"
                    >
                        <Route class="size-4" aria-hidden="true" />
                        {{
                            preview?.user
                                ? t('ui.incidents.will_assign', {
                                      name: preview.user.name,
                                  })
                                : t('ui.incidents.will_assign_unknown')
                        }}
                    </p>
                </div>

                <DialogFooter>
                    <Button
                        type="button"
                        variant="ghost"
                        @click="open = false"
                        >{{ t('ui.common.cancel') }}</Button
                    >
                    <Button
                        type="submit"
                        :disabled="
                            form.processing ||
                            form.description.trim().length < 3
                        "
                        data-test="incident-form-submit"
                    >
                        <Spinner v-if="form.processing" />
                        {{ t('ui.incidents.create') }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
