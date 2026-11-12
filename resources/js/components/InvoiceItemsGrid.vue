<script lang="ts">
export type DraftItem = {
    description: string | null;
    quantity: number | null;
    unit_price: string | null;
};
export type GridInfo = { total: number; incomplete: number; modified: boolean };
</script>

<script setup lang="ts">
import { translate as t } from '@/lib/i18n';
import type {
    CellClassParams,
    CellValueChangedEvent,
    ColDef,
    EditableCallbackParams,
    GridApi,
    GridReadyEvent,
    ICellRendererParams,
    ITooltipParams,
} from 'ag-grid-community';
import { shallowRef, watch } from 'vue';
import DataGrid from '@/components/DataGrid.vue';
import { money as formatCurrency } from '@/types/business';

type Field = 'description' | 'quantity' | 'unit_price';
type Origin = 'ai' | 'edited' | 'manual';
type Values = {
    description: string | null;
    quantity: number | null;
    unit_price: number | null;
};
type Row = Values & {
    id: string;
    base: Values | null;
    origin: Record<Field, Origin>;
};

const props = defineProps<{ aiItems: DraftItem[]; currency: string | null }>();
const emit = defineEmits<{ change: [items: DraftItem[], info: GridInfo] }>();

let seq = 0;
let baseCount = 0;

const num = (v: unknown): number | null => {
    if (v === null || v === undefined || v === '') return null;
    const n = Number(v);
    return Number.isFinite(n) ? n : null;
};

const makeRow = (item: DraftItem | null): Row => {
    if (item) {
        const v: Values = {
            description: item.description?.trim() || null,
            quantity: num(item.quantity),
            unit_price: num(item.unit_price),
        };
        return {
            id: `row-${++seq}`,
            ...v,
            base: { ...v },
            origin: { description: 'ai', quantity: 'ai', unit_price: 'ai' },
        };
    }
    return {
        id: `row-${++seq}`,
        description: null,
        quantity: 1,
        unit_price: null,
        base: null,
        origin: {
            description: 'manual',
            quantity: 'manual',
            unit_price: 'manual',
        },
    };
};

const api = shallowRef<GridApi>();
const rows = shallowRef<Row[]>([]);
const pinned = shallowRef<unknown[]>([]);

const currentRows = (): Row[] => {
    if (!api.value) return rows.value;
    const out: Row[] = [];
    api.value.forEachNode((n) => {
        if (n.data) out.push(n.data as Row);
    });
    return out;
};

const isComplete = (r: Row) =>
    !!r.description &&
    r.quantity !== null &&
    r.quantity > 0 &&
    r.unit_price !== null;

// One place that recalculates totals and tells the page what the items are right now.
function sync() {
    const list = currentRows();
    const total = list.reduce(
        (s, r) =>
            s +
            (isComplete(r)
                ? (r.quantity as number) * (r.unit_price as number)
                : 0),
        0,
    );
    const incomplete = list.filter((r) => !isComplete(r)).length;
    const modified =
        list.length !== baseCount ||
        list.some(
            (r) =>
                r.base === null ||
                (Object.values(r.origin) as Origin[]).includes('edited'),
        );
    pinned.value = [
        {
            id: 'total',
            get description() {
                return (
                    t('invoices.itemTotal', { count: list.length }) +
                    (incomplete
                        ? ' · ' +
                          t('invoices.incomplete', { count: incomplete })
                        : '')
                );
            },
            total,
            origin: {},
        },
    ];
    emit(
        'change',
        list.map((r) => ({
            description: r.description,
            quantity: r.quantity,
            unit_price: r.unit_price === null ? null : String(r.unit_price),
        })),
        { total, incomplete, modified },
    );
}

function applyDraft(items: DraftItem[]) {
    baseCount = items.length;
    rows.value = items.map(makeRow);
    api.value?.setGridOption('rowData', rows.value);
    sync();
}
watch(() => props.aiItems, applyDraft, { immediate: true });
watch(
    () => props.currency,
    () => api.value?.refreshCells({ force: true }),
);

function onReady(e: GridReadyEvent) {
    api.value = e.api;
    sync();
}

// A cell is "AI" while it equals the AI's value and "edited" otherwise, so undo restores the AI mark.
function onChanged(e: CellValueChangedEvent<Row>) {
    const f = e.colDef.field as Field | undefined;
    if (f && e.data?.base)
        e.data.origin[f] = e.data.base[f] === e.data[f] ? 'ai' : 'edited';
    e.api.refreshCells({ rowNodes: [e.node], force: true });
    sync();
}

function addItem() {
    const res = api.value?.applyTransaction({ add: [makeRow(null)] });
    sync();
    const idx = res?.add?.[0]?.rowIndex;
    if (idx != null)
        api.value?.startEditingCell({ rowIndex: idx, colKey: 'description' });
}

const rowId = (r: Row) => r.id;
const money = (n: number) =>
    props.currency ? formatCurrency(n, props.currency) : String(n);

const rules = (f: Field) => ({
    'mf-missing': (p: CellClassParams<Row>) =>
        p.data?.[f] === null || p.data?.[f] === '',
    'mf-ai': (p: CellClassParams<Row>) =>
        p.data?.origin?.[f] === 'ai' && p.data?.[f] !== null,
    'mf-edited': (p: CellClassParams<Row>) =>
        p.data?.origin?.[f] === 'edited' && p.data?.[f] !== null,
});

const tip = (f: Field) => (p: ITooltipParams<Row>) => {
    const r = p.data;
    if (!r) return undefined;
    if (r[f] === null || r[f] === '') return t('invoices.needsYourInput');
    if (r.origin?.[f] === 'ai') return t('invoices.suggested');
    if (r.origin?.[f] === 'edited' && r.base) {
        const b = r.base[f];
        return `AI suggested: ${b === null ? t('common.nothingValue') : f === 'unit_price' ? money(b as number) : b}`;
    }
    return undefined;
};

const editable = (p: EditableCallbackParams<Row>) => !p.node.rowPinned;
const needsInput = () => t('invoices.needsInput');

const colDefs: ColDef<Row>[] = [
    {
        field: 'description',
        get headerName() {
            return t('common.description');
        },
        flex: 1,
        minWidth: 220,
        editable,
        valueParser: (p) => String(p.newValue ?? '').trim() || null,
        valueFormatter: (p) =>
            p.value === null || p.value === undefined || p.value === ''
                ? needsInput()
                : String(p.value),
        cellClassRules: rules('description'),
        tooltipValueGetter: tip('description'),
    },
    {
        field: 'quantity',
        get headerName() {
            return t('interface.qty');
        },
        width: 120,
        editable,
        valueParser: (p) => {
            const n = Number(p.newValue);
            return p.newValue !== '' && Number.isFinite(n) && n > 0
                ? n
                : p.oldValue;
        },
        valueFormatter: (p) =>
            p.node?.rowPinned
                ? ''
                : p.value === null || p.value === undefined
                  ? needsInput()
                  : String(p.value),
        cellClassRules: rules('quantity'),
        tooltipValueGetter: tip('quantity'),
    },
    {
        field: 'unit_price',
        get headerName() {
            return t('invoices.unitPrice');
        },
        width: 160,
        editable,
        valueParser: (p) => {
            const n = Number(p.newValue);
            return p.newValue !== '' && Number.isFinite(n) && n >= 0
                ? n
                : p.oldValue;
        },
        valueFormatter: (p) =>
            p.node?.rowPinned
                ? ''
                : p.value === null || p.value === undefined
                  ? needsInput()
                  : money(Number(p.value)),
        cellClassRules: rules('unit_price'),
        tooltipValueGetter: tip('unit_price'),
    },
    {
        get headerName() {
            return t('interface.line_total');
        },
        width: 170,
        sortable: false,
        headerClass: 'ag-right-aligned-header',
        cellClass: 'text-right font-semibold',
        valueGetter: (p) => {
            if (p.node?.rowPinned)
                return (
                    (p.data as unknown as { total: number } | undefined)
                        ?.total ?? 0
                );
            const r = p.data;
            return r && r.quantity !== null && r.unit_price !== null
                ? r.quantity * r.unit_price
                : null;
        },
        valueFormatter: (p) =>
            p.value === null || p.value === undefined
                ? '—'
                : money(Number(p.value)),
    },
    {
        headerName: '',
        width: 56,
        sortable: false,
        cellRenderer: (p: ICellRendererParams<Row>) =>
            p.node.rowPinned
                ? ''
                : `<button type="button" aria-label="${t('invoices.removeItem')}" class="text-[#6b7773] hover:text-[#b4432f]">✕</button>`,
        onCellClicked: (p) => {
            if (p.node.rowPinned || !p.data) return;
            p.api.applyTransaction({ remove: [p.data] });
            sync();
        },
    },
];
</script>

<template>
    <div class="flex flex-col gap-3">
        <div class="flex flex-wrap items-center gap-2 text-xs text-[#6b7773]">
            <span class="inline-flex items-center gap-1.5"
                ><span class="size-2.5 rounded-sm bg-[#15966b]" />{{
                    t('interface.ai_suggested')
                }}</span
            >
            <span class="inline-flex items-center gap-1.5"
                ><span class="size-2.5 rounded-sm bg-[#b7791f]" />{{
                    t('interface.you_edited_hover_to_see_the_ai_s_value')
                }}</span
            >
            <span class="inline-flex items-center gap-1.5"
                ><span class="size-2.5 rounded-sm bg-[#b4432f]" />{{
                    t('interface.needs_your_input')
                }}</span
            >
            <span class="flex-1" />
            <button
                type="button"
                class="rounded-lg border border-[#e6e4dc] px-2.5 py-1 text-[#202a27]"
                @click="api?.undoCellEditing()"
            >
                {{ t('interface.undo') }}
            </button>
            <button
                type="button"
                class="rounded-lg border border-[#e6e4dc] px-2.5 py-1 text-[#202a27]"
                @click="api?.redoCellEditing()"
            >
                {{ t('interface.redo') }}
            </button>
            <button
                type="button"
                class="rounded-lg border border-[#e6e4dc] px-2.5 py-1 text-[#202a27]"
                @click="applyDraft(aiItems)"
            >
                {{ t('interface.reset_to_ai_draft') }}
            </button>
            <button
                type="button"
                class="rounded-lg border-[1.5px] border-[#123d32] px-2.5 py-1 font-semibold text-[#123d32]"
                :disabled="!api"
                @click="addItem"
            >
                {{ t('invoices.addItem') }}
            </button>
        </div>
        <DataGrid
            :row-data="rows"
            :column-defs="colDefs"
            :get-row-id="rowId"
            :pinned-bottom-row-data="pinned"
            :paginate="false"
            :row-height="48"
            :undo-redo-cell-editing="true"
            :undo-redo-cell-editing-limit="25"
            :single-click-edit="true"
            :tooltip-show-delay="250"
            :empty-text="
                t(
                    'interface.no_items_yet_describe_the_invoice_above_or_add_an_item',
                )
            "
            @grid-ready="onReady"
            @cell-value-changed="onChanged"
        />
    </div>
</template>

<style>
.ag-cell.mf-ai {
    background-color: #e8f7f5;
    box-shadow: inset 3px 0 0 #15966b;
}
.ag-cell.mf-edited {
    background-color: #fbf0da;
    box-shadow: inset 3px 0 0 #b7791f;
}
.ag-cell.mf-missing {
    background-color: #f9e4df;
    box-shadow: inset 3px 0 0 #b4432f;
    color: #b4432f;
    font-style: italic;
}
</style>
