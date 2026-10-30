<script setup lang="ts">
import { computed } from 'vue';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';

const ALL = '__all';

const props = withDefaults(
    defineProps<{
        options: Array<{ value: string | number; label: string }>;
        label: string;
        allLabel?: string | null;
        nullable?: boolean;
        class?: string;
        testId?: string;
        id?: string;
    }>(),
    {
        allLabel: null,
        nullable: true,
        class: '',
        testId: undefined,
        id: undefined,
    },
);

const model = defineModel<string | number | null | undefined>();

const value = computed({
    get: () =>
        model.value === null || model.value === undefined || model.value === ''
            ? ALL
            : String(model.value),
    set: (next: string) => {
        model.value = next === ALL ? null : next;
    },
});
</script>

<template>
    <Select v-model="value">
        <SelectTrigger
            :id="id"
            :aria-label="label"
            :class="cn('h-11 w-full min-w-0 sm:w-48', props.class)"
            :data-test="testId"
        >
            <SelectValue :placeholder="label" />
        </SelectTrigger>
        <SelectContent>
            <SelectItem v-if="nullable" :value="ALL">{{
                allLabel ?? t('ui.common.all')
            }}</SelectItem>
            <SelectItem
                v-for="option in options"
                :key="option.value"
                :value="String(option.value)"
                >{{ option.label }}</SelectItem
            >
        </SelectContent>
    </Select>
</template>
