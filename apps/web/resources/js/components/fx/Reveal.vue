<script setup lang="ts">
import { computed } from 'vue';

const props = withDefaults(defineProps<{ delay?: number }>(), { delay: 0 });

const style = computed(() => ({
    '--reveal-offset': `${Math.round(props.delay * 100)}%`,
}));
</script>

<template>
    <div class="reveal" :style="style">
        <slot />
    </div>
</template>
