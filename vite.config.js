import { defineConfig } from 'vite';
import legacy from '@vitejs/plugin-legacy';
import compression from 'vite-plugin-compression';
import { resolve } from 'path';

export default defineConfig({
  plugins: [
    legacy({
      targets: ['> 0.5%', 'last 2 versions', 'not dead', 'not IE 11'],
    }),
    compression({
      algorithm: 'gzip',
      ext: '.gz',
    }),
  ],
  build: {
    outDir: 'dist',
    assetsDir: 'assets',
    emptyOutDir: true,
    manifest: true,
    rollupOptions: {
      input: {
        'theme': resolve(__dirname, 'assets/js/theme.js'),
        'auth-modal': resolve(__dirname, 'assets/js/auth-modal.js'),
        'quiz-public-v2': resolve(__dirname, 'assets/js/quiz-public-v2.js'),
        'quiz-results-ui': resolve(__dirname, 'assets/js/quiz-results-ui.js'),
        'share-result-page': resolve(__dirname, 'assets/js/share-result-page.js'),
        'main': resolve(__dirname, 'assets/css/style.css'),
      },
      output: {
        entryFileNames: 'js/[name].[hash].js',
        chunkFileNames: 'js/[name].[hash].js',
        assetFileNames: (assetInfo) => {
          if (assetInfo.name.endsWith('.css')) {
            return 'css/[name].[hash][extname]';
          }
          return 'assets/[name].[hash][extname]';
        },
      },
    },
    minify: 'terser',
    terserOptions: {
      compress: {
        drop_console: true,
        drop_debugger: true,
      },
    },
    sourcemap: false,
  },
  css: {
    postcss: {
      plugins: [
        require('autoprefixer'),
        require('cssnano')({
          preset: ['default', {
            discardComments: {
              removeAll: true,
            },
          }],
        }),
      ],
    },
  },
  server: {
    cors: true,
    strictPort: true,
    port: 3000,
    hmr: {
      host: 'localhost',
    },
  },
});
