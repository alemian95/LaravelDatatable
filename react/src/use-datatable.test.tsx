import { describe, it, expect, vi, beforeEach } from 'vitest'
import { renderHook, waitFor } from '@testing-library/react'
import { QueryClient } from '@tanstack/react-query'
import { DatatableProvider } from './provider'
import { useDatatable } from './use-datatable'

function wrapper(headers?: any) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return ({ children }: { children: React.ReactNode }) => (
    <DatatableProvider config={{ baseUrl: 'https://api.test', headers }} queryClient={client}>
      {children}
    </DatatableProvider>
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
    const headers = new Headers((fetch as any).mock.calls[0][1].headers)
    expect(headers.get('authorization')).toBe('Bearer tok')
  })

  it('asks for JSON by default so an expired session is not a login redirect', async () => {
    const { result } = renderHook(
      () => useDatatable('/users', { page: 1, perPage: 15 }),
      { wrapper: wrapper({ Accept: 'application/vnd.api+json' }) },
    )
    await waitFor(() => expect(result.current.isLoading).toBe(false))
    const headers = new Headers((fetch as any).mock.calls[0][1].headers)
    expect(headers.get('accept')).toBe('application/vnd.api+json')
    expect(headers.get('x-requested-with')).toBe('XMLHttpRequest')
  })

  it('passes an abort signal so superseded requests can be cancelled', async () => {
    const { result } = renderHook(
      () => useDatatable('/users', { page: 1, perPage: 15 }),
      { wrapper: wrapper() },
    )
    await waitFor(() => expect(result.current.isLoading).toBe(false))
    expect((fetch as any).mock.calls[0][1].signal).toBeInstanceOf(AbortSignal)
  })

  it('reports a non-object response body as an error', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => ({ ok: true, json: async () => null })))
    const { result } = renderHook(
      () => useDatatable('/users', { page: 1, perPage: 15 }),
      { wrapper: wrapper() },
    )
    await waitFor(() => expect(result.current.error).toBeInstanceOf(Error))
    expect(result.current.error?.message).toMatch(/unexpected response/i)
  })

  it('reads pagination from the meta envelope of an API Resource collection', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => ({
      ok: true,
      json: async () => ({
        data: [{ id: 1, name: 'Jane' }],
        links: { first: '…', last: '…', prev: null, next: '…' },
        meta: { current_page: 1, last_page: 4, per_page: 15, total: 60 },
      }),
    })))

    const { result } = renderHook(
      () => useDatatable('/users', { page: 1, perPage: 15 }),
      { wrapper: wrapper() },
    )
    await waitFor(() => expect(result.current.isLoading).toBe(false))

    expect(result.current.rows).toEqual([{ id: 1, name: 'Jane' }])
    expect(result.current.pageCount).toBe(4)
    expect(result.current.total).toBe(60)
  })
})

describe('useDatatable HTTP errors', () => {
  it('does not retry an HTTP error and reports the server message', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => ({
      ok: false,
      status: 500,
      json: async () => ({ message: 'Server Error' }),
    })))
    // Default client options: the hook itself must opt out of retries.
    const client = new QueryClient()
    const { result } = renderHook(() => useDatatable('/users', { page: 1, perPage: 15 }), {
      wrapper: ({ children }) => (
        <DatatableProvider config={{ baseUrl: '' }} queryClient={client}>
          {children}
        </DatatableProvider>
      ),
    })

    await waitFor(() => expect(result.current.error).not.toBeNull())
    expect(fetch).toHaveBeenCalledTimes(1)
    expect(result.current.error?.message).toBe('Request failed with status 500: Server Error')
  })
})
