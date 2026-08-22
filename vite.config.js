import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/css/landing.css', 'resources/js/app.js'],
            refresh: true,
            fonts: [
                bunny('Manrope', {
                    subsets: ['cyrillic', 'latin'],
                    weights: [400, 500, 600, 700, 800],
                }),
                bunny('Neucha', {
                    subsets: ['cyrillic', 'latin'],
                }),
            ],
        }),
        tailwindcss(),
    ],
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
