import { defineConfig } from 'vitest/config';

// Отдельно от vite.config.js: плагину Laravel в тестах делать нечего.
// Тесты — только tests/js: в vendor и node_modules свои *.test.js.
export default defineConfig({
    test: {
        include: ['tests/js/**/*.test.js'],
        environment: 'node',
    },
});
