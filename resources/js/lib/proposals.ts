import type { RatingResultData } from '@/lib/riskForm';

/** A proposal as the proposal page and the referral queue receive it (slice R5, ProposalPageController::present). */
export interface ProposalData {
    id: string; number: string; status: string; underwriting_status: string | null; quotation: { id: string; number: string }; customer: string; producer: string | null;
    product: string; class_code: string; inception: string; currency: string; sum_insured: string; net_premium: string; duties: string; gross_premium: string;
    rating_result: RatingResultData; kyc_status: string; kyc_id_type: string | null; kyc_id_number: string | null; kyc_waiver_reason: string | null; kyc_by: string | null;
    referral_reasons: { code: string; detail: string }[]; manual_loading: string | null; manual_loading_reason: string | null; submitted_by: string | null; submitted_at: string | null;
    decided_by: string | null; decision_reason: string | null; policy_id: string | null;
}
