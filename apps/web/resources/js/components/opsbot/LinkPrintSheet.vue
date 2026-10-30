<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import QrCode from '@/components/common/QrCode.vue';
import { useFormat } from '@/composables/useFormat';
import { t } from '@/lib/i18n';

export type PrintableLink = {
    user_id: number;
    name: string;
    url: string;
    expires_at: string;
    bot_username: string;
};

defineProps<{ links: PrintableLink[] }>();

const page = usePage();
const fmt = useFormat();
</script>

<template>
    <Teleport to="body">
        <div class="print-sheet" aria-hidden="true">
            <div class="print-grid">
                <section
                    v-for="link in links"
                    :key="link.user_id"
                    class="print-card"
                >
                    <p class="print-company">
                        {{ page.props.workspace?.name }}
                    </p>
                    <p class="print-name">{{ link.name }}</p>
                    <div class="print-qr">
                        <QrCode
                            :value="link.url"
                            :label="link.name"
                            :size="150"
                        />
                    </div>
                    <ol class="print-steps">
                        <li>{{ t('ui.employees.print.step_camera') }}</li>
                        <li>
                            {{
                                t('ui.employees.print.step_start', {
                                    bot: `@${link.bot_username}`,
                                })
                            }}
                        </li>
                        <li>{{ t('ui.employees.print.step_notice') }}</li>
                    </ol>
                    <p class="print-expiry">
                        {{
                            t('ui.employees.print.valid_until', {
                                date: fmt.date(link.expires_at),
                            })
                        }}
                    </p>
                </section>
            </div>
        </div>
    </Teleport>
</template>
