import { describe, it, expect, vi, beforeEach } from 'vitest'
import { renderHook, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { DatatableProvider } from './provider'
import { useDatatable } from './use-datatable'

function wrapper(headers?: any) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return ({ children }: { children: React.ReactNode }) => (
    <QueryClientProvider client={client}>
      <DatatableProvider config={{ baseUrl: 'https://api.test', headers }}>{children}</DatatableProvider>
    </QueryClientProvider>
  )
}

const paginator = {
  data: [{ id: 1, name: 'Jane' }],
  current_page: 1, last_page: 5, per_page: 15, total: 75,
}

beforeEach(() => {
  vi.stubGlobal('fetch', vi.fn(async () => ({ ok: true, json: async () => paginator })))
})

describe('useDatatable', () => {
  it('fetches the endpoint with built params and returns normalized data', async () => {
    const { result } = renderHook(
      () => useDatatable('/users', { page: 2, perPage: 15, search: 'jane', searchColumns: ['name'] }),
      { wrapper: wrapper() },
    )
    await waitFor(() => expect(result.current.isLoading).toBe(false))

    const url = (fetch as any).mock.calls[0][0] as string
    expect(url).toContain('https://api.test/users?')
    expect(url).toContain('page=2')
    expect(url).toContain('search=jane')
    expect(url).toContain('search_columns=name')
    expect(result.current.rows).toEqual(paginator.data)
    expect(result.current.pageCount).toBe(5)
    expect(result.current.total).toBe(75)
  })

  it('sends resolved async auth headers', async () => {
    const { result } = renderHook(
      () => useDatatable('/users', { page: 1, perPage: 15 }),
      { wrapper: wrapper(async () => ({ Authorization: 'Bearer tok' })) },
    )
    await waitFor(() => expect(result.current.isLoading).toBe(false))
    const init = (fetch as any).mock.calls[0][1]
    expect(init.headers).toEqual({ Authorization: 'Bearer tok' })
  })
})
