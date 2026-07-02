import { describe, it, expect } from 'vitest'
import { buildParams } from './build-params'

const s = (q: Parameters<typeof buildParams>[0]) => buildParams(q).toString()

describe('buildParams', () => {
  it('sets page and per_page always', () => {
    expect(s({ page: 2, perPage: 25 })).toBe('page=2&per_page=25')
  })

  it('adds search and search_columns when present', () => {
    const out = buildParams({ page: 1, perPage: 15, search: 'jane', searchColumns: ['name', 'email'] })
    expect(out.get('search')).toBe('jane')
    expect(out.get('search_columns')).toBe('name,email')
  })

  it('omits search_columns when search is empty', () => {
    const out = buildParams({ page: 1, perPage: 15, search: '', searchColumns: ['name'] })
    expect(out.has('search')).toBe(false)
    expect(out.has('search_columns')).toBe(false)
  })

  it('maps sort_by and sort_order', () => {
    const out = buildParams({ page: 1, perPage: 15, sortBy: 'created_at', sortOrder: 'desc' })
    expect(out.get('sort_by')).toBe('created_at')
    expect(out.get('sort_order')).toBe('desc')
  })

  it('emits scalar and date-range filters, skipping empty values', () => {
    const out = buildParams({
      page: 1, perPage: 15,
      filters: { status: 'active', role: '', created_at: { from: '2026-06-01', to: '' } },
    })
    expect(out.get('filter[status]')).toBe('active')
    expect(out.has('filter[role]')).toBe(false)
    expect(out.get('filter[created_at][from]')).toBe('2026-06-01')
    expect(out.has('filter[created_at][to]')).toBe(false)
  })
})
