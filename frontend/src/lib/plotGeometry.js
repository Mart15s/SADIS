export const MIN_NORMALIZED_GEOMETRY_POINT_COUNT = 3

export function clamp01(value) {
  return Math.min(Math.max(value, 0), 1)
}

export function roundGeometryValue(value, digits = 4) {
  const factor = 10 ** digits
  return Math.round(value * factor) / factor
}

export function finiteNumber(value) {
  const number = Number(value)
  return Number.isFinite(number) ? number : null
}

export function clampNormalizedPoint(point) {
  const x = finiteNumber(point?.x)
  const y = finiteNumber(point?.y)

  if (x === null || y === null) {
    return null
  }

  return {
    x: roundGeometryValue(clamp01(x)),
    y: roundGeometryValue(clamp01(y)),
  }
}

export function normalizePoint(point, reference) {
  const x = finiteNumber(point?.x)
  const y = finiteNumber(point?.y)
  const size = Math.max(finiteNumber(reference?.size) ?? 0, 0)

  if (x === null || y === null || size <= 0) {
    return null
  }

  return clampNormalizedPoint({
    x: (x - (finiteNumber(reference?.originX) ?? 0)) / size,
    y: (y - (finiteNumber(reference?.originY) ?? 0)) / size,
  })
}

export function denormalizePoint(point, reference) {
  const normalized = clampNormalizedPoint(point)
  const size = Math.max(finiteNumber(reference?.size) ?? 0, 0)

  if (!normalized || size <= 0) {
    return null
  }

  return {
    x: roundGeometryValue((finiteNumber(reference?.originX) ?? 0) + (normalized.x * size), 2),
    y: roundGeometryValue((finiteNumber(reference?.originY) ?? 0) + (normalized.y * size), 2),
  }
}

export function canvasToPlotNormalizedPoint(screenPoint, viewport, reference) {
  const scale = Math.max(finiteNumber(viewport?.scale) ?? 0, Number.EPSILON)
  const worldPoint = {
    x: ((finiteNumber(screenPoint?.x) ?? 0) - (finiteNumber(viewport?.x) ?? 0)) / scale,
    y: ((finiteNumber(screenPoint?.y) ?? 0) - (finiteNumber(viewport?.y) ?? 0)) / scale,
  }

  return normalizePoint(worldPoint, reference)
}

export function plotNormalizedToCanvasPoint(normalizedPoint, viewport, reference) {
  const worldPoint = denormalizePoint(normalizedPoint, reference)

  if (!worldPoint) {
    return null
  }

  const scale = Math.max(finiteNumber(viewport?.scale) ?? 0, Number.EPSILON)

  return {
    x: roundGeometryValue((worldPoint.x * scale) + (finiteNumber(viewport?.x) ?? 0), 2),
    y: roundGeometryValue((worldPoint.y * scale) + (finiteNumber(viewport?.y) ?? 0), 2),
  }
}

function polygonArea(points) {
  if (points.length < 3) {
    return 0
  }

  return Math.abs(points.reduce((sum, point, index) => {
    const next = points[(index + 1) % points.length]
    return sum + (point.x * next.y) - (next.x * point.y)
  }, 0)) / 2
}

function sanitizeLatLng(point) {
  const lat = finiteNumber(point?.lat)
  const lng = finiteNumber(point?.lng)

  if (lat === null || lng === null || lat < -90 || lat > 90 || lng < -180 || lng > 180) {
    return null
  }

  return {
    lat: roundGeometryValue(lat, 6),
    lng: roundGeometryValue(lng, 6),
  }
}

function sanitizeMapGeometry(map) {
  if (!map || typeof map !== 'object') {
    return null
  }

  const boundary = Array.isArray(map.boundary)
    ? map.boundary.map(sanitizeLatLng).filter(Boolean)
    : []
  const center = sanitizeLatLng(map.center)
  const zoom = finiteNumber(map.zoom)
  const sanitized = {
    provider: typeof map.provider === 'string' && map.provider.trim()
      ? map.provider.trim()
      : 'openstreetmap',
  }

  if (center) {
    sanitized.center = center
  }

  if (Number.isFinite(zoom)) {
    sanitized.zoom = Math.round(zoom)
  }

  if (boundary.length >= MIN_NORMALIZED_GEOMETRY_POINT_COUNT) {
    sanitized.boundary = boundary
  }

  return sanitized.center || sanitized.boundary ? sanitized : null
}

function pointKey(point) {
  return `${roundGeometryValue(point.x, 6)}:${roundGeometryValue(point.y, 6)}`
}

function orientation(a, b, c) {
  const value = ((b.y - a.y) * (c.x - b.x)) - ((b.x - a.x) * (c.y - b.y))

  if (Math.abs(value) < 0.000001) {
    return 0
  }

  return value > 0 ? 1 : 2
}

function pointOnSegment(point, start, end) {
  return point.x <= Math.max(start.x, end.x) + 0.000001
    && point.x + 0.000001 >= Math.min(start.x, end.x)
    && point.y <= Math.max(start.y, end.y) + 0.000001
    && point.y + 0.000001 >= Math.min(start.y, end.y)
}

function segmentsIntersect(startA, endA, startB, endB) {
  const first = orientation(startA, endA, startB)
  const second = orientation(startA, endA, endB)
  const third = orientation(startB, endB, startA)
  const fourth = orientation(startB, endB, endA)

  if (first !== second && third !== fourth) {
    return true
  }

  return (first === 0 && pointOnSegment(startB, startA, endA))
    || (second === 0 && pointOnSegment(endB, startA, endA))
    || (third === 0 && pointOnSegment(startA, startB, endB))
    || (fourth === 0 && pointOnSegment(endA, startB, endB))
}

export function hasSelfIntersection(points) {
  if (points.length < 4) {
    return false
  }

  for (let index = 0; index < points.length; index += 1) {
    const startA = points[index]
    const endA = points[(index + 1) % points.length]

    for (let compareIndex = index + 1; compareIndex < points.length; compareIndex += 1) {
      const nextCompareIndex = (compareIndex + 1) % points.length

      if (
        index === compareIndex
        || (index + 1) % points.length === compareIndex
        || index === nextCompareIndex
      ) {
        continue
      }

      if (segmentsIntersect(startA, endA, points[compareIndex], points[nextCompareIndex])) {
        return true
      }
    }
  }

  return false
}

export function isValidNormalizedPolygon(points, minArea = 0.000001) {
  if (!Array.isArray(points) || points.length < MIN_NORMALIZED_GEOMETRY_POINT_COUNT) {
    return false
  }

  if (points.some((point) => finiteNumber(point?.x) === null || finiteNumber(point?.y) === null)) {
    return false
  }

  const clampedPoints = points.map(clampNormalizedPoint)

  if (clampedPoints.some((point) => !point)) {
    return false
  }

  if (new Set(clampedPoints.map(pointKey)).size < 3) {
    return false
  }

  return polygonArea(clampedPoints) >= minArea && !hasSelfIntersection(clampedPoints)
}

export function sanitizeNormalizedGeometry(geometry, options = {}) {
  if (geometry === null || geometry === undefined) {
    return { geometry: null, error: null, clamped: false }
  }

  if (!Array.isArray(geometry?.points) || geometry.points.length < MIN_NORMALIZED_GEOMETRY_POINT_COUNT) {
    return { geometry: null, error: 'Geometry must contain at least 3 points.', clamped: false }
  }

  const sanitizedPoints = geometry.points.map(clampNormalizedPoint)

  if (sanitizedPoints.some((point) => !point)) {
    return { geometry: null, error: 'Geometry contains a non-numeric or infinite coordinate.', clamped: false }
  }

  const clamped = sanitizedPoints.some((point, index) => {
    const sourceX = finiteNumber(geometry.points[index]?.x)
    const sourceY = finiteNumber(geometry.points[index]?.y)
    return sourceX !== point.x || sourceY !== point.y
  })

  if (!isValidNormalizedPolygon(sanitizedPoints, options.minArea ?? 0.000001)) {
    return {
      geometry: null,
      error: 'Geometry becomes invalid after clamping. Please adjust the plot or zone shape before saving.',
      clamped,
    }
  }

  const map = sanitizeMapGeometry(geometry.map)

  return {
    geometry: {
      points: sanitizedPoints,
      ...(map ? { map } : {}),
    },
    error: null,
    clamped,
  }
}

export function assertSanitizedGeometryPayload(label, geometry) {
  const result = sanitizeNormalizedGeometry(geometry)

  if (result.error) {
    return {
      geometry: null,
      error: `${label}: ${result.error}`,
    }
  }

  return {
    geometry: result.geometry,
    error: null,
  }
}
