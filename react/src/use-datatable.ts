import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { useDatatableConfig } from './provider'
import { resolveHeaders } from './resolve-headers'
import { buildParams } from './build-params'
import type { DatatableQuery, PaginatorResponse, ResourceCollectionResponse } from './types'

// Both envelopes the backend can emit: raw paginator, or returnResource().
function toPaginator<T>(body: unknown): PaginatorResponse<T> {
  if (typeof body !== 'object' || body === null || !('data' in body)) {
    throw new Error('Unexpected response: expected a paginator or an API Resource collection')
  }
  const paginated = body as PaginatorResponse<T> | ResourceCollectionResponse<T>
  return 'meta' in paginated ? { data: paginated.data, ...paginated.meta } : paginated
}

// Without these Laravel answers an expired session with a 302 to the login
// page instead of a 401. The consumer's headers win.
async function requestHeaders(resolver: Parameters<typeof resolveHeaders>[0]): Promise<Headers> {
  const headers = new Headers({ Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' })
  new Headers(await resolveHeaders(resolver)).forEach((value, key) => headers.set(key, value))
  return headers
}

async function httpError(res: Response): Promise<Error> {
  const body: unknown = await res.json().catch(() => null)
  const message = typeof body === 'object' && body !== null && 'message' in body ? body.message : null
  return new Error(`Request failed with status ${res.status}${typeof message === 'string' ? `: ${message}` : ''}`)
}

export function useDatatable<T>(endpoint: string, query: DatatableQuery) {
  const config = useDatatableConfig()

  const q = useQuery({
    queryKey: [config.baseUrl, endpoint, query],
    placeholderData: keepPreviousData,
    // Retry only network failures (fetch rejects with a TypeError). An answer
    // from the server, a 500, an expired session or an unexpected body, will
    // not change on retry.
    retry: (failures, error) => error instanceof TypeError && failures < 3,
    queryFn: async ({ signal }): Promise<PaginatorResponse<T>> => {
      const headers = await requestHeaders(config.headers)
      const url = `${config.baseUrl}${endpoint}?${buildParams(query).toString()}`
      const res = await fetch(url, { headers, signal })
      if (!res.ok) throw await httpError(res)
      return toPaginator<T>(await res.json())
    },
  })

  return {
    rows: q.data?.data ?? [],
    pageCount: q.data?.last_page ?? 0,
    total: q.data?.total ?? 0,
    isLoading: q.isLoading,
    isFetching: q.isFetching,
    error: q.error as Error | null,
    refetch: q.refetch,
  }
}
