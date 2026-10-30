<script setup lang="ts">
import { useForm, usePage } from '@inertiajs/vue3';
import { CircleAlert, Moon } from '@lucide/vue';
import { computed, watch } from 'vue';
import FilterSelect from '@/components/opsbot/FilterSelect.vue';
import { Button } from '@/components/ui/button';
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
import { Textarea } from '@/components/ui/textarea';
import { endpoints } from '@/lib/endpoints';
import { t } from '@/lib/i18n';
import { toLocalInput, zoneOffsetLabel } from '@/lib/opsbot';
import type { DepartmentRef, Task, UserRef } from '@/types/opsbot';

const props = withDefaults(
    defineProps<{
        task: Task | null;
        incidentId?: number | null;
        assignees: UserRef[];
        departments: DepartmentRef[];
        canAssign: boolean;
        canManage: boolean;
    }>(),
    { incidentId: null },
);

const open = defineModel<boolean>('open', { default: false });

const page = usePage();
const timezone = computed(() => page.props.workspace?.timezone ?? 'UTC');

type TaskForm = {
    title: string;
    description: string;
    due_at: string;
    assignee_id: string | number | null;
    department_id: string | number | null;
};

const form = useForm<TaskForm>({
    title: '',
    description: '',
    due_at: '',
    assignee_id: null,
    department_id: null,
});

watch(open, (value) => {
    if (!value) {
        return;
    }

    form.clearErrors();
    form.title = props.task?.title ?? '';
    form.description = props.task?.description ?? '';
    form.due_at = toLocalInput(props.task?.due_at ?? null, timezone.value);
    form.assignee_id = props.task?.assignee?.id ?? null;
    form.department_id = props.task?.department?.id ?? null;
});

const editing = computed(() => props.task !== null);

function submit(): void {
    const payload = (data: TaskForm) => ({
        title: data.title.trim(),
        description: data.description.trim() || null,
        due_at: data.due_at || null,
        assignee_id: data.assignee_id ? Number(data.assignee_id) : null,
        ...(editing.value || props.incidentId
            ? {}
            : {
                  department_id: data.department_id
                      ? Number(data.department_id)
                      : null,
              }),
    });
    const options = {
        preserveScroll: true,
        onSuccess: () => {
            open.value = false;
        },
    };

    if (props.task) {
        form.transform(payload).patch(
            endpoints.tasks.update(props.task.id).url,
            options,
        );
    } else if (props.incidentId) {
        form.transform(payload).post(
            endpoints.incidents.createTask(props.incidentId).url,
            options,
        );
    } else {
        form.transform(payload).post(endpoints.tasks.store().url, options);
    }
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
                        ? t('ui.tasks.edit_title', {
                              number: task?.display_number ?? '',
                          })
                        : t('ui.tasks.create_title')
                }}</SheetTitle>
                <SheetDescription>{{
                    t('ui.tasks.form_hint')
                }}</SheetDescription>
            </SheetHeader>
            <form
                id="task-form"
                class="grid gap-5 px-5 py-4"
                @submit.prevent="submit"
            >
                <div class="grid gap-2">
                    <Label for="task-title">{{
                        t('ui.tasks.fields.title')
                    }}</Label>
                    <Input
                        id="task-title"
                        v-model="form.title"
                        maxlength="160"
                        required
                        :aria-invalid="Boolean(form.errors.title)"
                        data-test="task-form-title"
                    />
                    <p
                        v-if="form.errors.title"
                        class="inline-flex items-center gap-1 text-xs text-destructive"
                    >
                        <CircleAlert class="size-3.5" aria-hidden="true" />
                        {{ form.errors.title }}
                    </p>
                </div>
                <div v-if="!incidentId" class="grid gap-2">
                    <Label for="task-description">{{
                        t('ui.tasks.fields.description')
                    }}</Label>
                    <Textarea
                        id="task-description"
                        v-model="form.description"
                        rows="3"
                        maxlength="2000"
                    />
                </div>
                <div class="grid gap-2">
                    <Label for="task-due">{{ t('ui.tasks.fields.due') }}</Label>
                    <Input
                        id="task-due"
                        v-model="form.due_at"
                        type="datetime-local"
                        aria-describedby="task-due-hint"
                        data-test="task-form-due"
                    />
                    <p
                        id="task-due-hint"
                        class="space-y-1 text-xs text-muted-foreground"
                    >
                        <span class="block">{{
                            t('ui.tasks.timezone', {
                                zone: timezone,
                                offset: zoneOffsetLabel(timezone),
                            })
                        }}</span>
                        <span class="flex items-start gap-1">
                            <Moon
                                class="mt-0.5 size-3.5 shrink-0"
                                aria-hidden="true"
                            />
                            {{ t('ui.tasks.reminder_rule') }}
                        </span>
                    </p>
                    <p
                        v-if="form.errors.due_at"
                        class="text-xs text-destructive"
                    >
                        {{ form.errors.due_at }}
                    </p>
                </div>
                <div v-if="canAssign" class="grid gap-2">
                    <Label for="task-assignee">{{
                        t('ui.tasks.fields.assignee')
                    }}</Label>
                    <FilterSelect
                        id="task-assignee"
                        v-model="form.assignee_id"
                        :label="t('ui.tasks.fields.assignee')"
                        :all-label="t('ui.tasks.by_routing')"
                        :options="
                            assignees.map((u) => ({
                                value: u.id,
                                label: u.name,
                            }))
                        "
                        class="sm:w-full"
                        test-id="task-form-assignee"
                    />
                    <p
                        v-if="form.errors.assignee_id"
                        class="text-xs text-destructive"
                    >
                        {{ form.errors.assignee_id }}
                    </p>
                </div>
                <div
                    v-if="!editing && !incidentId && departments.length"
                    class="grid gap-2"
                >
                    <Label for="task-department">{{
                        t('ui.common.department')
                    }}</Label>
                    <FilterSelect
                        id="task-department"
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
                <p
                    class="rounded-2xl bg-surface-2 px-3 py-2 text-xs text-muted-foreground"
                >
                    {{ t('ui.tasks.delivery_note') }}
                </p>
            </form>
            <SheetFooter class="border-t px-5 py-4">
                <Button type="button" variant="ghost" @click="open = false">{{
                    t('ui.common.cancel')
                }}</Button>
                <Button
                    type="submit"
                    form="task-form"
                    :disabled="form.processing || form.title.trim().length < 3"
                    data-test="task-form-submit"
                >
                    <Spinner v-if="form.processing" />
                    {{ editing ? t('ui.common.save') : t('ui.tasks.create') }}
                </Button>
            </SheetFooter>
        </SheetContent>
    </Sheet>
</template>
