<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { CircleAlert, Eye, EyeOff } from '@lucide/vue';
import { computed, ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useReducedMotion } from '@/composables/useReducedMotion';
import { endpoints } from '@/lib/endpoints';
import { t } from '@/lib/i18n';
import type { BotErrorCode } from '@/types/opsbot';

const props = withDefaults(
    defineProps<{
        mode?: 'connect' | 'rotate';
        lastError?: BotErrorCode | null;
        driver?: 'auto' | 'fake';
        inputTest?: string;
        submitTest?: string;
        submitLabel?: string | null;
        only?: string[];
    }>(),
    {
        mode: 'connect',
        lastError: null,
        driver: 'auto',
        inputTest: 'bot-token-input',
        submitTest: 'bot-connect',
        submitLabel: null,
        only: undefined,
    },
);

const emit = defineEmits<{ submit: [token: string]; done: [] }>();

const reduced = useReducedMotion();
const token = ref('');
const visible = ref(false);
const sending = ref(false);
const fieldError = ref<string | null>(null);

const TOKEN_PATTERN = /^\d{5,16}:[A-Za-z0-9_-]{30,64}$/;

const serverError = computed(() => {
    if (fieldError.value) {
        return { code: 'invalid_token', message: fieldError.value };
    }

    if (props.lastError) {
        return {
            code: props.lastError,
            message: t(`bot.errors.${props.lastError}`),
        };
    }

    return null;
});

function submit(): void {
    const value = token.value.trim();

    if (!TOKEN_PATTERN.test(value)) {
        fieldError.value = t('ui.bot_page.token_format');

        return;
    }

    fieldError.value = null;
    sending.value = true;
    emit('submit', value);

    const url =
        props.mode === 'rotate'
            ? endpoints.bot.rotate().url
            : endpoints.bot.connect().url;
    const options = {
        preserveScroll: true,
        only: props.only,
        onError: (errors: Record<string, string>) => {
            fieldError.value = errors.token ?? null;
        },
        onSuccess: () => {
            token.value = '';
            emit('done');
        },
        onFinish: () => (sending.value = false),
    };

    if (props.mode === 'rotate') {
        router.patch(url, { token: value }, options);
    } else {
        router.post(url, { token: value }, options);
    }
}
</script>

<template>
    <form class="grid gap-3" autocomplete="off" @submit.prevent="submit">
        <Label :for="`bot-token-${mode}`">{{
            t('ui.bot_page.token_label')
        }}</Label>
        <div class="flex gap-2">
            <div class="relative min-w-0 flex-1">
                <Input
                    :id="`bot-token-${mode}`"
                    v-model="token"
                    :type="visible ? 'text' : 'password'"
                    class="pr-12 font-mono"
                    autocomplete="off"
                    spellcheck="false"
                    autocapitalize="off"
                    placeholder="123456789:AA…"
                    :aria-invalid="Boolean(serverError)"
                    aria-describedby="bot-token-feedback"
                    :data-test="inputTest"
                />
                <button
                    type="button"
                    class="absolute inset-y-0 right-0 inline-flex w-11 items-center justify-center rounded-r-[14px] text-muted-foreground hover:text-foreground"
                    :aria-label="
                        visible
                            ? t('ui.bot_page.hide_token')
                            : t('ui.bot_page.show_token')
                    "
                    :aria-pressed="visible"
                    @click="visible = !visible"
                >
                    <EyeOff v-if="visible" class="size-4" aria-hidden="true" />
                    <Eye v-else class="size-4" aria-hidden="true" />
                </button>
            </div>
            <Button
                type="submit"
                :variant="mode === 'rotate' ? 'secondary' : 'default'"
                :disabled="sending || !token.trim()"
                :data-test="submitTest"
            >
                <Spinner v-if="sending && !reduced" />
                {{
                    sending
                        ? t('ui.bot_page.checking')
                        : (submitLabel ??
                          (mode === 'rotate'
                              ? t('ui.bot_page.rotate')
                              : t('ui.bot_page.connect')))
                }}
            </Button>
        </div>
        <div id="bot-token-feedback" aria-live="polite" class="text-sm">
            <p v-if="sending" class="text-muted-foreground">
                {{ t('ui.bot_page.checking_long') }}
            </p>
            <p
                v-else-if="serverError"
                class="flex items-start gap-1.5 text-destructive"
                data-test="bot-error"
                :data-code="serverError.code"
            >
                <CircleAlert
                    class="mt-0.5 size-4 shrink-0"
                    aria-hidden="true"
                />
                <span>
                    {{ serverError.message }}
                    <span
                        v-if="!fieldError"
                        class="block text-muted-foreground"
                        >{{ t(`ui.bot_page.hints.${serverError.code}`) }}</span
                    >
                </span>
            </p>
            <p v-else-if="driver === 'fake'" class="text-muted-foreground">
                {{ t('ui.bot_page.fake_hint') }}
            </p>
        </div>
    </form>
</template>
