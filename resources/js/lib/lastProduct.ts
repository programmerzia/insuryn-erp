/**
 * Flow fix X6: the product a new quote starts with — the one already chosen (a saved quotation or a draft), else the product the user quoted last while
 * it can still be quoted here, else the only product when there is just one; empty otherwise, so the user picks.
 */
export function startingProduct(chosen: string | null | undefined, lastProductId: string | null | undefined, products: { id: string }[]): string {
    if (chosen) return chosen;
    if (lastProductId && products.some((p) => p.id === lastProductId)) return lastProductId;
    return products.length === 1 ? (products[0]?.id ?? '') : '';
}
