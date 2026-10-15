import { defineConfig } from 'vite';
import { resolve } from 'node:path';

export default defineConfig({
    root: __dirname,
    publicDir: 'public',
    // Extension CSS is standalone; do not inherit the parent Laravel app's PostCSS plugins.
    css: { postcss: { plugins: [] } },
    build: {
        outDir: 'dist',
        emptyOutDir: true,
        rollupOptions: {
            input: {
                popup: resolve(__dirname, 'popup.html'),
                options: resolve(__dirname, 'options.html'),
                background: resolve(__dirname, 'src/background.ts'),
                content: resolve(__dirname, 'src/content.ts'),
                productBridge: resolve(__dirname, 'src/product-bridge.ts'),
            },
            output: {
                entryFileNames: (chunk) => chunk.name === 'background' ? 'service-worker.js' : chunk.name === 'content' ? 'content.js' : chunk.name === 'productBridge' ? 'product-bridge.js' : 'assets/[name].js',
                chunkFileNames: 'assets/[name].js',
                assetFileNames: 'assets/[name][extname]',
            },
        },
    },
});
