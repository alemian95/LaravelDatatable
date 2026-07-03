import { defineConfig } from 'tsup'

export default defineConfig({
  entry: ['src/index.ts'],
  format: ['esm'],
  dts: true,
  clean: true,
  sourcemap: true,
  // The whole package is client-only (context, hooks, Radix). The banner lets
  // it be imported from a React Server Component (Next.js App Router) without a
  // hand-written 'use client' wrapper.
  banner: { js: "'use client';" },
  external: ['react', 'react-dom', '@tanstack/react-query', '@tanstack/react-table'],
})
