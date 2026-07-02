import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import type { ColumnDef } from '@tanstack/react-table'
import { DatatableProvider } from './provider'
import { DataTable } from './data-table'

type User = { id: number; name: string; email: string }
const columns: ColumnDef<User, unknown>[] = [
  { accessorKey: 'name', header: 'Name', enableSorting: true, meta: { searchable: true } },
  { accessorKey: 'email', header: 'Email', meta: { searchable: true } },
]

function renderTable(ui: React.ReactElement) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={client}>
      <DatatableProvider config={{ baseUrl: 'https://api.test' }}>{ui}</DatatableProvider>
    </QueryClientProvider>,
  )
}

beforeEach(() => {
  vi.stubGlobal('fetch', vi.fn(async () => ({
    ok: true,
    json: async () => ({
      data: [{ id: 1, name: 'Jane', email: 'jane@test' }],
      current_page: 1, last_page: 1, per_page: 15, total: 1,
    }),
  })))
})

describe('DataTable', () => {
  it('renders rows from the paginator response', async () => {
    renderTable(<DataTable<User> endpoint="/users" columns={columns} />)
    await waitFor(() => expect(screen.getByText('Jane')).toBeTruthy())
    expect(screen.getByText('jane@test')).toBeTruthy()
  })
})
