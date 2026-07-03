export { DatatableProvider, useDatatableConfig } from './provider'
export { DataTable, type DataTableProps } from './data-table'
export { useDatatable } from './use-datatable'
// Re-exported so consumers can type their columns without importing
// @tanstack/react-table directly.
export type { ColumnDef } from '@tanstack/react-table'
export type {
  DatatableConfig,
  HeadersResolver,
  ColumnMeta,
  FilterDef,
  FilterValue,
  BulkAction,
  PaginatorResponse,
  DatatableQuery,
} from './types'
