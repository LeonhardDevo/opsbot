<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
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
import { endpoints } from '@/lib/endpoints';
import { t } from '@/lib/i18n';
import type { Department, UserRef } from '@/types/opsbot';

const props = defineProps<{
    department: Department | null;
    managers: UserRef[];
}>();

const open = defineModel<boolean>('open', { default: false });

type DepartmentForm = {
    name: string;
    code: string;
    manager_user_id: string | number | null;
};

const form = useForm<DepartmentForm>({
    name: '',
    code: '',
    manager_user_id: null,
});

watch(open, (value) => {
    if (value) {
        form.clearErrors();
        form.name = props.department?.name ?? '';
        form.code = props.department?.code ?? '';
        form.manager_user_id = props.department?.manager?.id ?? null;
    }
});

const editing = computed(() => props.department !== null);

function submit(): void {
    const payload = (data: DepartmentForm) => ({
        name: data.name.trim(),
        code: data.code.trim() || null,
        manager_user_id: data.manager_user_id
            ? Number(data.manager_user_id)
            : null,
    });
    const options = {
        preserveScroll: true,
        onSuccess: () => (open.value = false),
    };

    if (props.department) {
        form.transform(payload).patch(
            endpoints.departments.update(props.department.id).url,
            options,
        );
    } else {
        form.transform(payload).post(
            endpoints.departments.store().url,
            options,
        );
    }
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle>{{
                    editing
                        ? t('ui.departments.edit_title')
                        : t('ui.departments.create_title')
                }}</DialogTitle>
                <DialogDescription>{{
                    t('ui.departments.form_hint')
                }}</DialogDescription>
            </DialogHeader>
            <form class="grid gap-4" @submit.prevent="submit">
                <div class="grid gap-2">
                    <Label for="department-name">{{
                        t('ui.departments.fields.name')
                    }}</Label>
                    <Input
                        id="department-name"
                        v-model="form.name"
                        required
                        maxlength="120"
                        data-test="department-form-name"
                    />
                    <p v-if="form.errors.name" class="text-xs text-destructive">
                        {{ form.errors.name }}
                    </p>
                </div>
                <div class="grid gap-2">
                    <Label for="department-code">{{
                        t('ui.departments.fields.code')
                    }}</Label>
                    <Input
                        id="department-code"
                        v-model="form.code"
                        maxlength="40"
                        class="font-mono"
                        pattern="[a-z0-9][a-z0-9-]*"
                        aria-describedby="department-code-hint"
                    />
                    <p
                        id="department-code-hint"
                        class="text-xs text-muted-foreground"
                    >
                        {{ form.errors.code ?? t('ui.departments.code_hint') }}
                    </p>
                </div>
                <div class="grid gap-2">
                    <Label for="department-manager">{{
                        t('ui.departments.fields.manager')
                    }}</Label>
                    <FilterSelect
                        id="department-manager"
                        v-model="form.manager_user_id"
                        :label="t('ui.departments.fields.manager')"
                        :all-label="t('ui.departments.no_manager')"
                        :options="
                            managers.map((u) => ({
                                value: u.id,
                                label: u.name,
                            }))
                        "
                        class="sm:w-full"
                        test-id="department-form-manager"
                    />
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
                            form.processing || form.name.trim().length < 2
                        "
                        data-test="department-form-submit"
                    >
                        <Spinner v-if="form.processing" />
                        {{
                            editing
                                ? t('ui.common.save')
                                : t('ui.departments.create')
                        }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
