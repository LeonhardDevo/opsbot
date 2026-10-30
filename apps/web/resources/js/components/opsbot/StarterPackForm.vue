<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { CircleCheck, Download, TriangleAlert } from '@lucide/vue';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { Switch } from '@/components/ui/switch';
import { endpoints } from '@/lib/endpoints';
import { t, tc } from '@/lib/i18n';
import type { StarterPack } from '@/types/opsbot';

const props = withDefaults(
    defineProps<{ pack: StarterPack; importTest?: string }>(),
    { importTest: 'knowledge-pack-import' },
);

const emit = defineEmits<{ imported: [] }>();

const languages = ref<Array<'ru' | 'en'>>([...props.pack.languages]);
const createDepartments = ref(true);
const importing = ref(false);

function toggle(language: 'ru' | 'en', value: boolean): void {
    languages.value = value
        ? [...new Set([...languages.value, language])]
        : languages.value.filter((item) => item !== language);
}

function submit(): void {
    importing.value = true;
    router.post(
        endpoints.knowledge.starterPack().url,
        {
            pack: props.pack.key,
            languages: languages.value,
            create_departments: createDepartments.value,
        },
        {
            preserveScroll: true,
            onSuccess: () => emit('imported'),
            onFinish: () => (importing.value = false),
        },
    );
}
</script>

<template>
    <div class="grid gap-4">
        <div class="space-y-1">
            <p class="font-semibold">{{ pack.title }}</p>
            <p class="text-sm text-pretty text-muted-foreground">
                {{ pack.description }}
            </p>
            <p class="text-caption">
                {{ tc('ui.knowledge.pack.documents', pack.documents_count) }}
            </p>
        </div>
        <p
            v-if="pack.imported"
            class="inline-flex items-center gap-1.5 text-sm font-semibold text-success"
        >
            <CircleCheck class="size-4" aria-hidden="true" />
            {{ t('ui.knowledge.pack.imported') }}
        </p>
        <template v-else>
            <fieldset class="grid gap-2">
                <legend class="mb-1 text-sm font-semibold">
                    {{ t('ui.knowledge.pack.languages') }}
                </legend>
                <div class="flex flex-wrap gap-4">
                    <div
                        v-for="language in pack.languages"
                        :key="language"
                        class="flex min-h-11 items-center gap-2"
                    >
                        <Checkbox
                            :id="`pack-${pack.key}-${language}`"
                            :model-value="languages.includes(language)"
                            @update:model-value="
                                (value) => toggle(language, value === true)
                            "
                        />
                        <Label :for="`pack-${pack.key}-${language}`">{{
                            t(`ui.knowledge.languages.${language}`)
                        }}</Label>
                    </div>
                </div>
            </fieldset>
            <div
                v-if="pack.departments.length"
                class="flex items-center justify-between gap-4 rounded-2xl border px-4 py-3"
            >
                <div>
                    <Label
                        :for="`pack-${pack.key}-departments`"
                        class="font-semibold"
                        >{{ t('ui.knowledge.pack.create_departments') }}</Label
                    >
                    <p class="text-xs text-muted-foreground">
                        {{ pack.departments.map((d) => d.name).join(', ') }}
                    </p>
                </div>
                <Switch
                    :id="`pack-${pack.key}-departments`"
                    v-model="createDepartments"
                />
            </div>
            <p
                class="flex items-start gap-2 rounded-2xl bg-sun-soft px-3 py-2.5 text-sm"
            >
                <TriangleAlert
                    class="mt-0.5 size-4 shrink-0 text-sun-strong"
                    aria-hidden="true"
                />
                {{ t('ui.knowledge.pack.warning') }}
            </p>
            <Button
                class="justify-self-start"
                :disabled="importing || !languages.length"
                :data-test="importTest"
                @click="submit"
            >
                <Spinner v-if="importing" />
                <Download v-else class="size-4" aria-hidden="true" />
                {{ t('ui.knowledge.pack.import') }}
            </Button>
        </template>
    </div>
</template>
