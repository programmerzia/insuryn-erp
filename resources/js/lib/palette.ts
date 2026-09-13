import { ref } from 'vue';

/** Open state of the command palette (UX brief §4), shared by the top bar field, Ctrl+K and the palette itself. */
export const paletteOpen = ref(false);

export function openPalette(): void {
    paletteOpen.value = true;
}
