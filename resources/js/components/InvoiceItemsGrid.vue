<script lang="ts">
export type DraftItem = { description: string; quantity: number; unit_price: number };
</script>

<script setup lang="ts">
import type { CellValueChangedEvent, ColDef, EditableCallbackParams, GridApi, GridReadyEvent, ITooltipParams } from 'ag-grid-community';
import { shallowRef, watch } from 'vue';
import DataGrid from '@/components/DataGrid.vue';

type Field = keyof DraftItem;
type Origin = 'ai' | 'edited' | 'manual';
type Row = DraftItem & { id: string; base: DraftItem | null; origin: Record<Field, Origin> };

const props = defineProps<{ aiItems: DraftItem[]; currency: string }>();
const emit = defineEmits<{ change: [items: DraftItem[], total: number] }>();

let seq = 0;
const makeRow = (item: DraftItem | null): Row => {
    const o: Origin = item ? 'ai' : 'manual';
    const v = item ?? { description: '', quantity: 1, unit_price: 0 };
    return { id: `row-${++seq}`, ...v, base: item ? { ...item } : null, origin: { description: o, quantity: o, unit_price: o } };
};

const api = shallowRef<GridApi>();
const rows = shallowRef<Row[]>([]);
const pinned = shallowRef<unknown[]>([]);

const currentRows = (): Row[] => {
    if (!api.value) return rows.value;
    const out: Row[] = [];
    api.value.forEachNode((n) => n.data && out.push(n.data as Row));
    return out;
};

// One place that recalculates the total and tells the form what the items are right now.
function sync() {
    const list = currentRows();
    const total = list.reduce((s, r) => s + r.quantity * r.unit_price, 0);
    pinned.value = [{ id: 'total', description: `Total · ${list.length} item${list.length === 1 ? '' : 's'}`, total, origin: {} }];
    emit('change', list.map(({ description, quantity, unit_price }) => ({ description, quantity, unit_price })), total);
}

function applyDraft(items: DraftItem[]) {
    rows.value = items.map(makeRow);
    api.value?.setGridOption('rowData', rows.value);
    sync();
}
watch(() => props.aiItems, applyDraft, { immediate: true });
watch(() => props.currency, () => api.value?.refreshCells({ force: true }));

function onReady(e: GridReadyEvent) {
    api.value = e.api;
    sync();
}

// A cell is "AI" while it equals the AI's value and "edited" otherwise, so undo restores the AI mark.
function onChanged(e: CellValueChangedEvent<Row>) {
    const f = e.colDef.field as Field | undefined;
    if (f && e.data?.base) e.data.origin[f] = e.data.base[f] === e.data[f] ? 'ai' : 'edited';
    e.api.refreshCells({ rowNodes: [e.node], force: true });
    sync();
}

function addItem() {
    const res = api.value?.applyTransaction({ add: [makeRow(null)] });
    sync();
    const idx = res?.add?.[0]?.rowIndex;
    if (idx != null) api.value?.startEditingCell({ rowIndex: idx, colKey: 'description' });
}

const rowId = (r: Row) => r.id;
const money = (n: number) => `${props.currency} ${n.toLocaleString(undefined, { maximumFractionDigits: 2 })}`;
const provenance = (f: Field) => ({
    'mf-ai': (p: { data?: Row }) => p.data?.origin?.[f] === 'ai',
    'mf-edited': (p: { data?: Row }) => p.data?.origin?.[f] === 'edited',
});
const tip = (f: Field) => (p: ITooltipParams<Row>) => {
    const o = p.data?.origin?.[f];
    if (o === 'ai') return 'Suggested by AI. Click to change it.';
    if (o === 'edited' && p.data?.base) return `AI suggested: ${f === 'unit_price' ? money(p.data.base[f]) : p.data.base[f]}`;
    return undefined;
};
const editable = (p: EditableCallbackParams<Row>) => !p.node.rowPinned;
const parseNum = (p: { newValue: unknown; oldValue: number }) => {
    const n = Number(p.newValue);
    return Number.isFinite(n) && n >= 0 ? n : p.oldValue;
};

const colDefs: ColDef<Row>[] = [
    {
        field: 'description',
        headerName: 'Description',
        flex: 1,
        minWidth: 220,
        editable,
        valueParser: (p) => String(p.newValue ?? '').trim() || p.oldValue,
        cellClassRules: provenance('description'),
        tooltipValueGetter: tip('description'),
    },
    { field: 'quantity', headerName: 'Qty', width: 100, editable, valueParser: parseNum, cellClassRules: provenance('quantity'), tooltipValueGetter: tip('quantity') },
    {
        field: 'unit_price',
        headerName: 'Unit price',
        width: 150,
        editable,
        valueParser: parseNum,
        valueFormatter: (p) => (p.value == null ? '' : money(p.value)),
        cellClassRules: provenance('unit_price'),
        tooltipValueGetter: tip('unit_price'),
    },
    {
        headerName: 'Line total',
        width: 170,
        sortable: false,
        headerClass: 'ag-right-aligned-header',
        cellClass: 'text-right font-semibold',
        valueGetter: (p) => (p.node?.rowPinned ? ((p.data as unknown as { total: number })?.total ?? 0) : (p.data?.quantity ?? 0) * (p.data?.unit_price ?? 0)),
        valueFormatter: (p) => money(p.value ?? 0),
    },
    {
        headerName: '',
        width: 56,
        sortable: false,
        cellRenderer: (p: { node: { rowPinned?: string } }) =>
            p.node.rowPinned ? '' : '<button type="button" aria-label="Remove item" class="text-[#6b7773] hover:text-[#b4432f]">✕</button>',
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
            <span class="inline-flex items-center gap-1.5"><span class="size-2.5 rounded-sm bg-[#15966b]" />AI suggested</span>
            <span class="inline-flex items-center gap-1.5"><span class="size-2.5 rounded-sm bg-[#b7791f]" />You edited (hover to see the AI’s value)</span>
            <span class="flex-1" />
            <button type="button" class="rounded-lg border border-[#e6e4dc] px-2.5 py-1 text-[#202a27]" @click="api?.undoCellEditing()">Undo</button>
            <button type="button" class="rounded-lg border border-[#e6e4dc] px-2.5 py-1 text-[#202a27]" @click="api?.redoCellEditing()">Redo</button>
            <button type="button" class="rounded-lg border border-[#e6e4dc] px-2.5 py-1 text-[#202a27]" @click="applyDraft(aiItems)">Reset to AI draft</button>
            <button type="button" class="rounded-lg border-[1.5px] border-[#123d32] px-2.5 py-1 font-semibold text-[#123d32]" @click="addItem">Add item</button>
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
            empty-text="No items yet. Describe the invoice above, or add an item."
            @grid-ready="onReady"
            @cell-value-changed="onChanged"
        />
    </div>
</template>

<style>
.ag-cell.mf-ai { background-color: #e8f7f5; box-shadow: inset 3px 0 0 #15966b; }
.ag-cell.mf-edited { background-color: #fbf0da; box-shadow: inset 3px 0 0 #b7791f; }
</style>
