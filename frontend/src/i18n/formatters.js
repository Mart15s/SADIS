const DEFAULT_LOCALE = 'en-IN'

export function formatDate(value, options = {}, locale = DEFAULT_LOCALE) {
  if (!value) return '—'
  const date = value instanceof Date ? value : new Date(value)
  if (Number.isNaN(date.getTime())) return '—'
  return new Intl.DateTimeFormat(locale, {
    day: 'numeric',
    month: 'short',
    year: 'numeric',
    ...options,
  }).format(date)
}

export function formatDateTime(value, options = {}, locale = DEFAULT_LOCALE, timeZone) {
  return formatDate(value, {
    hour: '2-digit',
    minute: '2-digit',
    ...(timeZone ? { timeZone } : {}),
    ...options,
  }, locale)
}

export function formatNumber(value, options = {}, locale = DEFAULT_LOCALE) {
  const number = Number(value)
  if (!Number.isFinite(number)) return '—'
  return new Intl.NumberFormat(locale, options).format(number)
}

export function formatArea(squareMeters, preference = 'hectare', locale = DEFAULT_LOCALE) {
  const area = Number(squareMeters)
  if (!Number.isFinite(area)) return '—'
  if (preference === 'acre') return `${formatNumber(area / 4046.8564224, { maximumFractionDigits: 2 }, locale)} ac`
  if (preference === 'square_meter' || area < 10000) return `${formatNumber(area, { maximumFractionDigits: 1 }, locale)} m²`
  return `${formatNumber(area / 10000, { maximumFractionDigits: 2 }, locale)} ha`
}

export function formatQuantity(value, unit, locale = DEFAULT_LOCALE) {
  const suffixes = { kilogram: 'kg', litre: 'L', unit: 'units', hour: 'hr' }
  return `${formatNumber(value, { maximumFractionDigits: 2 }, locale)} ${suffixes[unit] ?? unit ?? ''}`.trim()
}
