import { defineConfig } from 'vite'
import tailwindcss from '@tailwindcss/vite'

export default defineConfig(({ command }) => {
    const isBuild = command === 'build';

    return {
        base: isBuild ? '/wp-content/themes/goetz-legal/dist/' : '/',
        server: {
            port: 3000,
            cors: true,
            origin: 'http://goetzlegal.test',
        },
        build: {
            manifest: true,
            outDir: 'dist',
            rollupOptions: {
                input: [
                    'resources/ts/app.ts',
                    'resources/scss/app.scss',
                    'resources/scss/editor-style.scss'
                ],
            },
        },
        plugins: [
            tailwindcss(),
        ],
    }
});
