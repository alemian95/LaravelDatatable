import { createContext, useContext, useState } from 'react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import type { DatatableConfig } from './types'

const DatatableContext = createContext<DatatableConfig | null>(null)

export function DatatableProvider({
  config,
  children,
  queryClient,
}: {
  config: DatatableConfig
  children: React.ReactNode
  /**
   * Optional: share your app's QueryClient (cache, devtools) with the tables.
   * When omitted, the provider creates and owns an internal client, so the
   * consumer does not need to install or set up @tanstack/react-query.
   */
  queryClient?: QueryClient
}) {
  const [internalClient] = useState(() => new QueryClient())
  const client = queryClient ?? internalClient

  return (
    <DatatableContext.Provider value={config}>
      <QueryClientProvider client={client}>{children}</QueryClientProvider>
    </DatatableContext.Provider>
  )
}

export function useDatatableConfig(): DatatableConfig {
  const ctx = useContext(DatatableContext)
  if (!ctx) {
    throw new Error('useDatatableConfig must be used within a <DatatableProvider>. See the package README.')
  }
  return ctx
}
