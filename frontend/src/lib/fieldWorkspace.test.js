import { describe, expect, it } from 'vitest'
import { normalizeFieldWorkspace, serializeFieldWorkspace } from './fieldWorkspace.js'

describe('canonical field workspace contract', () => {
  it('loads canonical boundary/colour and tolerates malformed zone geometry', () => {
    const result = normalizeFieldWorkspace({
      boundary: [{ x: 1, y: 2 }, null, { x: '3', y: '4' }],
      zones: [{ id: 9, name: 'North', colour: '#123456', boundary: null }],
    })
    expect(result.geometry).toEqual([{ x: 1, y: 2 }, { x: 3, y: 4 }])
    expect(result.zones[0]).toMatchObject({ color: '#123456', geometry: [] })
  })

  it('writes canonical boundary/colour and omits non-integer draft IDs', () => {
    const result = serializeFieldWorkspace({ geometry: [{ x: 10, y: 20 }], zones: [{ id: 'draft-1', client_id: 'draft-1', color: '#DA743A', geometry: [{ x: 30, y: 40 }] }] })
    expect(result.boundary).toEqual([{ x: 10, y: 20 }])
    expect(result.zones[0]).not.toHaveProperty('id')
    expect(result.zones[0]).toMatchObject({ client_id: 'draft-1', colour: '#DA743A', boundary: [{ x: 30, y: 40 }] })
  })
})
