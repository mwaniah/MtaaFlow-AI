<script setup lang="ts">
import type { ColDef, RowClickedEvent } from 'ag-grid-community';
import { AllCommunityModule, ModuleRegistry, themeQuartz } from 'ag-grid-community';
import { AgGridVue } from 'ag-grid-vue3';

ModuleRegistry.registerModules([AllCommunityModule]);

const props = defineProps<{
    rowData: unknown[];
    columnDefs: ColDef<any>[];
    rowHeight?: number;
    clickable?: boolean;
    emptyText?: string;
}>();

const emit = defineEmits<{ rowClicked: [event: RowClickedEvent<any>] }>();

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
});

const defaultColDef: ColDef = { sortable: true, resizable: true, suppressMovable: true };
const emptyTemplate = `<span style="color:#6b7773;font-size:14px">${props.emptyText ?? 'Nothing to show yet.'}</span>`;
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
        :suppress-cell-focus="true"
        :pagination="rowData.length > 10"
        :pagination-page-size="10"
        :pagination-page-size-selector="false"
        :overlay-no-rows-template="emptyTemplate"
        dom-layout="autoHeight"
        @row-clicked="emit('rowClicked', $event)"
    />
</template>
