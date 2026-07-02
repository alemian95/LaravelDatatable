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
