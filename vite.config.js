import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/css/shared.css',
                'resources/css/public.css',
                'resources/css/citizen.css',
                'resources/css/operator.css',
                'resources/css/admin.css',
                'resources/css/command.css',
                'resources/js/entries/public.js',
                'resources/js/entries/citizen.js',
                'resources/js/entries/operator.js',
                'resources/js/entries/admin.js',
                'resources/js/entries/command.js',
            ],
            refresh: true,
        }),
        tailwindcss(),
    ],
    build: {
        minify: false,
        cssMinify: false,
        sourcemap: true,
        rollupOptions: {
            output: {
                manualChunks: undefined,
            },
        },
    },
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
