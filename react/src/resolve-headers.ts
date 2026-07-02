import type { HeadersResolver } from './types'

export async function resolveHeaders(h?: HeadersResolver): Promise<HeadersInit> {
  if (!h) return {}
  if (typeof h === 'function') return await h()
  return h
}
