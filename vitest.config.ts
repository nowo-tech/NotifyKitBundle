import { defineConfig } from 'vitest/config';

export default defineConfig({
  test: {
    environment: 'jsdom',
    include: ['src/Resources/assets/tests/**/*.test.ts'],
    restoreMocks: true,
    clearMocks: true,
    coverage: {
      provider: 'v8',
      reporter: ['text', 'text-summary'],
      reportsDirectory: './coverage-ts',
      include: ['src/Resources/assets/src/**/*.ts', 'src/Resources/assets/stimulus-peers/**/*.ts'],
      exclude: ['**/*.test.ts', '**/node_modules/**'],
      thresholds: {
        lines: 90,
        functions: 90,
        statements: 90,
        branches: 90,
      },
    },
  },
});
