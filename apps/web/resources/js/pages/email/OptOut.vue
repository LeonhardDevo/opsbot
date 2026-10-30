<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { Mail, MailCheck, MailX, TriangleAlert } from '@lucide/vue';
import { computed, ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { endpoints } from '@/lib/endpoints';
import { t } from '@/lib/i18n';
import type { OptOutStatus } from '@/types/legal';

const props = withDefaults(
    defineProps<{
        status?: OptOutStatus;
        token?: string | null;
        email?: string | null;
        workspace?: string | null;
    }>(),
    { status: 'confirm', token: null, email: null, workspace: null },
);

const page = usePage();
const processing = ref(false);

const sender = computed(() => props.workspace || page.props.name);

const view = computed(() => {
    switch (props.status) {
        case 'done':
            return {
                icon: MailCheck,
                tone: 'bg-success-soft text-success',
                title: t('ui.email_opt_out.done_title'),
                body: t('ui.email_opt_out.done_body', { sender: sender.value }),
            };
        case 'invalid':
            return {
                icon: TriangleAlert,
                tone: 'bg-sun-soft text-sun-strong',
                title: t('ui.email_opt_out.invalid_title'),
                body: t('ui.email_opt_out.invalid_body'),
            };
        default:
            return {
                icon: MailX,
                tone: 'bg-primary-soft text-primary-strong',
                title: t('ui.email_opt_out.confirm_title'),
                body: t('ui.email_opt_out.confirm_body', {
                    sender: sender.value,
                }),
            };
    }
});

const kinds = computed(() =>
    ['notifications', 'reminders'].map((key) =>
        t(`ui.email_opt_out.kinds.${key}`),
    ),
);

function confirm(): void {
    router.post(
        page.url,
        {},
        {
            preserveScroll: true,
            onStart: () => (processing.value = true),
            onFinish: () => (processing.value = false),
        },
    );
}
</script>

<template>
    <Head :title="view.title" />

    <div class="mx-auto flex w-full max-w-lg flex-1 items-center py-8">
        <section
            class="w-full rounded-dialog border bg-surface p-6 shadow-clay-lg sm:p-8"
            aria-labelledby="opt-out-title"
            data-test="email-opt-out"
            :data-status="status"
        >
            <span
                :class="[
                    'inline-flex size-12 items-center justify-center rounded-2xl shadow-clay-sm',
                    view.tone,
                ]"
                aria-hidden="true"
            >
                <component :is="view.icon" class="size-6" />
            </span>
            <h1 id="opt-out-title" class="text-display-m mt-5">
                {{ view.title }}
            </h1>
            <p class="mt-2 text-[0.9375rem] text-pretty text-muted-foreground">
                {{ view.body }}
            </p>
            <p
                v-if="email && status !== 'invalid'"
                class="mt-4 inline-flex max-w-full items-center gap-1.5 rounded-chip bg-surface-2 px-3 py-1 font-mono text-[0.8125rem] [overflow-wrap:anywhere]"
            >
                <Mail
                    class="size-3.5 shrink-0 text-muted-foreground"
                    aria-hidden="true"
                />
                {{ email }}
            </p>

            <div v-if="status === 'confirm'" class="mt-6 grid gap-4">
                <div class="rounded-card bg-surface-2 p-4 text-sm">
                    <p class="font-semibold">
                        {{ t('ui.email_opt_out.kinds_title') }}
                    </p>
                    <ul class="mt-2 grid gap-1.5 text-muted-foreground">
                        <li
                            v-for="kind in kinds"
                            :key="kind"
                            class="flex items-start gap-2"
                        >
                            <span
                                class="mt-2 size-1.5 shrink-0 rounded-full bg-primary"
                                aria-hidden="true"
                            />
                            {{ kind }}
                        </li>
                    </ul>
                </div>
                <Button
                    class="w-full"
                    :disabled="processing"
                    data-test="email-opt-out-confirm"
                    @click="confirm"
                >
                    <Spinner v-if="processing" />
                    {{ t('ui.email_opt_out.confirm') }}
                </Button>
                <p class="text-caption text-pretty">
                    {{ t('ui.email_opt_out.confirm_hint') }}
                </p>
            </div>

            <p class="mt-6 border-t pt-4 text-caption text-pretty">
                {{ t('ui.email_opt_out.notice') }}
                <Link
                    :href="endpoints.legal('privacy')"
                    class="font-semibold text-primary underline underline-offset-2 hover:text-primary-strong"
                    >{{ t('ui.legal.privacy') }}</Link
                >
            </p>
        </section>
    </div>
</template>
