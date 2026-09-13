import tailwindcss from '@tailwindcss/vite';
import vue from '@vitejs/plugin-vue';
import laravel from 'laravel-vite-plugin';
import { fileURLToPath, URL } from 'node:url';
import { defineConfig } from 'vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/css/theme/corebari.css', 'resources/js/app.ts'],
            refresh: true,
        }),
        vue({
            template: { transformAssetUrls: { base: null, includeAbsolute: false } },
        }),
        tailwindcss(),
    ],
    build: {
        rolldownOptions: {
            output: {
                // Long-lived vendor chunks: framework code changes less often than pages, so returning users keep it cached. Icons are not grouped,
                // so each page carries only the icons it uses.
                advancedChunks: {
                    groups: [
                        { name: 'vendor-vue', test: /node_modules[\\/](vue|@vue|@inertiajs|laravel-precognition|es-toolkit)[\\/]/, priority: 30 },
                        { name: 'vendor-table', test: /node_modules[\\/]@tanstack[\\/]/, priority: 20 },
                        { name: 'vendor-ui', test: /node_modules[\\/](reka-ui|@floating-ui|@vueuse|@internationalized|aria-hidden)[\\/]/, priority: 20 },
                    ],
                },
            },
        },
    },
    resolve: {
        alias: { '@': fileURLToPath(new URL('./resources/js', import.meta.url)) },
    },
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
