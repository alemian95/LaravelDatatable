import { describe, it, expect } from 'vitest'
import { resolveHeaders } from './resolve-headers'

describe('resolveHeaders', () => {
  it('returns {} when undefined', async () => {
    expect(await resolveHeaders(undefined)).toEqual({})
  })

  it('returns a static object as-is', async () => {
    expect(await resolveHeaders({ Authorization: 'Bearer x' })).toEqual({ Authorization: 'Bearer x' })
  })

  it('calls a sync function', async () => {
    expect(await resolveHeaders(() => ({ Authorization: 'Bearer y' }))).toEqual({ Authorization: 'Bearer y' })
  })

  it('awaits an async function', async () => {
    expect(await resolveHeaders(async () => ({ Authorization: 'Bearer z' }))).toEqual({ Authorization: 'Bearer z' })
  })
})
