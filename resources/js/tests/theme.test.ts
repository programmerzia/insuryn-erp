import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join } from 'node:path';
import { describe, expect, it } from 'vitest';

/**
 * UX brief §2 and U1: the theme file is the only place colours are written; every text and control pairing
 * a component uses clears WCAG AA in both modes.
 */
const root = join(__dirname, '..', '..', '..');
const themeCss = readFileSync(join(root, 'resources/css/theme/corebari.css'), 'utf8');

type Rgba = [number, number, number, number];

function tokensOf(block: string): Record<string, string> {
    const tokens: Record<string, string> = {};
    for (const match of block.matchAll(/--([a-z0-9-]+):\s*([^;]+);/g)) {
        tokens[match[1] ?? ''] = (match[2] ?? '').trim();
    }
    return tokens;
}

function block(selector: string): string {
    const start = themeCss.indexOf(selector);
    expect(start, `theme block ${selector}`).toBeGreaterThanOrEqual(0);
    const open = themeCss.indexOf('{', start);
    return themeCss.slice(open + 1, themeCss.indexOf('}', open));
}

function parse(colour: string): Rgba {
    const hex = colour.match(/^#([0-9a-f]{6})$/i);
    if (hex) {
        const n = parseInt(hex[1] ?? '', 16);
        return [(n >> 16) & 255, (n >> 8) & 255, n & 255, 1];
    }
    const rgb = colour.match(/^rgb\((\d+) (\d+) (\d+)(?: \/ ([\d.]+))?\)$/);
    if (rgb) {
        return [Number(rgb[1]), Number(rgb[2]), Number(rgb[3]), rgb[4] === undefined ? 1 : Number(rgb[4])];
    }
    throw new Error(`Unsupported colour ${colour}`);
}

function over(top: Rgba, ground: Rgba): Rgba {
    const a = top[3];
    return [top[0] * a + ground[0] * (1 - a), top[1] * a + ground[1] * (1 - a), top[2] * a + ground[2] * (1 - a), 1];
}

function luminance([r, g, b]: Rgba): number {
    const channel = (v: number) => {
        const s = v / 255;
        return s <= 0.03928 ? s / 12.92 : ((s + 0.055) / 1.055) ** 2.4;
    };
    return 0.2126 * channel(r) + 0.7152 * channel(g) + 0.0722 * channel(b);
}

function contrast(fore: string, back: string, tokens: Record<string, string>): number {
    const ground = parse(tokens[back] ?? '');
    const a = luminance(over(parse(tokens[fore] ?? ''), ground));
    const b = luminance(ground);
    return (Math.max(a, b) + 0.05) / (Math.min(a, b) + 0.05);
}

const light = tokensOf(block(':root {'));
const dark = { ...light, ...tokensOf(block(':root[data-theme="dark"]')) };

/** [foreground, background, minimum ratio]: 4.5 for text, 3 for control boundaries and focus rings (WCAG 1.4.3, 1.4.11). */
const pairs: [string, string, number][] = [
    ['ink', 'surface', 4.5], ['ink', 'surface-2', 4.5], ['ink', 'accent-soft', 4.5],
    ['ink-2', 'surface', 4.5], ['ink-2', 'surface-2', 4.5], ['ink-2', 'accent-soft', 4.5],
    ['accent-text', 'surface', 4.5], ['accent-text', 'surface-2', 4.5], ['accent-text', 'accent-soft', 4.5],
    ['accent-ink', 'accent', 4.5], ['accent-ink', 'accent-hover', 4.5],
    ['ok', 'surface', 4.5], ['ok', 'surface-2', 4.5], ['warn', 'surface', 4.5], ['warn', 'surface-2', 4.5],
    ['danger', 'surface', 4.5], ['danger', 'surface-2', 4.5],
    ['line-control', 'surface', 3], ['line-control', 'surface-2', 3], ['focus', 'surface', 3], ['focus', 'surface-2', 3],
];

describe('CoreBari semantic tokens', () => {
    const names = ['surface', 'surface-2', 'line', 'ink', 'ink-2', 'accent', 'accent-soft', 'ok', 'warn', 'danger'];

    it.each([['light', light], ['dark', dark]] as const)('defines every brief §2 token in %s mode', (_mode, tokens) => {
        for (const name of names) {
            expect(tokens[name], name).toBeDefined();
        }
    });

    it('redefines the dark tokens for the system preference and the explicit choice alike', () => {
        const system = tokensOf(block('@media (prefers-color-scheme: dark)'));
        const explicit = tokensOf(block(':root[data-theme="dark"]'));
        expect(system).toEqual(explicit);
    });

    it.each(pairs)('%s on %s clears %s:1 in light mode', (fore, back, minimum) => {
        expect(contrast(fore, back, light)).toBeGreaterThanOrEqual(minimum);
    });

    it.each(pairs)('%s on %s clears %s:1 in dark mode', (fore, back, minimum) => {
        expect(contrast(fore, back, dark)).toBeGreaterThanOrEqual(minimum);
    });
});

describe('components consume tokens only', () => {
    function files(dir: string): string[] {
        return readdirSync(dir).flatMap((name) => {
            const path = join(dir, name);
            return statSync(path).isDirectory() ? files(path) : [path];
        });
    }

    const sources = [...files(join(root, 'resources/js')), ...files(join(root, 'resources/css'))]
        .filter((path) => /\.(vue|ts|css)$/.test(path) && !path.endsWith('theme/corebari.css') && !path.endsWith('.test.ts'));
    const retired = /\b(?:bg|text|border|ring|outline|decoration|divide|from|to)-(?:bg|bg-deep|ivory|ivory-dim|blueprint|brick|brick-soft|brick-hover|amber|green|surface-raised)\b/;

    it.each(sources.map((path) => [path.slice(root.length + 1)]))('%s uses only theme tokens, the type scale and the radius rules', (path) => {
        const text = readFileSync(join(root, path), 'utf8');
        expect(text).not.toMatch(/#[0-9a-fA-F]{3,8}\b(?![\w-])/);
        expect(text).not.toMatch(retired);
        expect(text).not.toMatch(/\b(?:uppercase|font-display|font-bold|font-mono|font-light|font-extrabold)\b/);
        // brief §2 type scale and shape: text-dense/ui/body/section/title; rounded-control/panel (tables 0); one shadow for floating layers
        expect(text).not.toMatch(/\btext-(?:xs|sm|base|lg|xl|2xl|3xl|4xl|\[\d+px\])(?![\w-])/);
        expect(text).not.toMatch(/\brounded(?:-(?:sm|md|lg|xl|2xl|none))?(?=[\s"'`])/);
        expect(text).not.toMatch(/\bshadow-(?!float\b)[a-z]+/);
    });
});
