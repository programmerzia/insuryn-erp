import { computed, inject, type Ref } from 'vue';

export interface FieldContext {
    id: string;
    describedBy: string | undefined;
    invalid: boolean;
}

/** The surrounding Field's id and descriptions, for accessible controls. */
export function useField(fallbackId?: string) {
    const context = inject<Ref<FieldContext> | null>('field', null);
    return computed(() => ({
        id: context?.value.id ?? fallbackId,
        'aria-describedby': context?.value.describedBy,
        'aria-invalid': context?.value.invalid || undefined,
    }));
}
