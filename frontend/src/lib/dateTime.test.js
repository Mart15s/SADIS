import { describe, expect, it } from 'vitest'
import {
  deserializeDateTimeFields,
  serializeDateTimeFields,
  utcIsoToZonedLocalInput,
  zonedLocalInputToUtcIso,
} from './dateTime.js'

describe('workspace timezone date-time conversion', () => {
  it('serializes an India local wall-clock value as UTC', () => {
    expect(zonedLocalInputToUtcIso('2026-08-02T10:15', 'Asia/Kolkata')).toBe(
      '2026-08-02T04:45:00.000Z',
    )
  })

  it('deserializes UTC for a non-whole-hour workspace timezone', () => {
    expect(utcIsoToZonedLocalInput('2026-08-02T04:45:00.000Z', 'Asia/Kolkata')).toBe(
      '2026-08-02T10:15',
    )
  })

  it('only transforms fields declared as datetime-local', () => {
    const fields = [{ name: 'title' }, { name: 'starts_at', type: 'datetime-local' }]
    const payload = serializeDateTimeFields(
      { title: 'Inspect', starts_at: '2026-08-02T10:15' },
      fields,
      'Asia/Kolkata',
    )
    expect(payload).toEqual({ title: 'Inspect', starts_at: '2026-08-02T04:45:00.000Z' })
    expect(deserializeDateTimeFields(payload, fields, 'Asia/Kolkata')).toEqual({
      title: 'Inspect',
      starts_at: '2026-08-02T10:15',
    })
  })
})
