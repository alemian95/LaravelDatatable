import { useState } from 'react'
import type { Column, Table } from '@tanstack/react-table'
import { Button } from './ui/button'
import { Input } from './ui/input'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from './ui/select'
import {
  DropdownMenu,
  DropdownMenuCheckboxItem,
  DropdownMenuContent,
  DropdownMenuTrigger,
} from './ui/dropdown-menu'
import { FiltersSheet } from './filters-sheet'
import { useLabels } from './provider'
import type { BulkAction, FilterDef, FilterValue } from './types'

export interface ToolbarProps<T> {
  table: Table<T>
  search: string
  onSearch: (v: string) => void
  searchDisabled?: boolean
  filters?: FilterDef[]
  filterValues: Record<string, FilterValue>
  onFilters: (v: Record<string, FilterValue>) => void
  bulkActions?: BulkAction<T>[]
  selectedRows: T[]
  onActionDone: () => void
}

function columnLabel<T>(column: Column<T, unknown>): string {
  const { header, meta } = column.columnDef
  return meta?.label ?? (typeof header === 'string' ? header : column.id)
}

export function Toolbar<T>(props: ToolbarProps<T>) {
  const { table, search, onSearch, searchDisabled, filters, filterValues, onFilters, bulkActions, selectedRows, onActionDone } = props
  const labels = useLabels()
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
        <Input
          placeholder={searchDisabled ? labels.searchDisabled : labels.search}
          value={search}
          onChange={(e) => onSearch(e.target.value)}
          disabled={searchDisabled}
          className="max-w-xs"
        />
        {selectedRows.length > 0 && bulkActions?.length ? (
          <>
            <span className="rounded-md bg-gray-100 px-3 py-1 text-sm text-gray-900 dark:bg-gray-800 dark:text-gray-50">
              {labels.selected(selectedRows.length)}
            </span>
            <Select value={action} onValueChange={setAction} disabled={pending}>
              <SelectTrigger aria-label={labels.bulkAction} className="w-44">
                <SelectValue placeholder={labels.actions} />
              </SelectTrigger>
              <SelectContent>
                {bulkActions.map((a) => (
                  <SelectItem key={a.value} value={a.value}>
                    {a.label}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
            <Button onClick={apply} disabled={pending || action === ''}>
              {pending ? labels.applying : labels.apply}
            </Button>
          </>
        ) : null}
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
            <Button>{labels.columns}</Button>
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
                  {columnLabel(c)}
                </DropdownMenuCheckboxItem>
              ))}
          </DropdownMenuContent>
        </DropdownMenu>
      </div>
    </div>
  )
}
