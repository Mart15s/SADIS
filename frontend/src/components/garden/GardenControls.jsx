import Badge from '../ui/Badge.jsx'
import { formatPlantCondition, formatPriority, formatSoilType } from '../../lib/constants.js'

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

export function MapLayerControl({ title = 'Žemėlapio sluoksniai', items = [], className = '' }) {
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
    <div className="plot-scale-control" aria-label="Sklypo mastelio valdikliai">
      <div className="plot-scale-ruler" aria-hidden="true">
        <span />
        <span />
        <span />
      </div>
      <div className="plot-scale-copy">
        <strong>{zoom}</strong>
        <span>
          {snapEnabled ? 'Lygiavimas prie tinklelio įjungtas' : 'Laisvas išdėstymas'} - {dimensionsVisible ? 'matmenys rodomi' : 'matmenys paslėpti'}
        </span>
      </div>
    </div>
  )
}

export function ZoneInspector({
  zone,
  measurements,
  plantCount = 0,
  emptyTitle = 'Zona nepasirinkta',
  emptyDescription = 'Pasirinkite zoną sklypo plane, kad matytumėte dirvožemį, augalus ir matmenis.',
}) {
  if (!zone) {
    return (
      <div className="zone-inspector zone-inspector-empty">
        <span className="zone-inspector-kicker">Zonos informacija</span>
        <strong>{emptyTitle}</strong>
        <p>{emptyDescription}</p>
      </div>
    )
  }

  return (
    <div className="zone-inspector">
      <div className="zone-inspector-head">
        <span className="zone-inspector-kicker">Zonos informacija</span>
        <Badge tone="soft">{formatSoilType(zone.soil_type)}</Badge>
      </div>
      <strong className="zone-inspector-title">{zone.name}</strong>
      <div className="zone-inspector-grid">
        <MeasurementBadge label="Plotas" value={measurements?.area ?? '0'} tone="field" />
        <MeasurementBadge label="Perimetras" value={measurements?.perimeter ?? '0'} tone="earth" />
        <MeasurementBadge label="Augalai" value={plantCount} tone="leaf" />
        <MeasurementBadge label="Rotacija" value={zone.rotation_stage ?? 0} tone="amber" />
      </div>
      <div className="zone-inspector-note">
        <span>Kraštinių ilgiai</span>
        <strong>{measurements?.sideSummary || 'Geometrijos nėra'}</strong>
      </div>
    </div>
  )
}

export function GardenTimeline({ items = [], emptyText = 'Planavimo įvykių dar nėra.' }) {
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
      {status ? formatPlantCondition(status) : (careLinked === false ? 'Priežiūros profilis nesusietas' : 'Suplanuota')}
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
      {formatPriority(normalized)}
    </Badge>
  )
}
