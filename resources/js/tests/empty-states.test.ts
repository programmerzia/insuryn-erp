import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join } from 'node:path';
import { parse } from '@vue/compiler-sfc';
import { describe, expect, it } from 'vitest';

/**
 * Session S6 (UX brief §4): every queue's empty state is one sentence and has one action (its own, or the queue's primary action). Queues are QueueView
 * lists and page-level DataTables; tables inside a workbench (`:url-sync="false"`, such as the bank matching panes) sit next to their actions and are not queues.
 */
interface TemplateNode {
    type: number;
    tag?: string;
    props?: { type: number; name: string; value?: { content: string }; arg?: { content: string }; exp?: { content: string } }[];
    children?: TemplateNode[];
}

function pages(dir: string): string[] {
    return readdirSync(dir).flatMap((name) => {
        const path = join(dir, name);
        return statSync(path).isDirectory() ? pages(path) : path.endsWith('.vue') ? [path] : [];
    });
}

const isWorkbenchTable = (node: TemplateNode) => (node.props ?? []).some((prop) => prop.type === 7 && prop.arg?.content === 'url-sync' && prop.exp?.content === 'false');

function queueTags(node: TemplateNode): TemplateNode[] {
    const queue = node.tag === 'QueueView' || (node.tag === 'DataTable' && !isWorkbenchTable(node));
    return [...(queue ? [node] : []), ...(node.children ?? []).flatMap(queueTags)];
}

/** The sentence(s) an empty-text can show: the literal, or each quoted string in a bound expression. */
function sentences(tag: TemplateNode): string[] {
    for (const prop of tag.props ?? []) {
        if (prop.type === 6 && prop.name === 'empty-text') return [prop.value?.content ?? ''];
        if (prop.type === 7 && prop.arg?.content === 'empty-text') return [...(prop.exp?.content ?? '').matchAll(/'([^']+)'|`([^`]+)`/g)].map((m) => m[1] ?? m[2] ?? '');
    }
    return [];
}

const hasAction = (tag: TemplateNode) => (tag.props ?? []).some((prop) => prop.type === 7 && ['empty-action', 'action'].includes(prop.arg?.content ?? ''));

describe('queue empty states', () => {
    const files = pages('resources/js/pages').filter((path) => /<QueueView|<DataTable/.test(readFileSync(path, 'utf8')));

    it.each(files)('%s says why a queue is empty in one sentence and offers one action', (path) => {
        const { descriptor } = parse(readFileSync(path, 'utf8'));
        const tags = queueTags(descriptor.template!.ast as unknown as TemplateNode);
        for (const tag of tags) {
            const texts = sentences(tag);
            expect(texts.length, 'empty-text').toBeGreaterThan(0);
            for (const text of texts) expect(text.match(/[.!?](\s|$)/g)?.length ?? 0, text).toBe(1);
            expect(hasAction(tag), 'an empty action or a primary action').toBe(true);
        }
    });
});
