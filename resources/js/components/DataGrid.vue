<script setup lang="ts">
import type { ColDef, GetRowIdParams, GridApi, GridReadyEvent, IRowNode, ProcessCellForExportParams, RowClassParams, RowClickedEvent, RowStyle } from 'ag-grid-community';
import { AllCommunityModule, ModuleRegistry, themeQuartz } from 'ag-grid-community';
import { AgGridVue } from 'ag-grid-vue3';
import { shallowRef, watch } from 'vue';

ModuleRegistry.registerModules([AllCommunityModule]);

const props = withDefaults(
    defineProps<{
        rowData: unknown[];
        columnDefs: ColDef<any>[];
        rowHeight?: number;
        clickable?: boolean;
        emptyText?: string;
        paginate?: boolean;
        getRowId?: (data: any) => string;
        quickFilter?: string;
        externalFilter?: ((data: any) => boolean) | null;
        pinnedBottomRowData?: unknown[];
        exportCell?: (p: ProcessCellForExportParams) => string;
    }>(),
    { paginate: undefined, externalFilter: null },
);

const emit = defineEmits<{
    rowClicked: [event: RowClickedEvent<any>];
    displayedRows: [rows: unknown[]];
}>();

const api = shallowRef<GridApi>();

const theme = themeQuartz.withParams({
    fontFamily: ['DM Sans Variable', 'DM Sans', 'system-ui', 'sans-serif'],
    fontSize: 14,
    foregroundColor: '#202a27',
    backgroundColor: '#ffffff',
    borderColor: '#e6e4dc',
    headerBackgroundColor: '#ffffff',
    headerTextColor: '#6b7773',
    headerFontSize: 12,
    headerFontWeight: 400,
    rowHoverColor: '#faf9f6',
    wrapperBorder: false,
    wrapperBorderRadius: 0,
    columnBorder: false,
    valueChangeValueHighlightBackgroundColor: '#cfeee0',
});

// enableCellChangeFlash: any cell whose value changes through a data update flashes briefly.
const defaultColDef: ColDef = { sortable: true, resizable: true, suppressMovable: true, enableCellChangeFlash: true };
const emptyTemplate = `<span style="color:#6b7773;font-size:14px">${props.emptyText ?? 'Nothing to show yet.'}</span>`;

// With a stable row id, replacing rowData becomes a delta update: only changed rows and cells re-render.
const rowIdFn = props.getRowId ? (p: GetRowIdParams) => props.getRowId!(p.data) : undefined;

const isExternalFilterPresent = () => !!props.externalFilter;
const doesExternalFilterPass = (node: IRowNode) => (props.externalFilter ? props.externalFilter(node.data) : true);
watch(() => props.externalFilter, () => api.value?.onFilterChanged());

const getRowStyle = (p: RowClassParams): RowStyle | undefined =>
    p.node.rowPinned ? { fontWeight: '600', backgroundColor: '#faf9f6' } : undefined;

// Tells the page which rows survive the grid's own filtering and sorting, so totals can follow them.
function emitDisplayed() {
    if (!api.value) return;
    const out: unknown[] = [];
    api.value.forEachNodeAfterFilterAndSort((n) => {
        if (n.data) out.push(n.data);
    });
    emit('displayedRows', out);
}

function onReady(e: GridReadyEvent) {
    api.value = e.api;
    emitDisplayed();
}

defineExpose({
    exportCsv: (fileName = 'export.csv') => api.value?.exportDataAsCsv({ fileName, processCellCallback: props.exportCell }),
});
</script>

<template>
    <AgGridVue
        class="w-full"
        :theme="theme"
        :row-data="rowData"
        :column-defs="columnDefs"
        :default-col-def="defaultColDef"
        :row-height="rowHeight ?? 48"
        :row-class="clickable ? 'cursor-pointer' : undefined"
        :get-row-style="getRowStyle"
        :get-row-id="rowIdFn"
        :quick-filter-text="quickFilter"
        :is-external-filter-present="isExternalFilterPresent"
        :does-external-filter-pass="doesExternalFilterPass"
        :pinned-bottom-row-data="pinnedBottomRowData"
        :suppress-cell-focus="true"
        :pagination="paginate ?? rowData.length > 10"
        :pagination-page-size="10"
        :pagination-page-size-selector="false"
        :overlay-no-rows-template="emptyTemplate"
        dom-layout="autoHeight"
        @grid-ready="onReady"
        @model-updated="emitDisplayed"
        @row-clicked="emit('rowClicked', $event)"
    />
</template>
