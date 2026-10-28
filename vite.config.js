import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
        }),
        tailwindcss(),
    ],
    server: {
        host: '0.0.0.0',
        port: 5173,
        // ES module scripts require CORS: pages are served from nginx
        // (a different origin), so the dev server must allow them.
        cors: true,
        hmr: {
            host: 'localhost',
        },
    },
});
