import { roundTo } from './plotDesigner.js'

const EARTH_RADIUS_METERS = 6_371_000
const METERS_PER_DEGREE_LAT = 111_320

function toRadians(value) {
  return (Number(value) * Math.PI) / 180
}

function averageLatitude(points) {
  if (!points.length) {
    return 0
  }

  return points.reduce((sum, point) => sum + Number(point.lat || 0), 0) / points.length
}

function metersPerDegreeLng(points) {
  return Math.max(
    Math.cos(toRadians(averageLatitude(points))) * METERS_PER_DEGREE_LAT,
    Number.EPSILON,
  )
}

function projectLatLngPoints(points) {
  const lngScale = metersPerDegreeLng(points)

  return points.map((point) => ({
    x: Number(point.lng) * lngScale,
    y: Number(point.lat) * METERS_PER_DEGREE_LAT,
    source: point,
  }))
}

function polygonArea(projectedPoints) {
  if (projectedPoints.length < 3) {
    return 0
  }

  return projectedPoints.reduce((sum, point, index) => {
    const next = projectedPoints[(index + 1) % projectedPoints.length]
    return sum + (point.x * next.y) - (next.x * point.y)
  }, 0) / 2
}

export function distanceMeters(start, end) {
  const startLat = toRadians(start?.lat)
  const endLat = toRadians(end?.lat)
  const deltaLat = toRadians(Number(end?.lat) - Number(start?.lat))
  const deltaLng = toRadians(Number(end?.lng) - Number(start?.lng))
  const halfChord = (Math.sin(deltaLat / 2) ** 2)
    + (Math.cos(startLat) * Math.cos(endLat) * (Math.sin(deltaLng / 2) ** 2))

  return 2 * EARTH_RADIUS_METERS * Math.atan2(Math.sqrt(halfChord), Math.sqrt(1 - halfChord))
}

export function calculateLatLngArea(points) {
  if (!Array.isArray(points) || points.length < 3) {
    return 0
  }

  return roundTo(Math.abs(polygonArea(projectLatLngPoints(points))), 2)
}

export function calculateLatLngPerimeter(points, closed = true) {
  if (!Array.isArray(points) || points.length < 2) {
    return 0
  }

  const lastEdgeIndex = closed && points.length > 2 ? points.length : points.length - 1
  let perimeter = 0

  for (let index = 0; index < lastEdgeIndex; index += 1) {
    perimeter += distanceMeters(points[index], points[(index + 1) % points.length])
  }

  return roundTo(perimeter, 2)
}

export function calculateLatLngCenter(points) {
  if (!Array.isArray(points) || points.length === 0) {
    return null
  }

  const averageCenter = {
    lat: points.reduce((sum, point) => sum + Number(point.lat || 0), 0) / points.length,
    lng: points.reduce((sum, point) => sum + Number(point.lng || 0), 0) / points.length,
  }

  if (points.length < 3) {
    return {
      lat: averageCenter.lat,
      lng: averageCenter.lng,
    }
  }

  const projectedPoints = projectLatLngPoints(points)
  const signedArea = polygonArea(projectedPoints)

  if (Math.abs(signedArea) < Number.EPSILON) {
    return averageCenter
  }

  let centroidX = 0
  let centroidY = 0

  for (let index = 0; index < projectedPoints.length; index += 1) {
    const current = projectedPoints[index]
    const next = projectedPoints[(index + 1) % projectedPoints.length]
    const cross = (current.x * next.y) - (next.x * current.y)

    centroidX += (current.x + next.x) * cross
    centroidY += (current.y + next.y) * cross
  }

  const factor = 1 / (6 * signedArea)
  const lngScale = metersPerDegreeLng(points)

  return {
    lat: (centroidY * factor) / METERS_PER_DEGREE_LAT,
    lng: (centroidX * factor) / lngScale,
  }
}

export function getLatLngEdges(points, includeClosingEdge = true) {
  if (!Array.isArray(points) || points.length < 2) {
    return []
  }

  const edgeCount = includeClosingEdge && points.length > 2 ? points.length : points.length - 1

  return Array.from({ length: edgeCount }, (_, index) => {
    const start = points[index]
    const end = points[(index + 1) % points.length]

    return {
      index,
      start,
      end,
      length: roundTo(distanceMeters(start, end), 2),
      midpoint: {
        lat: (Number(start.lat) + Number(end.lat)) / 2,
        lng: (Number(start.lng) + Number(end.lng)) / 2,
      },
    }
  })
}
