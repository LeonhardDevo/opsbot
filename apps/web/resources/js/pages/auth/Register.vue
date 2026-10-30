<script setup lang="ts">
import { Form, Head, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import ConsentCheckbox from '@/components/common/ConsentCheckbox.vue';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { endpoints } from '@/lib/endpoints';
import { t } from '@/lib/i18n';
import { login } from '@/routes';
import { store } from '@/routes/register';
import type { RegisterLegal } from '@/types/legal';

const props = withDefaults(
    defineProps<{ passwordRules: string; legal?: RegisterLegal | null }>(),
    { legal: null },
);

const page = usePage();
const russian = computed(() =>
    props.legal ? props.legal.consent_ru !== null : page.props.locale === 'ru',
);
const legalLink = (doc: 'terms' | 'privacy' | 'consent-ru', label: string) => ({
    href: endpoints.legal(doc).url,
    label,
});

defineOptions({
    layout: {
        title: 'ui.auth.register.title',
        description: 'ui.auth.register.description',
    },
});
</script>

<template>
    <Head :title="t('ui.auth.register.head')" />

    <Form
        v-bind="store.form()"
        :reset-on-success="['password', 'password_confirmation']"
        v-slot="{ errors, processing }"
        class="flex flex-col gap-6"
    >
        <div class="grid gap-5">
            <div class="grid gap-2">
                <Label for="name">{{ t('ui.fields.name') }}</Label>
                <Input
                    id="name"
                    v-focus
                    type="text"
                    required
                    autocomplete="name"
                    name="name"
                    class="h-10"
                    :placeholder="t('ui.fields.name_placeholder')"
                    :aria-invalid="Boolean(errors.name)"
                />
                <InputError :message="errors.name" />
            </div>

            <div class="grid gap-2">
                <Label for="email">{{ t('ui.fields.work_email') }}</Label>
                <Input
                    id="email"
                    type="email"
                    required
                    autocomplete="email"
                    name="email"
                    class="h-10"
                    placeholder="name@company.com"
                    :aria-invalid="Boolean(errors.email)"
                />
                <InputError :message="errors.email" />
            </div>

            <div class="grid gap-2">
                <Label for="password">{{ t('ui.fields.password') }}</Label>
                <PasswordInput
                    id="password"
                    required
                    autocomplete="new-password"
                    name="password"
                    class="h-10"
                    :passwordrules="passwordRules"
                    :aria-invalid="Boolean(errors.password)"
                    aria-describedby="password-hint"
                />
                <p id="password-hint" class="text-xs text-muted-foreground">
                    {{ t('ui.auth.register.password_hint') }}
                </p>
                <InputError :message="errors.password" />
            </div>

            <div class="grid gap-2">
                <Label for="password_confirmation">{{
                    t('ui.fields.confirm_password')
                }}</Label>
                <PasswordInput
                    id="password_confirmation"
                    required
                    autocomplete="new-password"
                    name="password_confirmation"
                    class="h-10"
                    :passwordrules="passwordRules"
                    :aria-invalid="Boolean(errors.password_confirmation)"
                />
                <InputError :message="errors.password_confirmation" />
            </div>

            <ConsentCheckbox
                id="terms"
                name="terms"
                test-id="register-terms"
                :text="t('ui.auth.register.accept_terms')"
                :links="{
                    terms: legalLink('terms', t('ui.legal.terms')),
                    privacy: legalLink('privacy', t('ui.legal.privacy')),
                }"
                :error="errors.terms"
            />
            <ConsentCheckbox
                v-if="russian"
                id="consent_ru"
                name="consent_ru"
                test-id="register-consent"
                :text="t('ui.auth.register.accept_consent')"
                :links="{
                    consent: legalLink('consent-ru', t('ui.legal.consent_ru')),
                }"
                :error="errors.consent_ru"
            />

            <Button
                type="submit"
                class="mt-2 h-10 w-full"
                :disabled="processing"
                data-test="register-user-button"
            >
                <Spinner v-if="processing" />
                {{ t('ui.auth.register.submit') }}
            </Button>
        </div>

        <p class="text-center text-sm text-muted-foreground">
            {{ t('ui.auth.register.have_account') }}
            <TextLink :href="login()">{{
                t('ui.auth.register.log_in')
            }}</TextLink>
        </p>
    </Form>
</template>
