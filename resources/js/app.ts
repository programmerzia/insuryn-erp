import '../css/app.css';

import { createInertiaApp } from '@inertiajs/vue3';
import type { DefineComponent } from 'vue';
import { createApp, h } from 'vue';

// UX brief §7: each page is its own chunk, loaded when first visited (and prefetched on hover where links ask for it).
const pages = import.meta.glob<DefineComponent>('./pages/**/*.vue');

/** The stylesheet loads without blocking the first paint (app.blade.php); mount once it has arrived so nothing flashes unstyled. */
function stylesReady(): Promise<void> {
    const links = [...document.querySelectorAll<HTMLLinkElement>('link[rel="stylesheet"][media="print"]')];
    return Promise.all(links.map((link) => (link.sheet ? Promise.resolve() : new Promise<void>((resolve) => {
        link.addEventListener('load', () => resolve(), { once: true });
        link.addEventListener('error', () => resolve(), { once: true });
    })))).then(() => undefined);
}

void createInertiaApp({
    title: (title) => (title ? `${title} · Insuryn` : 'Insuryn'),
    resolve: async (name) => {
        const page = pages[`./pages/${name}.vue`];
        if (!page) {
            throw new Error(`Inertia page not found: ${name}`);
        }
        return page();
    },
    setup({ el, App, props, plugin }) {
        if (el) {
            void stylesReady().then(() => {
                createApp({ render: () => h(App, props) }).use(plugin).mount(el);
                document.getElementById('boot')?.remove();
            });
        }
    },
    progress: { color: 'var(--focus)', delay: 250 },
});
