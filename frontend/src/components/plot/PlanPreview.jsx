import { memo, useMemo } from 'react'
import {
  STANDARD_PREVIEW_VIEWBOX,
  buildPreviewModel,
} from '../../lib/plotRender.js'

export default memo(function PlanPreview({
  plotName,
  plotSize,
  plotGeometry,
  zones = [],
  className = '',
}) {
  const preview = useMemo(() => {
    return buildPreviewModel({
      plotGeometry,
      plotSize,
      zones,
      viewBox: STANDARD_PREVIEW_VIEWBOX,
    })
  }, [plotGeometry, plotSize, zones])

  const clipPathId = useMemo(
    () => `plan-preview-${String(plotName ?? 'plot').replace(/[^a-z0-9]+/gi, '-').toLowerCase()}-${zones.length}`,
    [plotName, zones.length],
  )

  return (
    <figure className={`plan-preview-card ${className}`.trim()} data-plan-source={preview.source}>
      <svg
        className="plan-preview-svg"
        viewBox={`0 0 ${preview.viewBox.width} ${preview.viewBox.height}`}
        aria-label={`${plotName || 'Plot'} visual preview`}
      >
        <defs>
          <clipPath id={clipPathId}>
            <polygon points={preview.plot} />
          </clipPath>
        </defs>

        <rect className="plan-preview-frame" x="2.5" y="2.5" width={preview.viewBox.width - 5} height={preview.viewBox.height - 5} rx="18" ry="18" />
        <rect className="plan-preview-surface" x="8" y="8" width={preview.viewBox.width - 16} height={preview.viewBox.height - 16} rx="14" ry="14" />
        <polygon className="plan-preview-outline" points={preview.plot} />

        <g clipPath={`url(#${clipPathId})`}>
          {preview.zones.map((zone) => (
            <g key={zone.id}>
              <title>{zone.name}</title>
              <polygon
                className="plan-preview-zone"
                points={zone.points}
                fill={zone.color.fill}
                stroke={zone.color.stroke}
              />
              {zone.label ? (
                <>
                  <rect
                    className={`plan-preview-label-shell plan-preview-label-shell--${zone.label.mode}`}
                    x={zone.label.x}
                    y={zone.label.y}
                    width={zone.label.width}
                    height={zone.label.height}
                    rx={zone.label.cornerRadius}
                    ry={zone.label.cornerRadius}
                  />
                  <text
                    className={`plan-preview-label plan-preview-label--${zone.label.mode}`}
                    x={zone.label.x + (zone.label.width / 2)}
                    y={zone.label.y + (zone.label.height / 2)}
                    fontSize={zone.label.fontSize}
                  >
                    {zone.label.text}
                  </text>
                </>
              ) : null}
            </g>
          ))}
        </g>
      </svg>
      {preview.zones.length > 0 ? (
        <div className="plan-preview-legend" aria-label="Zone legend">
          {preview.legend.map((zone) => (
            <span
              key={`legend-${zone.id}`}
              className={`plan-preview-legend-item ${zone.usesFallback ? 'is-fallback' : ''}`.trim()}
            >
              <span className="plan-preview-legend-index">{zone.index}</span>
              <span
                className="plan-preview-legend-swatch"
                style={{
                  background: zone.color.fill,
                  borderColor: zone.color.stroke,
                }}
              />
              <span className="plan-preview-legend-text">{zone.name}</span>
            </span>
          ))}
        </div>
      ) : null}
      <figcaption className="plan-preview-caption">
        {preview.source === 'geometry' ? 'Synced plot geometry preview' : 'Fallback plot preview'}
      </figcaption>
    </figure>
  )
})
