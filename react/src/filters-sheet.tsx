import { useState } from 'react'
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle, SheetTrigger } from './ui/sheet'
import { Button } from './ui/button'
import { Input } from './ui/input'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from './ui/select'
import { useLabels } from './provider'
import type { FilterDef, FilterValue } from './types'

// Radix Select forbids an empty-string item value, so "clear this filter" needs
// a sentinel that maps back to '' (which buildParams then omits).
const ANY = '__any__'

export interface FiltersSheetProps {
  filters: FilterDef[]
  values: Record<string, FilterValue>
  onApply: (v: Record<string, FilterValue>) => void
  activeCount: number
}

export function FiltersSheet({ filters, values, onApply, activeCount }: FiltersSheetProps) {
  const labels = useLabels()
  const [open, setOpen] = useState(false)
  const [draft, setDraft] = useState<Record<string, FilterValue>>(values)

  function set(id: string, v: FilterValue) {
    setDraft((d) => ({ ...d, [id]: v }))
  }

  return (
    <Sheet
      open={open}
      onOpenChange={(o) => {
        setOpen(o)
        if (o) setDraft(values)
      }}
    >
      <SheetTrigger asChild>
        <Button>{labels.filters}{activeCount > 0 ? ` (${activeCount})` : ''}</Button>
      </SheetTrigger>
      <SheetContent side="right">
        <SheetHeader>
          <SheetTitle>{labels.filters}</SheetTitle>
          <SheetDescription>{labels.filtersDescription}</SheetDescription>
        </SheetHeader>
        <div className="mt-4 space-y-4">
          {filters.map((f) => (
            <div key={f.id} className="space-y-1">
              <label htmlFor={`f-${f.id}`} className="text-sm text-gray-500 dark:text-gray-400">
                {f.label}
              </label>

              {f.type === 'text' && (
                <Input
                  id={`f-${f.id}`}
                  aria-label={f.label}
                  value={(draft[f.id] as string) ?? ''}
                  onChange={(e) => set(f.id, e.target.value)}
                />
              )}

              {f.type === 'select' && (
                <Select
                  value={((draft[f.id] as string) || undefined) ?? undefined}
                  onValueChange={(v) => set(f.id, v === ANY ? '' : v)}
                >
                  <SelectTrigger id={`f-${f.id}`} aria-label={f.label} className="w-full">
                    <SelectValue placeholder={labels.any} />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value={ANY}>{labels.any}</SelectItem>
                    {f.options.map((o) => (
                      <SelectItem key={o.value} value={o.value}>
                        {o.label}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              )}

              {f.type === 'date-range' && (
                <div className="flex gap-2">
                  <Input
                    aria-label={`${f.label} ${labels.from}`}
                    type="date"
                    value={(draft[f.id] as { from?: string } | undefined)?.from ?? ''}
                    onChange={(e) =>
                      set(f.id, { ...(draft[f.id] as { from?: string; to?: string }), from: e.target.value })
                    }
                  />
                  <Input
                    aria-label={`${f.label} ${labels.to}`}
                    type="date"
                    value={(draft[f.id] as { to?: string } | undefined)?.to ?? ''}
                    onChange={(e) =>
                      set(f.id, { ...(draft[f.id] as { from?: string; to?: string }), to: e.target.value })
                    }
                  />
                </div>
              )}
            </div>
          ))}

          <div className="flex gap-2 pt-2">
            <Button className="flex-1" onClick={() => setDraft({})}>
              {labels.reset}
            </Button>
            <Button
              className="flex-1"
              onClick={() => {
                onApply(draft)
                setOpen(false)
              }}
            >
              {labels.applyFilters}
            </Button>
          </div>
        </div>
      </SheetContent>
    </Sheet>
  )
}
