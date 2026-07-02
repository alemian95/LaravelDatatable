import { createContext, useContext } from 'react'
import type { DatatableConfig } from './types'

const DatatableContext = createContext<DatatableConfig | null>(null)

export function DatatableProvider({
  config,
  children,
}: {
  config: DatatableConfig
  children: React.ReactNode
}) {
  return <DatatableContext.Provider value={config}>{children}</DatatableContext.Provider>
}

export function useDatatableConfig(): DatatableConfig {
  const ctx = useContext(DatatableContext)
  if (!ctx) {
    throw new Error('useDatatableConfig must be used within a <DatatableProvider>. See the package README.')
  }
  return ctx
}
