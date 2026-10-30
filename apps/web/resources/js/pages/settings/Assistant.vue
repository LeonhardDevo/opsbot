<script setup lang="ts">
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { CircleAlert, Gauge, Moon, ShieldCheck } from '@lucide/vue';
import { computed } from 'vue';
import SectionCard from '@/components/common/SectionCard.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { Switch } from '@/components/ui/switch';
import { useFormat } from '@/composables/useFormat';
import { endpoints } from '@/lib/endpoints';
import { t } from '@/lib/i18n';
import { isValidTime, quietMinutes } from '@/lib/opsbot';
import type { OpsSettings } from '@/types/opsbot';

const props = defineProps<{
    settings: OpsSettings;
    refusalRate30d: number | null;
}>();

const page = usePage();
const fmt = useFormat();

const form = useForm<OpsSettings>({ ...props.settings });

const DEFAULT = 0.4;
const MIN = 0.2;
const MAX = 0.8;

const threshold = computed({
    get: () => form.min_confidence,
    set: (value: number) => {
        form.min_confidence = Math.round(value * 100) / 100;
    },
});

const timesValid = computed(
    () =>
        isValidTime(form.quiet_hours_start) &&
        isValidTime(form.quiet_hours_end) &&
        form.quiet_hours_start !== form.quiet_hours_end,
);

const quietRanges = computed(() =>
    timesValid.value
        ? quietMinutes(form.quiet_hours_start, form.quiet_hours_end)
        : [],
);

const ticks = [0, 6, 12, 18, 24];

function save(): void {
    form.patch(endpoints.settings.assistant.update().url, {
        preserveScroll: true,
        onSuccess: () => form.defaults(),
    });
}

function discard(): void {
    form.reset();
    form.clearErrors();
}

const timezone = computed(() => page.props.workspace?.timezone ?? 'UTC');
</script>

<template>
    <Head :title="t('ui.assistant_settings.title')" />

    <div class="flex max-w-[720px] flex-col gap-6 pb-24">
        <header class="space-y-1">
            <h1 class="text-h1">{{ t('ui.assistant_settings.title') }}</h1>
            <p class="text-sm text-muted-foreground">
                {{ t('ui.assistant_settings.subtitle') }}
            </p>
        </header>

        <SectionCard
            :title="t('ui.assistant_settings.threshold_title')"
            :description="t('ui.assistant_settings.threshold_body')"
            :icon="Gauge"
        >
            <div class="grid gap-3">
                <div class="flex items-center justify-between">
                    <Label for="threshold">{{
                        t('ui.assistant_settings.threshold_label')
                    }}</Label>
                    <span class="font-mono text-sm font-semibold tabular-nums">
                        {{ threshold.toFixed(2) }}
                        <span
                            v-if="threshold === DEFAULT"
                            class="font-sans text-xs font-medium text-muted-foreground"
                            >— {{ t('ui.assistant_settings.default') }}</span
                        >
                    </span>
                </div>
                <div class="relative">
                    <input
                        id="threshold"
                        v-model.number="threshold"
                        type="range"
                        :min="MIN"
                        :max="MAX"
                        step="0.05"
                        class="h-11 w-full cursor-pointer accent-[var(--primary)]"
                        :aria-valuetext="threshold.toFixed(2)"
                        data-test="assistant-settings-threshold"
                    />
                    <span
                        class="pointer-events-none absolute bottom-0 h-2 w-0.5 rounded-full bg-muted-foreground"
                        :style="{
                            left: `calc(${((DEFAULT - MIN) / (MAX - MIN)) * 100}% - 1px)`,
                        }"
                        aria-hidden="true"
                    />
                </div>
                <div class="flex justify-between text-xs text-muted-foreground">
                    <span>{{ t('ui.assistant_settings.answers_more') }}</span>
                    <span>{{ t('ui.assistant_settings.more_careful') }}</span>
                </div>
                <p
                    class="rounded-2xl bg-surface-2 px-3 py-2 text-sm"
                    data-test="assistant-settings-refusal-rate"
                >
                    {{
                        refusalRate30d === null
                            ? t('ui.assistant_settings.refusal_none')
                            : t('ui.assistant_settings.refusal_rate', {
                                  percent: fmt.percent(refusalRate30d),
                              })
                    }}
                </p>
                <p
                    v-if="form.errors.min_confidence"
                    class="inline-flex items-center gap-1 text-xs text-destructive"
                >
                    <CircleAlert class="size-3.5" aria-hidden="true" />
                    {{ form.errors.min_confidence }}
                </p>
            </div>
        </SectionCard>

        <SectionCard
            :title="t('ui.assistant_settings.quiet_title')"
            :description="
                t('ui.assistant_settings.quiet_body', { zone: timezone })
            "
            :icon="Moon"
        >
            <div class="grid gap-4">
                <div class="grid grid-cols-2 gap-4">
                    <div class="grid gap-2">
                        <Label for="quiet-start">{{
                            t('ui.assistant_settings.quiet_start')
                        }}</Label>
                        <Input
                            id="quiet-start"
                            v-model="form.quiet_hours_start"
                            type="time"
                            step="300"
                            data-test="assistant-settings-quiet-start"
                        />
                    </div>
                    <div class="grid gap-2">
                        <Label for="quiet-end">{{
                            t('ui.assistant_settings.quiet_end')
                        }}</Label>
                        <Input
                            id="quiet-end"
                            v-model="form.quiet_hours_end"
                            type="time"
                            step="300"
                            data-test="assistant-settings-quiet-end"
                        />
                    </div>
                </div>
                <p
                    v-if="!timesValid"
                    class="inline-flex items-center gap-1 text-xs text-destructive"
                >
                    <CircleAlert class="size-3.5" aria-hidden="true" />
                    {{ t('ui.assistant_settings.quiet_invalid') }}
                </p>
                <p
                    v-for="key in [
                        'quiet_hours_start',
                        'quiet_hours_end',
                    ] as const"
                    :key="key"
                    class="text-xs text-destructive"
                >
                    {{ form.errors[key] }}
                </p>
                <figure class="grid gap-1">
                    <figcaption class="sr-only">
                        {{ t('ui.assistant_settings.timeline') }}
                    </figcaption>
                    <div
                        class="relative h-8 overflow-hidden rounded-xl border bg-mint-soft"
                    >
                        <span
                            v-for="(range, index) in quietRanges"
                            :key="index"
                            class="absolute inset-y-0 flex items-center justify-center bg-surface-2"
                            :style="{
                                left: `${(range[0] / 1440) * 100}%`,
                                width: `${((range[1] - range[0]) / 1440) * 100}%`,
                            }"
                        >
                            <Moon
                                v-if="range[1] - range[0] > 120"
                                class="size-4 text-muted-foreground"
                                aria-hidden="true"
                            />
                        </span>
                    </div>
                    <div
                        class="flex justify-between font-mono text-[0.6875rem] text-muted-foreground"
                    >
                        <span v-for="tick in ticks" :key="tick"
                            >{{ String(tick).padStart(2, '0') }}:00</span
                        >
                    </div>
                </figure>
                <div
                    class="flex items-center justify-between gap-4 rounded-2xl border px-4 py-3"
                >
                    <div>
                        <Label for="reminders" class="font-semibold">{{
                            t('ui.assistant_settings.reminders')
                        }}</Label>
                        <p class="text-xs text-muted-foreground">
                            {{ t('ui.assistant_settings.reminders_hint') }}
                        </p>
                    </div>
                    <Switch
                        id="reminders"
                        v-model="form.reminders_enabled"
                        data-test="assistant-settings-reminders"
                    />
                </div>
            </div>
        </SectionCard>

        <SectionCard
            :title="t('ui.assistant_settings.privacy_title')"
            :icon="ShieldCheck"
        >
            <p class="text-sm text-pretty text-muted-foreground">
                {{ t('ui.assistant_settings.privacy_body') }}
            </p>
            <template #footer>
                <Button size="sm" variant="ghost" as-child>
                    <Link :href="endpoints.legal('ai')">{{
                        t('ui.assistant_settings.privacy_link')
                    }}</Link>
                </Button>
                <Button size="sm" variant="secondary" as-child>
                    <Link
                        :href="endpoints.privacy.show()"
                        data-test="assistant-settings-privacy"
                        >{{ t('ui.assistant_settings.privacy_open') }}</Link
                    >
                </Button>
            </template>
        </SectionCard>
    </div>

    <div
        v-if="form.isDirty"
        class="fixed inset-x-0 bottom-16 z-20 flex justify-center px-4 md:bottom-4 md:pl-[88px]"
    >
        <div
            class="flex items-center gap-3 rounded-card border bg-popover px-4 py-3 shadow-clay-lg"
        >
            <span class="text-sm font-medium">{{
                t('ui.departments.unsaved')
            }}</span>
            <Button variant="ghost" size="sm" @click="discard">{{
                t('ui.common.cancel')
            }}</Button>
            <Button
                size="sm"
                :disabled="form.processing || !timesValid"
                data-test="assistant-settings-save"
                @click="save"
            >
                <Spinner v-if="form.processing" />
                {{ t('ui.common.save') }}
            </Button>
        </div>
    </div>
</template>
