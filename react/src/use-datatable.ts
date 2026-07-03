import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { useDatatableConfig } from './provider'
import { resolveHeaders } from './resolve-headers'
import { buildParams } from './build-params'
import type { DatatableQuery, PaginatorResponse } from './types'

export function useDatatable<T>(endpoint: string, query: DatatableQuery) {
  const config = useDatatableConfig()

  const q = useQuery({
    queryKey: [config.baseUrl, endpoint, query],
    placeholderData: keepPreviousData,
    queryFn: async (): Promise<PaginatorResponse<T>> => {
      const headers = await resolveHeaders(config.headers)
      const url = `${config.baseUrl}${endpoint}?${buildParams(query).toString()}`
      const res = await fetch(url, { headers })
      if (!res.ok) throw new Error(`Request failed with status ${res.status}`)
      return res.json()
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
