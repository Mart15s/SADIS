export function safeWorkspacePoints(value) {
  return Array.isArray(value)
    ? value
        .filter((point) => Number.isFinite(Number(point?.x)) && Number.isFinite(Number(point?.y)))
        .map((point) => ({ x: Number(point.x), y: Number(point.y) }))
    : []
}

export function fieldZoneKey(zone) {
  return String(zone?.id ?? zone?.client_id ?? '')
}

export function normalizeFieldWorkspace(field) {
  return {
    geometry: safeWorkspacePoints(field?.boundary ?? field?.geometry),
    zones: (Array.isArray(field?.zones) ? field.zones : []).map((zone, index) => ({
      ...zone,
      client_id: zone.client_id || (zone.id ? undefined : `recovered-zone-${index + 1}`),
      geometry: safeWorkspacePoints(zone.boundary ?? zone.geometry),
      color: zone.colour || zone.color || '#DA743A',
    })),
    markers: Array.isArray(field?.markers) ? field.markers : [],
    client_revision: Number(field?.workspace_revision || field?.client_revision || 0),
  }
}

export function serializeFieldWorkspace(draft) {
  return {
    boundary: safeWorkspacePoints(draft?.geometry),
    zones: (Array.isArray(draft?.zones) ? draft.zones : []).map((zone) => {
      const payload = {
        ...zone,
        boundary: safeWorkspacePoints(zone.boundary ?? zone.geometry),
        colour: zone.colour || zone.color || '#DA743A',
      }
      delete payload.geometry
      delete payload.color
      if (!Number.isInteger(Number(payload.id))) delete payload.id
      return payload
    }),
    markers: Array.isArray(draft?.markers) ? draft.markers : [],
    client_revision: Number(draft?.client_revision || 0),
  }
}
