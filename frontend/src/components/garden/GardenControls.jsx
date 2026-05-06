import Badge from '../ui/Badge.jsx'

export function MeasurementBadge({ label, value, unit, tone = 'earth', className = '' }) {
  return (
    <span className={`measurement-badge measurement-badge-${tone} ${className}`.trim()}>
      <span className="measurement-badge-label">{label}</span>
      <strong className="measurement-badge-value">
        {value}
        {unit ? <span>{unit}</span> : null}
      </strong>
    </span>
  )
}

export function MapLayerControl({ title = 'Map layers', items = [], className = '' }) {
  return (
    <div className={`map-layer-control ${className}`.trim()}>
      <span className="map-layer-title">{title}</span>
      <div className="map-layer-list">
        {items.map((item) => (
          <span
            key={item.id ?? item.label}
            className={`map-layer-item ${item.active ? 'is-active' : ''}`.trim()}
          >
            <span className="map-layer-swatch" style={item.color ? { background: item.color } : undefined} />
            <span>{item.label}</span>
          </span>
        ))}
      </div>
    </div>
  )
}

export function PlotScaleControl({ zoom, snapEnabled, dimensionsVisible }) {
  return (
    <div className="plot-scale-control" aria-label="Plot scale controls">
      <div className="plot-scale-ruler" aria-hidden="true">
        <span />
        <span />
        <span />
      </div>
      <div className="plot-scale-copy">
        <strong>{zoom}</strong>
        <span>
          {snapEnabled ? 'Grid snap on' : 'Free placement'} - {dimensionsVisible ? 'measurements visible' : 'measurements hidden'}
        </span>
      </div>
    </div>
  )
}

export function ZoneInspector({
  zone,
  measurements,
  plantCount = 0,
  emptyTitle = 'No zone selected',
  emptyDescription = 'Select a zone on the plot plan to inspect soil, plants, and dimensions.',
}) {
  if (!zone) {
    return (
      <div className="zone-inspector zone-inspector-empty">
        <span className="zone-inspector-kicker">Zone inspector</span>
        <strong>{emptyTitle}</strong>
        <p>{emptyDescription}</p>
      </div>
    )
  }

  return (
    <div className="zone-inspector">
      <div className="zone-inspector-head">
        <span className="zone-inspector-kicker">Zone inspector</span>
        <Badge tone="soft">{zone.soil_type}</Badge>
      </div>
      <strong className="zone-inspector-title">{zone.name}</strong>
      <div className="zone-inspector-grid">
        <MeasurementBadge label="Area" value={measurements?.area ?? '0'} tone="field" />
        <MeasurementBadge label="Perimeter" value={measurements?.perimeter ?? '0'} tone="earth" />
        <MeasurementBadge label="Plants" value={plantCount} tone="leaf" />
        <MeasurementBadge label="Rotation" value={zone.rotation_stage ?? 0} tone="amber" />
      </div>
      <div className="zone-inspector-note">
        <span>Side lengths</span>
        <strong>{measurements?.sideSummary || 'No geometry'}</strong>
      </div>
    </div>
  )
}

export function GardenTimeline({ items = [], emptyText = 'No planning events yet.' }) {
  return (
    <div className="garden-timeline">
      {items.length > 0 ? items.map((item) => (
        <div key={item.id ?? `${item.label}-${item.meta}`} className="garden-timeline-item">
          <span className={`garden-timeline-dot garden-timeline-dot-${item.tone ?? 'earth'}`} />
          <div className="garden-timeline-copy">
            <strong>{item.label}</strong>
            {item.meta ? <span>{item.meta}</span> : null}
          </div>
        </div>
      )) : (
        <p className="garden-timeline-empty">{emptyText}</p>
      )}
    </div>
  )
}

export function PlantStatusBadge({ status, careLinked, className = '' }) {
  const normalized = String(status ?? '').toLowerCase()
  const tone = normalized.includes('disease') || normalized.includes('dried')
    ? 'danger'
    : normalized.includes('flower') || normalized.includes('mature')
      ? 'success'
      : careLinked === false
        ? 'warning'
        : 'soft'

  return (
    <Badge tone={tone} className={`plant-status-badge ${className}`.trim()}>
      {status || (careLinked === false ? 'Care missing' : 'Planned')}
    </Badge>
  )
}

export function TaskPriorityBadge({ priority, className = '' }) {
  const normalized = String(priority ?? 'medium').toLowerCase()
  const tone = normalized === 'high'
    ? 'danger'
    : normalized === 'medium'
      ? 'warning'
      : 'neutral'

  return (
    <Badge tone={tone} className={`task-priority-badge task-priority-${normalized} ${className}`.trim()}>
      {normalized}
    </Badge>
  )
}
