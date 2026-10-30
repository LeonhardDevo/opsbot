<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { ExternalLink, FileCheck } from '@lucide/vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { endpoints } from '@/lib/endpoints';
import { t } from '@/lib/i18n';
import { legalDocIcon } from '@/lib/legal';
import { toUrl } from '@/lib/utils';
import type { PendingAcceptance } from '@/types/legal';

withDefaults(defineProps<{ pending?: PendingAcceptance[] }>(), {
    pending: () => [],
});

const form = useForm({ accept: false });

function submit(): void {
    if (!form.accept) {
        return;
    }

    form.submit(endpoints.legalPages.acceptStore());
}
</script>

<template>
    <Head :title="t('ui.legal.accept.title')" />

    <div class="mx-auto w-full max-w-2xl flex-1 py-6 sm:py-10">
        <section
            class="rounded-dialog border bg-surface p-5 shadow-clay-lg sm:p-8"
            aria-labelledby="legal-accept-title"
            data-test="legal-accept"
        >
            <div class="flex items-start gap-4">
                <span
                    class="inline-flex size-12 shrink-0 items-center justify-center rounded-2xl bg-mint-soft text-mint-strong shadow-clay-sm"
                    aria-hidden="true"
                >
                    <FileCheck class="size-6" />
                </span>
                <div class="min-w-0 space-y-1.5">
                    <h1 id="legal-accept-title" class="text-display-m">
                        {{ t('ui.legal.accept.title') }}
                    </h1>
                    <p class="text-[0.9375rem] text-pretty text-muted-foreground">
                        {{ t('ui.legal.accept.description') }}
                    </p>
                </div>
            </div>

            <ul class="mt-6 grid gap-3">
                <li
                    v-for="doc in pending"
                    :key="doc.key"
                    class="rounded-card border bg-surface p-4"
                    :data-test="`legal-accept-doc-${doc.key}`"
                >
                    <div class="flex items-start gap-3">
                        <span
                            class="inline-flex size-9 shrink-0 items-center justify-center rounded-xl bg-primary-soft text-primary-strong"
                            aria-hidden="true"
                        >
                            <component
                                :is="legalDocIcon(doc.key)"
                                class="size-[1.125rem]"
                            />
                        </span>
                        <div class="min-w-0 flex-1">
                            <div
                                class="flex flex-wrap items-center justify-between gap-x-3 gap-y-1"
                            >
                                <p class="font-semibold">{{ doc.title }}</p>
                                <span
                                    class="inline-flex h-6 items-center rounded-chip bg-surface-2 px-2 font-mono text-xs text-muted-foreground"
                                    >{{
                                        t('ui.legal.accept.version', {
                                            version: doc.version,
                                        })
                                    }}</span
                                >
                            </div>
                            <p
                                v-if="doc.workspace"
                                class="text-caption mt-0.5"
                            >
                                {{
                                    t('ui.legal.accept.for_workspace', {
                                        name: doc.workspace,
                                    })
                                }}
                            </p>
                            <div
                                v-if="doc.summary_of_changes"
                                class="mt-3 rounded-xl border-l-[3px] border-mint-strong bg-mint-soft px-3 py-2 text-sm"
                            >
                                <p class="font-semibold text-mint-strong">
                                    {{ t('ui.legal.accept.changes') }}
                                </p>
                                <p class="mt-0.5 text-pretty">
                                    {{ doc.summary_of_changes }}
                                </p>
                            </div>
                            <a
                                :href="
                                    doc.url ?? toUrl(endpoints.legal(doc.key))
                                "
                                target="_blank"
                                rel="noopener"
                                class="mt-2 inline-flex min-h-11 items-center gap-1.5 text-sm font-semibold text-primary hover:text-primary-strong"
                            >
                                {{ t('ui.legal.accept.read') }}
                                <ExternalLink
                                    class="size-3.5"
                                    aria-hidden="true"
                                />
                                <span class="sr-only">{{
                                    t('ui.common.opens_new_tab')
                                }}</span>
                            </a>
                        </div>
                    </div>
                </li>
            </ul>

            <form class="mt-6 grid gap-4" @submit.prevent="submit">
                <Label
                    for="legal-accept-agree"
                    class="flex items-start gap-3 rounded-card border bg-surface-2 p-4 leading-snug font-normal"
                >
                    <Checkbox
                        id="legal-accept-agree"
                        v-model="form.accept"
                        class="mt-0.5"
                        data-test="legal-accept-checkbox"
                    />
                    <span class="text-[0.9375rem]">{{
                        t('ui.legal.accept.agree')
                    }}</span>
                </Label>
                <InputError :message="form.errors.accept" />
                <Button
                    type="submit"
                    class="w-full"
                    :disabled="!form.accept || form.processing"
                    data-test="legal-accept-submit"
                >
                    <Spinner v-if="form.processing" />
                    {{ t('ui.legal.accept.submit') }}
                </Button>
                <p class="text-caption text-center text-pretty">
                    {{ t('ui.legal.accept.decline_hint') }}
                </p>
            </form>
        </section>
    </div>
</template>
