export function safeRedirectPath(value, fallback = '/') {
  if (
    typeof value !== 'string' ||
    !value.startsWith('/') ||
    value.startsWith('//') ||
    value.includes('\\')
  ) {
    return fallback
  }

  try {
    const origin = window.location.origin
    const target = new URL(value, origin)
    return target.origin === origin ? `${target.pathname}${target.search}${target.hash}` : fallback
  } catch {
    return fallback
  }
}
