<script setup lang="ts">
import { computed } from 'vue';
import { formatDate } from '@/lib/format';
import { formatMinor } from '@/lib/money';
import { breakdown, type Locale, type RatingResultData } from '@/lib/riskForm';

/** A premium explained line by line (Phase 3 design §1 step 8): the rating result's explanation in English or Bangla, net, duties, gross and the tariff version. */
const props = withDefaults(defineProps<{ result: RatingResultData; locale: Locale; dimmed?: boolean }>(), { dimmed: false });
const lines = computed(() => breakdown(props.result, props.locale));
const money = (minor: number) => formatMinor(BigInt(minor));
</script>

<template>
    <div>
        <table class="w-full text-ui" :class="{ 'opacity-60': dimmed }">
            <tbody>
                <tr v-for="line in lines" :key="line.code" class="border-b border-line">
                    <td class="py-1 pr-2">{{ line.label }}</td>
                    <td class="py-1 text-right tabular-nums">{{ line.amount }}</td>
                </tr>
            </tbody>
            <tfoot>
                <tr><td class="pt-2 pr-2 text-ink-2">Net premium</td><td class="pt-2 text-right tabular-nums">{{ money(result.net_premium_minor) }}</td></tr>
                <tr><td class="pr-2 text-ink-2">Duties and VAT</td><td class="text-right tabular-nums">{{ money(result.duties_total_minor) }}</td></tr>
                <tr class="font-semibold"><td class="pt-1 pr-2">Gross premium ({{ result.currency }})</td><td class="pt-1 text-right tabular-nums">{{ money(result.gross_premium_minor) }}</td></tr>
            </tfoot>
        </table>
        <p class="mt-3 text-dense text-ink-2">Tariff {{ result.plan.code }} version {{ result.plan.version }}, rated for {{ formatDate(result.as_of) }}.</p>
        <p v-if="result.verify" class="mt-1 text-dense text-warn">Duty rates are placeholders to verify.</p>
    </div>
</template>
