import type { RowData } from '@tanstack/react-table'

// Augment TanStack's ColumnMeta so `columns[i].meta` is typed for the consumer:
// a typo like `serchable` is now a compile error instead of a silent no-op.
declare module '@tanstack/react-table' {
  // eslint-disable-next-line @typescript-eslint/no-unused-vars
  interface ColumnMeta<TData extends RowData, TValue> {
    searchable?: boolean
    sortKey?: string
    /** Name shown in the column visibility menu; defaults to a string header, then the id. */
    label?: string
  }
}

export type HeadersResolver =
  | HeadersInit
  | (() => HeadersInit | Promise<HeadersInit>)

export interface DatatableConfig {
  baseUrl: string
  headers?: HeadersResolver
}

export interface ColumnMeta {
  searchable?: boolean
  sortKey?: string
  label?: string
}

export type FilterValue = string | { from?: string; to?: string }

export type FilterDef =
  | { id: string; label: string; type: 'select'; options: { value: string; label: string }[] }
  | { id: string; label: string; type: 'date-range' }
  | { id: string; label: string; type: 'text' }

export interface BulkAction<T> {
  value: string
  label: string
  handler: (selectedRows: T[]) => void | Promise<void>
}

export interface PaginationMeta {
  current_page: number
  last_page: number
  per_page: number
  total: number
}

/** Raw Laravel length-aware paginator (no API Resource). */
export type PaginatorResponse<T> = { data: T[] } & PaginationMeta

/** `Resource::collection($paginator)` — pagination nested under `meta`. */
export interface ResourceCollectionResponse<T> {
  data: T[]
  meta: PaginationMeta
}

export interface DatatableQuery {
  page: number
  perPage: number
  search?: string
  searchColumns?: string[]
  sortBy?: string
  sortOrder?: 'asc' | 'desc'
  filters?: Record<string, FilterValue>
}
