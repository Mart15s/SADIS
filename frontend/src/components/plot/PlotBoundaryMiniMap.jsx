import { memo, useEffect, useMemo } from 'react'
import { MapContainer, Polygon, TileLayer, useMap } from 'react-leaflet'

const DEFAULT_CENTER = [54.6872, 25.2797]

function finiteLatLng(point) {
  const lat = Number(point?.lat)
  const lng = Number(point?.lng)

  if (!Number.isFinite(lat) || !Number.isFinite(lng) || lat < -90 || lat > 90 || lng < -180 || lng > 180) {
    return null
  }

  return { lat, lng }
}

// eslint-disable-next-line react-refresh/only-export-components
export function normalizeMapBoundary(geometry) {
  if (!Array.isArray(geometry?.map?.boundary)) {
    return []
  }

  return geometry.map.boundary
    .map(finiteLatLng)
    .filter(Boolean)
}

function centerForBoundary(boundary, geometry) {
  const storedCenter = finiteLatLng(geometry?.map?.center)

  if (storedCenter) {
    return [storedCenter.lat, storedCenter.lng]
  }

  if (boundary.length === 0) {
    return DEFAULT_CENTER
  }

  return [
    boundary.reduce((sum, point) => sum + point.lat, 0) / boundary.length,
    boundary.reduce((sum, point) => sum + point.lng, 0) / boundary.length,
  ]
}

function FitBoundary({ boundary }) {
  const map = useMap()
  const boundaryKey = boundary.map((point) => `${point.lat}:${point.lng}`).join('|')

  useEffect(() => {
    map.invalidateSize()

    if (boundary.length < 3) {
      return
    }

    map.fitBounds(boundary.map((point) => [point.lat, point.lng]), {
      animate: false,
      padding: [18, 18],
      maxZoom: 18,
    })
  }, [boundary, boundaryKey, map])

  return null
}

export default memo(function PlotBoundaryMiniMap({
  plotGeometry,
  plotName,
  className = '',
}) {
  const boundary = useMemo(() => normalizeMapBoundary(plotGeometry), [plotGeometry])
  const center = useMemo(() => centerForBoundary(boundary, plotGeometry), [boundary, plotGeometry])

  if (boundary.length < 3) {
    return (
      <figure className={`plot-boundary-mini-map plot-boundary-mini-map--empty ${className}`.trim()}>
        <div className="plot-boundary-mini-map-placeholder" aria-label={`${plotName || 'Sklypas'} ribų peržiūra nepasiekiama`}>
          <svg viewBox="0 0 160 104" role="presentation" focusable="false">
            <rect x="10" y="10" width="140" height="84" rx="8" />
            <path d="M24 78L58 42l24 20 24-32 30 48" />
            <circle cx="58" cy="42" r="4" />
            <circle cx="82" cy="62" r="4" />
            <circle cx="106" cy="30" r="4" />
          </svg>
          <span>Riba nenurodyta</span>
        </div>
      </figure>
    )
  }

  return (
    <figure className={`plot-boundary-mini-map ${className}`.trim()}>
      <MapContainer
        center={center}
        zoom={Number(plotGeometry?.map?.zoom) || 15}
        zoomControl={false}
        attributionControl={false}
        dragging={false}
        scrollWheelZoom={false}
        doubleClickZoom={false}
        boxZoom={false}
        keyboard={false}
        touchZoom={false}
        className="plot-boundary-mini-map-canvas"
      >
        <TileLayer url="https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png" />
        <FitBoundary boundary={boundary} />
        <Polygon
          positions={boundary.map((point) => [point.lat, point.lng])}
          interactive={false}
          pathOptions={{
            color: '#47633b',
            fillColor: '#9cb98c',
            fillOpacity: 0.26,
            opacity: 0.95,
            weight: 3,
          }}
        />
      </MapContainer>
    </figure>
  )
})
