<script setup lang="ts">
import type { HTMLAttributes } from 'vue';

withDefaults(
    defineProps<{ class?: HTMLAttributes['class']; mono?: boolean }>(),
    { class: undefined, mono: false },
);
</script>

<template>
    <svg
        viewBox="0 0 32 32"
        xmlns="http://www.w3.org/2000/svg"
        :class="$props.class"
        aria-hidden="true"
        focusable="false"
        data-test="app-logo-mark"
    >
        <rect
            width="32"
            height="32"
            rx="9"
            :class="mono ? 'fill-current' : 'fill-primary'"
        />
        <path
            d="M11.5 7.5h9a5 5 0 0 1 5 5v3.5a5 5 0 0 1-5 5h-6.3l-4.1 3.4c-.5.4-1.1.05-1.1-.55V20.6A5 5 0 0 1 6.5 16v-3.5a5 5 0 0 1 5-5Z"
            :class="mono ? 'fill-background' : 'fill-primary-foreground'"
        />
        <path
            d="m11.6 14.4 2.9 2.8 5.9-5.8"
            fill="none"
            stroke-width="2.6"
            stroke-linecap="round"
            stroke-linejoin="round"
            :class="mono ? 'stroke-current' : 'stroke-mint'"
        />
    </svg>
</template>
