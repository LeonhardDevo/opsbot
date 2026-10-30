<script setup lang="ts">
import { computed } from 'vue';
import { splitPlaceholders } from '@/lib/linkedText';

const props = defineProps<{
    id: string;
    name: string;
    text: string;
    links: Record<string, { href: string; label: string }>;
    error?: string | null;
    testId?: string;
}>();

const checked = defineModel<boolean>({ default: false });

const parts = computed(() =>
    splitPlaceholders(props.text, Object.keys(props.links)),
);
</script>

<template>
    <div class="grid gap-1.5">
        <div class="flex items-start gap-3">
            <input
                :id="id"
                v-model="checked"
                type="checkbox"
                :name="name"
                value="1"
                required
                class="mt-0.5 size-5 shrink-0 cursor-pointer rounded-md border-input accent-primary focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                :aria-invalid="Boolean(error)"
                :aria-describedby="error ? `${id}-error` : undefined"
                :data-test="testId"
            />
            <label
                :for="id"
                class="cursor-pointer text-sm leading-snug text-pretty text-muted-foreground"
            >
                <template v-for="(part, index) in parts" :key="index">
                    <a
                        v-if="part.kind === 'link'"
                        :href="links[part.key]?.href"
                        target="_blank"
                        rel="noopener"
                        class="font-medium text-foreground underline underline-offset-2 hover:text-primary-strong"
                        >{{ links[part.key]?.label }}</a
                    >
                    <template v-else>{{ part.text }}</template>
                </template>
            </label>
        </div>
        <p
            v-if="error"
            :id="`${id}-error`"
            class="text-sm text-destructive"
            role="alert"
        >
            {{ error }}
        </p>
    </div>
</template>
