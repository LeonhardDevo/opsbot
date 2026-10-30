<script setup lang="ts">
import { Copy, Printer, RefreshCw } from '@lucide/vue';
import { useClipboard } from '@vueuse/core';
import { toast } from 'vue-sonner';
import QrCode from '@/components/common/QrCode.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { useCountdown } from '@/composables/useCountdown';
import { useFormat } from '@/composables/useFormat';
import { t } from '@/lib/i18n';
import type { EmployeeLinkOut } from '@/types/opsbot';

const props = withDefaults(
    defineProps<{
        link: EmployeeLinkOut;
        name: string;
        renewing?: boolean;
        renewable?: boolean;
    }>(),
    { renewing: false, renewable: true },
);

const emit = defineEmits<{ renew: []; print: [] }>();

const fmt = useFormat();
const { copy } = useClipboard({ legacy: true });
const countdown = useCountdown(() => props.link.expires_at);

async function copyLink(): Promise<void> {
    await copy(props.link.url);
    toast.success(t('ui.employees.link.copied'));
}
</script>

<template>
    <div
        class="grid gap-4 sm:grid-cols-[auto_1fr]"
        data-test="employee-link-qr"
    >
        <div class="mx-auto rounded-card bg-[#FFFFFF] p-4 shadow-clay-sm">
            <QrCode
                :value="link.url"
                :label="t('ui.employees.link.qr_label', { name })"
                :size="176"
            />
        </div>
        <div class="grid content-start gap-3">
            <p class="text-sm text-pretty">
                {{
                    t('ui.employees.link.instructions', {
                        bot: `@${link.bot_username}`,
                    })
                }}
            </p>
            <div class="flex gap-2">
                <Input
                    :model-value="link.url"
                    readonly
                    class="font-mono text-[0.8125rem]"
                    :aria-label="t('ui.employees.link.url_label')"
                    data-test="employee-link-url"
                    @focus="($event.target as HTMLInputElement).select()"
                />
                <Button
                    variant="secondary"
                    size="icon"
                    :aria-label="t('ui.employees.link.copy')"
                    data-test="employee-link-copy"
                    @click="copyLink"
                >
                    <Copy class="size-4" aria-hidden="true" />
                </Button>
            </div>
            <p class="text-caption">
                {{
                    t('ui.employees.link.expires', {
                        left: countdown.label.value ?? '—',
                        date: fmt.date(link.expires_at),
                    })
                }}
            </p>
            <div class="flex flex-wrap gap-2">
                <Button variant="secondary" size="sm" @click="emit('print')">
                    <Printer class="size-4" aria-hidden="true" />
                    {{ t('ui.employees.link.print') }}
                </Button>
                <Button
                    v-if="renewable"
                    variant="ghost"
                    size="sm"
                    :disabled="renewing"
                    data-test="employee-link-new"
                    @click="emit('renew')"
                >
                    <Spinner v-if="renewing" />
                    <RefreshCw v-else class="size-4" aria-hidden="true" />
                    {{ t('ui.employees.link.renew') }}
                </Button>
            </div>
        </div>
    </div>
</template>
