<script setup lang="ts">
import { SendHorizontal, Square } from '@lucide/vue';
import { computed, nextTick, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import { t } from '@/lib/i18n';

const props = withDefaults(
    defineProps<{
        busy?: boolean;
        maxLength?: number;
        placeholder?: string | null;
    }>(),
    { busy: false, maxLength: 2000, placeholder: null },
);

const emit = defineEmits<{ send: [message: string]; stop: [] }>();

const text = defineModel<string>({ default: '' });
const textarea = ref<HTMLTextAreaElement | null>(null);

const canSend = computed(
    () =>
        !props.busy &&
        text.value.trim().length > 0 &&
        text.value.length <= props.maxLength,
);
const nearLimit = computed(() => text.value.length > props.maxLength - 500);

function resize(): void {
    const el = textarea.value;

    if (!el) {
        return;
    }

    el.style.height = 'auto';
    el.style.height = `${Math.min(el.scrollHeight, 168)}px`;
}

watch(text, () => nextTick(resize));

function submit(): void {
    if (!canSend.value) {
        return;
    }

    emit('send', text.value.trim());
}

function onKeydown(event: KeyboardEvent): void {
    if (event.key === 'Enter' && !event.shiftKey && !event.isComposing) {
        event.preventDefault();
        submit();
    }
}

defineExpose({ focus: () => textarea.value?.focus() });
</script>

<template>
    <form
        class="relative rounded-card border border-input bg-surface shadow-clay transition-shadow focus-within:border-ring focus-within:outline-3 focus-within:outline-offset-2 focus-within:outline-ring"
        @submit.prevent="submit"
    >
        <label for="assistant-input" class="sr-only">{{
            t('ui.assistant.input_label')
        }}</label>
        <textarea
            id="assistant-input"
            ref="textarea"
            v-model="text"
            name="message"
            rows="1"
            :maxlength="maxLength + 200"
            :placeholder="placeholder ?? t('ui.assistant.placeholder')"
            class="block max-h-[168px] w-full resize-none bg-transparent px-4 pt-3.5 pb-14 text-base outline-none placeholder:text-muted-foreground"
            data-test="assistant-input"
            @keydown="onKeydown"
        />
        <div
            class="absolute inset-x-3 bottom-2 flex items-center justify-between gap-2"
        >
            <p class="text-xs text-muted-foreground">
                <span class="hidden sm:inline">{{
                    t('ui.assistant.hint_keys')
                }}</span>
                <span
                    v-if="nearLimit"
                    :class="[
                        'ml-2 tabular-nums',
                        text.length > maxLength
                            ? 'font-semibold text-destructive'
                            : '',
                    ]"
                    aria-live="polite"
                    >{{ text.length }}/{{ maxLength }}</span
                >
            </p>
            <Button
                v-if="busy"
                type="button"
                size="icon"
                variant="secondary"
                :aria-label="t('ui.assistant.stop')"
                @click="emit('stop')"
            >
                <Square class="size-3.5 fill-current" aria-hidden="true" />
            </Button>
            <Button
                v-else
                type="submit"
                size="icon"
                :disabled="!canSend"
                :aria-label="t('ui.assistant.send')"
                data-test="assistant-send"
            >
                <SendHorizontal class="size-5" aria-hidden="true" />
            </Button>
        </div>
    </form>
</template>
