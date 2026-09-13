import { onBeforeUnmount, onMounted } from 'vue';

/**
 * Keyboard shortcuts registry (UX brief §4 "Every action in a menu shows its shortcut"). Menus, tooltips and the command palette read
 * the keys from here; handlers register with useShortcut. Keys are written "Ctrl+K", "Ctrl+Enter", "Esc", "/"; Ctrl also matches ⌘.
 */
export const SHORTCUTS = {
    'app.palette': { keys: 'Ctrl+K', label: 'Search or run a command' },
    'app.sidebar': { keys: 'Ctrl+B', label: 'Collapse or expand the sidebar' },
    'app.home': { keys: 'Alt+H', label: 'Go to home' },
    'app.theme': { keys: 'Alt+T', label: 'Switch theme' },
    'app.density': { keys: 'Alt+D', label: 'Switch row density' },
    'table.filter': { keys: '/', label: 'Filter the table' },
    'table.open': { keys: 'Enter', label: 'Open the row' },
    'table.select': { keys: 'Space', label: 'Select the row' },
    'inspector.close': { keys: 'Esc', label: 'Close the inspector' },
    'inspector.primary': { keys: 'Ctrl+Enter', label: 'Run the primary action' },
    'form.save': { keys: 'Ctrl+S', label: 'Save draft' },
    'form.submit': { keys: 'Ctrl+Enter', label: 'Submit' },
    'form.cancel': { keys: 'Esc', label: 'Cancel' },
    'lookup.create': { keys: 'Ctrl+N', label: 'Create new' },
    'tab.pin': { keys: 'Ctrl+Click', label: 'Open as a pinned tab' },
} as const;

export type ShortcutId = keyof typeof SHORTCUTS;

export function shortcutKeys(id: ShortcutId): string {
    return SHORTCUTS[id].keys;
}

export function formatKeys(keys: string): string[] {
    return keys.split('+');
}

const named: Record<string, string> = { esc: 'escape', space: ' ', enter: 'enter' };

export function matchesKeys(event: KeyboardEvent, keys: string): boolean {
    const parts = keys.toLowerCase().split('+');
    const main = parts[parts.length - 1] ?? '';
    const wantCtrl = parts.includes('ctrl');
    const wantAlt = parts.includes('alt');
    const wantShift = parts.includes('shift');
    const ctrl = event.ctrlKey || event.metaKey;
    if (ctrl !== wantCtrl || event.altKey !== wantAlt || (event.shiftKey !== wantShift && main.length > 1)) {
        return false;
    }
    if (main.length === 1 && /[a-z]/.test(main) && event.shiftKey !== wantShift) {
        return false;
    }
    const expected = named[main] ?? main;
    const actual = event.key.toLowerCase();
    return actual === expected || (wantAlt && event.code.toLowerCase() === `key${expected}`);
}

/** True when the key event comes from a place where typing should not trigger single-key shortcuts. */
export function isTyping(event: KeyboardEvent): boolean {
    const target = event.target as HTMLElement | null;
    return !!target && (target.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName));
}

/** Runs handler on the shortcut while the component is mounted. Single keys without modifiers are ignored while typing. */
export function useShortcut(id: ShortcutId, handler: (event: KeyboardEvent) => void, options: { when?: () => boolean; allowInInputs?: boolean } = {}): void {
    const keys = shortcutKeys(id);
    const plain = !keys.includes('+');
    const listener = (event: KeyboardEvent) => {
        if (!matchesKeys(event, keys) || (options.when && !options.when())) {
            return;
        }
        if (plain && !options.allowInInputs && isTyping(event)) {
            return;
        }
        event.preventDefault();
        handler(event);
    };
    onMounted(() => window.addEventListener('keydown', listener));
    onBeforeUnmount(() => window.removeEventListener('keydown', listener));
}
