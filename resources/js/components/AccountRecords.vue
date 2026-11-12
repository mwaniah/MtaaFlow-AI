<script setup lang="ts">
import { translate as t } from '@/lib/i18n';
import { fieldLabel } from '@/lib/i18n';
import { computed } from 'vue';

defineProps<{ records: Record<string, unknown> }>();
const label = fieldLabel;
const isRecord = (value: unknown): value is Record<string, unknown> =>
    typeof value === 'object' && value !== null && !Array.isArray(value);
const columns = (rows: unknown[]) => [
    ...new Set(rows.flatMap((row) => (isRecord(row) ? Object.keys(row) : []))),
];
const display = (value: unknown): string =>
    value === null || value === undefined
        ? t('common.unavailable')
        : typeof value === 'object'
          ? JSON.stringify(value)
          : String(value);
</script>

<template>
    <dl class="w-full min-w-0 space-y-4">
        <div
            v-for="(value, key) in records"
            :key="key"
            class="min-w-0 overflow-hidden rounded-xl border border-border bg-background/60"
        >
            <dt
                class="border-b border-border/70 px-4 py-2.5 text-[11px] font-bold tracking-[0.08em] text-muted-foreground uppercase"
            >
                {{ label(String(key)) }}
            </dt>
            <dd
                v-if="Array.isArray(value) && !value.length"
                class="px-4 py-4 text-sm text-muted-foreground"
            >
                {{ t('interface.no_records_found') }}
            </dd>
            <dd
                v-else-if="Array.isArray(value) && value.every(isRecord)"
                class="max-w-full overflow-x-auto overscroll-x-contain"
                tabindex="0"
                role="region"
                :aria-label="label(String(key))"
            >
                <table class="w-full text-left text-xs">
                    <caption class="sr-only">
                        {{
                            label(String(key))
                        }}
                    </caption>
                    <thead class="bg-card text-muted-foreground">
                        <tr>
                            <th
                                v-for="column in columns(value)"
                                :key="column"
                                scope="col"
                                class="px-4 py-3 font-medium whitespace-nowrap capitalize"
                            >
                                {{ label(column) }}
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border/70">
                        <tr
                            v-for="(row, index) in value"
                            :key="index"
                            class="bg-card/70"
                        >
                            <td
                                v-for="column in columns(value)"
                                :key="column"
                                class="max-w-72 px-4 py-3 align-top break-words tabular-nums"
                            >
                                {{ display(row[column]) }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </dd>
            <dd v-else-if="isRecord(value)" class="px-4 py-3">
                <dl class="grid gap-2 sm:grid-cols-2">
                    <div
                        v-for="(entry, field) in value"
                        :key="field"
                        class="min-w-0"
                    >
                        <dt class="text-xs text-muted-foreground capitalize">
                            {{ label(String(field)) }}
                        </dt>
                        <dd class="text-sm break-words tabular-nums">
                            {{ display(entry) }}
                        </dd>
                    </div>
                </dl>
            </dd>
            <dd
                v-else
                class="px-4 py-3 text-sm font-medium break-words tabular-nums"
            >
                {{ display(value) }}
            </dd>
        </div>
    </dl>
</template>
