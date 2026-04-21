import { memo, useMemo } from 'react'
import {
  buildPlotRenderModel,
  createPreviewViewport,
  getProjectedLabelConfig,
  getZoneColor,
  shapePointsToSvg,
} from '../../lib/plotRender.js'

export default memo(function PlanPreview({
  plotName,
  plotSize,
  plotGeometry,
  zones = [],
  className = '',
}) {
  const preview = useMemo(() => {
    const renderModel = buildPlotRenderModel({
      plotGeometry,
      plotSize,
      zones,
    })
    const viewport = createPreviewViewport(renderModel.boundary, renderModel.layouts)
    const previewZones = zones
      .map((zone, index) => {
        const shape = renderModel.layouts[String(zone.id)] ?? renderModel.layouts[zone.id]

        if (!shape) {
          return null
        }

        return {
          id: zone.id ?? index,
          name: zone.name ?? 'Zone',
          points: shapePointsToSvg(shape, viewport),
          label: getProjectedLabelConfig(zone.name, shape, viewport),
          color: getZoneColor(index),
        }
      })
      .filter(Boolean)

    return {
      plot: shapePointsToSvg(renderModel.boundary, viewport),
      zones: previewZones,
      source: renderModel.source,
    }
  }, [plotGeometry, plotSize, zones])

  const clipPathId = useMemo(
    () => `plan-preview-${String(plotName ?? 'plot').replace(/[^a-z0-9]+/gi, '-').toLowerCase()}-${zones.length}`,
    [plotName, zones.length],
  )

  return (
    <figure className={`plan-preview-card ${className}`.trim()} data-plan-source={preview.source}>
      <svg className="plan-preview-svg" viewBox="0 0 100 100" aria-label={`${plotName || 'Plot'} visual preview`}>
        <defs>
          <clipPath id={clipPathId}>
            <polygon points={preview.plot} />
          </clipPath>
        </defs>

        <polygon className="plan-preview-outline" points={preview.plot} />

        <g clipPath={`url(#${clipPathId})`}>
          {preview.zones.map((zone) => (
            <g key={zone.id}>
              <polygon
                className="plan-preview-zone"
                points={zone.points}
                fill={zone.color.fill}
                stroke={zone.color.stroke}
              />
              {zone.label ? (
                <>
                  <rect
                    className={`plan-preview-label-shell plan-preview-label-shell--${zone.label.variant}`}
                    x={zone.label.x}
                    y={zone.label.y}
                    width={zone.label.width}
                    height={zone.label.height}
                    rx={zone.label.cornerRadius}
                    ry={zone.label.cornerRadius}
                  />
                  <text
                    className={`plan-preview-label plan-preview-label--${zone.label.variant}`}
                    x={zone.label.x + (zone.label.width / 2)}
                    y={zone.label.y + (zone.label.height / 2)}
                  >
                    {zone.label.text}
                  </text>
                </>
              ) : null}
            </g>
          ))}
        </g>
      </svg>
      <figcaption className="plan-preview-caption">
        {preview.source === 'geometry' ? 'Synced plot geometry preview' : 'Fallback plot preview'}
      </figcaption>
    </figure>
  )
})
