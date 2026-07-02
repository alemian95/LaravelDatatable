import { useState } from 'react'
import { Sheet, SheetContent, SheetHeader, SheetTitle, SheetTrigger } from './ui/sheet'
import { Button } from './ui/button'
import { Input } from './ui/input'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from './ui/select'
import type { FilterDef, FilterValue } from './types'

export interface FiltersSheetProps {
  filters: FilterDef[]
  values: Record<string, FilterValue>
  onApply: (v: Record<string, FilterValue>) => void
  activeCount: number
}

export function FiltersSheet({ filters, values, onApply, activeCount }: FiltersSheetProps) {
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
        <Button>Filters{activeCount > 0 ? ` (${activeCount})` : ''}</Button>
      </SheetTrigger>
      <SheetContent side="right">
        <SheetHeader>
          <SheetTitle>Filters</SheetTitle>
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
                <Select value={(draft[f.id] as string) ?? ''} onValueChange={(v) => set(f.id, v)}>
                  <SelectTrigger id={`f-${f.id}`} aria-label={f.label} className="w-full">
                    <SelectValue placeholder="Any" />
                  </SelectTrigger>
                  <SelectContent>
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
                    aria-label={`${f.label} from`}
                    type="date"
                    value={(draft[f.id] as { from?: string } | undefined)?.from ?? ''}
                    onChange={(e) =>
                      set(f.id, { ...(draft[f.id] as { from?: string; to?: string }), from: e.target.value })
                    }
                  />
                  <Input
                    aria-label={`${f.label} to`}
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
              Reset
            </Button>
            <Button
              className="flex-1"
              onClick={() => {
                onApply(draft)
                setOpen(false)
              }}
            >
              Apply filters
            </Button>
          </div>
        </div>
      </SheetContent>
    </Sheet>
  )
}
