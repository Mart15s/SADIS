import { getZoneLabelConfig } from './plotCanvasLabels.js'
import {
  buildBoundaryFromGeometry,
  buildZoneLayoutsFromGeometry,
  createViewportToFit,
  estimateBoundaryFromArea,
  getShapeBounds,
  getShapePoints,
  isNormalizedGeometry,
  mergeZoneLayouts,
} from './plotDesigner.js'

export const ZONE_COLORS = [
  { fill: '#9cb98c', stroke: '#47633b' },
  { fill: '#cfb46c', stroke: '#9b6b22' },
  { fill: '#c98c7c', stroke: '#9a4c39' },
  { fill: '#8ab4aa', stroke: '#2f6f68' },
  { fill: '#ad96c8', stroke: '#64488f' },
  { fill: '#b7a18c', stroke: '#71533a' },
]

const DEFAULT_PLOT_GEOMETRY = {
  kind: 'polygon',
  points: [
    { x: 0.06, y: 0.08 },
    { x: 0.94, y: 0.08 },
    { x: 0.94, y: 0.92 },
    { x: 0.06, y: 0.92 },
  ],
}

function round(value, digits = 2) {
  const factor = 10 ** digits
  return Math.round(value * factor) / factor
}

function normalizePoint(point) {
  return {
    x: round(Number(point?.x) || 0, 4),
    y: round(Number(point?.y) || 0, 4),
  }
}

function fallbackBoundaryShape(plotGeometry, plotSize) {
  if (isNormalizedGeometry(plotGeometry)) {
    if (plotSize) {
      const boundaryFromGeometry = buildBoundaryFromGeometry(plotGeometry, plotSize)

      if (boundaryFromGeometry) {
        return boundaryFromGeometry
      }
    }

    return {
      kind: 'polygon',
      points: plotGeometry.points.map(normalizePoint),
    }
  }

  return estimateBoundaryFromArea(plotSize)
}

export function getZoneColor(index) {
  return ZONE_COLORS[index % ZONE_COLORS.length]
}

export function buildPlotRenderModel({
  plotGeometry,
  plotSize,
  zones = [],
}) {
  const boundary = fallbackBoundaryShape(plotGeometry, plotSize) ?? DEFAULT_PLOT_GEOMETRY
  const geometryLayouts = buildZoneLayoutsFromGeometry(zones, boundary)

  return {
    boundary,
    layouts: mergeZoneLayouts(zones, boundary, geometryLayouts),
    source: isNormalizedGeometry(plotGeometry) ? 'geometry' : 'fallback',
  }
}

export function projectPoint(point, viewport) {
  return {
    x: round((point.x * viewport.scale) + viewport.x, 2),
    y: round((point.y * viewport.scale) + viewport.y, 2),
  }
}

export function projectShape(shape, viewport) {
  return getShapePoints(shape).map((point) => projectPoint(point, viewport))
}

export function shapePointsToSvg(shape, viewport) {
  return projectShape(shape, viewport)
    .map((point) => `${point.x},${point.y}`)
    .join(' ')
}

export function createPreviewViewport(boundary, layouts, {
  width = 100,
  height = 100,
  paddingRatio = 0.82,
} = {}) {
  return createViewportToFit(boundary, layouts, { width, height }, paddingRatio)
}

export function getProjectedLabelConfig(zoneName, shape, viewport) {
  const projectedBounds = getShapeBounds({
    kind: 'polygon',
    points: projectShape(shape, viewport),
  })

  return getZoneLabelConfig(zoneName, projectedBounds, 1, {
    allowCompact: true,
    compactCenter: true,
  })
}

