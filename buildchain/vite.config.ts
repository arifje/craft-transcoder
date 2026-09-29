import {defineConfig} from 'vite';
import createVuePlugin from '@vitejs/plugin-vue';
import viteCompressionPlugin from 'vite-plugin-compression';
import viteEslintPlugin from 'vite-plugin-eslint';
import viteStylelintPlugin from 'vite-plugin-stylelint';
import viteRestartPlugin from 'vite-plugin-restart';
import {visualizer} from 'rollup-plugin-visualizer';
import * as path from 'path';

// https://vitejs.dev/config/
export default defineConfig(({command}) => ({
  base: command === 'serve' ? '' : '/dist/',
  build: {
    emptyOutDir: true,
    manifest: 'manifest.json',
    outDir: '../src/web/assets/dist',
    sourcemap: true,
    rollupOptions: {
      input: {
        app: 'src/js/app.ts',
        welcome: 'src/js/welcome.ts',
      },
    }
  },
  plugins: [
    viteRestartPlugin({
      reload: [
        '../src/templates/**/*',
      ],
    }),
    createVuePlugin(),
    viteCompressionPlugin({
      filter: /\.(js|mjs|json|css|map)$/i
    }),
    // Keep the bundle report out of the shipped dist directory
    visualizer({
      filename: 'stats.html',
      template: 'treemap',
      sourcemap: true,
    }),
    viteEslintPlugin({
      cache: false,
      fix: true,
    }),
    viteStylelintPlugin({
      fix: true,
      lintInWorker: true
    })
  ],
  resolve: {
    alias: [
      {find: '@', replacement: path.resolve(__dirname, './src')},
    ],
    preserveSymlinks: true,
  },
  server: {
    fs: {
      strict: false
    },
    host: '0.0.0.0',
    origin: 'http://localhost:' + process.env.DEV_PORT,
    port: parseInt(process.env.DEV_PORT),
    strictPort: true,
  }
}));
