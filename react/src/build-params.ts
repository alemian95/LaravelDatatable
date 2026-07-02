import type { DatatableQuery } from './types'

export function buildParams(query: DatatableQuery): URLSearchParams {
  const p = new URLSearchParams()
  p.set('page', String(query.page))
  p.set('per_page', String(query.perPage))

  if (query.search) {
    p.set('search', query.search)
    if (query.searchColumns && query.searchColumns.length > 0) {
      p.set('search_columns', query.searchColumns.join(','))
    }
  }

  if (query.sortBy) {
    p.set('sort_by', query.sortBy)
    p.set('sort_order', query.sortOrder ?? 'asc')
  }

  for (const [id, value] of Object.entries(query.filters ?? {})) {
    if (value == null) continue
    if (typeof value === 'object') {
      if (value.from) p.set(`filter[${id}][from]`, value.from)
      if (value.to) p.set(`filter[${id}][to]`, value.to)
    } else if (value !== '') {
      p.set(`filter[${id}]`, value)
    }
  }

  return p
}
