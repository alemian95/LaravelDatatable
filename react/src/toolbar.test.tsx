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
})
