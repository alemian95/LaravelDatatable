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

export interface PaginatorResponse<T> {
  data: T[]
  current_page: number
  last_page: number
  per_page: number
  total: number
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
