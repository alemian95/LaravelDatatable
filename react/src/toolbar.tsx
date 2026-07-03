import { useState } from 'react'
import type { Table } from '@tanstack/react-table'
import { Button } from './ui/button'
import { Input } from './ui/input'
import {
  DropdownMenu,
  DropdownMenuCheckboxItem,
  DropdownMenuContent,
  DropdownMenuTrigger,
} from './ui/dropdown-menu'
import { FiltersSheet } from './filters-sheet'
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

export function Toolbar<T>(props: ToolbarProps<T>) {
  const { table, search, onSearch, filters, filterValues, onFilters, bulkActions, selectedRows, onActionDone } = props
  const [action, setAction] = useState('')
  const [pending, setPending] = useState(false)

  const activeFilters = Object.values(filterValues).filter((v) =>
    typeof v === 'object' ? v.from || v.to : v !== '' && v != null,
  ).length

  async function apply() {
    const chosen = bulkActions?.find((a) => a.value === action)
    if (!chosen || pending) return
    setPending(true)
    try {
      await chosen.handler(selectedRows)
      onActionDone()
    } finally {
      setPending(false)
    }
  }

  return (
    <div className="flex flex-wrap items-center justify-between gap-3">
      <div className="flex items-center gap-2">
        {selectedRows.length > 0 && bulkActions?.length ? (
          <>
            <span className="rounded-md bg-gray-100 px-3 py-1 text-sm text-gray-900 dark:bg-gray-800 dark:text-gray-50">
              {selectedRows.length} selected
            </span>
            <select
              aria-label="Bulk action"
              value={action}
              onChange={(e) => setAction(e.target.value)}
              disabled={pending}
              className="h-9 rounded-md border border-gray-200 bg-white px-2 text-sm disabled:opacity-50 dark:border-gray-800 dark:bg-gray-950 dark:text-gray-50"
            >
              <option value="">Actions…</option>
              {bulkActions.map((a) => (
                <option key={a.value} value={a.value}>
                  {a.label}
                </option>
              ))}
            </select>
            <Button onClick={apply} disabled={pending || action === ''}>
              {pending ? 'Applying…' : 'Apply'}
            </Button>
          </>
        ) : (
          <Input
            placeholder="Search…"
            value={search}
            onChange={(e) => onSearch(e.target.value)}
            className="max-w-xs"
          />
        )}
      </div>

      <div className="flex items-center gap-2">
        {filters?.length ? (
          <FiltersSheet
            filters={filters}
            values={filterValues}
            onApply={onFilters}
            activeCount={activeFilters}
          />
        ) : null}
        <DropdownMenu>
          <DropdownMenuTrigger asChild>
            <Button>Columns</Button>
          </DropdownMenuTrigger>
          <DropdownMenuContent align="end">
            {table
              .getAllColumns()
              .filter((c) => c.getCanHide())
              .map((c) => (
                <DropdownMenuCheckboxItem
                  key={c.id}
                  checked={c.getIsVisible()}
                  onCheckedChange={(v) => c.toggleVisibility(!!v)}
                >
                  {c.id}
                </DropdownMenuCheckboxItem>
              ))}
          </DropdownMenuContent>
        </DropdownMenu>
      </div>
    </div>
  )
}
