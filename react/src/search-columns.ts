import type { ColumnDef, VisibilityState } from '@tanstack/react-table'

export function columnId<T>(column: ColumnDef<T, unknown>): string {
  if ('accessorKey' in column) return String(column.accessorKey)
  if (column.id) return column.id
  throw new Error('laraveldatatable: every column needs an accessorKey or an explicit id.')
}

/**
 * Columns to send as `search_columns`. When columns opt in via
 * `meta.searchable` but all of them are hidden, search is disabled: sending
 * an empty list would make the backend search its whole whitelist instead.
 */
export function resolveSearchColumns<T>(
  columns: ColumnDef<T, unknown>[],
  visibility: VisibilityState,
): { columns: string[]; disabled: boolean } {
  const declared = columns.filter((c) => c.meta?.searchable).map(columnId)
  const visible = declared.filter((id) => visibility[id] !== false)
  return { columns: visible, disabled: declared.length > 0 && visible.length === 0 }
}
