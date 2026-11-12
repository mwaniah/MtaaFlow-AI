<script setup lang="ts">
import type {
    CellValueChangedEvent,
    ColDef,
    GetRowIdParams,
    GridApi,
    GridReadyEvent,
    IRowNode,
    ProcessCellForExportParams,
    RowClassParams,
    RowClickedEvent,
    RowStyle,
} from 'ag-grid-community';
import {
    AllCommunityModule,
    ModuleRegistry,
    themeQuartz,
} from 'ag-grid-community';
import { AgGridVue } from 'ag-grid-vue3';
import { computed, shallowRef, watch } from 'vue';
import { i18n, translate as t } from '@/lib/i18n';
import en from '@/locales/en.json';

ModuleRegistry.registerModules([AllCommunityModule]);

const props = withDefaults(
    defineProps<{
        rowData: unknown[];
        columnDefs: ColDef<any>[];
        rowHeight?: number;
        singleClickEdit?: boolean;
        undoRedoCellEditing?: boolean;
        undoRedoCellEditingLimit?: number;
        tooltipShowDelay?: number;
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
    gridReady: [event: GridReadyEvent];
    cellValueChanged: [event: CellValueChangedEvent<any>];
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
    headerBackgroundColor: '#f4f7f3',
    headerTextColor: '#51665b',
    headerFontSize: 12,
    headerFontWeight: 600,
    rowHoverColor: '#f0f8f3',
    wrapperBorder: false,
    wrapperBorderRadius: 0,
    columnBorder: false,
    valueChangeValueHighlightBackgroundColor: '#cfeee0',
});

// enableCellChangeFlash: any cell whose value changes through a data update flashes briefly.
const defaultColDef: ColDef = {
    sortable: true,
    resizable: true,
    suppressMovable: true,
    enableCellChangeFlash: true,
};
const emptyTemplate = computed(
    () =>
        `<span style="color:#6b7773;font-size:14px">${props.emptyText ?? t('common.nothing')}</span>`,
);
const translatedColumns = computed(() => {
    void i18n.global.locale.value;
    return props.columnDefs.map((column) => ({ ...column }));
});
const gridLocale = computed(() =>
    Object.fromEntries(
        Object.keys(en)
            .filter((key) => key.startsWith('grid.'))
            .map((key) => [key.slice(5), t(key)]),
    ),
);
const gridState = shallowRef<ReturnType<GridApi['getState']>>();
watch(
    () => i18n.global.locale.value,
    () => {
        api.value?.stopEditing();
        gridState.value = api.value?.getState();
    },
);

// With a stable row id, replacing rowData becomes a delta update: only changed rows and cells re-render.
const rowIdFn = props.getRowId
    ? (p: GetRowIdParams) => props.getRowId!(p.data)
    : undefined;

const isExternalFilterPresent = () => !!props.externalFilter;
const doesExternalFilterPass = (node: IRowNode) =>
    props.externalFilter ? props.externalFilter(node.data) : true;
watch(
    () => props.externalFilter,
    () => api.value?.onFilterChanged(),
);

const getRowStyle = (p: RowClassParams): RowStyle | undefined =>
    p.node.rowPinned
        ? { fontWeight: '600', backgroundColor: '#faf9f6' }
        : undefined;

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
    emit('gridReady', e);
    emitDisplayed();
}

defineExpose({
    exportCsv: (fileName = 'export.csv') =>
        api.value?.exportDataAsCsv({
            fileName,
            processCellCallback: props.exportCell,
        }),
});
</script>

<template>
    <AgGridVue
        :key="i18n.global.locale.value"
        :locale-text="gridLocale"
        :initial-state="gridState"
        class="mf-grid w-full"
        :theme="theme"
        :row-data="rowData"
        :column-defs="translatedColumns"
        :default-col-def="defaultColDef"
        :row-height="rowHeight ?? 52"
        :single-click-edit="singleClickEdit"
        :undo-redo-cell-editing="undoRedoCellEditing"
        :undo-redo-cell-editing-limit="undoRedoCellEditingLimit"
        :tooltip-show-delay="tooltipShowDelay"
        :row-class="clickable ? 'cursor-pointer' : undefined"
        :get-row-style="getRowStyle"
        :get-row-id="rowIdFn"
        :quick-filter-text="quickFilter"
        :is-external-filter-present="isExternalFilterPresent"
        :does-external-filter-pass="doesExternalFilterPass"
        :pinned-bottom-row-data="pinnedBottomRowData"
        :suppress-cell-focus="false"
        :pagination="paginate ?? rowData.length > 10"
        :pagination-page-size="10"
        :pagination-page-size-selector="false"
        :overlay-no-rows-template="emptyTemplate"
        dom-layout="autoHeight"
        @grid-ready="onReady"
        @cell-value-changed="emit('cellValueChanged', $event)"
        @model-updated="emitDisplayed"
        @row-clicked="emit('rowClicked', $event)"
    />
</template>
