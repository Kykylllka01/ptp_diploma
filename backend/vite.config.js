import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import { readdirSync } from 'node:fs';

// Каждый экран — отдельная точка входа: resources/js/pages/<экран>.js
const pages = readdirSync('resources/js/pages')
    .filter((f) => f.endsWith('.js'))
    .map((f) => `resources/js/pages/${f}`);

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js', ...pages],
            refresh: true,
        }),
    ],
});
