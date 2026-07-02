import { Button } from './ui/button'
import type { FilterDef, FilterValue } from './types'

export interface FiltersSheetProps {
  filters: FilterDef[]
  values: Record<string, FilterValue>
  onApply: (v: Record<string, FilterValue>) => void
  activeCount: number
}

// ponytail: temporary stub — replaced by the full slide-over in a later task.
export function FiltersSheet({ activeCount }: FiltersSheetProps) {
  return <Button>Filters{activeCount > 0 ? ` (${activeCount})` : ''}</Button>
}
