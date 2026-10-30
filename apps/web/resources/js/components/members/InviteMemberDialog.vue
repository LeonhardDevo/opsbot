<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { MailPlus } from '@lucide/vue';
import { computed, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import ChipToggleGroup from '@/components/common/ChipToggleGroup.vue';
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
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { endpoints } from '@/lib/endpoints';
import { t, tOr } from '@/lib/i18n';
import type { SelectOption } from '@/types';
import type { DepartmentRef } from '@/types/opsbot';

const props = withDefaults(
    defineProps<{ roles: SelectOption[]; departments?: DepartmentRef[] }>(),
    { departments: () => [] },
);
const open = defineModel<boolean>('open', { default: false });

const defaultRole = (): string =>
    props.roles.find((role) => role.value === 'manager')?.value ??
    props.roles.at(-1)?.value ??
    'manager';

const form = useForm({
    email: '',
    role: defaultRole(),
    department_ids: [] as number[],
});

const departmentOptions = computed<SelectOption[]>(() =>
    props.departments.map((department) => ({
        value: String(department.id),
        label: department.name,
    })),
);

const departmentModel = computed({
    get: () => form.department_ids.map(String),
    set: (values: string[]) => {
        form.department_ids = values.map(Number);
    },
});

const needsDepartments = computed(
    () => form.role === 'manager' || form.role === 'employee',
);

watch(open, (value) => {
    if (!value) {
        form.reset();
        form.clearErrors();
    }
});

function submit(): void {
    form.transform((data) => ({
        ...data,
        department_ids: needsDepartments.value ? data.department_ids : [],
    })).post(endpoints.settings.members.invite().url, {
        preserveScroll: true,
        onSuccess: () => {
            open.value = false;
        },
    });
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="sm:max-w-md">
            <form class="space-y-6" @submit.prevent="submit">
                <DialogHeader>
                    <div
                        class="mb-1 inline-flex size-11 items-center justify-center rounded-xl bg-primary-soft text-primary-soft-foreground"
                        aria-hidden="true"
                    >
                        <MailPlus class="size-5" />
                    </div>
                    <DialogTitle>{{
                        t('ui.members.invite_title')
                    }}</DialogTitle>
                    <DialogDescription>{{
                        t('ui.members.invite_body')
                    }}</DialogDescription>
                </DialogHeader>

                <div class="grid gap-2">
                    <Label for="invite-email">{{ t('ui.fields.email') }}</Label>
                    <Input
                        id="invite-email"
                        v-model="form.email"
                        name="email"
                        v-focus
                        type="email"
                        required
                        autocomplete="off"
                        placeholder="name@company.com"
                        :aria-invalid="Boolean(form.errors.email)"
                    />
                    <InputError :message="form.errors.email" />
                </div>

                <div class="grid gap-2">
                    <Label for="invite-role">{{ t('ui.members.role') }}</Label>
                    <Select v-model="form.role" name="role">
                        <SelectTrigger id="invite-role" class="w-full">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="role in roles"
                                :key="role.value"
                                :value="role.value"
                            >
                                {{ tOr(`roles.${role.value}`, role.label) }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <p class="text-xs text-muted-foreground">
                        {{ tOr(`ui.members.role_hints.${form.role}`, '') }}
                    </p>
                    <InputError :message="form.errors.role" />
                </div>

                <div
                    v-if="needsDepartments && departmentOptions.length"
                    class="grid gap-2"
                    data-test="invite-departments"
                >
                    <span class="text-sm font-medium">{{
                        t('ui.members.departments')
                    }}</span>
                    <ChipToggleGroup
                        v-model="departmentModel"
                        :options="departmentOptions"
                        :label="t('ui.members.departments')"
                    />
                    <p class="text-xs text-muted-foreground">
                        {{ t('ui.members.departments_hint') }}
                    </p>
                    <InputError :message="form.errors.department_ids" />
                </div>

                <DialogFooter class="gap-2">
                    <Button
                        type="button"
                        variant="outline"
                        @click="open = false"
                    >
                        {{ t('ui.common.cancel') }}
                    </Button>
                    <Button
                        type="submit"
                        :disabled="form.processing"
                        data-test="invite-submit"
                    >
                        <Spinner v-if="form.processing" />
                        {{ t('ui.members.send_invite') }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
