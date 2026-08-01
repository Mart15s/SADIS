import { memo, useMemo, useState } from 'react'
import { Link } from 'react-router-dom'
import { getShapeBounds, pointInPolygon } from '../../lib/plotDesigner.js'
import { projectPoint } from '../../lib/plotRender.js'
import { getPlantStatusSemantic, getZoomTier } from '../../lib/plotPlan.js'
import { markerPosition } from '../../lib/plantVisual.js'
import { plotPlanText } from '../../lib/plotPlanLt.js'
import Button from '../ui/Button.jsx'
import PlantVisualIcon from './PlantVisualIcon.jsx'

function sameId(left, right) {
  return String(left ?? '') === String(right ?? '')
}

function shortName(name, length = 11) {
  const value = String(name ?? 'Augalas')
  return value.length > length ? `${value.slice(0, length - 1)}…` : value
}

function pointFromZonePosition(position, shape) {
  if (!position) return null
  const bounds = getShapeBounds(shape)
  const point = { x: bounds.left + (position.x * bounds.width), y: bounds.top + (position.y * bounds.height) }
  return pointInPolygon(point, shape) ? point : null
}

function automaticPoint(shape, index, count) {
  const bounds = getShapeBounds(shape)
  const candidates = [
    { x: 0.5, y: 0.62 }, { x: 0.5, y: 0.78 }, { x: 0.27, y: 0.68 }, { x: 0.73, y: 0.68 },
    { x: 0.35, y: 0.42 }, { x: 0.65, y: 0.42 }, { x: 0.5, y: 0.5 },
  ]
  const candidate = candidates[index % Math.max(count, 1)] ?? candidates[0]
  const point = { x: bounds.left + (candidate.x * bounds.width), y: bounds.top + (candidate.y * bounds.height) }
  return pointInPolygon(point, shape) ? point : { x: bounds.centerX, y: bounds.centerY }
}

export function markerModel(zones, plants, layouts, viewport, mobile) {
  const tier = getZoomTier(viewport.scale, mobile)

  return zones.flatMap((zone) => {
    const shape = layouts[String(zone.id)] ?? layouts[zone.id]
    if (!shape) return []
    const zonePlants = plants.filter((plant) => sameId(plant.fk_plant_zone_id ?? plant.plant_zone_id, zone.id))
    if (!zonePlants.length) return []

    const bounds = getShapeBounds(shape)
    const center = projectPoint({ x: bounds.centerX, y: bounds.centerY }, viewport)
    const screenWidth = bounds.width * viewport.scale
    const screenHeight = bounds.height * viewport.scale
    const markerCount = Math.min(3, zonePlants.length) + (zonePlants.length > 3 ? 1 : 0)
    const requiredWidth = ((markerCount - 1) * (tier === 'close' ? 98 : 76)) + 80

    if (tier === 'distant' || screenWidth < Math.max(112, requiredWidth) || screenHeight < 72) {
      return [{ id: `aggregate-${zone.id}`, type: 'aggregate', zone, count: zonePlants.length, x: center.x, y: center.y + 17, tier }]
    }

    const visible = zonePlants.slice(0, 3)
    const markers = visible.map((plant, index) => {
      const worldPoint = pointFromZonePosition(markerPosition(plant), shape) ?? automaticPoint(shape, index, visible.length)
      const point = projectPoint(worldPoint, viewport)
      return { id: plant.id, type: 'plant', plant, zone, shape, tier, x: point.x, y: point.y, worldPoint }
    })
    if (zonePlants.length > visible.length) markers.push({
      id: `overflow-${zone.id}`, type: 'overflow', count: zonePlants.length - visible.length, plants: zonePlants, zone, tier,
      x: center.x, y: center.y + 20,
    })
    return markers
  })
}

function PlantInformationCard({ marker, plotId, canEdit, isMobile, onClose, onReset, onReposition }) {
  const plant = marker.plant
  const status = getPlantStatusSemantic(plant)
  const task = plant.nearest_recommended_task
  return (
    <section className="plot-plant-info-card" role="dialog" aria-label={plotPlanText('plantInformation')}>
      <button type="button" className="plot-plan-close" onClick={onClose} aria-label="Uždaryti augalo informaciją">×</button>
      <div className="plot-plant-info-heading">
        <PlantVisualIcon plant={plant} className="plot-plant-info-icon" />
        <div><strong>{plant.name}</strong>{plant.variety ? <small>{plant.variety}</small> : null}</div>
      </div>
      <dl className="plot-plant-info-grid">
        <div><dt>Būklė</dt><dd>{status.label}</dd></div>
        {plant.plant_date ? <div><dt>Pasodinta</dt><dd>{plant.plant_date}</dd></div> : null}
        {plant.quantity !== null && plant.quantity !== undefined ? <div><dt>Kiekis</dt><dd>{plant.quantity}</dd></div> : null}
        {plant.occupied_area ? <div><dt>Plotas</dt><dd>{plant.occupied_area} m²</dd></div> : null}
        <div><dt>Artimiausias darbas</dt><dd>{task ? `${task.name} · ${task.date}` : plotPlanText('noRecommendedTask')}</dd></div>
      </dl>
      <div className="plot-plant-info-actions">
        <Link to={`/plots/${plotId}/plants/${plant.id}`}><Button size="sm" variant="secondary">{plotPlanText('viewPlantInformation')}</Button></Link>
        {canEdit ? <Button size="sm" variant="ghost" onClick={() => onReposition(plant.id)}>{isMobile ? 'Perkelti augalų žymas' : 'Perkelti žymą'}</Button> : null}
        {canEdit && markerPosition(plant) ? <Button size="sm" variant="ghost" onClick={() => onReset(plant.id)}>Atkurti automatinę poziciją</Button> : null}
        {canEdit ? <Link to={`/plants/${plant.id}/edit`}><Button size="sm" variant="ghost">{plotPlanText('editPlanting')}</Button></Link> : null}
      </div>
    </section>
  )
}

function PlotPlanOverlay({ zones, plants, layouts, viewport, plotId, canEdit, mobile = false, onSelectZone, onMarkerPositionChange, onMarkerPositionReset }) {
  const [selectedMarker, setSelectedMarker] = useState(null)
  const [expandedZoneId, setExpandedZoneId] = useState(null)
  const [dragging, setDragging] = useState(null)
  const [repositioningId, setRepositioningId] = useState(null)
  const markers = useMemo(() => markerModel(zones, plants, layouts, viewport, mobile), [layouts, mobile, plants, viewport, zones])

  function positionFromPointer(event, marker) {
    const bounds = event.currentTarget.parentElement.getBoundingClientRect()
    const world = {
      x: ((event.clientX - bounds.left) - viewport.x) / viewport.scale,
      y: ((event.clientY - bounds.top) - viewport.y) / viewport.scale,
    }
    if (!pointInPolygon(world, marker.shape)) return null
    const zoneBounds = getShapeBounds(marker.shape)
    return {
      x: Math.min(1, Math.max(0, (world.x - zoneBounds.left) / zoneBounds.width)),
      y: Math.min(1, Math.max(0, (world.y - zoneBounds.top) / zoneBounds.height)),
    }
  }

  function beginDrag(event, marker) {
    if (!canEdit || (mobile && repositioningId !== marker.plant.id)) return
    event.preventDefault()
    event.stopPropagation()
    event.currentTarget.setPointerCapture?.(event.pointerId)
    setDragging({ marker, moved: false })
  }

  function moveMarker(event, marker) {
    if (!dragging || !sameId(dragging.marker.plant.id, marker.plant.id)) return
    const position = positionFromPointer(event, marker)
    if (!position) return
    const zoneBounds = getShapeBounds(marker.shape)
    const point = projectPoint({
      x: zoneBounds.left + (position.x * zoneBounds.width),
      y: zoneBounds.top + (position.y * zoneBounds.height),
    }, viewport)
    setDragging({ marker: { ...marker, ...point, plant: { ...marker.plant, marker_position_x: position.x, marker_position_y: position.y } }, moved: true })
  }

  function endDrag(event, marker) {
    if (!dragging || !sameId(dragging.marker.plant.id, marker.plant.id)) return
    const position = positionFromPointer(event, marker)
    if (position && dragging.moved) onMarkerPositionChange?.(marker.plant.id, position)
    setDragging(null)
    if (mobile) setRepositioningId(null)
  }

  return (
    <div className={`designer-plan-overlay ${repositioningId ? 'is-marker-repositioning' : ''}`}>
      {repositioningId ? <div className="plot-marker-reposition-notice" role="status">Perkelkite pasirinktą žymą zonos viduje.</div> : null}
      {markers.map((marker) => {
        if (marker.type === 'aggregate') return <button key={marker.id} type="button" className="plot-plant-marker plot-plant-marker--aggregate" style={{ left: marker.x, top: marker.y }} onClick={() => { onSelectZone(marker.zone); setExpandedZoneId(marker.zone.id) }} aria-label={plotPlanText('activePlantings', { count: marker.count })}><PlantVisualIcon plant={{ type: 'leafy', name: 'Augalai' }} className="plot-plant-icon" /> {marker.count}</button>
        if (marker.type === 'overflow') return <button key={marker.id} type="button" className="plot-plant-marker plot-plant-marker--overflow" style={{ left: marker.x, top: marker.y }} onClick={() => { onSelectZone(marker.zone); setExpandedZoneId(marker.zone.id) }} aria-label={`Rodyti visus ${marker.zone.name} augalus`}>{plotPlanText('morePlantings', { count: marker.count })}</button>
        const displayMarker = dragging && sameId(dragging.marker.plant.id, marker.plant.id) ? dragging.marker : marker
        const status = getPlantStatusSemantic(displayMarker.plant)
        const draggable = canEdit && (!mobile || repositioningId === marker.plant.id)
        return <button key={`${marker.zone.id}-${marker.id}`} type="button" className={`plot-plant-marker plot-plant-marker--${marker.tier} ${draggable ? 'is-draggable' : ''}`} style={{ left: displayMarker.x, top: displayMarker.y }} onPointerDown={(event) => beginDrag(event, marker)} onPointerMove={(event) => moveMarker(event, marker)} onPointerUp={(event) => endDrag(event, marker)} onPointerCancel={() => setDragging(null)} onClick={() => { if (!dragging) { onSelectZone(marker.zone); setSelectedMarker(marker) } }} aria-label={`${marker.plant.name}. ${status.label}`}>
          <PlantVisualIcon plant={marker.plant} className="plot-plant-icon" />
          {marker.tier !== 'distant' ? <span>{marker.tier === 'close' ? marker.plant.name : shortName(marker.plant.name)}</span> : null}
          {marker.tier === 'close' && (marker.plant.quantity || marker.plant.occupied_area) ? <small>{marker.plant.quantity ? `${marker.plant.quantity} vnt.` : `${marker.plant.occupied_area} m²`}</small> : null}
          <span className={`plot-plant-status is-${status.key}`} title={status.label} aria-hidden="true">{status.symbol}</span>
        </button>
      })}
      {expandedZoneId !== null ? <section className="plot-zone-plant-popover" role="dialog" aria-label="Visi zonos augalai"><button type="button" className="plot-plan-close" onClick={() => setExpandedZoneId(null)} aria-label="Uždaryti augalų sąrašą">×</button><strong>{zones.find((zone) => sameId(zone.id, expandedZoneId))?.name}</strong><div className="plot-zone-plant-popover-list">{plants.filter((plant) => sameId(plant.fk_plant_zone_id ?? plant.plant_zone_id, expandedZoneId)).map((plant) => <button key={plant.id} type="button" onClick={() => setSelectedMarker({ plant })}>{plant.name}</button>)}</div></section> : null}
      {selectedMarker?.plant ? <PlantInformationCard marker={selectedMarker} plotId={plotId} canEdit={canEdit} isMobile={mobile} onClose={() => { setSelectedMarker(null); setRepositioningId(null) }} onReset={onMarkerPositionReset} onReposition={setRepositioningId} /> : null}
    </div>
  )
}

export default memo(PlotPlanOverlay)
