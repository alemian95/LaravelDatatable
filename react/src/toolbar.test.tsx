import { describe, it, expect, vi } from 'vitest'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { Toolbar } from './toolbar'

const fakeTable = { getAllColumns: () => [] } as any

describe('Toolbar', () => {
  it('shows the selected count and runs the chosen bulk action', async () => {
    const handler = vi.fn()
    const onActionDone = vi.fn()
    render(
      <Toolbar
        table={fakeTable}
        search=""
        onSearch={() => {}}
        filterValues={{}}
        onFilters={() => {}}
        bulkActions={[{ value: 'del', label: 'Delete', handler }]}
        selectedRows={[{ id: 1 }, { id: 2 }]}
        onActionDone={onActionDone}
      />,
    )
    expect(screen.getByText('2 selected')).toBeTruthy()

    await userEvent.selectOptions(screen.getByLabelText('Bulk action'), 'del')
    await userEvent.click(screen.getByText('Apply'))
    expect(handler).toHaveBeenCalledWith([{ id: 1 }, { id: 2 }])
    expect(onActionDone).toHaveBeenCalled()
  })

  it('disables the search input when no searchable column is visible', () => {
    render(
      <Toolbar
        table={fakeTable}
        search=""
        onSearch={() => {}}
        searchDisabled
        filterValues={{}}
        onFilters={() => {}}
        selectedRows={[]}
        onActionDone={() => {}}
      />,
    )
    const input = screen.getByPlaceholderText('Show a searchable column to search') as HTMLInputElement
    expect(input.disabled).toBe(true)
  })

  it('keeps the search box next to the bulk actions while rows are selected', () => {
    render(
      <Toolbar
        table={fakeTable}
        search="ja"
        onSearch={() => {}}
        filterValues={{}}
        onFilters={() => {}}
        bulkActions={[{ value: 'del', label: 'Delete', handler: () => {} }]}
        selectedRows={[{ id: 1 }]}
        onActionDone={() => {}}
      />,
    )
    expect(screen.getByText('1 selected')).toBeTruthy()
    expect((screen.getByPlaceholderText('Search…') as HTMLInputElement).value).toBe('ja')
  })

  it('labels the column menu with meta.label, then a string header, then the id', async () => {
    const column = (id: string, columnDef: object) => ({
      id,
      columnDef,
      getCanHide: () => true,
      getIsVisible: () => true,
      toggleVisibility: () => {},
    })
    const table = {
      getAllColumns: () => [
        column('full_name', { header: () => 'ignored', meta: { label: 'Full name' } }),
        column('email', { header: 'E-mail' }),
        column('created_at', { header: () => 'x' }),
      ],
    } as any
    render(
      <Toolbar
        table={table}
        search=""
        onSearch={() => {}}
        filterValues={{}}
        onFilters={() => {}}
        selectedRows={[]}
        onActionDone={() => {}}
      />,
    )
    await userEvent.click(screen.getByText('Columns'))
    expect(screen.getByText('Full name')).toBeTruthy()
    expect(screen.getByText('E-mail')).toBeTruthy()
    expect(screen.getByText('created_at')).toBeTruthy()
  })
})
