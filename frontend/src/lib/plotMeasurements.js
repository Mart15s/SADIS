import { getShapePoints, roundTo } from './plotDesigner.js'

const DIMENSION_LABEL_MIN_SCREEN_LENGTH = 54
const DIMENSION_LABEL_HEIGHT = 24

function projectPoint(point, viewport) {
  return {
    x: roundTo((point.x * viewport.scale) + viewport.x, 2),
    y: roundTo((point.y * viewport.scale) + viewport.y, 2),
  }
}

function boxesOverlap(first, second, padding = 5) {
  return !(
    first.right + padding < second.left
    || second.right + padding < first.left
    || first.bottom + padding < second.top
    || second.bottom + padding < first.top
  )
}

function readableAngle(angle) {
  if (angle > 90 || angle < -90) {
    return angle + 180
  }

  return angle
}

function formatNumber(value, digits = 1) {
  return roundTo(value, digits).toLocaleString('lt-LT', {
    minimumFractionDigits: value < 10 ? 1 : 0,
    maximumFractionDigits: digits,
  })
}

export function formatMeters(value, digits = 1) {
  return `${formatNumber(value, digits)} m`
}

export function formatSquareMeters(value, digits = 1) {
  return `${formatNumber(value, digits)} m²`
}

export function getShapeEdges(shape) {
  const points = getShapePoints(shape)

  if (points.length < 2) {
    return []
  }

  return points.map((start, index) => {
    const end = points[(index + 1) % points.length]
    const dx = end.x - start.x
    const dy = end.y - start.y
    const length = Math.hypot(dx, dy)

    return {
      index,
      start,
      end,
      length: roundTo(length, 2),
      midpoint: {
        x: roundTo(start.x + (dx / 2), 2),
        y: roundTo(start.y + (dy / 2), 2),
      },
      angle: Math.atan2(dy, dx) * (180 / Math.PI),
    }
  })
}

export function buildShapeMetrics(shape) {
  const edges = getShapeEdges(shape)
  const perimeter = edges.reduce((sum, edge) => sum + edge.length, 0)

  return {
    edges,
    perimeter: roundTo(perimeter, 2),
    sideSummary: edges.map((edge) => `K${edge.index + 1}: ${formatMeters(edge.length)}`).join(', '),
  }
}

export function createDimensionLabels({
  shape,
  viewport,
  viewportBounds,
  idPrefix,
  occupiedBoxes = [],
  minScreenLength = DIMENSION_LABEL_MIN_SCREEN_LENGTH,
}) {
  const labels = []
  const usedBoxes = occupiedBoxes

  for (const edge of getShapeEdges(shape)) {
    const start = projectPoint(edge.start, viewport)
    const end = projectPoint(edge.end, viewport)
    const midpoint = projectPoint(edge.midpoint, viewport)
    const screenLength = Math.hypot(end.x - start.x, end.y - start.y)
    const text = formatMeters(edge.length)
    const width = Math.max(48, Math.min(92, (text.length * 7) + 16))

    if (screenLength < Math.max(minScreenLength, width * 0.72)) {
      continue
    }

    const box = {
      left: midpoint.x - (width / 2),
      right: midpoint.x + (width / 2),
      top: midpoint.y - (DIMENSION_LABEL_HEIGHT / 2),
      bottom: midpoint.y + (DIMENSION_LABEL_HEIGHT / 2),
    }

    if (
      box.left < 6
      || box.top < 6
      || box.right > viewportBounds.width - 6
      || box.bottom > viewportBounds.height - 6
      || usedBoxes.some((usedBox) => boxesOverlap(box, usedBox))
    ) {
      continue
    }

    usedBoxes.push(box)
    labels.push({
      id: `${idPrefix}-${edge.index}`,
      text,
      title: `Kraštinė ${edge.index + 1}: ${text}`,
      x: midpoint.x,
      y: midpoint.y,
      width,
      height: DIMENSION_LABEL_HEIGHT,
      angle: readableAngle(edge.angle),
    })
  }

  return labels
}
