import { formatMinor } from '@/lib/money';
import type { RatingResultData } from '@/lib/riskForm';

/**
 * Phase 3 slice R7: an endorsement's re-rating as the policy page receives it (PolicyLifecycle::rateEndorsement → EndorsementRating::toArray) — the rating in
 * force before, the new rating, and the premium change charged — turned into the rows the endorse drawer and the Rating tab show. Pure functions, no Vue.
 */
export interface EndorsementRatingData {
    basis: 'original_plan' | 'current_tariff';
    before: RatingResultData;
    after: RatingResultData;
    change: { net_minor: number; tax_minor: number; stamp_duty_minor: number; gross_minor: number };
    pro_rata: boolean;
    days_charged: number;
    days_in_term: number;
}

export interface PremiumParts {
    net: number;
    tax: number;
    stamp: number;
    gross: number;
}

/** A rating result's premium as the policy charges it: net, VAT and levies (every duty but stamp), stamp duty, gross (D-37). */
export function premiumParts(result: RatingResultData): PremiumParts {
    const stamp = result.duties.filter((d) => d.code === 'stamp').reduce((sum, d) => sum + d.amount_minor, 0);
    return { net: result.net_premium_minor, tax: result.duties_total_minor - stamp, stamp, gross: result.gross_premium_minor };
}

/** Before, after and the change charged, per premium part, in major units; a decrease in parentheses ("(2,440.00)"), as numbers are shown everywhere. */
export function changeRows(rating: EndorsementRatingData): { label: string; before: string; after: string; change: string }[] {
    const before = premiumParts(rating.before);
    const after = premiumParts(rating.after);
    const money = (minor: number) => formatMinor(BigInt(minor));
    return [
        { label: 'Net premium', before: money(before.net), after: money(after.net), change: money(rating.change.net_minor) },
        { label: 'VAT and levies', before: money(before.tax), after: money(after.tax), change: money(rating.change.tax_minor) },
        { label: 'Stamp duty', before: money(before.stamp), after: money(after.stamp), change: money(rating.change.stamp_duty_minor) },
        { label: 'Gross premium', before: money(before.gross), after: money(after.gross), change: money(rating.change.gross_minor) },
    ];
}

/** One sentence on which tariff re-rated the risk and how the change is charged. */
export function basisSentence(rating: EndorsementRatingData): string {
    const plan = `${rating.after.plan.code} version ${rating.after.plan.version}`;
    const tariff = rating.basis === 'original_plan' ? `Re-rated on the policy's own tariff, ${plan}.` : `Re-rated on the tariff in force, ${plan}.`;
    const charge = rating.pro_rata ? ` Net premium and VAT are charged for ${rating.days_charged} of ${rating.days_in_term} days; stamp duty in full.` : ' The whole annual difference is charged.';
    return tariff + charge;
}

/** What the endorsement will do, in words: "The premium goes down by 2,806.00.", "No premium change: nothing is posted." */
export function changeSentence(rating: EndorsementRatingData, currency: string): string {
    const gross = rating.change.gross_minor;
    if (gross === 0 && rating.change.net_minor === 0 && rating.change.tax_minor === 0 && rating.change.stamp_duty_minor === 0) return 'No premium change: nothing is posted.';
    const amount = formatMinor(BigInt(Math.abs(gross)));
    return gross >= 0 ? `The premium goes up by ${currency} ${amount}.` : `The premium goes down by ${currency} ${amount}.`;
}
