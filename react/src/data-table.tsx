import { useEffect, useMemo, useState } from 'react'
import {
  flexRender,
  getCoreRowModel,
  useReactTable,
  type ColumnDef,
  type RowSelectionState,
  type SortingState,
  type VisibilityState,
} from '@tanstack/react-table'
import { useDatatable } from './use-datatable'
import { Toolbar } from './toolbar'
import { useLabels } from './provider'
import { columnId, resolveSearchColumns } from './search-columns'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from './ui/table'
import { Button } from './ui/button'
import { Checkbox } from './ui/checkbox'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from './ui/select'
import type { BulkAction, DatatableQuery, FilterDef, FilterValue } from './types'

export interface DataTableProps<T> {
  endpoint: string
  columns: ColumnDef<T, unknown>[]
  defaultPerPage?: number
  /** Page sizes offered to the user; `defaultPerPage` is always added. Defaults to 15, 25, 50. */
  perPageOptions?: number[]
  filters?: FilterDef[]
  bulkActions?: BulkAction<T>[]
  /**
   * Stable row identity, used for selection and React keys. Defaults to the
   * row's `id` field. Provide this if your rows key on something else — bulk
   * actions rely on it to target the right records instead of a row index.
   */
  getRowId?: (row: T, index: number) => string
}

// Default row identity: the conventional `id` field, falling back to the index
// when absent. Rows without a stable id should pass the `getRowId` prop.
function defaultRowId<T>(row: T, index: number): string {
  const id = (row as { id?: string | number }).id
  return id != null ? String(id) : String(index)
}

export function perPageChoices(defaultPerPage: number, options: number[] = [15, 25, 50]): number[] {
  return [...new Set([...options, defaultPerPage])].sort((a, b) => a - b)
}

export function DataTable<T>({
  endpoint,
  columns,
  defaultPerPage = 15,
  perPageOptions,
  filters,
  bulkActions,
  getRowId,
}: DataTableProps<T>) {
  const labels = useLabels()
  const [pagination, setPagination] = useState({ pageIndex: 0, pageSize: defaultPerPage })
  const [sorting, setSorting] = useState<SortingState>([])
  const [search, setSearch] = useState('')
  const [debouncedSearch, setDebouncedSearch] = useState('')
  const [columnVisibility, setColumnVisibility] = useState<VisibilityState>({})
  const [filterValues, setFilterValues] = useState<Record<string, FilterValue>>({})
  const [rowSelection, setRowSelection] = useState<RowSelectionState>({})

  useEffect(() => {
    const t = setTimeout(() => setDebouncedSearch(search), 300)
    return () => clearTimeout(t)
  }, [search])

  const searchScope = useMemo(
    () => resolveSearchColumns(columns, columnVisibility),
    [columns, columnVisibility],
  )
  // The typed term stays in state, so re-showing a searchable column re-applies it.
  const effectiveSearch = searchScope.disabled ? '' : debouncedSearch
  // What the backend actually searches: changes with the term and, while a term
  // is set, with the visible searchable columns.
  const searchKey = effectiveSearch ? `${effectiveSearch}|${searchScope.columns.join(',')}` : ''

  // Reset to the first page when the result set changes shape, so we never sit
  // on a page that no longer exists (e.g. searching while on page 8).
  useEffect(() => {
    setPagination((p) => (p.pageIndex === 0 ? p : { ...p, pageIndex: 0 }))
  }, [searchKey, filterValues])

  // Selection is per-page: clear it whenever the visible rows change, so a bulk
  // action can never target rows the user can no longer see.
  useEffect(() => {
    setRowSelection((s) => (Object.keys(s).length === 0 ? s : {}))
  }, [pagination.pageIndex, pagination.pageSize, searchKey, filterValues, sorting])

  const selectable = !!bulkActions?.length

  const tableColumns = useMemo<ColumnDef<T, unknown>[]>(() => {
    if (!selectable) return columns
    const selectionColumn: ColumnDef<T, unknown> = {
      id: 'select',
      enableSorting: false,
      enableHiding: false,
      header: ({ table }) => (
        <Checkbox
          checked={table.getIsAllPageRowsSelected() || (table.getIsSomePageRowsSelected() && 'indeterminate')}
          onCheckedChange={(v) => table.toggleAllPageRowsSelected(!!v)}
          aria-label={labels.selectAll}
        />
      ),
      cell: ({ row }) => (
        <Checkbox
          checked={row.getIsSelected()}
          onCheckedChange={(v) => row.toggleSelected(!!v)}
          aria-label={labels.selectRow}
        />
      ),
    }
    return [selectionColumn, ...columns]
  }, [columns, selectable, labels])


  const query: DatatableQuery = useMemo(() => {
    const sort = sorting[0]
    const sortMeta = sort ? columns.find((c) => columnId(c) === sort.id)?.meta : undefined
    return {
      page: pagination.pageIndex + 1,
      perPage: pagination.pageSize,
      search: effectiveSearch || undefined,
      // Only meaningful alongside a search term; omit otherwise to avoid a
      // redundant refetch when a column's visibility toggles.
      searchColumns: effectiveSearch ? searchScope.columns : undefined,
      sortBy: sort ? (sortMeta?.sortKey ?? sort.id) : undefined,
      sortOrder: sort ? (sort.desc ? 'desc' : 'asc') : undefined,
      filters: filterValues,
    }
  }, [pagination, sorting, effectiveSearch, searchScope, filterValues, columns])

  const { rows, pageCount, total, isLoading, isFetching, error, refetch } = useDatatable<T>(endpoint, query)

  const table = useReactTable({
    data: rows,
    columns: tableColumns,
    pageCount,
    getRowId: getRowId ?? defaultRowId,
    state: { pagination, sorting, columnVisibility, rowSelection },
    manualPagination: true,
    manualSorting: true,
    manualFiltering: true,
    enableRowSelection: selectable,
    onPaginationChange: setPagination,
    onSortingChange: setSorting,
    onColumnVisibilityChange: setColumnVisibility,
    onRowSelectionChange: setRowSelection,
    getCoreRowModel: getCoreRowModel(),
  })

  const selectedRows = table.getSelectedRowModel().rows.map((r) => r.original)
  const colCount = tableColumns.length

  return (
    <div className="space-y-3">
      <Toolbar
        table={table}
        search={search}
        onSearch={setSearch}
        searchDisabled={searchScope.disabled}
        filters={filters}
        filterValues={filterValues}
        onFilters={setFilterValues}
        bulkActions={bulkActions}
        selectedRows={selectedRows}
        onActionDone={() => {
          setRowSelection({})
          refetch()
        }}
      />

      <div
        className={`overflow-hidden rounded-xl border border-gray-200 transition-opacity dark:border-gray-800 ${
          isFetching && !isLoading ? 'opacity-60' : ''
        }`}
        aria-busy={isFetching}
      >
        <Table>
          <TableHeader>
            {table.getHeaderGroups().map((hg) => (
              <TableRow key={hg.id}>
                {hg.headers.map((h) => {
                  const sorted = h.column.getIsSorted()
                  return (
                    <TableHead
                      key={h.id}
                      aria-sort={
                        sorted === 'asc'
                          ? 'ascending'
                          : sorted === 'desc'
                            ? 'descending'
                            : h.column.getCanSort()
                              ? 'none'
                              : undefined
                      }
                    >
                      {h.isPlaceholder ? null : h.column.getCanSort() ? (
                        <button
                          type="button"
                          onClick={h.column.getToggleSortingHandler()}
                          className="inline-flex select-none items-center gap-1"
                        >
                          {flexRender(h.column.columnDef.header, h.getContext())}
                          <span aria-hidden="true">{{ asc: '↑', desc: '↓' }[sorted as string] ?? ''}</span>
                        </button>
                      ) : (
                        flexRender(h.column.columnDef.header, h.getContext())
                      )}
                    </TableHead>
                  )
                })}
              </TableRow>
            ))}
          </TableHeader>
          <TableBody>
            {isLoading ? (
              <TableRow>
                <TableCell colSpan={colCount} className="text-gray-500">
                  {labels.loading}
                </TableCell>
              </TableRow>
            ) : error ? (
              <TableRow>
                <TableCell colSpan={colCount} className="text-gray-500">
                  {labels.error} <Button onClick={() => refetch()}>{labels.retry}</Button>
                </TableCell>
              </TableRow>
            ) : rows.length === 0 ? (
              <TableRow>
                <TableCell colSpan={colCount} className="text-gray-500">
                  {labels.noResults}
                </TableCell>
              </TableRow>
            ) : (
              table.getRowModel().rows.map((row) => (
                <TableRow key={row.id} data-state={row.getIsSelected() ? 'selected' : undefined}>
                  {row.getVisibleCells().map((cell) => (
                    <TableCell key={cell.id}>
                      {flexRender(cell.column.columnDef.cell, cell.getContext())}
                    </TableCell>
                  ))}
                </TableRow>
              ))
            )}
          </TableBody>
        </Table>
      </div>

      <div className="flex items-center justify-between text-sm text-gray-500">
        <span>{labels.total(total)}</span>
        <div className="flex items-center gap-4">
          <Select
            value={String(pagination.pageSize)}
            onValueChange={(v) => setPagination((p) => ({ ...p, pageIndex: 0, pageSize: Number(v) }))}
          >
            <SelectTrigger className="w-20">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              {perPageChoices(defaultPerPage, perPageOptions).map((n) => (
                <SelectItem key={n} value={String(n)}>
                  {n}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
          <span>
            {labels.page(pagination.pageIndex + 1, Math.max(pageCount, 1))}
          </span>
          <div className="flex gap-2">
            <Button onClick={() => table.previousPage()} disabled={!table.getCanPreviousPage()}>
              {labels.previous}
            </Button>
            <Button onClick={() => table.nextPage()} disabled={!table.getCanNextPage()}>
              {labels.next}
            </Button>
          </div>
        </div>
      </div>
    </div>
  )
}
