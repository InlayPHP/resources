import { afterEach, describe, expect, it, vi } from 'vitest'
import { mutateRelation, RelationMutationError, relationEndpoint } from '../src'

afterEach(() => vi.unstubAllGlobals())

describe('resource relation transport', () => {
  it('interpolates encoded record keys', () => {
    expect(relationEndpoint('/users/{record}', 'a/b')).toBe('/users/a%2Fb')
  })

  it('returns mutation payloads and exposes Laravel validation errors', async () => {
    const fetcher = vi.fn()
      .mockResolvedValueOnce(new Response(JSON.stringify({
        contract: 'inlay.resources.relation-mutation.v1',
        operation: 'create',
        record: { id: 1 },
      }), { status: 201, headers: { 'Content-Type': 'application/json' } }))
      .mockResolvedValueOnce(new Response(JSON.stringify({
        message: 'Invalid data.',
        errors: { title: ['The title field is required.'] },
      }), { status: 422, headers: { 'Content-Type': 'application/json' } }))
    vi.stubGlobal('fetch', fetcher)

    await expect(mutateRelation({ url: '/posts', method: 'post', data: { title: 'Hello' } }))
      .resolves.toMatchObject({ operation: 'create', record: { id: 1 } })
    await expect(mutateRelation({ url: '/posts', method: 'post' }))
      .rejects.toEqual(expect.objectContaining<Partial<RelationMutationError>>({
        status: 422,
        errors: { title: ['The title field is required.'] },
      }))
  })
})
