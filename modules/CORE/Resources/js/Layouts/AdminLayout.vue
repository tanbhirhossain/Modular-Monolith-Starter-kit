<script setup lang="ts">
import UserMenuContent from '@/components/UserMenuContent.vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Separator } from '@/components/ui/separator';
import { SidebarInset, SidebarProvider, SidebarTrigger } from '@/components/ui/sidebar';
import { useAppearance } from '@/composables/useAppearance';
import type { AuthUser, Breadcrumb } from '../types';
import ToastStack from '../Components/ToastStack.vue';
import TreeSidebar from './TreeSidebar.vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import { ChevronRight, Moon, Sun } from 'lucide-vue-next';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

/**
 * Premium admin shell for every CORE page: collapsible tree sidebar,
 * floating glass header with breadcrumbs, theme toggle, account menu and
 * the global toast stack — all owned by the module.
 */
interface Props {
    title?: string;
    breadcrumbs?: Breadcrumb[];
}

withDefaults(defineProps<Props>(), {
    title: undefined,
    breadcrumbs: () => [],
});

const page = usePage<{ props: { auth: { user: AuthUser | null }; name: string } }>();
const { updateAppearance } = useAppearance();

const user = computed(() => page.props.auth.user);

const isDark = ref(false);
const navigating = ref(false);

let offStart: (() => void) | undefined;
let offFinish: (() => void) | undefined;

onMounted(() => {
    isDark.value = document.documentElement.classList.contains('dark');

    // Global navigation progress (premium feedback for every Inertia visit —
    // sidebar links, forms, pagination alike).
    offStart = router.on('start', () => {
        navigating.value = true;
    });
    offFinish = router.on('finish', () => {
        navigating.value = false;
    });
});

onBeforeUnmount(() => {
    offStart?.();
    offFinish?.();
});

function toggleTheme() {
    const next = !isDark.value;
    updateAppearance(next ? 'dark' : 'light');
    isDark.value = next;
}
</script>

<template>
    <SidebarProvider>
        <TreeSidebar />

        <SidebarInset class="relative min-w-0">
            <header
                class="no-print sticky top-0 z-30 flex h-14 shrink-0 items-center gap-3 border-b bg-background/80 px-4 backdrop-blur-md transition-[width,height] print:hidden"
            >
                <SidebarTrigger class="-ml-1 shrink-0" />
                <Separator orientation="vertical" class="h-5 shrink-0" />

                <!-- Breadcrumbs -->
                <nav class="flex min-w-0 items-center gap-1.5 overflow-hidden" aria-label="Breadcrumb">
                    <template v-for="(crumb, index) in breadcrumbs" :key="`${crumb.title}-${index}`">
                        <ChevronRight
                            v-if="index > 0"
                            class="size-3.5 shrink-0 text-muted-foreground/60"
                            aria-hidden="true"
                        />
                        <Link
                            v-if="crumb.href && index < breadcrumbs.length - 1"
                            :href="crumb.href"
                            class="max-w-[10rem] truncate text-sm text-muted-foreground transition hover:text-foreground"
                        >
                            {{ crumb.title }}
                        </Link>
                        <span
                            v-else
                            class="max-w-[14rem] truncate text-sm font-semibold text-foreground"
                            aria-current="page"
                        >
                            {{ crumb.title }}
                        </span>
                    </template>
                </nav>

                <div class="ml-auto flex items-center gap-1.5">
                    <!-- Theme toggle -->
                    <Button
                        variant="ghost"
                        size="icon"
                        class="size-9 rounded-lg text-muted-foreground"
                        :aria-label="isDark ? 'Switch to light mode' : 'Switch to dark mode'"
                        @click="toggleTheme"
                    >
                        <Sun v-if="isDark" class="size-4.5" />
                        <Moon v-else class="size-4.5" />
                    </Button>

                    <!-- Account -->
                    <DropdownMenu v-if="user">
                        <DropdownMenuTrigger as-child>
                            <button
                                type="button"
                                class="flex items-center gap-2 rounded-lg border border-transparent px-2 py-1.5 text-sm transition hover:border-border hover:bg-accent focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                            >
                                <span
                                    class="grid size-7 shrink-0 place-items-center rounded-md bg-gradient-to-br from-indigo-500 to-violet-600 text-[11px] font-bold text-white"
                                >
                                    {{ user.name.split(/\s+/).map((part) => part[0]).slice(0, 2).join('').toUpperCase() }}
                                </span>
                                <span class="hidden max-w-36 truncate font-medium md:inline">{{ user.name }}</span>
                                <ChevronRight class="size-3.5 rotate-90 text-muted-foreground" />
                            </button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end" class="w-64">
                            <UserMenuContent :user="user" />
                        </DropdownMenuContent>
                    </DropdownMenu>
                </div>
            </header>

            <!-- Global navigation progress -->
            <div
                v-if="navigating"
                class="global-progress fixed inset-x-0 top-0 z-50 h-0.5 overflow-hidden"
                aria-hidden="true"
            >
                <div class="global-progress-bar h-full w-1/3 rounded-full bg-primary" />
            </div>

            <main class="flex-1 p-4 md:p-6">
                <div class="page-enter mx-auto w-full max-w-[1500px] space-y-6">
                    <slot />
                </div>
            </main>

            <ToastStack />
        </SidebarInset>
    </SidebarProvider>
</template>

<style scoped>
/* Global navigation bar pinned to the top of the viewport */
.global-progress-bar {
    animation: global-progress 1.1s ease-in-out infinite;
    box-shadow: 0 0 8px hsl(var(--primary) / 0.6);
}

@keyframes global-progress {
    0% {
        transform: translateX(-100%);
    }
    100% {
        transform: translateX(400%);
    }
}

/* Fresh entrance on every Inertia mount */
.page-enter {
    animation: page-in 0.4s cubic-bezier(0.21, 1.02, 0.73, 1) both;
}

@keyframes page-in {
    from {
        opacity: 0;
        transform: translateY(8px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

@media (prefers-reduced-motion: reduce) {
    .page-enter,
    .global-progress-bar {
        animation: none !important;
    }
}
</style>
