<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import UserAvatar from '@/components/common/UserAvatar.vue';
import UserMenuContent from '@/components/shell/UserMenuContent.vue';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { t } from '@/lib/i18n';

withDefaults(defineProps<{ side?: 'right' | 'top' | 'bottom' }>(), {
    side: 'right',
});

const page = usePage();
</script>

<template>
    <DropdownMenu v-if="page.props.auth.user">
        <DropdownMenuTrigger as-child>
            <button
                type="button"
                class="press inline-flex size-11 items-center justify-center rounded-chip hover:shadow-clay-sm"
                data-test="user-menu-trigger"
            >
                <UserAvatar
                    :name="page.props.auth.user.name"
                    :src="page.props.auth.user.avatar"
                    size="md"
                    class="rounded-chip"
                />
                <span class="sr-only">{{ t('ui.shell.user_menu') }}</span>
            </button>
        </DropdownMenuTrigger>
        <DropdownMenuContent
            class="w-64"
            :side="side"
            align="end"
            :side-offset="10"
        >
            <UserMenuContent :user="page.props.auth.user" />
        </DropdownMenuContent>
    </DropdownMenu>
</template>
