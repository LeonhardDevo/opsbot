<script setup lang="ts">
import { ref, watch } from 'vue';
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
import { t } from '@/lib/i18n';

const props = withDefaults(
    defineProps<{
        title: string;
        description?: string | null;
        required?: boolean;
        processing?: boolean;
        confirmLabel?: string | null;
    }>(),
    {
        description: null,
        required: false,
        processing: false,
        confirmLabel: null,
    },
);

const open = defineModel<boolean>('open', { default: false });
const emit = defineEmits<{ confirm: [comment: string] }>();

const comment = ref('');

watch(open, (value) => {
    if (value) {
        comment.value = '';
    }
});

function submit(): void {
    if (props.required && !comment.value.trim()) {
        return;
    }

    emit('confirm', comment.value.trim());
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle>{{ title }}</DialogTitle>
                <DialogDescription v-if="description">{{
                    description
                }}</DialogDescription>
            </DialogHeader>
            <form class="grid gap-2" @submit.prevent="submit">
                <Label for="transition-comment">{{
                    required
                        ? t('ui.incidents.comment_required')
                        : t('ui.incidents.comment_optional')
                }}</Label>
                <Textarea
                    id="transition-comment"
                    v-model="comment"
                    rows="3"
                    maxlength="2000"
                    :required="required"
                />
                <DialogFooter class="pt-2">
                    <Button
                        type="button"
                        variant="ghost"
                        @click="open = false"
                        >{{ t('ui.common.cancel') }}</Button
                    >
                    <Button
                        type="submit"
                        :disabled="processing || (required && !comment.trim())"
                    >
                        <Spinner v-if="processing" />
                        {{ confirmLabel ?? t('ui.common.confirm') }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
