import { describe, expect, it } from 'vitest'
import { createWorkspaceClientId } from './plotWorkspaceDraft.js'

describe('createWorkspaceClientId', () => {
  it('returns a draft id that does not collide with existing ids or client ids', () => {
    const tokens = ['taken', 'also-taken', 'fresh']
    const id = createWorkspaceClientId(
      'draft-zone',
      [
        { id: 'draft-zone-taken' },
        { client_id: 'draft-zone-also-taken' },
      ],
      () => tokens.shift(),
    )

    expect(id).toBe('draft-zone-fresh')
  })
})
