/** The producer hierarchy as a tree on a date (Distribution design note §6 hierarchy tree), and the moves it may offer. */
export interface HierarchyRow {
    id: string;
    code: string;
    parent_id: string | null;
    level: string | null;
}

export interface TreeNode<T extends HierarchyRow = HierarchyRow> {
    row: T;
    id: string;
    code: string;
    children: TreeNode<T>[];
}

export interface FlatNode<T extends HierarchyRow = HierarchyRow> {
    row: T;
    id: string;
    code: string;
    depth: number;
    hasChildren: boolean;
}

/** Producers under their parents, siblings by code; a producer whose parent is not in the list is a root. */
export function buildTree<T extends HierarchyRow>(rows: T[]): TreeNode<T>[] {
    const byId = new Map<string, TreeNode<T>>(rows.map((row) => [row.id, { row, id: row.id, code: row.code, children: [] }]));
    const roots: TreeNode<T>[] = [];
    for (const node of byId.values()) {
        const parent = node.row.parent_id === null ? undefined : byId.get(node.row.parent_id);
        (parent ? parent.children : roots).push(node);
    }
    const sort = (list: TreeNode<T>[]): TreeNode<T>[] => {
        list.sort((a, b) => a.code.localeCompare(b.code));
        list.forEach((n) => sort(n.children));
        return list;
    };
    return sort(roots);
}

/** Depth-first rows for display; children of ids in `collapsed` are left out. */
export function flatten<T extends HierarchyRow>(tree: TreeNode<T>[], collapsed: Set<string> = new Set(), depth = 0): FlatNode<T>[] {
    return tree.flatMap((node) => [
        { row: node.row, id: node.id, code: node.code, depth, hasChildren: node.children.length > 0 },
        ...(collapsed.has(node.id) ? [] : flatten(node.children, collapsed, depth + 1)),
    ]);
}

function find<T extends HierarchyRow>(tree: TreeNode<T>[], id: string): TreeNode<T> | undefined {
    for (const node of tree) {
        if (node.id === id) return node;
        const inner = find(node.children, id);
        if (inner) return inner;
    }
    return undefined;
}

/** A move the tree may offer: not under the producer itself, not under anyone in its team, not where it already is. The server checks every date. */
export function canMoveUnder<T extends HierarchyRow>(tree: TreeNode<T>[], producerId: string, parentId: string | null): boolean {
    const node = find(tree, producerId);
    if (!node || node.row.parent_id === parentId) return false;
    if (parentId === null) return true;
    if (parentId === producerId) return false;
    const inTeam = (list: TreeNode<T>[]): boolean => list.some((child) => child.id === parentId || inTeam(child.children));
    return !inTeam(node.children);
}
