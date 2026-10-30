<script setup lang="ts">
import QRCode from 'qrcode';
import { ref, watch } from 'vue';
import { Skeleton } from '@/components/ui/skeleton';

const props = withDefaults(
    defineProps<{ value: string; label: string; size?: number }>(),
    {
        size: 192,
    },
);

const svg = ref<string | null>(null);
const failed = ref(false);

watch(
    () => props.value,
    async (value) => {
        svg.value = null;
        failed.value = false;

        try {
            svg.value = await QRCode.toString(value, {
                type: 'svg',
                margin: 1,
                errorCorrectionLevel: 'M',
                color: { dark: '#0B1B2B', light: '#FFFFFF' },
            });
        } catch {
            failed.value = true;
        }
    },
    { immediate: true },
);
</script>

<template>
    <div
        class="relative overflow-hidden rounded-card border bg-white p-4 shadow-clay"
        :style="{ width: `${size}px`, height: `${size}px` }"
    >
        <div
            v-if="svg"
            role="img"
            :aria-label="label"
            class="size-full [&_svg]:size-full"
            v-html="svg"
        />
        <Skeleton v-else-if="!failed" class="size-full bg-surface-2" />
    </div>
</template>
