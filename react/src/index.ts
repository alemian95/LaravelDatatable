export { DatatableProvider, useDatatableConfig } from './provider'
export { DataTable, type DataTableProps } from './data-table'
export { useDatatable } from './use-datatable'
// Re-exported so consumers can author columns and cells without importing
// @tanstack/react-table directly — required under pnpm's strict node_modules,
// convenient everywhere else.
export { createColumnHelper } from '@tanstack/react-table'
export type {
  ColumnDef,
  CellContext,
  HeaderContext,
  Row,
} from '@tanstack/react-table'
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
