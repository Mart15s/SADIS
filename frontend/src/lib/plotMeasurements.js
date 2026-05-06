import { getShapePoints, roundTo } from './plotDesigner.js'

const DIMENSION_LABEL_MIN_SCREEN_LENGTH = 54
const DIMENSION_LABEL_HEIGHT = 24
const DIMENSION_LABEL_VIEWPORT_MARGIN = 8

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

function clamp(value, min, max) {
  return Math.min(Math.max(value, min), max)
}

function readableAngle(angle) {
  if (angle > 90 || angle < -90) {
    return angle + 180
  }

  return angle
}

function formatNumber(value, digits = 1) {
  return roundTo(value, digits).toLocaleString('en-US', {
    minimumFractionDigits: value < 10 ? 1 : 0,
    maximumFractionDigits: digits,
  })
}

export function formatMeters(value, digits = 1) {
  return `${formatNumber(value, digits)} m`
}

export function formatSquareMeters(value, digits = 1) {
  return `${formatNumber(value, digits)} sq m`
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
    sideSummary: edges.map((edge) => `E${edge.index + 1}: ${formatMeters(edge.length)}`).join(', '),
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
    const text = formatMeters(edge.length)
    const width = Math.max(48, Math.min(92, (text.length * 7) + 16))
    const dx = end.x - start.x
    const dy = end.y - start.y
    const screenLength = Math.hypot(dx, dy)
    const normal = screenLength > 0
      ? { x: -dy / screenLength, y: dx / screenLength }
      : { x: 0, y: -1 }
    const baseOffset = screenLength < Math.max(minScreenLength, width * 0.72) ? 12 : 0

    const offsetSteps = [baseOffset, baseOffset + 12, -(baseOffset + 12), baseOffset + 24, -(baseOffset + 24), baseOffset + 36]
    let selectedPlacement = null

    for (const offset of offsetSteps) {
      const x = clamp(
        midpoint.x + (normal.x * offset),
        DIMENSION_LABEL_VIEWPORT_MARGIN + (width / 2),
        Math.max(DIMENSION_LABEL_VIEWPORT_MARGIN + (width / 2), viewportBounds.width - DIMENSION_LABEL_VIEWPORT_MARGIN - (width / 2)),
      )
      const y = clamp(
        midpoint.y + (normal.y * offset),
        DIMENSION_LABEL_VIEWPORT_MARGIN + (DIMENSION_LABEL_HEIGHT / 2),
        Math.max(DIMENSION_LABEL_VIEWPORT_MARGIN + (DIMENSION_LABEL_HEIGHT / 2), viewportBounds.height - DIMENSION_LABEL_VIEWPORT_MARGIN - (DIMENSION_LABEL_HEIGHT / 2)),
      )
      const box = {
        left: x - (width / 2),
        right: x + (width / 2),
        top: y - (DIMENSION_LABEL_HEIGHT / 2),
        bottom: y + (DIMENSION_LABEL_HEIGHT / 2),
      }

      selectedPlacement = { x, y, box }

      if (!usedBoxes.some((usedBox) => boxesOverlap(box, usedBox))) {
        break
      }
    }

    usedBoxes.push(selectedPlacement.box)
    labels.push({
      id: `${idPrefix}-${edge.index}`,
      text,
      title: `Edge ${edge.index + 1}: ${text}`,
      x: roundTo(selectedPlacement.x, 2),
      y: roundTo(selectedPlacement.y, 2),
      width,
      height: DIMENSION_LABEL_HEIGHT,
      angle: readableAngle(edge.angle),
    })
  }

  return labels
}
