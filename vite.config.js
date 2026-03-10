import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/css/custom.css',
                'resources/js/app.js',
                'resources/js/bootstrap.js',
                'resources/js/dropdown-tree.js',
                'resources/js/product-picker.js',
                'resources/js/product-search.js',
                'resources/js/tree-view.js',
            ],
            refresh: true,
        }),
    ],
});
