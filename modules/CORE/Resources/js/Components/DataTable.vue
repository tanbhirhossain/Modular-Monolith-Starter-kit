<script setup lang="ts">
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuCheckboxItem,
    DropdownMenuContent,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';
import type { FilterDef, PaginatorMeta } from '../types';
import { formatDateTime, formatNumber } from '../Utils/format';
import { useToast } from '../Composables/useToast';
import { router, usePage } from '@inertiajs/vue3';
import {
    ArrowDown,
    ArrowUp,
    ArrowUpDown,
    ChevronLeft,
    ChevronRight,
    ChevronsLeft,
    ChevronsRight,
    Download,
    FileSpreadsheet,
    FileText,
    Inbox,
    Loader2,
    Printer,
    Search,
    Settings2,
    SlidersHorizontal,
    X,
} from 'lucide-vue-next';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import SearchableSelect from './SearchableSelect.vue';

/**
 * Server-side DataTable built for tables of any size (100 → billions of
 * rows): the browser only ever holds the current page. URL is the single
 * source of truth — search, sort, filters, page and per-page all live in the
 * query string, so views are shareable and the back button behaves.
 */
export interface DataTableColumn {
    key: string;
    label: string;
    sortable?: boolean;
    align?: 'left' | 'center' | 'right';
    width?: string;
    /** Hidden from both print and exports when false. */
    exportable?: boolean;
    /** Render-only visibility toggle starts hidden. */
    defaultHidden?: boolean;
    headerClass?: string;
    cellClass?: string;
}

interface Props {
    columns: DataTableColumn[];
    rows: Record<string, unknown>[];
    meta: PaginatorMeta;
    /** Ziggy name of the index route, e.g. 'core.users.index'. */
    routeName: string;
    /** Enables exports when set, e.g. 'users'. */
    exportResource?: string;
    /** Human title used by PDF export + print header. */
    title?: string;
    filterDefs?: FilterDef[];
    searchPlaceholder?: string;
    defaultSort?: { key: string; direction: 'asc' | 'desc' };
    emptyTitle?: string;
    emptyDescription?: string;
}

const props = withDefaults(defineProps<Props>(), {
    exportResource: undefined,
    title: 'Records',
    filterDefs: () => [],
    searchPlaceholder: 'Search…',
    defaultSort: () => ({ key: 'created_at', direction: 'desc' }),
    emptyTitle: 'No records found',
    emptyDescription: 'Try adjusting your search or filters to widen the results.',
});

const page = usePage();
const toast = useToast();
const loading = ref(false);
const exporting = ref(false);
const searchInput = ref('');
let searchTimer: number | undefined;
let exportResetTimer: number | undefined;

/* --------------------------------- URL ---------------------------------- */

const urlParams = computed(() => new URL(page.url, 'http://core.local').searchParams);

const filterValue = (key: string): string => urlParams.value.get(`filters[${key}]`) ?? '';

const activeFilters = computed<Record<string, string>>(() => {
    const result: Record<string, string> = {};

    for (const def of props.filterDefs) {
        const value = filterValue(def.key);
        if (value !== '') result[def.key] = value;
    }

    return result;
});

const hasActiveFilters = computed(() => Object.keys(activeFilters.value).length > 0 || (searchInput.value ?? '') !== '');

const hasSearch = computed(() => (urlParams.value.get('search') ?? '') !== '');

/**
 * Navigate while merging a flat patch of query params into the current URL.
 * Keys not mentioned are preserved; `page` resets unless explicitly patched.
 */
function navigate(patch: Record<string, string | number | null | undefined>, push = false) {
    const params = new URLSearchParams(urlParams.value);

    for (const [key, value] of Object.entries(patch)) {
        if (value === null || value === undefined || value === '') {
            params.delete(key);
        } else {
            params.set(key, String(value));
        }
    }

    if (!('page' in patch)) {
        params.delete('page');
    }

    loading.value = true;

    const query = Object.fromEntries(params.entries());

    router.get(route(props.routeName), query, {
        preserveState: true,
        preserveScroll: true,
        replace: !push,
        onFinish: () => {
            loading.value = false;
        },
    });
}

/* ------------------------------- live search ----------------------------- */

function onSearchInputEvent(event: Event) {
    onSearchInput((event.target as HTMLInputElement).value);
}

function onSearchInput(value: string) {
    window.clearTimeout(searchTimer);

    searchTimer = window.setTimeout(() => navigate({ search: value.trim() || null }), 400);
}

watch(
    () => page.url,
    () => {
        const incoming = urlParams.value.get('search') ?? '';

        if (incoming !== searchInput.value.trim()) {
            searchInput.value = incoming;
        }
    },
    { immediate: true },
);

/* -------------------------------- sorting -------------------------------- */

function sortState(key: string): 'asc' | 'desc' | null {
    const urlSort = urlParams.value.get('sort');

    if (urlSort) {
        return urlSort === key ? (urlParams.value.get('dir') === 'asc' ? 'asc' : 'desc') : null;
    }

    // No explicit sort in the URL — surface the default order on its column.
    return props.defaultSort.key === key ? props.defaultSort.direction : null;
}

const activeSortKey = computed(() => urlParams.value.get('sort') ?? props.defaultSort.key);

function toggleSort(column: DataTableColumn) {
    if (!column.sortable) return;

    const urlSort = urlParams.value.get('sort');

    if (!urlSort || urlSort !== column.key) {
        navigate({ sort: column.key, dir: 'asc' });
        return;
    }

    const direction = urlParams.value.get('dir') ?? 'desc';

    if (direction === 'asc') {
        navigate({ sort: column.key, dir: 'desc' });
    } else {
        // back to default order
        navigate({ sort: null, dir: null });
    }
}

/* -------------------------------- filters -------------------------------- */

function onFilterChange(def: FilterDef, value: unknown) {
    const normalized = value === null || value === undefined || value === '' ? null : String(value);
    applyFilter(def, normalized);
}

function applyFilter(def: FilterDef, value: string | null) {
    navigate({ [`filters[${def.key}]`]: value === null || value === '' ? null : String(value) });
}

function clearAll() {
    const params = new URLSearchParams(urlParams.value);
    const patch: Record<string, string | null> = { search: null };

    for (const key of [...params.keys()]) {
        if (key.startsWith('filters[') || key === 'sort' || key === 'dir') {
            patch[key] = null;
        }
    }

    searchInput.value = '';
    navigate(patch);
}

/* ------------------------------ column visibility ------------------------ */

const hiddenColumns = ref<Set<string>>(new Set(props.columns.filter((column) => column.defaultHidden).map((c) => c.key)));

function toggleColumn(key: string) {
    const next = new Set(hiddenColumns.value);

    if (next.has(key)) {
        next.delete(key);
    } else {
        // never hide the last visible column
        if (next.size >= props.columns.length - 1) return;
        next.add(key);
    }

    hiddenColumns.value = next;
}

const visibleColumns = computed(() => props.columns.filter((column) => !hiddenColumns.value.has(column.key)));

/* -------------------------------- exports -------------------------------- */

const exportHint = 'Respects the current search, filters and sort';

/**
 * Start a file download for the current view.
 *
 * Hardened for the real world: the query string is built natively so
 * bracketed filter keys can never break route generation, a literal URL
 * covers a missing ziggy route, and `finally` guarantees the button always
 * re-arms — a failed attempt can never leave the export stuck/disabled.
 */
function runExport(format: 'excel' | 'pdf') {
    if (!props.exportResource || exporting.value) return;

    exporting.value = true;

    try {
        let base = `/core/exports/${props.exportResource}/${format}`;

        try {
            base = route('core.exports', { resource: props.exportResource, format });
        } catch {
            /* route table unavailable — the literal path above is identical */
        }

        const query = new URLSearchParams();
        urlParams.value.forEach((value, key) => {
            if (value !== '') query.set(key, value);
        });

        const target = query.toString() ? `${base}?${query.toString()}` : base;

        toast.info(
            `Preparing your ${format === 'excel' ? 'Excel' : 'PDF'} export — the download will start in a moment.`,
            'Export started',
        );

        window.location.href = target;
    } catch (error) {
        console.error('[CORE] export failed', error);
        toast.error('The export could not be started. Please try again.', 'Export failed');
    } finally {
        window.clearTimeout(exportResetTimer);
        exportResetTimer = window.setTimeout(() => {
            exporting.value = false;
        }, 3000);
    }
}

function onWindowFocus() {
    // Returning to the tab after a download → re-arm immediately.
    if (exporting.value) exporting.value = false;
}

onMounted(() => window.addEventListener('focus', onWindowFocus));
onBeforeUnmount(() => {
    window.removeEventListener('focus', onWindowFocus);
    window.clearTimeout(exportResetTimer);
    window.clearTimeout(searchTimer);
});

function printView() {
    window.print();
}

/* ------------------------------- pagination ------------------------------ */

const perPageOptions = [10, 25, 50, 100];

const pageWindow = computed<(number | 'gap-start' | 'gap-end')[]>(() => {
    const { current_page: current, last_page: last } = props.meta;

    if (last <= 7) return Array.from({ length: last }, (_, index) => index + 1);

    if (current <= 4) return [1, 2, 3, 4, 5, 6, 'gap-end', last];

    if (current >= last - 4) return [1, 'gap-start', last - 5, last - 4, last - 3, last - 2, last - 1, last];

    return [1, 'gap-start', current - 1, current, current + 1, 'gap-end', last];
});

/* -------------------------------- helpers -------------------------------- */

const showFilters = computed(() => props.filterDefs.length > 0);

/** Staggered entrance for rows — re-triggered per page via the row key. */
function rowStyle(index: number): Record<string, string> {
    return { '--dt-row-delay': `${Math.min(index * 16, 240)}ms` };
}

function cellValue(row: Record<string, unknown>, key: string): unknown {
    const value = row[key];

    if (value === null || value === undefined || value === '') return '—';
    if (typeof value === 'boolean') return value ? 'Yes' : 'No';
    if (typeof value === 'string' && /^\d{4}-\d{2}-\d{2}T/.test(value)) return formatDateTime(value);
    if (Array.isArray(value)) return value.join(', ');
    if (typeof value === 'object') return JSON.stringify(value);

    return value;
}
</script>

<template>
    <div class="relative space-y-3">
        <!-- Print-only header -->
        <div class="mb-4 hidden print:block">
            <h1 class="text-xl font-bold">{{ title }}</h1>
            <p class="mt-1 text-xs text-gray-600">
                Generated {{ formatDateTime(new Date().toISOString()) }} · {{ formatNumber(meta.total) }} total
                records · current page only
            </p>
        </div>

        <!-- Toolbar -->
        <div
            class="flex flex-col gap-3 rounded-xl border bg-card p-3 shadow-sm lg:flex-row lg:items-center lg:justify-between print:hidden"
        >
            <div class="flex flex-1 flex-wrap items-center gap-2">
                <div class="relative w-full max-w-sm">
                    <Search
                        class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground"
                    />
                    <Input
                        v-model="searchInput"
                        type="search"
                        :placeholder="searchPlaceholder"
                        class="h-9 w-full rounded-lg pl-9 pr-9 shadow-none"
                        aria-label="Live search"
                        @input="onSearchInputEvent($event)"
                    />
                    <button
                        v-if="searchInput"
                        type="button"
                        class="absolute right-2.5 top-1/2 -translate-y-1/2 rounded-md p-0.5 text-muted-foreground transition hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                        aria-label="Clear search"
                        @click="searchInput = ''; onSearchInput('')"
                    >
                        <X class="size-3.5" />
                    </button>
                </div>

                <!-- Filters -->
                <template v-if="showFilters">
                    <div
                        v-for="def in filterDefs"
                        :key="def.key"
                        class="w-full sm:w-48"
                    >
                        <Input
                            v-if="def.kind === 'date'"
                            type="date"
                            :value="filterValue(def.key)"
                            class="h-9 w-full rounded-lg"
                            :aria-label="def.label"
                            @update:model-value="onFilterChange(def, $event)"
                        />
                        <SearchableSelect
                            v-else
                            :model-value="filterValue(def.key) || null"
                            :options="def.options ?? []"
                            :endpoint="def.kind === 'endpoint' ? def.endpoint : undefined"
                            :placeholder="def.label + (def.kind === 'select' ? ': any' : '')"
                            :search-placeholder="def.placeholder ?? `Search ${def.label.toLowerCase()}…`"
                            clearable
                            badge
                            class="h-9"
                            @update:model-value="onFilterChange(def, $event)"
                        />
                    </div>
                </template>

                <Button
                    v-if="hasActiveFilters || urlParams.get('sort')"
                    variant="ghost"
                    size="sm"
                    class="h-9 rounded-lg text-muted-foreground hover:text-foreground"
                    @click="clearAll"
                >
                    <X class="size-4" />
                    Clear
                </Button>
            </div>

            <div class="flex items-center gap-2">
                <!-- Column visibility -->
                <DropdownMenu>
                    <DropdownMenuTrigger as-child>
                        <Button variant="outline" size="sm" class="h-9 rounded-lg" title="Toggle columns">
                            <Settings2 class="size-4" />
                            <span class="hidden sm:inline">Columns</span>
                        </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end" class="w-52">
                        <DropdownMenuLabel class="text-xs text-muted-foreground">Visible columns</DropdownMenuLabel>
                        <DropdownMenuSeparator />
                        <DropdownMenuCheckboxItem
                            v-for="column in columns"
                            :key="column.key"
                            :checked="!hiddenColumns.has(column.key)"
                            @select.prevent="toggleColumn(column.key)"
                        >
                            {{ column.label }}
                        </DropdownMenuCheckboxItem>
                    </DropdownMenuContent>
                </DropdownMenu>

                <!-- Exports -->
                <DropdownMenu v-if="exportResource">
                    <DropdownMenuTrigger as-child>
                        <Button variant="outline" size="sm" class="h-9 rounded-lg" :disabled="exporting">
                            <Loader2 v-if="exporting" class="size-4 animate-spin" />
                            <Download v-else class="size-4" />
                            <span class="hidden sm:inline">{{ exporting ? 'Exporting…' : 'Export' }}</span>
                        </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end" class="w-68 p-1.5">
                        <div class="px-2.5 py-2">
                            <p class="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
                                Export current view
                            </p>
                            <p class="mt-0.5 text-xs leading-snug text-muted-foreground/80">{{ exportHint }}</p>
                        </div>
                        <DropdownMenuSeparator class="my-1.5" />
                        <DropdownMenuItem
                            class="dt-menu-item cursor-pointer gap-3 rounded-lg px-2.5 py-2.5"
                            @select="runExport('excel')"
                        >
                            <span class="grid size-8 shrink-0 place-items-center rounded-lg bg-emerald-500/10 text-emerald-600 dark:text-emerald-400">
                                <FileSpreadsheet class="size-4" />
                            </span>
                            <span class="flex min-w-0 flex-col leading-tight">
                                <span class="font-medium">Export to Excel</span>
                                <span class="text-xs text-muted-foreground">.xlsx · full fidelity</span>
                            </span>
                        </DropdownMenuItem>
                        <DropdownMenuItem
                            class="dt-menu-item cursor-pointer gap-3 rounded-lg px-2.5 py-2.5"
                            @select="runExport('pdf')"
                        >
                            <span class="grid size-8 shrink-0 place-items-center rounded-lg bg-rose-500/10 text-rose-600 dark:text-rose-400">
                                <FileText class="size-4" />
                            </span>
                            <span class="flex min-w-0 flex-col leading-tight">
                                <span class="font-medium">Export to PDF</span>
                                <span class="text-xs text-muted-foreground">.pdf · print ready</span>
                            </span>
                        </DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>

                <Button variant="outline" size="sm" class="h-9 rounded-lg" @click="printView">
                    <Printer class="size-4" />
                    <span class="hidden sm:inline">Print</span>
                </Button>
            </div>
        </div>

        <!-- Active filter chips -->
        <div
            v-if="Object.keys(activeFilters).length > 0 || hasSearch"
            class="flex flex-wrap items-center gap-2 print:hidden"
        >
            <span
                class="inline-flex items-center gap-1.5 rounded-full border border-primary/25 bg-primary/5 px-2.5 py-1 text-[11px] font-semibold uppercase tracking-wider text-primary"
            >
                <SlidersHorizontal class="size-3" />
                Filtered
            </span>
            <span
                v-if="hasSearch"
                class="inline-flex items-center gap-1.5 rounded-full border bg-card px-2.5 py-1 text-xs font-medium shadow-sm"
            >
                <span class="text-muted-foreground">Search:</span>
                “{{ urlParams.get('search') }}”
                <button
                    type="button"
                    class="rounded-full p-0.5 text-muted-foreground transition hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                    aria-label="Clear search filter"
                    @click="searchInput = ''; onSearchInput('')"
                >
                    <X class="size-3" />
                </button>
            </span>
            <span
                v-for="(value, key) in activeFilters"
                :key="key"
                class="inline-flex items-center gap-1.5 rounded-full border bg-card px-2.5 py-1 text-xs font-medium shadow-sm"
            >
                <span class="text-muted-foreground">{{ filterDefs.find((def) => def.key === key)?.label ?? key }}:</span>
                {{ value }}
                <button
                    type="button"
                    class="rounded-full p-0.5 text-muted-foreground transition hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                    :aria-label="`Remove ${key} filter`"
                    @click="applyFilter({ key, label: key, kind: 'select' }, null)"
                >
                    <X class="size-3" />
                </button>
            </span>
            <button
                type="button"
                class="rounded-full px-2 py-1 text-xs font-medium text-muted-foreground transition hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                @click="clearAll"
            >
                Clear all
            </button>
        </div>

        <!-- Table card -->
        <div class="relative overflow-hidden rounded-xl border bg-card shadow-sm">
            <!-- indeterminate progress -->
            <div
                v-if="loading"
                class="absolute inset-x-0 top-0 z-20 h-0.5 overflow-hidden bg-muted"
            >
                <div class="dt-progress h-full w-1/3 rounded-full bg-primary" />
            </div>

            <div
                class="dt-scroll max-h-[72vh] overflow-auto print:max-h-none print:overflow-visible"
                :class="loading && 'pointer-events-none opacity-70 transition-opacity'"
            >
                <table class="w-full caption-bottom text-sm">
                    <thead>
                        <tr class="border-b">
                            <th
                                v-for="column in visibleColumns"
                                :key="column.key"
                                scope="col"
                                :style="column.width ? { width: column.width } : undefined"
                                :class="
                                    cn(
                                        'dt-th sticky top-0 z-[1] px-4 py-3 text-xs font-semibold uppercase tracking-wider text-muted-foreground',
                                        column.align === 'right' && 'text-right',
                                        column.align === 'center' && 'text-center',
                                        column.key === 'actions' && 'w-1 print:hidden',
                                        column.sortable && 'select-none',
                                        column.headerClass,
                                    )
                                "
                            >
                                <button
                                    v-if="column.sortable"
                                    type="button"
                                    class="group inline-flex items-center gap-1.5 rounded transition hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                    :class="sortState(column.key) && 'text-foreground'"
                                    :aria-label="`Sort by ${column.label}`"
                                    @click="toggleSort(column)"
                                >
                                    {{ column.label }}
                                    <ArrowUp
                                        v-if="sortState(column.key) === 'asc'"
                                        class="size-3.5 text-primary"
                                    />
                                    <ArrowDown
                                        v-else-if="sortState(column.key) === 'desc'"
                                        class="size-3.5 text-primary"
                                    />
                                    <ArrowUpDown
                                        v-else
                                        class="size-3 text-muted-foreground/40 transition group-hover:text-muted-foreground"
                                    />
                                </button>
                                <template v-else>{{ column.label }}</template>
                            </th>
                        </tr>
                    </thead>

                    <tbody class="divide-y divide-border/70">
                        <tr
                            v-for="(row, rowIndex) in rows"
                            :key="`${meta.current_page}-${row.id}`"
                            class="dt-row group transition-colors hover:bg-accent/50"
                            :style="rowStyle(rowIndex)"
                        >
                            <td
                                v-for="column in visibleColumns"
                                :key="column.key"
                                :class="
                                    cn(
                                        'px-4 py-3 align-middle text-sm text-foreground/90',
                                        column.align === 'right' && 'text-right tabular-nums',
                                        column.align === 'center' && 'text-center',
                                        column.key === 'actions' && 'w-1 print:hidden',
                                        activeSortKey && sortState(column.key) && 'bg-primary/[0.035]',
                                        column.cellClass,
                                    )
                                "
                            >
                                <slot
                                    :name="`cell-${column.key}`"
                                    :row="row"
                                    :value="row[column.key]"
                                    :index="rowIndex"
                                >
                                    <span :class="column.key === 'created_at' ? 'whitespace-nowrap text-muted-foreground' : ''">
                                        {{ cellValue(row, column.key) }}
                                    </span>
                                </slot>
                            </td>
                        </tr>

                        <!-- Empty state -->
                        <tr v-if="rows.length === 0">
                            <td :colspan="visibleColumns.length" class="px-4 py-16">
                                <div class="mx-auto flex max-w-sm flex-col items-center text-center">
                                    <div
                                        class="grid size-16 place-items-center rounded-2xl border border-dashed bg-gradient-to-b from-muted/80 to-transparent"
                                    >
                                        <Inbox class="size-7 text-muted-foreground/70" />
                                    </div>
                                    <h3 class="mt-4 text-sm font-semibold">{{ emptyTitle }}</h3>
                                    <p class="mt-1 text-sm leading-relaxed text-muted-foreground">{{ emptyDescription }}</p>
                                    <Button
                                        v-if="hasActiveFilters"
                                        variant="outline"
                                        size="sm"
                                        class="mt-4 rounded-lg"
                                        @click="clearAll"
                                    >
                                        Clear search & filters
                                    </Button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Footer -->
            <div
                v-if="meta.total > 0"
                class="flex flex-col gap-3 border-t px-4 py-3 sm:flex-row sm:items-center sm:justify-between print:hidden"
            >
                <div class="flex items-center gap-4 text-sm text-muted-foreground">
                    <span>
                        Showing <strong class="font-medium text-foreground">{{ formatNumber(meta.from) }}</strong>
                        –
                        <strong class="font-medium text-foreground">{{ formatNumber(meta.to) }}</strong>
                        of
                        <strong class="font-medium text-foreground">{{ formatNumber(meta.total) }}</strong>
                        <span class="ml-1.5 text-muted-foreground/70">
                            · Page {{ formatNumber(meta.current_page) }} / {{ formatNumber(meta.last_page) }}
                        </span>
                    </span>

                    <label class="flex items-center gap-2 text-sm">
                        <span class="whitespace-nowrap">Rows</span>
                        <select
                            :value="meta.per_page"
                            class="h-8 rounded-lg border bg-muted/50 px-2 text-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                            @change="navigate({ per_page: Number(($event.target as HTMLSelectElement).value) })"
                        >
                            <option v-for="size in perPageOptions" :key="size" :value="size">{{ size }}</option>
                        </select>
                    </label>
                </div>

                <nav class="flex items-center gap-1" aria-label="Pagination">
                    <button
                        type="button"
                        class="inline-flex h-8 w-8 items-center justify-center rounded-lg border text-muted-foreground transition hover:bg-accent hover:text-accent-foreground disabled:pointer-events-none disabled:opacity-40"
                        :disabled="meta.current_page <= 1 || loading"
                        aria-label="First page"
                        @click="navigate({ page: 1 }, true)"
                    >
                        <ChevronsLeft class="size-4" />
                    </button>
                    <button
                        type="button"
                        class="inline-flex h-8 w-8 items-center justify-center rounded-lg border text-muted-foreground transition hover:bg-accent hover:text-accent-foreground disabled:pointer-events-none disabled:opacity-40"
                        :disabled="meta.current_page <= 1 || loading"
                        aria-label="Previous page"
                        @click="navigate({ page: meta.current_page - 1 }, true)"
                    >
                        <ChevronLeft class="size-4" />
                    </button>

                    <template v-for="entry in pageWindow" :key="entry">
                        <span
                            v-if="entry === 'gap-start' || entry === 'gap-end'"
                            class="px-1.5 text-sm text-muted-foreground"
                            aria-hidden="true"
                        >
                            …
                        </span>
                        <button
                            v-else
                            type="button"
                            :class="
                                cn(
                                    'inline-flex h-8 min-w-8 items-center justify-center rounded-lg px-2 text-sm font-medium transition',
                                    entry === meta.current_page
                                        ? 'bg-primary text-primary-foreground shadow-sm shadow-primary/30'
                                        : 'text-muted-foreground hover:bg-accent hover:text-accent-foreground',
                                )
                            "
                            :aria-current="entry === meta.current_page ? 'page' : undefined"
                            :aria-label="`Page ${entry}`"
                            :disabled="loading"
                            @click="navigate({ page: entry }, true)"
                        >
                            {{ entry }}
                        </button>
                    </template>

                    <button
                        type="button"
                        class="inline-flex h-8 w-8 items-center justify-center rounded-lg border text-muted-foreground transition hover:bg-accent hover:text-accent-foreground disabled:pointer-events-none disabled:opacity-40"
                        :disabled="meta.current_page >= meta.last_page || loading"
                        aria-label="Next page"
                        @click="navigate({ page: meta.current_page + 1 }, true)"
                    >
                        <ChevronRight class="size-4" />
                    </button>
                    <button
                        type="button"
                        class="inline-flex h-8 w-8 items-center justify-center rounded-lg border text-muted-foreground transition hover:bg-accent hover:text-accent-foreground disabled:pointer-events-none disabled:opacity-40"
                        :disabled="meta.current_page >= meta.last_page || loading"
                        aria-label="Last page"
                        @click="navigate({ page: meta.last_page }, true)"
                    >
                        <ChevronsRight class="size-4" />
                    </button>
                </nav>
            </div>
        </div>
    </div>
</template>

<style scoped>
.dt-progress {
    animation: dt-indeterminate 1.1s ease-in-out infinite;
}

@keyframes dt-indeterminate {
    0% {
        transform: translateX(-100%);
    }
    100% {
        transform: translateX(400%);
    }
}

/* Sticky header over the scrolled body (theme vars are raw HSL triplets) */
.dt-th {
    background-color: hsl(var(--muted) / 0.88);
    backdrop-filter: blur(6px);
    box-shadow: inset 0 -1px 0 0 hsl(var(--border));
}

/* Staggered row entrance — re-triggered whenever the page key changes */
.dt-row {
    animation: dt-row-in 0.32s cubic-bezier(0.21, 1.02, 0.73, 1) both;
    animation-delay: var(--dt-row-delay, 0ms);
}

@keyframes dt-row-in {
    from {
        opacity: 0;
        transform: translateY(6px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

@media (prefers-reduced-motion: reduce) {
    .dt-row,
    .dt-progress {
        animation: none !important;
    }
}

@media print {
    .dt-th {
        position: static !important;
        background: transparent !important;
        backdrop-filter: none !important;
        box-shadow: none !important;
    }

    table {
        font-size: 11px;
    }

    thead th {
        color: #111827 !important;
        border-bottom: 2px solid #111827 !important;
    }

    tbody tr:nth-child(even) {
        background: #f9fafb;
    }
}
</style>
