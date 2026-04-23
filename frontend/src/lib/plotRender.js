import { getZoneLabelLayout } from './plotCanvasLabels.js'
import {
  buildBoundaryFromGeometry,
  buildZoneLayoutsFromGeometry,
  estimateBoundaryFromArea,
  getCombinedBounds,
  getShapePoints,
  isNormalizedGeometry,
  mergeZoneLayouts,
  roundTo,
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

export const STANDARD_PREVIEW_VIEWBOX = {
  width: 160,
  height: 120,
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
  width = STANDARD_PREVIEW_VIEWBOX.width,
  height = STANDARD_PREVIEW_VIEWBOX.height,
  padding = 12,
  occupancy = 0.84,
} = {}) {
  const contentBounds = getCombinedBounds([boundary, ...Object.values(layouts ?? {})])
  const widthScale = ((Math.max(width - (padding * 2), 1)) * occupancy) / Math.max(contentBounds.width, 1)
  const heightScale = ((Math.max(height - (padding * 2), 1)) * occupancy) / Math.max(contentBounds.height, 1)
  const scale = roundTo(Math.min(widthScale, heightScale), 4)

  return {
    scale,
    x: roundTo((width / 2) - (contentBounds.centerX * scale), 2),
    y: roundTo((height / 2) - (contentBounds.centerY * scale), 2),
  }
}

export function getProjectedLabelConfig(zoneName, shape, viewport, {
  context = 'preview',
  isSelected = false,
  markerText = '',
  viewportBounds = null,
} = {}) {
  return getZoneLabelLayout({
    zoneName,
    screenPoints: projectShape(shape, viewport),
    context,
    isSelected,
    markerText,
    viewportBounds,
  })
}

export function buildPreviewModel({
  plotGeometry,
  plotSize,
  zones = [],
  viewBox = STANDARD_PREVIEW_VIEWBOX,
} = {}) {
  const renderModel = buildPlotRenderModel({
    plotGeometry,
    plotSize,
    zones,
  })
  const viewport = createPreviewViewport(renderModel.boundary, renderModel.layouts, viewBox)
  const previewZones = zones
    .map((zone, index) => {
      const shape = renderModel.layouts[String(zone.id)] ?? renderModel.layouts[zone.id]

      if (!shape) {
        return null
      }

      const color = getZoneColor(index)

      return {
        id: zone.id ?? index,
        index: index + 1,
        name: zone.name ?? 'Zone',
        color,
        points: shapePointsToSvg(shape, viewport),
        label: getProjectedLabelConfig(zone.name, shape, viewport, {
          context: 'preview',
          markerText: index + 1,
        }),
      }
    })
    .filter(Boolean)

  return {
    viewBox,
    plot: shapePointsToSvg(renderModel.boundary, viewport),
    zones: previewZones,
    legend: previewZones.map((zone) => ({
      id: zone.id,
      index: zone.index,
      name: zone.name,
      color: zone.color,
      usesFallback: zone.label?.mode === 'marker' || !zone.label,
    })),
    source: renderModel.source,
  }
}
