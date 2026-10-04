import { describe, it, expect } from 'vitest'
import type { ColumnDef } from '@tanstack/react-table'
import { resolveSearchColumns } from './search-columns'

type U = { name: string; email: string; role: string }
const cols: ColumnDef<U, unknown>[] = [
  { accessorKey: 'name', meta: { searchable: true } },
  { accessorKey: 'email', meta: { searchable: true } },
  { accessorKey: 'role' },
]

describe('resolveSearchColumns', () => {
  it('returns the visible searchable columns', () => {
    expect(resolveSearchColumns(cols, { email: false })).toEqual({ columns: ['name'], disabled: false })
  })

  it('disables search when every searchable column is hidden', () => {
    expect(resolveSearchColumns(cols, { name: false, email: false })).toEqual({ columns: [], disabled: true })
  })

  it('stays enabled with no columns when none declares meta.searchable (backend decides)', () => {
    const plain: ColumnDef<U, unknown>[] = [{ accessorKey: 'name' }, { accessorKey: 'role' }]
    expect(resolveSearchColumns(plain, {})).toEqual({ columns: [], disabled: false })
  })
})
