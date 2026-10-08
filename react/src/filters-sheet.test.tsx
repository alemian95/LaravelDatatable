import { describe, it, expect, vi } from 'vitest'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { FiltersSheet } from './filters-sheet'

describe('FiltersSheet', () => {
  it('applies a text filter draft', async () => {
    const onApply = vi.fn()
    render(
      <FiltersSheet
        filters={[{ id: 'city', label: 'City', type: 'text' }]}
        values={{}}
        onApply={onApply}
        activeCount={0}
      />,
    )
    await userEvent.click(screen.getByText('Filters'))
    await userEvent.type(screen.getByLabelText('City'), 'Rome')
    await userEvent.click(screen.getByText('Apply filters'))
    expect(onApply).toHaveBeenCalledWith({ city: 'Rome' })
  })
})

describe('FiltersSheet select', () => {
  it('stays controlled when a value is picked', async () => {
    // Radix Select needs these in jsdom.
    Element.prototype.hasPointerCapture ??= () => false
    Element.prototype.scrollIntoView ??= () => {}
    const warn = vi.spyOn(console, 'warn').mockImplementation(() => {})
    const onApply = vi.fn()
    render(
      <FiltersSheet
        filters={[{ id: 'status', label: 'Status', type: 'select', options: [{ value: 'paid', label: 'Paid' }] }]}
        values={{}}
        onApply={onApply}
        activeCount={0}
      />,
    )
    await userEvent.click(screen.getByText('Filters'))
    await userEvent.click(screen.getByLabelText('Status'))
    await userEvent.click(await screen.findByRole('option', { name: 'Paid' }))
    await userEvent.click(screen.getByText('Apply filters'))

    expect(onApply).toHaveBeenCalledWith({ status: 'paid' })
    expect(warn.mock.calls.flat().join(' ')).not.toMatch(/uncontrolled to controlled/)
    warn.mockRestore()
  })
})
