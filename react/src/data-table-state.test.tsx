import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { QueryClient } from '@tanstack/react-query'
import type { ColumnDef } from '@tanstack/react-table'
import { DatatableProvider } from './provider'
import { DataTable } from './data-table'

type User = { id: number; name: string; email: string }
const columns: ColumnDef<User, unknown>[] = [
  { accessorKey: 'name', header: 'Name', enableSorting: true, meta: { searchable: true } },
  { accessorKey: 'email', header: 'Email' },
]

function renderTable(ui: React.ReactElement) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <DatatableProvider config={{ baseUrl: 'https://api.test' }} queryClient={client}>
      {ui}
    </DatatableProvider>,
  )
}

function lastUrl(): string {
  const calls = (fetch as unknown as { mock: { calls: unknown[][] } }).mock.calls
  return calls[calls.length - 1]![0] as string
}

async function toggleColumn(label: string) {
  await userEvent.click(screen.getByRole('button', { name: 'Columns' }))
  await userEvent.click(await screen.findByRole('menuitemcheckbox', { name: label }))
}

beforeEach(() => {
  vi.stubGlobal(
    'fetch',
    vi.fn(async () => ({
      ok: true,
      json: async () => ({
        data: [{ id: 1, name: 'Jane', email: 'jane@test' }],
        current_page: 1,
        last_page: 3,
        per_page: 15,
        total: 40,
      }),
    })),
  )
})

describe('DataTable state', () => {
  it('resets to page 1 when the search term changes', async () => {
    renderTable(<DataTable<User> endpoint="/users" columns={columns} />)
    await waitFor(() => expect(screen.getByText('Jane')).toBeTruthy())

    await userEvent.click(screen.getByText('Next'))
    await waitFor(() => expect(lastUrl()).toContain('page=2'))

    await userEvent.type(screen.getByPlaceholderText('Search…'), 'ann')
    await waitFor(() => {
      const url = lastUrl()
      expect(url).toContain('search=ann')
      expect(url).toMatch(/[?&]page=1(&|$)/)
    })
  })

  it('clears the selection when the page changes', async () => {
    const bulkActions = [{ value: 'del', label: 'Delete', handler: vi.fn() }]
    renderTable(<DataTable<User> endpoint="/users" columns={columns} bulkActions={bulkActions} />)
    await waitFor(() => expect(screen.getByText('Jane')).toBeTruthy())

    await userEvent.click(screen.getByLabelText('Select row'))
    expect(screen.getByText('1 selected')).toBeTruthy()

    await userEvent.click(screen.getByText('Next'))
    await waitFor(() => expect(screen.queryByText('1 selected')).toBeNull())
  })

  it('renders sortable headers as keyboard-accessible buttons with aria-sort', async () => {
    renderTable(<DataTable<User> endpoint="/users" columns={columns} />)
    await waitFor(() => expect(screen.getByText('Jane')).toBeTruthy())

    expect(screen.getByRole('button', { name: 'Name' })).toBeTruthy()
    const header = screen.getByRole('columnheader', { name: /name/i })
    expect(header.getAttribute('aria-sort')).toBe('none')
  })
  it('stops sending the term while every searchable column is hidden and re-applies it when one is shown', async () => {
    renderTable(<DataTable<User> endpoint="/users" columns={columns} />)
    await waitFor(() => expect(screen.getByText('Jane')).toBeTruthy())

    await userEvent.type(screen.getByPlaceholderText('Search…'), 'ann')
    await waitFor(() => expect(lastUrl()).toContain('search=ann&search_columns=name'))

    await toggleColumn('Name')
    await waitFor(() => {
      expect(lastUrl()).not.toContain('search=')
      expect(lastUrl()).not.toContain('search_columns=')
    })

    await toggleColumn('Name')
    await waitFor(() => expect(lastUrl()).toContain('search=ann&search_columns=name'))
  })

  it('resets to page 1 when hiding the last searchable column changes the effective search', async () => {
    renderTable(<DataTable<User> endpoint="/users" columns={columns} />)
    await waitFor(() => expect(screen.getByText('Jane')).toBeTruthy())

    await userEvent.type(screen.getByPlaceholderText('Search…'), 'ann')
    await waitFor(() => expect(lastUrl()).toContain('search=ann'))
    await userEvent.click(screen.getByText('Next'))
    await waitFor(() => expect(lastUrl()).toMatch(/[?&]page=2(&|$)/))

    await toggleColumn('Name')
    await waitFor(() => {
      expect(lastUrl()).not.toContain('search=')
      expect(lastUrl()).toMatch(/[?&]page=1(&|$)/)
    })
  })
})
