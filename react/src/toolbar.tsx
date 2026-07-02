import type { Table } from '@tanstack/react-table'
import { Input } from './ui/input'
import type { BulkAction, FilterDef, FilterValue } from './types'

export interface ToolbarProps<T> {
  table: Table<T>
  search: string
  onSearch: (v: string) => void
  filters?: FilterDef[]
  filterValues: Record<string, FilterValue>
  onFilters: (v: Record<string, FilterValue>) => void
  bulkActions?: BulkAction<T>[]
  selectedRows: T[]
  onActionDone: () => void
}

// ponytail: temporary stub — replaced by the full toolbar in a later task.
export function Toolbar<T>({ search, onSearch }: ToolbarProps<T>) {
  return (
    <Input
      placeholder="Search…"
      value={search}
      onChange={(e) => onSearch(e.target.value)}
      className="max-w-xs"
    />
  )
}
