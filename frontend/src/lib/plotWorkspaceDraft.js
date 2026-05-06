const STORAGE_PREFIX = 'sad-plot-workspace-draft-v1'

function defaultClientIdToken() {
  if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
    return crypto.randomUUID()
  }

  return `${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 10)}`
}

function collectWorkspaceEntityIds(items) {
  return new Set(
    (items ?? [])
      .flatMap((item) => [item?.id, item?.client_id])
      .filter((value) => value !== null && value !== undefined && value !== '')
      .map(String),
  )
}

export function createWorkspaceClientId(prefix, existingItems = [], tokenFactory = defaultClientIdToken) {
  const existingIds = collectWorkspaceEntityIds(existingItems)

  for (let attempt = 0; attempt < 20; attempt += 1) {
    const candidate = `${prefix}-${tokenFactory()}`

    if (!existingIds.has(candidate)) {
      return candidate
    }
  }

  let counter = 1
  let fallback = `${prefix}-${counter}`

  while (existingIds.has(fallback)) {
    counter += 1
    fallback = `${prefix}-${counter}`
  }

  return fallback
}

function stableCopy(workspace) {
  return {
    plot: workspace?.plot
      ? {
        id: workspace.plot.id ?? null,
        plot_size: workspace.plot.plot_size ?? null,
        geometry: workspace.plot.geometry ?? null,
      }
      : null,
    zones: [...(workspace?.zones ?? [])]
      .map((zone) => ({
        id: zone.id ?? null,
        client_id: zone.client_id ?? null,
        name: zone.name ?? '',
        zone_size: zone.zone_size ?? null,
        soil_type: zone.soil_type ?? null,
        rotation_stage: zone.rotation_stage ?? 0,
        last_planting_date: zone.last_planting_date ?? null,
        geometry: zone.geometry ?? null,
      }))
      .sort((left, right) => String(left.id ?? left.client_id).localeCompare(String(right.id ?? right.client_id))),
    plants: [...(workspace?.plants ?? [])]
      .map((plant) => ({
        id: plant.id ?? null,
        client_id: plant.client_id ?? null,
        name: plant.name ?? '',
        type: plant.type ?? null,
        condition: plant.condition ?? null,
        plant_date: plant.plant_date ?? null,
        disease: Boolean(plant.disease),
        disease_notes: plant.disease_notes ?? null,
        fk_catalog_plant_id: plant.fk_catalog_plant_id ?? null,
        fk_plant_zone_id: plant.fk_plant_zone_id ?? plant.plant_zone_id ?? null,
      }))
      .sort((left, right) => String(left.id ?? left.client_id).localeCompare(String(right.id ?? right.client_id))),
  }
}

export function createPlotWorkspaceSignature(workspace) {
  return JSON.stringify(stableCopy(workspace))
}

export function loadPlotWorkspaceDraft(plotId, sourceSignature) {
  if (typeof window === 'undefined' || !plotId) {
    return null
  }

  try {
    const raw = window.localStorage.getItem(`${STORAGE_PREFIX}:${plotId}`)

    if (!raw) {
      return null
    }

    const parsed = JSON.parse(raw)

    if (parsed?.sourceSignature !== sourceSignature || !parsed.workspace) {
      return null
    }

    return parsed.workspace
  } catch {
    return null
  }
}

export function savePlotWorkspaceDraft(plotId, sourceSignature, workspace) {
  if (typeof window === 'undefined' || !plotId) {
    return
  }

  window.localStorage.setItem(`${STORAGE_PREFIX}:${plotId}`, JSON.stringify({
    sourceSignature,
    workspace,
    savedAt: new Date().toISOString(),
  }))
}

export function clearPlotWorkspaceDraft(plotId) {
  if (typeof window === 'undefined' || !plotId) {
    return
  }

  window.localStorage.removeItem(`${STORAGE_PREFIX}:${plotId}`)
}
