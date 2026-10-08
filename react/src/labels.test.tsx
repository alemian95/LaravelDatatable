import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { QueryClient } from '@tanstack/react-query'
import type { ColumnDef } from '@tanstack/react-table'
import { DatatableProvider } from './provider'
import { DataTable } from './data-table'
import type { DatatableLabels } from './types'

type User = { id: number; name: string }
const columns: ColumnDef<User, unknown>[] = [{ accessorKey: 'name', header: 'Nome', meta: { searchable: true } }]

const italian: Partial<DatatableLabels> = {
  search: 'Cerca…',
  filters: 'Filtri',
  columns: 'Colonne',
  applyFilters: 'Applica filtri',
  previous: 'Precedente',
  next: 'Successiva',
  total: (n) => `${n} risultati`,
  page: (page, pages) => `Pagina ${page} di ${pages}`,
}

beforeEach(() => {
  vi.stubGlobal('fetch', vi.fn(async () => ({
    ok: true,
    json: async () => ({ data: [{ id: 1, name: 'Jane' }], current_page: 1, last_page: 3, per_page: 15, total: 40 }),
  })))
})

describe('labels', () => {
  it('replaces the built-in texts with the ones passed to the provider', async () => {
    const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
    render(
      <DatatableProvider config={{ baseUrl: '', labels: italian }} queryClient={client}>
        <DataTable<User> endpoint="/users" columns={columns} filters={[{ id: 'city', label: 'Città', type: 'text' }]} />
      </DatatableProvider>,
    )
    await waitFor(() => expect(screen.getByText('Jane')).toBeTruthy())

    expect(screen.getByPlaceholderText('Cerca…')).toBeTruthy()
    expect(screen.getByText('Colonne')).toBeTruthy()
    expect(screen.getByText('40 risultati')).toBeTruthy()
    expect(screen.getByText('Pagina 1 di 3')).toBeTruthy()
    expect(screen.getByText('Precedente')).toBeTruthy()
    expect(screen.getByText('Successiva')).toBeTruthy()

    await userEvent.click(screen.getByText('Filtri'))
    expect(screen.getByText('Applica filtri')).toBeTruthy()
    // Untranslated keys keep the English default.
    expect(screen.getByText('Reset')).toBeTruthy()
  })
})
