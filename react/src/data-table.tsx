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
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from './ui/table'
import { Button } from './ui/button'
import { Checkbox } from './ui/checkbox'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from './ui/select'
import type { BulkAction, DatatableQuery, FilterDef, FilterValue } from './types'

export interface DataTableProps<T> {
  endpoint: string
  columns: ColumnDef<T, unknown>[]
  defaultPerPage?: number
  filters?: FilterDef[]
  bulkActions?: BulkAction<T>[]
  /**
   * Stable row identity, used for selection and React keys. Defaults to the
   * row's `id` field. Provide this if your rows key on something else — bulk
   * actions rely on it to target the right records instead of a row index.
   */
  getRowId?: (row: T, index: number) => string
}

function columnId<T>(column: ColumnDef<T, unknown>): string {
  if ('accessorKey' in column) return String(column.accessorKey)
  if (column.id) return column.id
  throw new Error('laraveldatatable: every column needs an accessorKey or an explicit id.')
}

// Default row identity: the conventional `id` field, falling back to the index
// when absent. Rows without a stable id should pass the `getRowId` prop.
function defaultRowId<T>(row: T, index: number): string {
  const id = (row as { id?: string | number }).id
  return id != null ? String(id) : String(index)
}

export function DataTable<T>({
  endpoint,
  columns,
  defaultPerPage = 15,
  filters,
  bulkActions,
  getRowId,
}: DataTableProps<T>) {
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

  // Reset to the first page when the result set changes shape, so we never sit
  // on a page that no longer exists (e.g. searching while on page 8).
  useEffect(() => {
    setPagination((p) => (p.pageIndex === 0 ? p : { ...p, pageIndex: 0 }))
  }, [debouncedSearch, filterValues])

  // Selection is per-page: clear it whenever the visible rows change, so a bulk
  // action can never target rows the user can no longer see.
  useEffect(() => {
    setRowSelection((s) => (Object.keys(s).length === 0 ? s : {}))
  }, [pagination.pageIndex, pagination.pageSize, debouncedSearch, filterValues, sorting])

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
          aria-label="Select all"
        />
      ),
      cell: ({ row }) => (
        <Checkbox
          checked={row.getIsSelected()}
          onCheckedChange={(v) => row.toggleSelected(!!v)}
          aria-label="Select row"
        />
      ),
    }
    return [selectionColumn, ...columns]
  }, [columns, selectable])

  const searchColumns = useMemo(
    () =>
      columns
        .filter((c) => c.meta?.searchable)
        .map(columnId)
        .filter((id) => columnVisibility[id] !== false),
    [columns, columnVisibility],
  )

  const query: DatatableQuery = useMemo(() => {
    const sort = sorting[0]
    const sortMeta = sort ? columns.find((c) => columnId(c) === sort.id)?.meta : undefined
    return {
      page: pagination.pageIndex + 1,
      perPage: pagination.pageSize,
      search: debouncedSearch || undefined,
      // Only meaningful alongside a search term; omit otherwise to avoid a
      // redundant refetch when a column's visibility toggles.
      searchColumns: debouncedSearch ? searchColumns : undefined,
      sortBy: sort ? (sortMeta?.sortKey ?? sort.id) : undefined,
      sortOrder: sort ? (sort.desc ? 'desc' : 'asc') : undefined,
      filters: filterValues,
    }
  }, [pagination, sorting, debouncedSearch, searchColumns, filterValues, columns])

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
                  Loading…
                </TableCell>
              </TableRow>
            ) : error ? (
              <TableRow>
                <TableCell colSpan={colCount} className="text-gray-500">
                  Couldn't load the data. <Button onClick={() => refetch()}>Retry</Button>
                </TableCell>
              </TableRow>
            ) : rows.length === 0 ? (
              <TableRow>
                <TableCell colSpan={colCount} className="text-gray-500">
                  No results.
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
        <span>{total} total</span>
        <div className="flex items-center gap-4">
          <Select
            value={String(pagination.pageSize)}
            onValueChange={(v) => setPagination((p) => ({ ...p, pageIndex: 0, pageSize: Number(v) }))}
          >
            <SelectTrigger className="w-20">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              {[15, 25, 50].map((n) => (
                <SelectItem key={n} value={String(n)}>
                  {n}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
          <span>
            Page {pagination.pageIndex + 1} of {Math.max(pageCount, 1)}
          </span>
          <div className="flex gap-2">
            <Button onClick={() => table.previousPage()} disabled={!table.getCanPreviousPage()}>
              Previous
            </Button>
            <Button onClick={() => table.nextPage()} disabled={!table.getCanNextPage()}>
              Next
            </Button>
          </div>
        </div>
      </div>
    </div>
  )
}
