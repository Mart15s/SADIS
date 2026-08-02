function parseLocalDateTime(value) {
  const match = String(value || '').match(/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})(?::(\d{2}))?/)
  if (!match) return null
  const [, year, month, day, hour, minute, second = '00'] = match
  return {
    year: Number(year),
    month: Number(month),
    day: Number(day),
    hour: Number(hour),
    minute: Number(minute),
    second: Number(second),
  }
}

function zonedParts(timestamp, timeZone) {
  const parts = new Intl.DateTimeFormat('en-CA', {
    timeZone,
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
    second: '2-digit',
    hourCycle: 'h23',
  }).formatToParts(new Date(timestamp))

  return Object.fromEntries(
    parts.filter((part) => part.type !== 'literal').map((part) => [part.type, Number(part.value)]),
  )
}

function asUtcTimestamp(parts) {
  return Date.UTC(
    parts.year,
    parts.month - 1,
    parts.day,
    parts.hour,
    parts.minute,
    parts.second || 0,
  )
}

export function zonedLocalInputToUtcIso(value, timeZone = 'UTC') {
  const local = parseLocalDateTime(value)
  if (!local) return value

  const localTimestamp = asUtcTimestamp(local)
  let result = localTimestamp

  // Re-evaluate the offset to handle zones whose offset changes around this
  // date. Two passes are sufficient for normal DST transitions.
  for (let pass = 0; pass < 3; pass += 1) {
    const rendered = zonedParts(result, timeZone)
    const offset = asUtcTimestamp(rendered) - result
    const next = localTimestamp - offset
    if (next === result) break
    result = next
  }

  return new Date(result).toISOString()
}

export function utcIsoToZonedLocalInput(value, timeZone = 'UTC') {
  if (!value) return ''
  const timestamp = Date.parse(value)
  if (!Number.isFinite(timestamp)) return value
  const parts = zonedParts(timestamp, timeZone)
  const pad = (number) => String(number).padStart(2, '0')

  return `${parts.year}-${pad(parts.month)}-${pad(parts.day)}T${pad(parts.hour)}:${pad(parts.minute)}`
}

export function serializeDateTimeFields(form, fields, timeZone) {
  const dateTimeNames = new Set(
    (fields || []).filter((field) => field.type === 'datetime-local').map((field) => field.name),
  )

  return Object.fromEntries(
    Object.entries(form).map(([name, value]) => [
      name,
      dateTimeNames.has(name) && value ? zonedLocalInputToUtcIso(value, timeZone) : value,
    ]),
  )
}

export function deserializeDateTimeFields(item, fields, timeZone) {
  return Object.fromEntries(
    (fields || []).map((field) => [
      field.name,
      field.type === 'datetime-local' && item?.[field.name]
        ? utcIsoToZonedLocalInput(item[field.name], timeZone)
        : item?.[field.name],
    ]),
  )
}
