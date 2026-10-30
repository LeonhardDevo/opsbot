<script setup lang="ts">
import { siTelegram } from 'simple-icons';

withDefaults(defineProps<{ class?: string; mono?: boolean }>(), {
    class: '',
    mono: false,
});

const brand = `#${siTelegram.hex}`;
</script>

<template>
    <svg
        viewBox="0 0 24 24"
        :class="$props.class"
        aria-hidden="true"
        focusable="false"
        role="img"
    >
        <path :d="siTelegram.path" :fill="mono ? 'currentColor' : brand" />
    </svg>
</template>
