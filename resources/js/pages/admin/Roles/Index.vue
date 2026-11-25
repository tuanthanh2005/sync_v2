<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { KeyRound, Lock, Save } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { permissions as rolePermissionsRoute } from '@/routes/admin/roles';

type RoleItem = {
    id: number;
    slug: string;
    name: string;
    description: string | null;
    hierarchy_level: number;
    is_system: boolean;
    users_count: number;
    permission_ids: number[];
};

type PermissionGroup = {
    module: string;
    items: Array<{ id: number; action: string; slug: string; label: string }>;
};

const props = defineProps<{
    roles: RoleItem[];
    permissionGroups: PermissionGroup[];
}>();

const { t } = useI18n();
const selectedRoleId = ref<number>(props.roles[0]?.id ?? 0);
const saving = ref(false);

const selectedRole = computed(
    () =>
        props.roles.find((r) => r.id === selectedRoleId.value) ??
        props.roles[0],
);
const checkedIds = ref<number[]>([
    ...(selectedRole.value?.permission_ids ?? []),
]);

watch(selectedRole, (role) => {
    checkedIds.value = [...(role?.permission_ids ?? [])];
});

function togglePermission(id: number): void {
    if (selectedRole.value?.slug === 'super_admin') {
        return;
    }

    if (checkedIds.value.includes(id)) {
        checkedIds.value = checkedIds.value.filter((item) => item !== id);
    } else {
        checkedIds.value = [...checkedIds.value, id];
    }
}

function savePermissions(): void {
    if (!selectedRole.value || selectedRole.value.slug === 'super_admin') {
        return;
    }

    saving.value = true;
    router.patch(
        rolePermissionsRoute.url(selectedRole.value.id),
        { permission_ids: checkedIds.value },
        {
            preserveScroll: true,
            onFinish: () => {
                saving.value = false;
            },
        },
    );
}
</script>

<template>
    <div class="space-y-5">
        <Head :title="t('admin.roles.title')" />

        <div class="rounded-xl border border-border bg-card p-5">
            <h1
                class="flex items-center gap-2 text-xl font-bold text-foreground"
            >
                <KeyRound class="size-5 text-primary" />
                <span>{{ t('admin.roles.title') }}</span>
            </h1>
            <p class="mt-1 text-sm text-muted-foreground">
                {{ t('admin.roles.subtitle') }}
            </p>
        </div>

        <div class="grid gap-5 lg:grid-cols-4">
            <!-- Roles Sidebar -->
            <div class="space-y-2 rounded-xl border border-border bg-card p-3">
                <button
                    v-for="role in roles"
                    :key="role.id"
                    type="button"
                    :class="[
                        'flex w-full flex-col rounded-lg p-3 text-left transition-colors',
                        selectedRole?.id === role.id
                            ? 'bg-primary-soft text-accent-foreground'
                            : 'hover:bg-surface-hover',
                    ]"
                    @click="selectedRoleId = role.id"
                >
                    <div class="flex items-center justify-between gap-2">
                        <span class="text-xs font-semibold text-foreground">{{
                            role.name
                        }}</span>
                        <Badge variant="outline" class="text-[10px]"
                            >L{{ role.hierarchy_level }}</Badge
                        >
                    </div>
                    <p
                        class="mt-1 line-clamp-2 text-[11px] text-muted-foreground"
                    >
                        {{ role.description }}
                    </p>
                </button>
            </div>

            <!-- Permission Matrix -->
            <div
                v-if="selectedRole"
                class="space-y-4 rounded-xl border border-border bg-card p-5 lg:col-span-3"
            >
                <div
                    class="flex flex-wrap items-center justify-between gap-3 border-b border-border pb-4"
                >
                    <div>
                        <h2 class="text-base font-bold text-foreground">
                            {{ selectedRole.name }}
                        </h2>
                        <p class="text-xs text-muted-foreground">
                            {{ selectedRole.description }}
                        </p>
                    </div>
                    <div
                        v-if="selectedRole.slug === 'super_admin'"
                        class="flex items-center gap-1.5 text-xs font-medium text-primary"
                    >
                        <Lock class="size-3.5" />
                        <span>{{ t('admin.roles.superAdminLocked') }}</span>
                    </div>
                    <Button
                        v-else
                        size="sm"
                        class="gap-1.5 rounded-lg text-xs"
                        :disabled="saving"
                        @click="savePermissions"
                    >
                        <Save class="size-3.5" />
                        <span>{{
                            saving
                                ? t('admin.common.saving')
                                : t('admin.roles.savePermissions')
                        }}</span>
                    </Button>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div
                        v-for="group in permissionGroups"
                        :key="group.module"
                        class="rounded-xl border border-border bg-muted/20 p-3.5"
                    >
                        <h3
                            class="mb-2.5 text-xs font-bold tracking-wider text-foreground uppercase"
                        >
                            {{ group.module.replace('_', ' ') }}
                        </h3>
                        <div class="flex flex-wrap gap-2">
                            <label
                                v-for="perm in group.items"
                                :key="perm.id"
                                class="inline-flex cursor-pointer items-center gap-1.5 rounded-lg border border-border bg-card px-2.5 py-1.5 text-[11px]"
                            >
                                <input
                                    type="checkbox"
                                    :checked="
                                        selectedRole.slug === 'super_admin' ||
                                        checkedIds.includes(perm.id)
                                    "
                                    :disabled="
                                        selectedRole.slug === 'super_admin'
                                    "
                                    class="rounded border-input"
                                    @change="togglePermission(perm.id)"
                                />
                                <span>{{ perm.action }}</span>
                            </label>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
