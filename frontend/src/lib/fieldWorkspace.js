export function safeWorkspacePoints(value) {
  return Array.isArray(value)
    ? value
        .filter((point) => Number.isFinite(Number(point?.x)) && Number.isFinite(Number(point?.y)))
        .map((point) => ({ x: Number(point.x), y: Number(point.y) }))
    : []
}

function sameCoordinate(first, second) {
  return Number(first?.[0]) === Number(second?.[0]) && Number(first?.[1]) === Number(second?.[1])
}

function normalizeGeoJsonPolygon(value) {
  if (value?.type !== 'Polygon' || !Array.isArray(value.coordinates?.[0])) return null
  const coordinates = value.coordinates[0]
    .filter(
      (coordinate) =>
        Array.isArray(coordinate) &&
        Number.isFinite(Number(coordinate[0])) &&
        Number.isFinite(Number(coordinate[1])),
    )
    .map(([longitude, latitude]) => [Number(longitude), Number(latitude)])
  if (coordinates.length > 1 && sameCoordinate(coordinates[0], coordinates.at(-1))) {
    coordinates.pop()
  }
  if (coordinates.length < 3) return null

  const longitudes = coordinates.map(([longitude]) => longitude)
  const latitudes = coordinates.map(([, latitude]) => latitude)
  const source = {
    type: 'geojson-polygon',
    minLongitude: Math.min(...longitudes),
    maxLongitude: Math.max(...longitudes),
    minLatitude: Math.min(...latitudes),
    maxLatitude: Math.max(...latitudes),
  }
  const longitudeRange = source.maxLongitude - source.minLongitude || 1
  const latitudeRange = source.maxLatitude - source.minLatitude || 1

  return {
    points: coordinates.map(([longitude, latitude]) => ({
      x: 10 + ((longitude - source.minLongitude) / longitudeRange) * 80,
      y: 90 - ((latitude - source.minLatitude) / latitudeRange) * 80,
    })),
    source,
  }
}

function normalizeWorkspaceGeometry(value, existingSource = null) {
  return (
    normalizeGeoJsonPolygon(value) || {
      points: safeWorkspacePoints(value),
      source: existingSource?.type === 'geojson-polygon' ? existingSource : null,
    }
  )
}

function serializeWorkspaceGeometry(points, source) {
  const safePoints = safeWorkspacePoints(points)
  if (source?.type !== 'geojson-polygon') return safePoints

  const longitudeRange = source.maxLongitude - source.minLongitude || 1
  const latitudeRange = source.maxLatitude - source.minLatitude || 1
  const coordinates = safePoints.map((point) => [
    source.minLongitude + ((point.x - 10) / 80) * longitudeRange,
    source.minLatitude + ((90 - point.y) / 80) * latitudeRange,
  ])
  if (coordinates.length) coordinates.push([...coordinates[0]])

  return { type: 'Polygon', coordinates: [coordinates] }
}

export function fieldZoneKey(zone) {
  return String(zone?.id ?? zone?.client_id ?? '')
}

export function normalizeFieldWorkspace(field) {
  const boundary = normalizeWorkspaceGeometry(
    field?.boundary ?? field?.geometry,
    field?.geometrySource,
  )
  return {
    geometry: boundary.points,
    geometrySource: boundary.source,
    zones: (Array.isArray(field?.zones) ? field.zones : []).map((zone, index) => {
      const geometry = normalizeWorkspaceGeometry(
        zone.boundary ?? zone.geometry,
        zone.geometrySource,
      )
      return {
        ...zone,
        client_id: zone.client_id || (zone.id ? undefined : `recovered-zone-${index + 1}`),
        geometry: geometry.points,
        geometrySource: geometry.source,
        color: zone.colour || zone.color || '#DA743A',
      }
    }),
    markers: Array.isArray(field?.markers) ? field.markers : [],
    client_revision: Number(field?.workspace_revision || field?.client_revision || 0),
  }
}

export function serializeFieldWorkspace(draft) {
  return {
    boundary: serializeWorkspaceGeometry(draft?.geometry, draft?.geometrySource),
    zones: (Array.isArray(draft?.zones) ? draft.zones : []).map((zone) => {
      const payload = {
        ...zone,
        boundary: serializeWorkspaceGeometry(zone.boundary ?? zone.geometry, zone.geometrySource),
        colour: zone.colour || zone.color || '#DA743A',
      }
      delete payload.geometry
      delete payload.geometrySource
      delete payload.color
      if (!Number.isInteger(Number(payload.id))) delete payload.id
      return payload
    }),
    markers: Array.isArray(draft?.markers) ? draft.markers : [],
    client_revision: Number(draft?.client_revision || 0),
  }
}
