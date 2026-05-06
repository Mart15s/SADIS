import {
  denormalizePoint,
  isValidNormalizedPolygon,
  normalizePoint as normalizeReferencePoint,
  sanitizeNormalizedGeometry,
} from './plotGeometry.js'

const STORAGE_PREFIX = 'sad-plot-designer-v1'

export const MIN_ZOOM = 0.12
export const MAX_ZOOM = 180
export const GRID_SIZE = 2
export const MIN_ZONE_EDGE = 1.5
export const MIN_BOUNDARY_EDGE = 6

function clamp(value, min, max) {
  return Math.min(Math.max(value, min), max)
}

export function roundTo(value, digits = 2) {
  const factor = 10 ** digits
  return Math.round(value * factor) / factor
}

function normalizePoint(point) {
  return {
    x: roundTo(Number(point?.x) || 0, 2),
    y: roundTo(Number(point?.y) || 0, 2),
  }
}

function pointKey(point) {
  return `${roundTo(point.x, 3)}:${roundTo(point.y, 3)}`
}

export function rectToPoints(rect) {
  const x = Number(rect?.x) || 0
  const y = Number(rect?.y) || 0
  const width = Math.max(Number(rect?.width) || MIN_ZONE_EDGE, MIN_ZONE_EDGE)
  const height = Math.max(Number(rect?.height) || MIN_ZONE_EDGE, MIN_ZONE_EDGE)

  return [
    { x, y },
    { x: x + width, y },
    { x: x + width, y: y + height },
    { x, y: y + height },
  ].map(normalizePoint)
}

export const RESIZE_HANDLES = [
  'nw',
  'n',
  'ne',
  'e',
  'se',
  's',
  'sw',
  'w',
]

export const ZONE_VERTEX_HANDLES = [0, 1, 2, 3]

export function createRectShape(rect) {
  return {
    kind: 'polygon',
    points: rectToPoints(rect),
  }
}

export function getShapePoints(shape, fallbackShape = null) {
  if (Array.isArray(shape?.points) && shape.points.length >= 3) {
    return shape.points.map(normalizePoint)
  }

  if (shape && ['x', 'y', 'width', 'height'].every((key) => key in shape)) {
    return rectToPoints(shape)
  }

  if (fallbackShape) {
    return getShapePoints(fallbackShape)
  }

  return []
}

function polygonArea(points) {
  if (points.length < 3) {
    return 0
  }

  let sum = 0

  for (let index = 0; index < points.length; index += 1) {
    const current = points[index]
    const next = points[(index + 1) % points.length]
    sum += (current.x * next.y) - (next.x * current.y)
  }

  return Math.abs(sum) / 2
}

export function calculateArea(shape) {
  return roundTo(polygonArea(getShapePoints(shape)), 2)
}

export function getShapeBounds(shape) {
  const points = getShapePoints(shape)

  if (!points.length) {
    return {
      x: 0,
      y: 0,
      width: 0,
      height: 0,
      centerX: 0,
      centerY: 0,
      left: 0,
      right: 0,
      top: 0,
      bottom: 0,
    }
  }

  const xs = points.map((point) => point.x)
  const ys = points.map((point) => point.y)
  const left = Math.min(...xs)
  const right = Math.max(...xs)
  const top = Math.min(...ys)
  const bottom = Math.max(...ys)
  const width = right - left
  const height = bottom - top

  return {
    x: roundTo(left, 2),
    y: roundTo(top, 2),
    width: roundTo(width, 2),
    height: roundTo(height, 2),
    centerX: roundTo(left + (width / 2), 2),
    centerY: roundTo(top + (height / 2), 2),
    left: roundTo(left, 2),
    right: roundTo(right, 2),
    top: roundTo(top, 2),
    bottom: roundTo(bottom, 2),
  }
}

export function getShapeEdgeMidpoints(shape) {
  const points = getShapePoints(shape)

  if (points.length < 2) {
    return []
  }

  return points.map((point, index) => {
    const next = points[(index + 1) % points.length]

    return {
      index,
      start: point,
      end: next,
      point: normalizePoint({
        x: (point.x + next.x) / 2,
        y: (point.y + next.y) / 2,
      }),
    }
  })
}

export function getCombinedBounds(shapes) {
  const boundsList = shapes
    .map((shape) => getShapeBounds(shape))
    .filter((bounds) => bounds.width > 0 || bounds.height > 0)

  if (!boundsList.length) {
    return getShapeBounds(createRectShape({ x: 0, y: 0, width: MIN_BOUNDARY_EDGE, height: MIN_BOUNDARY_EDGE }))
  }

  return getShapeBounds(createRectShape({
    x: Math.min(...boundsList.map((bounds) => bounds.left)),
    y: Math.min(...boundsList.map((bounds) => bounds.top)),
    width: Math.max(...boundsList.map((bounds) => bounds.right)) - Math.min(...boundsList.map((bounds) => bounds.left)),
    height: Math.max(...boundsList.map((bounds) => bounds.bottom)) - Math.min(...boundsList.map((bounds) => bounds.top)),
  }))
}

function pointOnSegment(point, start, end, epsilon = 0.02) {
  const cross = ((point.y - start.y) * (end.x - start.x)) - ((point.x - start.x) * (end.y - start.y))

  if (Math.abs(cross) > epsilon) {
    return false
  }

  const dot = ((point.x - start.x) * (end.x - start.x)) + ((point.y - start.y) * (end.y - start.y))

  if (dot < -epsilon) {
    return false
  }

  const squaredLength = ((end.x - start.x) ** 2) + ((end.y - start.y) ** 2)
  return dot <= squaredLength + epsilon
}

export function pointInPolygon(point, shape) {
  const points = getShapePoints(shape)

  if (points.length < 3) {
    return false
  }

  for (let index = 0; index < points.length; index += 1) {
    if (pointOnSegment(point, points[index], points[(index + 1) % points.length])) {
      return true
    }
  }

  let isInside = false

  for (let index = 0, previous = points.length - 1; index < points.length; previous = index, index += 1) {
    const current = points[index]
    const prior = points[previous]
    const intersects = (
      current.y > point.y
      !== prior.y > point.y
      && point.x < (((prior.x - current.x) * (point.y - current.y)) / ((prior.y - current.y) || Number.EPSILON)) + current.x
    )

    if (intersects) {
      isInside = !isInside
    }
  }

  return isInside
}

function closestPointOnSegment(point, start, end) {
  const dx = end.x - start.x
  const dy = end.y - start.y
  const lengthSquared = (dx ** 2) + (dy ** 2)

  if (lengthSquared === 0) {
    return normalizePoint(start)
  }

  const ratio = clamp((((point.x - start.x) * dx) + ((point.y - start.y) * dy)) / lengthSquared, 0, 1)

  return normalizePoint({
    x: start.x + (ratio * dx),
    y: start.y + (ratio * dy),
  })
}

export function projectPointToPolygon(point, shape) {
  const points = getShapePoints(shape)

  if (!points.length || pointInPolygon(point, { points })) {
    return normalizePoint(point)
  }

  let bestPoint = points[0]
  let bestDistance = Number.POSITIVE_INFINITY

  for (let index = 0; index < points.length; index += 1) {
    const candidate = closestPointOnSegment(point, points[index], points[(index + 1) % points.length])
    const distance = ((candidate.x - point.x) ** 2) + ((candidate.y - point.y) ** 2)

    if (distance < bestDistance) {
      bestDistance = distance
      bestPoint = candidate
    }
  }

  return bestPoint
}

function segmentsIntersect(startA, endA, startB, endB) {
  function orientation(a, b, c) {
    const value = ((b.y - a.y) * (c.x - b.x)) - ((b.x - a.x) * (c.y - b.y))

    if (Math.abs(value) < 0.001) {
      return 0
    }

    return value > 0 ? 1 : 2
  }

  const first = orientation(startA, endA, startB)
  const second = orientation(startA, endA, endB)
  const third = orientation(startB, endB, startA)
  const fourth = orientation(startB, endB, endA)

  if (first !== second && third !== fourth) {
    return true
  }

  if (first === 0 && pointOnSegment(startB, startA, endA)) {
    return true
  }

  if (second === 0 && pointOnSegment(endB, startA, endA)) {
    return true
  }

  if (third === 0 && pointOnSegment(startA, startB, endB)) {
    return true
  }

  if (fourth === 0 && pointOnSegment(endA, startB, endB)) {
    return true
  }

  return false
}

export function doShapesOverlap(firstShape, secondShape) {
  const firstPoints = getShapePoints(firstShape)
  const secondPoints = getShapePoints(secondShape)

  if (firstPoints.length < 3 || secondPoints.length < 3) {
    return false
  }

  const axes = [firstPoints, secondPoints].flatMap((points) => (
    points.map((point, index) => {
      const next = points[(index + 1) % points.length]
      return {
        x: -(next.y - point.y),
        y: next.x - point.x,
      }
    })
  ))

  return axes.every((axis) => {
    const firstProjection = firstPoints.map((point) => (point.x * axis.x) + (point.y * axis.y))
    const secondProjection = secondPoints.map((point) => (point.x * axis.x) + (point.y * axis.y))
    const firstMin = Math.min(...firstProjection)
    const firstMax = Math.max(...firstProjection)
    const secondMin = Math.min(...secondProjection)
    const secondMax = Math.max(...secondProjection)

    return firstMax > secondMin + 0.001 && secondMax > firstMin + 0.001
  })
}

function hasSelfIntersection(points) {
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

      const startB = points[compareIndex]
      const endB = points[nextCompareIndex]

      if (segmentsIntersect(startA, endA, startB, endB)) {
        return true
      }
    }
  }

  return false
}

export function isPolygonShapeValid(shape, minArea = MIN_ZONE_EDGE * MIN_ZONE_EDGE * 0.35) {
  const points = getShapePoints(shape)

  if (points.length < 3) {
    return false
  }

  const uniquePoints = new Set(points.map(pointKey))

  if (uniquePoints.size < 3) {
    return false
  }

  return polygonArea(points) >= minArea && !hasSelfIntersection(points)
}

function sanitizePolygon(points, fallbackShape, minArea) {
  const normalized = points.map(normalizePoint)

  if (isPolygonShapeValid({ points: normalized }, minArea)) {
    return {
      kind: 'polygon',
      points: normalized,
    }
  }

  return {
    kind: 'polygon',
    points: getShapePoints(fallbackShape),
  }
}

export function estimateBoundaryFromArea(plotSize) {
  const area = Math.max(Number(plotSize) || 96, 36)
  const width = Math.max(MIN_BOUNDARY_EDGE, Math.sqrt(area * 1.35))
  const height = Math.max(MIN_BOUNDARY_EDGE, area / width)

  return createRectShape({
    x: 0,
    y: 0,
    width: roundTo(width, 2),
    height: roundTo(height, 2),
  })
}

function boundsContainsShape(bounds, shape) {
  return getShapePoints(shape).every((point) => (
    point.x >= bounds.left - 0.02
    && point.x <= bounds.right + 0.02
    && point.y >= bounds.top - 0.02
    && point.y <= bounds.bottom + 0.02
  ))
}

export function isZonePlacementValid(shape, boundary, existingShapes = []) {
  return Boolean(shape)
    && isPolygonShapeValid(shape)
    && isShapeInsideBoundary(shape, boundary)
    && existingShapes.every((existingShape) => !doShapesOverlap(shape, existingShape))
}

export function isTranslatedShapeValid(shape, boundary, translation, extraValidator = null) {
  const candidate = translateShape(shape, translation)

  return isPolygonShapeValid(candidate)
    && isShapeInsideBoundary(candidate, boundary)
    && (extraValidator ? extraValidator(candidate) : true)
}

export function resolveTranslatedShape(shape, boundary, translation, lastValidShape = shape, extraValidator = null) {
  const candidate = translateShape(shape, translation)

  if (
    isPolygonShapeValid(candidate)
    && isShapeInsideBoundary(candidate, boundary)
    && (extraValidator ? extraValidator(candidate) : true)
  ) {
    return candidate
  }

  return lastValidShape
}

export function translateShape(shape, offset) {
  const dx = Number(offset?.x) || 0
  const dy = Number(offset?.y) || 0

  return {
    kind: 'polygon',
    points: getShapePoints(shape).map((point) => normalizePoint({
      x: point.x + dx,
      y: point.y + dy,
    })),
  }
}

export function updateShapePoint(shape, index, point) {
  const points = getShapePoints(shape)

  return {
    kind: 'polygon',
    points: points.map((currentPoint, currentIndex) => (
      currentIndex === index ? normalizePoint(point) : currentPoint
    )),
  }
}

export function insertShapePoint(shape, edgeIndex, point, fallbackShape = shape, minArea = MIN_BOUNDARY_EDGE * MIN_BOUNDARY_EDGE * 0.55) {
  const points = getShapePoints(shape)

  if (points.length < 3) {
    return sanitizePolygon(points, fallbackShape, minArea)
  }

  const insertAfter = clamp(Math.round(Number(edgeIndex) || 0), 0, points.length - 1)
  const nextPoints = [
    ...points.slice(0, insertAfter + 1),
    normalizePoint(point),
    ...points.slice(insertAfter + 1),
  ]

  return sanitizePolygon(nextPoints, fallbackShape, minArea)
}

function scaleShapeToFitBounds(shape, targetBounds, padding = 0.85) {
  const currentBounds = getShapeBounds(shape)

  if (currentBounds.width <= 0 || currentBounds.height <= 0) {
    return shape
  }

  const scale = Math.min(
    1,
    (targetBounds.width * padding) / currentBounds.width,
    (targetBounds.height * padding) / currentBounds.height,
  )

  if (scale === 1) {
    return shape
  }

  return {
    kind: 'polygon',
    points: getShapePoints(shape).map((point) => normalizePoint({
      x: currentBounds.centerX + ((point.x - currentBounds.centerX) * scale),
      y: currentBounds.centerY + ((point.y - currentBounds.centerY) * scale),
    })),
  }
}

export function isShapeInsideBoundary(shape, boundary) {
  return getShapePoints(shape).every((point) => pointInPolygon(point, boundary))
}

export function getConstrainedTranslation(shape, boundary, translation, extraValidator = null) {
  if (!translation?.x && !translation?.y) {
    return { x: 0, y: 0 }
  }

  const candidate = translateShape(shape, translation)
  const isValidCandidate = (nextCandidate) => (
    isShapeInsideBoundary(nextCandidate, boundary)
    && (extraValidator ? extraValidator(nextCandidate) : true)
  )

  if (isValidCandidate(candidate)) {
    return normalizePoint(translation)
  }

  let low = 0
  let high = 1

  for (let iteration = 0; iteration < 20; iteration += 1) {
    const middle = (low + high) / 2
    const nextCandidate = translateShape(shape, {
      x: (translation.x || 0) * middle,
      y: (translation.y || 0) * middle,
    })

    if (isValidCandidate(nextCandidate)) {
      low = middle
    } else {
      high = middle
    }
  }

  return normalizePoint({
    x: (translation.x || 0) * low,
    y: (translation.y || 0) * low,
  })
}

export function getConstrainedVertexMove(
  shape,
  index,
  targetPoint,
  boundary,
  minArea = MIN_ZONE_EDGE * MIN_ZONE_EDGE * 0.35,
  extraValidator = null,
) {
  const points = getShapePoints(shape)
  const origin = points[index]
  const normalizedTarget = normalizePoint(targetPoint)

  const validator = (point) => {
    const candidate = updateShapePoint(shape, index, point)
    const insideBoundary = boundary ? isShapeInsideBoundary(candidate, boundary) : true
    return insideBoundary
      && isPolygonShapeValid(candidate, minArea)
      && (extraValidator ? extraValidator(candidate) : true)
  }

  if (validator(normalizedTarget)) {
    return normalizedTarget
  }

  let low = 0
  let high = 1
  let bestPoint = origin

  for (let iteration = 0; iteration < 20; iteration += 1) {
    const middle = (low + high) / 2
    const candidatePoint = normalizePoint({
      x: origin.x + ((normalizedTarget.x - origin.x) * middle),
      y: origin.y + ((normalizedTarget.y - origin.y) * middle),
    })

    if (validator(candidatePoint)) {
      bestPoint = candidatePoint
      low = middle
    } else {
      high = middle
    }
  }

  return bestPoint
}

export function fitShapeInsideBoundary(shape, boundary) {
  let fitted = {
    kind: 'polygon',
    points: getShapePoints(shape),
  }

  if (!isPolygonShapeValid(fitted)) {
    return createRectShape(getShapeBounds(boundary))
  }

  const boundaryBounds = getShapeBounds(boundary)

  if (!boundsContainsShape(boundaryBounds, fitted)) {
    fitted = scaleShapeToFitBounds(fitted, boundaryBounds)
  }

  const fittedBounds = getShapeBounds(fitted)
  const delta = {
    x: fittedBounds.left < boundaryBounds.left
      ? boundaryBounds.left - fittedBounds.left
      : (fittedBounds.right > boundaryBounds.right ? boundaryBounds.right - fittedBounds.right : 0),
    y: fittedBounds.top < boundaryBounds.top
      ? boundaryBounds.top - fittedBounds.top
      : (fittedBounds.bottom > boundaryBounds.bottom ? boundaryBounds.bottom - fittedBounds.bottom : 0),
  }

  if (delta.x || delta.y) {
    fitted = translateShape(fitted, getConstrainedTranslation(fitted, boundary, delta))
  }

  if (!isShapeInsideBoundary(fitted, boundary)) {
    const boundaryCenter = getShapeBounds(boundary)
    const finalDelta = getConstrainedTranslation(fitted, boundary, {
      x: boundaryCenter.centerX - getShapeBounds(fitted).centerX,
      y: boundaryCenter.centerY - getShapeBounds(fitted).centerY,
    })
    fitted = translateShape(fitted, finalDelta)
  }

  return fitted
}

export function createDefaultZoneShape(boundary, existingShapes = []) {
  const boundaryBounds = getShapeBounds(boundary)
  const boundaryArea = Math.max(calculateArea(boundary), MIN_ZONE_EDGE * MIN_ZONE_EDGE * 12)
  const targetArea = clamp(
    boundaryArea * 0.16,
    MIN_ZONE_EDGE * MIN_ZONE_EDGE * 6,
    boundaryArea * 0.3,
  )
  const width = clamp(
    Math.sqrt(targetArea * 1.3),
    MIN_ZONE_EDGE * 3,
    Math.max(boundaryBounds.width * 0.58, MIN_ZONE_EDGE * 3),
  )
  const height = clamp(
    targetArea / width,
    MIN_ZONE_EDGE * 3,
    Math.max(boundaryBounds.height * 0.52, MIN_ZONE_EDGE * 3),
  )
  const standardCenter = {
    x: boundaryBounds.centerX,
    y: boundaryBounds.centerY,
  }
  const triedKeys = new Set()
  const candidates = []

  function addCandidate(center, candidateWidth = width, candidateHeight = height) {
    const key = `${roundTo(center.x, 2)}:${roundTo(center.y, 2)}:${roundTo(candidateWidth, 2)}:${roundTo(candidateHeight, 2)}`

    if (triedKeys.has(key)) {
      return
    }

    triedKeys.add(key)

    const rect = createRectShape({
      x: center.x - (candidateWidth / 2),
      y: center.y - (candidateHeight / 2),
      width: candidateWidth,
      height: candidateHeight,
    })
    const candidate = fitShapeInsideBoundary(rect, boundary)

    if (!isZonePlacementValid(candidate, boundary, existingShapes)) {
      return
    }

    const candidateBounds = getShapeBounds(candidate)
    const distance = ((candidateBounds.centerX - standardCenter.x) ** 2) + ((candidateBounds.centerY - standardCenter.y) ** 2)

    candidates.push({
      candidate,
      distance,
    })
  }

  const sizeScales = [1, 0.85, 0.7, 0.55, 0.4]
  const maxRadius = Math.hypot(boundaryBounds.width, boundaryBounds.height)

  for (const scale of sizeScales) {
    const candidateWidth = Math.max(MIN_ZONE_EDGE * 3, width * scale)
    const candidateHeight = Math.max(MIN_ZONE_EDGE * 3, height * scale)
    const step = Math.max(MIN_ZONE_EDGE, Math.min(candidateWidth, candidateHeight) * 0.5)
    const maxSteps = Math.ceil(maxRadius / step)

    addCandidate(standardCenter, candidateWidth, candidateHeight)

    if (candidates.length) {
      return candidates.sort((left, right) => left.distance - right.distance)[0].candidate
    }

    for (let ring = 1; ring <= maxSteps; ring += 1) {
      for (let xStep = -ring; xStep <= ring; xStep += 1) {
        for (let yStep = -ring; yStep <= ring; yStep += 1) {
          if (Math.max(Math.abs(xStep), Math.abs(yStep)) !== ring) {
            continue
          }

          addCandidate({
            x: standardCenter.x + (xStep * step),
            y: standardCenter.y + (yStep * step),
          }, candidateWidth, candidateHeight)
        }
      }

      if (candidates.length) {
        return candidates.sort((left, right) => left.distance - right.distance)[0].candidate
      }
    }
  }

  return null
}

function toNumericArea(value, fallback) {
  return Math.max(Number(value) || fallback, fallback)
}

export function buildDefaultZoneLayouts(zones, boundary) {
  if (!zones.length) {
    return {}
  }

  const boundaryBounds = getShapeBounds(boundary)
  const gap = 1.2
  const columns = Math.max(1, Math.ceil(Math.sqrt((zones.length * boundaryBounds.width) / Math.max(boundaryBounds.height, MIN_ZONE_EDGE))))
  const rows = Math.max(1, Math.ceil(zones.length / columns))
  const cellWidth = Math.max(MIN_ZONE_EDGE * 2, (boundaryBounds.width - gap * (columns + 1)) / columns)
  const cellHeight = Math.max(MIN_ZONE_EDGE * 2, (boundaryBounds.height - gap * (rows + 1)) / rows)

  return zones.reduce((layouts, zone, index) => {
    const column = index % columns
    const row = Math.floor(index / columns)
    const area = toNumericArea(zone.zone_size, cellWidth * cellHeight * 0.65)
    const targetWidth = clamp(Math.sqrt(area * 1.3), MIN_ZONE_EDGE * 2, cellWidth)
    const targetHeight = clamp(area / targetWidth, MIN_ZONE_EDGE * 2, cellHeight)
    const cellX = boundaryBounds.left + gap + (column * (cellWidth + gap))
    const cellY = boundaryBounds.top + gap + (row * (cellHeight + gap))
    const fallbackShape = createRectShape({
      x: cellX + Math.max(0, (cellWidth - targetWidth) / 2),
      y: cellY + Math.max(0, (cellHeight - targetHeight) / 2),
      width: targetWidth,
      height: targetHeight,
    })

    layouts[zone.id] = fitShapeInsideBoundary(fallbackShape, boundary)
    return layouts
  }, {})
}

export function sanitizeShape(shape, boundary, fallbackShape) {
  const baseFallback = fallbackShape ?? createRectShape(getShapeBounds(boundary))
  const candidate = sanitizePolygon(getShapePoints(shape, baseFallback), baseFallback, MIN_ZONE_EDGE * MIN_ZONE_EDGE * 0.35)
  return boundary ? fitShapeInsideBoundary(candidate, boundary) : candidate
}

export function sanitizeBoundary(boundary, fallbackBoundary) {
  const fallback = fallbackBoundary ?? estimateBoundaryFromArea()
  return sanitizePolygon(getShapePoints(boundary, fallback), fallback, MIN_BOUNDARY_EDGE * MIN_BOUNDARY_EDGE * 0.55)
}

export function mergeZoneLayouts(zones, boundary, savedLayouts = {}) {
  const fallbackLayouts = buildDefaultZoneLayouts(zones, boundary)

  return zones.reduce((layouts, zone) => {
    const saved = savedLayouts?.[zone.id] ?? savedLayouts?.[String(zone.id)]
    layouts[zone.id] = saved
      ? sanitizeShape(saved, boundary, fallbackLayouts[zone.id])
      : fallbackLayouts[zone.id]

    return layouts
  }, {})
}

export function snapPoint(point, step = GRID_SIZE) {
  if (!step) {
    return normalizePoint(point)
  }

  return normalizePoint({
    x: roundTo(Math.round(point.x / step) * step, 2),
    y: roundTo(Math.round(point.y / step) * step, 2),
  })
}

export function snapShapeToGrid(shape, boundary, step = GRID_SIZE) {
  const snapped = {
    kind: 'polygon',
    points: getShapePoints(shape).map((point) => snapPoint(point, step)),
  }

  if (boundary) {
    return fitShapeInsideBoundary(snapped, boundary)
  }

  return snapped
}

export function createViewportToFit(boundary, layouts, canvasSize, paddingRatio = 0.9) {
  const contentBounds = getCombinedBounds([boundary, ...Object.values(layouts ?? {})])
  const width = Math.max(contentBounds.width, 1)
  const height = Math.max(contentBounds.height, 1)
  const scale = roundTo(
    clamp(
      Math.min((canvasSize.width * paddingRatio) / width, (canvasSize.height * paddingRatio) / height),
      MIN_ZOOM,
      MAX_ZOOM,
    ),
    4,
  )

  return {
    scale,
    x: roundTo((canvasSize.width / 2) - (contentBounds.centerX * scale), 2),
    y: roundTo((canvasSize.height / 2) - (contentBounds.centerY * scale), 2),
  }
}

export function getResizeHandlePoints(shape) {
  const bounds = getShapeBounds(shape)

  return {
    nw: { x: bounds.left, y: bounds.top },
    n: { x: bounds.centerX, y: bounds.top },
    ne: { x: bounds.right, y: bounds.top },
    e: { x: bounds.right, y: bounds.centerY },
    se: { x: bounds.right, y: bounds.bottom },
    s: { x: bounds.centerX, y: bounds.bottom },
    sw: { x: bounds.left, y: bounds.bottom },
    w: { x: bounds.left, y: bounds.centerY },
  }
}

function resizeBoundsByHandle(originBounds, handle, targetPoint, minEdge = MIN_ZONE_EDGE) {
  const nextBounds = { ...originBounds }

  if (handle.includes('w')) {
    nextBounds.left = Math.min(targetPoint.x, originBounds.right - minEdge)
  }

  if (handle.includes('e')) {
    nextBounds.right = Math.max(targetPoint.x, originBounds.left + minEdge)
  }

  if (handle.includes('n')) {
    nextBounds.top = Math.min(targetPoint.y, originBounds.bottom - minEdge)
  }

  if (handle.includes('s')) {
    nextBounds.bottom = Math.max(targetPoint.y, originBounds.top + minEdge)
  }

  return nextBounds
}

function shapeFromBounds(bounds, minEdge = MIN_ZONE_EDGE) {
  const width = Math.max(bounds.right - bounds.left, minEdge)
  const height = Math.max(bounds.bottom - bounds.top, minEdge)

  return {
    kind: 'polygon',
    points: [
      { x: bounds.left, y: bounds.top },
      { x: bounds.left + width, y: bounds.top },
      { x: bounds.left + width, y: bounds.top + height },
      { x: bounds.left, y: bounds.top + height },
    ].map(normalizePoint),
  }
}

export function resizeShapeByHandle(originShape, handle, targetPoint, boundary, minArea = MIN_ZONE_EDGE * MIN_ZONE_EDGE * 0.35, minEdge = MIN_ZONE_EDGE) {
  const originBounds = getShapeBounds(originShape)
  const boundaryBounds = boundary ? getShapeBounds(boundary) : null
  const projectedTarget = boundary
    ? projectPointToPolygon({
      x: clamp(targetPoint.x, boundaryBounds.left, boundaryBounds.right),
      y: clamp(targetPoint.y, boundaryBounds.top, boundaryBounds.bottom),
    }, boundary)
    : normalizePoint(targetPoint)

  const buildCandidate = (ratio) => {
    const interpolated = {
      x: originBounds[handle.includes('w') ? 'left' : handle.includes('e') ? 'right' : 'centerX']
        + ((projectedTarget.x - originBounds[handle.includes('w') ? 'left' : handle.includes('e') ? 'right' : 'centerX']) * ratio),
      y: originBounds[handle.includes('n') ? 'top' : handle.includes('s') ? 'bottom' : 'centerY']
        + ((projectedTarget.y - originBounds[handle.includes('n') ? 'top' : handle.includes('s') ? 'bottom' : 'centerY']) * ratio),
    }
    const candidateBounds = resizeBoundsByHandle(originBounds, handle, interpolated, minEdge)

    return shapeFromBounds(candidateBounds, minEdge)
  }

  const isValidCandidate = (candidate) => (
    isPolygonShapeValid(candidate, minArea)
    && (!boundary || isShapeInsideBoundary(candidate, boundary))
  )

  const fullCandidate = buildCandidate(1)

  if (isValidCandidate(fullCandidate)) {
    return fullCandidate
  }

  let low = 0
  let high = 1
  let bestShape = originShape

  for (let iteration = 0; iteration < 24; iteration += 1) {
    const middle = (low + high) / 2
    const candidate = buildCandidate(middle)

    if (isValidCandidate(candidate)) {
      bestShape = candidate
      low = middle
    } else {
      high = middle
    }
  }

  return bestShape
}

export function translateLayouts(layouts, offset) {
  return Object.fromEntries(
    Object.entries(layouts).map(([zoneId, shape]) => [zoneId, translateShape(shape, offset)]),
  )
}

export function isNormalizedGeometry(geometry) {
  return Array.isArray(geometry?.points)
    && geometry.points.length >= 3
    && isValidNormalizedPolygon(geometry.points)
}

export function createGeometryReference(shape) {
  const bounds = getShapeBounds(shape)

  return {
    originX: bounds.left,
    originY: bounds.top,
    size: Math.max(bounds.width, bounds.height, 1),
  }
}

export function geometryToShape(geometry, reference) {
  if (!isNormalizedGeometry(geometry)) {
    return null
  }

  const size = Math.max(Number(reference?.size) || 0, 0)

  if (!size) {
    return null
  }

  return {
    kind: 'polygon',
    points: geometry.points
      .map((point) => denormalizePoint(point, reference))
      .filter(Boolean)
      .map(normalizePoint),
  }
}

export function shapeToGeometry(shape, referenceShape = shape) {
  const points = getShapePoints(shape)

  if (points.length < 3) {
    return null
  }

  const reference = createGeometryReference(referenceShape)

  if (!reference.size) {
    return null
  }

  const geometry = {
    points: points.map((point) => normalizeReferencePoint(point, reference)).filter(Boolean),
  }
  const sanitized = sanitizeNormalizedGeometry(geometry)

  return sanitized.error ? null : sanitized.geometry
}

export function geometryEquals(firstGeometry, secondGeometry) {
  if (!isNormalizedGeometry(firstGeometry) || !isNormalizedGeometry(secondGeometry)) {
    return false
  }

  if (firstGeometry.points.length !== secondGeometry.points.length) {
    return false
  }

  return firstGeometry.points.every((point, index) => (
    roundTo(point.x, 4) === roundTo(secondGeometry.points[index]?.x, 4)
    && roundTo(point.y, 4) === roundTo(secondGeometry.points[index]?.y, 4)
  ))
}

export function buildBoundaryFromGeometry(plotGeometry, plotSize) {
  if (!isNormalizedGeometry(plotGeometry)) {
    return null
  }

  const normalizedShape = {
    kind: 'polygon',
    points: plotGeometry.points.map(normalizePoint),
  }
  const normalizedArea = calculateArea(normalizedShape)

  if (normalizedArea <= 0) {
    return null
  }

  const targetArea = Math.max(Number(plotSize) || 0, 36)
  const size = Math.sqrt(targetArea / normalizedArea)

  return sanitizeBoundary(geometryToShape(plotGeometry, {
    originX: 0,
    originY: 0,
    size,
  }), estimateBoundaryFromArea(plotSize))
}

export function buildZoneLayoutsFromGeometry(zones, boundary) {
  const reference = createGeometryReference(boundary)

  return zones.reduce((layouts, zone) => {
    const shape = geometryToShape(zone.geometry, reference)

    if (shape) {
      layouts[zone.id] = fitShapeInsideBoundary(shape, boundary)
    }

    return layouts
  }, {})
}

export function buildDesignerStateFromPersistence({
  plotSize,
  plotGeometry,
  zones,
  storedState,
}) {
  const fallbackBoundary = estimateBoundaryFromArea(plotSize)
  const boundaryFromGeometry = buildBoundaryFromGeometry(plotGeometry, plotSize)
  const nextBoundary = boundaryFromGeometry
    ?? sanitizeBoundary(storedState?.boundary, fallbackBoundary)
  const geometryLayouts = buildZoneLayoutsFromGeometry(zones, nextBoundary)
  const savedLayouts = {
    ...(storedState?.layouts ?? {}),
    ...geometryLayouts,
  }

  return {
    boundary: nextBoundary,
    layouts: mergeZoneLayouts(zones, nextBoundary, savedLayouts),
  }
}

export function loadDesignerState(plotId) {
  if (typeof window === 'undefined' || !plotId) {
    return null
  }

  try {
    const raw = window.localStorage.getItem(`${STORAGE_PREFIX}:${plotId}`)
    return raw ? JSON.parse(raw) : null
  } catch {
    return null
  }
}

export function saveDesignerState(plotId, state) {
  if (typeof window === 'undefined' || !plotId) {
    return
  }

  window.localStorage.setItem(`${STORAGE_PREFIX}:${plotId}`, JSON.stringify(state))
}

export function clearDesignerState(plotId) {
  if (typeof window === 'undefined' || !plotId) {
    return
  }

  window.localStorage.removeItem(`${STORAGE_PREFIX}:${plotId}`)
}
