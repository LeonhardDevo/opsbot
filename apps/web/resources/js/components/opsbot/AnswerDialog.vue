<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { CircleAlert, SendHorizontal } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { endpoints } from '@/lib/endpoints';
import { t } from '@/lib/i18n';

const props = defineProps<{
    questionId: number | null;
    title: string;
    question: string | null;
}>();

const open = defineModel<boolean>('open', { default: false });
const emit = defineEmits<{ answered: [] }>();

const text = ref('');
const error = ref<string | null>(null);
const sending = ref(false);
const MAX = 2000;

watch(open, (value) => {
    if (value) {
        text.value = '';
        error.value = null;
    }
});

const valid = computed(() => {
    const length = text.value.trim().length;

    return length > 0 && length <= MAX;
});

function submit(): void {
    if (!props.questionId || !valid.value) {
        return;
    }

    sending.value = true;
    router.post(
        endpoints.unanswered.answer(props.questionId).url,
        { answer_text: text.value.trim() },
        {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                emit('answered');
                open.value = false;
            },
            onError: (errors) => {
                error.value = errors.answer_text ?? t('ui.errors.generic');
            },
            onFinish: () => (sending.value = false),
        },
    );
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="sm:max-w-lg" data-test="desk-answer-dialog">
            <DialogHeader>
                <DialogTitle>{{
                    t('ui.unanswered.answer_title', { number: title })
                }}</DialogTitle>
                <DialogDescription>{{
                    t('ui.unanswered.answer_hint')
                }}</DialogDescription>
            </DialogHeader>
            <blockquote
                v-if="question"
                class="rounded-2xl border-l-2 border-primary bg-surface-2 px-3 py-2 text-sm"
            >
                {{ question }}
            </blockquote>
            <form class="grid gap-2" @submit.prevent="submit">
                <Label for="desk-answer">{{
                    t('ui.unanswered.answer_label', { number: title })
                }}</Label>
                <Textarea
                    id="desk-answer"
                    v-model="text"
                    rows="5"
                    :maxlength="MAX"
                    :aria-invalid="Boolean(error)"
                    aria-describedby="desk-answer-hint"
                    data-test="unanswered-answer-input"
                />
                <p
                    id="desk-answer-hint"
                    class="flex justify-between text-xs text-muted-foreground"
                >
                    <span
                        v-if="error"
                        class="inline-flex items-center gap-1 text-destructive"
                    >
                        <CircleAlert class="size-3.5" aria-hidden="true" />
                        {{ error }}
                    </span>
                    <span v-else>{{ t('ui.unanswered.not_from_rules') }}</span>
                    <span class="tabular-nums"
                        >{{ text.length }} / {{ MAX }}</span
                    >
                </p>
                <DialogFooter>
                    <Button
                        type="button"
                        variant="ghost"
                        @click="open = false"
                        >{{ t('ui.common.cancel') }}</Button
                    >
                    <Button
                        type="submit"
                        :disabled="!valid || sending"
                        data-test="unanswered-answer-submit"
                    >
                        <Spinner v-if="sending" />
                        <SendHorizontal
                            v-else
                            class="size-4"
                            aria-hidden="true"
                        />
                        {{ t('ui.unanswered.answer') }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
