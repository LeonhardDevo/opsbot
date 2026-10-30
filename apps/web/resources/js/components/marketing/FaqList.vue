<script setup lang="ts">
import {
    Accordion,
    AccordionContent,
    AccordionItem,
    AccordionTrigger,
} from '@/components/ui/accordion';

defineProps<{
    items: Array<{ id: string; question: string; answer: string }>;
}>();
</script>

<template>
    <Accordion
        type="single"
        collapsible
        class="divide-y rounded-card border bg-card px-2 shadow-clay sm:px-4"
    >
        <AccordionItem
            v-for="item in items"
            :key="item.id"
            :value="item.id"
            class="border-b-0"
        >
            <AccordionTrigger
                class="min-h-14 py-5 text-left text-base font-semibold hover:no-underline"
            >
                {{ item.question }}
            </AccordionTrigger>
            <AccordionContent
                class="pb-5 text-[0.9375rem] leading-relaxed text-muted-foreground"
            >
                {{ item.answer }}
            </AccordionContent>
        </AccordionItem>
    </Accordion>
</template>
