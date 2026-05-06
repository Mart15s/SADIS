import { useEffect, useMemo } from 'react'
import L from 'leaflet'
import { CircleMarker, MapContainer, Marker, Polygon, Polyline, TileLayer, Tooltip, useMap, useMapEvents } from 'react-leaflet'
import { formatMeters } from '../../lib/plotMeasurements.js'
import { getLatLngEdges } from '../../lib/geoMeasurements.js'

const DEFAULT_CENTER = { lat: 54.6872, lng: 25.2797 }
const DEFAULT_ZOOM = 13
const MAX_BOUNDARY_POINTS = 12

const cornerIcon = L.divIcon({
  className: 'plot-boundary-corner-marker',
  html: '<span></span>',
  iconSize: [26, 26],
  iconAnchor: [13, 13],
})

const firstCornerIcon = L.divIcon({
  className: 'plot-boundary-corner-marker plot-boundary-corner-marker--first',
  html: '<span></span>',
  iconSize: [30, 30],
  iconAnchor: [15, 15],
})

const edgeMeasureIcon = L.divIcon({
  className: 'plot-boundary-edge-measure-anchor',
  html: '<span></span>',
  iconSize: [1, 1],
  iconAnchor: [0, 0],
})

const edgeInsertIcon = L.divIcon({
  className: 'plot-boundary-edge-insert-marker',
  html: '<span></span>',
  iconSize: [18, 18],
  iconAnchor: [9, 9],
})

function toLatLngTuple(point) {
  return [point.lat, point.lng]
}

function MapInteractionLayer({
  mode,
  boundaryClosed,
  boundaryPoints,
  onBoundaryPointAdd,
  onViewChange,
}) {
  const map = useMapEvents({
    click(event) {
      const point = {
        lat: event.latlng.lat,
        lng: event.latlng.lng,
      }

      if (mode === 'boundary' && !boundaryClosed && boundaryPoints.length < MAX_BOUNDARY_POINTS) {
        onBoundaryPointAdd?.(point)
      }
    },
    moveend() {
      const center = map.getCenter()
      onViewChange?.({
        center: {
          lat: center.lat,
          lng: center.lng,
        },
        zoom: map.getZoom(),
      })
    },
    zoomend() {
      const center = map.getCenter()
      onViewChange?.({
        center: {
          lat: center.lat,
          lng: center.lng,
        },
        zoom: map.getZoom(),
      })
    },
  })

  return null
}

function MapViewSynchronizer({ view }) {
  const map = useMap()
  const center = view?.center
  const zoom = view?.zoom

  useEffect(() => {
    map.invalidateSize()

    if (!center) {
      return
    }

    const currentCenter = map.getCenter()
    const currentZoom = map.getZoom()
    const hasDifferentCenter = Math.abs(currentCenter.lat - center.lat) > 0.000001
      || Math.abs(currentCenter.lng - center.lng) > 0.000001
    const hasDifferentZoom = Number.isFinite(Number(zoom)) && currentZoom !== zoom

    if (hasDifferentCenter || hasDifferentZoom) {
      map.setView(toLatLngTuple(center), hasDifferentZoom ? zoom : currentZoom, { animate: false })
    }
  }, [center, map, zoom])

  return null
}

function BoundaryFitSynchronizer({ boundaryPoints, enabled }) {
  const map = useMap()
  const boundaryKey = boundaryPoints.map((point) => `${point.lat}:${point.lng}`).join('|')

  useEffect(() => {
    map.invalidateSize()

    if (!enabled || boundaryPoints.length < 3) {
      return
    }

    map.fitBounds(boundaryPoints.map(toLatLngTuple), {
      animate: false,
      maxZoom: 18,
      padding: [48, 48],
    })
  }, [boundaryKey, boundaryPoints, enabled, map])

  return null
}

function getProjectedMidpoint(map, start, end) {
  const startPoint = map.latLngToLayerPoint(toLatLngTuple(start))
  const endPoint = map.latLngToLayerPoint(toLatLngTuple(end))

  return map.layerPointToLatLng([
    (startPoint.x + endPoint.x) / 2,
    (startPoint.y + endPoint.y) / 2,
  ])
}

function BoundaryOverlays({
  boundaryClosed,
  boundaryPoints,
  canInsertPoints,
  readOnly,
  selectedLocation,
  showOpenBoundaryLine,
  onBoundaryPointInsert,
  onBoundaryPointMove,
  onBoundaryPointRemove,
}) {
  const map = useMap()
  const boundaryLine = useMemo(() => boundaryPoints.map(toLatLngTuple), [boundaryPoints])
  const polygonPoints = boundaryClosed && boundaryPoints.length >= 3 ? boundaryLine : []
  const closingLine = !boundaryClosed && boundaryPoints.length >= 3
    ? [toLatLngTuple(boundaryPoints[boundaryPoints.length - 1]), toLatLngTuple(boundaryPoints[0])]
    : []
  const edgeLabels = getLatLngEdges(boundaryPoints, boundaryPoints.length >= 3).map((edge) => {
    const midpoint = getProjectedMidpoint(map, edge.start, edge.end)

    return {
      ...edge,
      midpoint: {
        lat: midpoint.lat,
        lng: midpoint.lng,
      },
    }
  })

  return (
    <>
      {selectedLocation ? (
        <CircleMarker
          center={toLatLngTuple(selectedLocation)}
          radius={9}
          pathOptions={{
            color: '#b9683f',
            fillColor: '#f26a21',
            fillOpacity: 0.92,
            weight: 3,
          }}
        />
      ) : null}

      {showOpenBoundaryLine && boundaryLine.length > 1 ? (
        <Polyline
          positions={boundaryLine}
          interactive={false}
          pathOptions={{
            color: '#47633b',
            opacity: 0.95,
            weight: 4,
          }}
        />
      ) : null}

      {closingLine.length ? (
        <Polyline
          positions={closingLine}
          interactive={false}
          pathOptions={{
            color: '#47633b',
            dashArray: '8 8',
            opacity: 0.72,
            weight: 3,
          }}
        />
      ) : null}

      {polygonPoints.length ? (
        <Polygon
          positions={polygonPoints}
          interactive={false}
          pathOptions={{
            color: '#47633b',
            fillColor: '#d8e8ca',
            fillOpacity: 0.28,
            weight: 4,
          }}
        />
      ) : null}

      {edgeLabels.map((edge) => (
        <Marker
          key={`edge-label-${edge.index}`}
          position={toLatLngTuple(edge.midpoint)}
          icon={edgeMeasureIcon}
          interactive={false}
          keyboard={false}
        >
          <Tooltip permanent direction="center" className="plot-boundary-edge-label">
            {formatMeters(edge.length)}
          </Tooltip>
        </Marker>
      ))}

      {canInsertPoints ? edgeLabels.map((edge) => (
        <Marker
          key={`edge-insert-${edge.index}`}
          position={toLatLngTuple(edge.midpoint)}
          icon={edgeInsertIcon}
          riseOnHover
          title="Add a corner on this edge"
          eventHandlers={{
            click(event) {
              event.originalEvent?.stopPropagation()
              onBoundaryPointInsert?.(edge.index + 1, edge.midpoint)
            },
          }}
        >
          <Tooltip direction="top" offset={[0, -10]} className="plot-boundary-action-tooltip">
            Add corner
          </Tooltip>
        </Marker>
      )) : null}

      {boundaryPoints.map((point, index) => (
        <Marker
          key={`boundary-point-${index}`}
          position={toLatLngTuple(point)}
          icon={index === 0 ? firstCornerIcon : cornerIcon}
          draggable={!readOnly}
          autoPan={!readOnly}
          autoPanPadding={[56, 56]}
          riseOnHover
          title={readOnly ? 'Plot boundary corner' : index === 0 ? 'First corner - boundary closes here' : 'Drag corner to adjust boundary'}
          eventHandlers={readOnly ? {
            click(event) {
              event.originalEvent?.stopPropagation()
            },
          } : {
            drag(event) {
              const next = event.target.getLatLng()
              onBoundaryPointMove?.(index, { lat: next.lat, lng: next.lng })
            },
            click(event) {
              event.originalEvent?.stopPropagation()
            },
            contextmenu(event) {
              event.originalEvent?.preventDefault()
              onBoundaryPointRemove?.(index)
            },
          }}
        />
      ))}
    </>
  )
}

export default function PlotLocationMap({
  boundaryClosed = false,
  boundaryPoints = [],
  center = DEFAULT_CENTER,
  className = '',
  fitBoundary = false,
  mode,
  readOnly = false,
  selectedLocation,
  view,
  onBoundaryPointAdd,
  onBoundaryPointInsert,
  onBoundaryPointMove,
  onBoundaryPointRemove,
  onViewChange,
}) {
  const initialCenter = view?.center ?? selectedLocation ?? center
  const initialZoom = view?.zoom ?? DEFAULT_ZOOM
  const canInsertPoints = !readOnly && boundaryClosed && boundaryPoints.length < MAX_BOUNDARY_POINTS
  const helperText = readOnly
    ? 'Saved corners, side lengths, and center.'
    : boundaryClosed
      ? 'Drag corners. Use edge markers to add points.'
      : 'Click the map to add corners, then close the boundary.'
  const rootClassName = [
    'plot-location-map',
    `plot-location-map--${mode}`,
    readOnly ? 'plot-location-map--readonly' : '',
    className,
  ].filter(Boolean).join(' ')

  return (
    <div className={rootClassName}>
      <div className="plot-location-map-mode-hint" aria-live="polite">
        <strong>{readOnly ? 'Boundary preview' : boundaryClosed ? 'Edit boundary' : 'Draw boundary'}</strong>
        <span>{helperText}</span>
      </div>
      <MapContainer
        center={toLatLngTuple(initialCenter)}
        zoom={initialZoom}
        scrollWheelZoom
        className="plot-location-map-canvas"
      >
        <TileLayer
          attribution="&copy; OpenStreetMap contributors"
          url="https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png"
        />
        {fitBoundary ? (
          <BoundaryFitSynchronizer boundaryPoints={boundaryPoints} enabled={fitBoundary} />
        ) : (
          <MapViewSynchronizer view={view} />
        )}
        <MapInteractionLayer
          mode={readOnly ? 'preview' : mode}
          boundaryClosed={boundaryClosed}
          boundaryPoints={boundaryPoints}
          onBoundaryPointAdd={onBoundaryPointAdd}
          onViewChange={onViewChange}
        />

        <BoundaryOverlays
          boundaryClosed={boundaryClosed}
          boundaryPoints={boundaryPoints}
          canInsertPoints={canInsertPoints}
          readOnly={readOnly}
          selectedLocation={selectedLocation}
          showOpenBoundaryLine={!boundaryClosed}
          onBoundaryPointInsert={onBoundaryPointInsert}
          onBoundaryPointMove={onBoundaryPointMove}
          onBoundaryPointRemove={onBoundaryPointRemove}
        />
      </MapContainer>
    </div>
  )
}
