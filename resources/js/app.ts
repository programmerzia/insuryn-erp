import '../css/app.css';

import { createInertiaApp } from '@inertiajs/vue3';
import type { DefineComponent } from 'vue';
import { createApp, h } from 'vue';

const pages = import.meta.glob<DefineComponent>('./pages/**/*.vue', { eager: true });

void createInertiaApp({
    title: (title) => (title ? `${title} · Insuryn` : 'Insuryn'),
    resolve: (name) => {
        const page = pages[`./pages/${name}.vue`];
        if (!page) {
            throw new Error(`Inertia page not found: ${name}`);
        }
        return page;
    },
    setup({ el, App, props, plugin }) {
        if (el) {
            createApp({ render: () => h(App, props) }).use(plugin).mount(el);
        }
    },
    progress: { color: '#8fd3ff' },
});
