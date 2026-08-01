import { useEffect, useMemo, useRef, useState } from 'react'
import {
  ZoneInspector,
  MeasurementBadge,
  MapLayerControl,
  GardenTimeline,
  PlantStatusBadge,
} from '../../components/garden/GardenControls.jsx'
import {
  Link,
  useParams,
} from 'react-router-dom'
import PlotDesignerCanvas from '../../components/plot/PlotDesignerCanvas.jsx'
import PlotLocationMap from '../../components/plot/PlotLocationMap.jsx'
import PlotPlantingDrawer from '../../components/plot/PlotPlantingDrawer.jsx'
import PlotPlanControls from '../../components/plot/PlotPlanControls.jsx'
import ZoneColorControl from '../../components/plot/ZoneColorControl.jsx'
import PlotSectionNav from '../../components/plot/PlotSectionNav.jsx'
import PlotWorkspaceModeSwitch from '../../components/plot/PlotWorkspaceModeSwitch.jsx'
import {
  EmptyState,
  ErrorState,
  LoadingState,
  SuccessToast,
} from '../../components/shared/StatusView.jsx'
import Button from '../../components/ui/Button.jsx'
import { DefinitionList } from '../../components/ui/DefinitionList.jsx'
import EmptyStatePanel from '../../components/ui/EmptyStatePanel.jsx'
import InspectorPanel, { InspectorSection } from '../../components/ui/InspectorPanel.jsx'
import StatusBadge from '../../components/ui/StatusBadge.jsx'
import { api } from '../../lib/api.js'
import {
  formatSoilType,
  SOIL_TYPES,
} from '../../lib/constants.js'
import { useAsyncData } from '../../lib/hooks/useAsyncData.js'
import { useUnsavedChangesGuard } from '../../lib/hooks/useUnsavedChangesGuard.js'
import {
  clearPlotWorkspaceDraft,
  createWorkspaceClientId,
  createPlotWorkspaceSignature,
  loadPlotWorkspaceDraft,
  savePlotWorkspaceDraft,
} from '../../lib/plotWorkspaceDraft.js'
import {
  buildDesignerStateFromPersistence,
  calculateArea,
  getShapeBounds,
  isShapeInsideBoundary,
  pointInPolygon,
  shapeToGeometry,
} from '../../lib/plotDesigner.js'
import { assertSanitizedGeometryPayload } from '../../lib/plotGeometry.js'
import { calculateLatLngArea, calculateLatLngCenter, calculateLatLngPerimeter } from '../../lib/geoMeasurements.js'
import { buildShapeMetrics, formatMeters, formatSquareMeters } from '../../lib/plotMeasurements.js'
import { getPlantStatusSemantic, normalizeZoneColor, plantingSeason, plantingYear, suggestZoneColor } from '../../lib/plotPlan.js'
import { markerPosition } from '../../lib/plantVisual.js'
import { plotPlanText } from '../../lib/plotPlanLt.js'

const emptyZoneForm = {
  name: '',
  soil_type: SOIL_TYPES[0],
  rotation_stage: 0,
  last_planting_date: '',
  color_hex: '',
}

function zoneToForm(zone) {
  return {
    name: zone?.name ?? '',
    soil_type: zone?.soil_type ?? SOIL_TYPES[0],
    rotation_stage: zone?.rotation_stage ?? 0,
    last_planting_date: zone?.last_planting_date ?? '',
    color_hex: zone?.color_hex ?? '',
  }
}

function sameId(left, right) {
  return String(left ?? '') === String(right ?? '')
}

function getFiniteLatLng(point) {
  const lat = Number(point?.lat)
  const lng = Number(point?.lng)

  if (!Number.isFinite(lat) || !Number.isFinite(lng)) {
    return null
  }

  return { lat, lng }
}

function getMapBoundaryPoints(geometry) {
  if (!Array.isArray(geometry?.map?.boundary)) {
    return []
  }

  return geometry.map.boundary
    .map(getFiniteLatLng)
    .filter(Boolean)
}

function getMapCenter(geometry, boundaryPoints) {
  return getFiniteLatLng(geometry?.map?.center) ?? calculateLatLngCenter(boundaryPoints)
}

function withPreservedMapGeometry(nextGeometry, currentGeometry) {
  if (!nextGeometry) {
    return null
  }

  return currentGeometry?.map
    ? { ...nextGeometry, map: currentGeometry.map }
    : nextGeometry
}

function createPersistedWorkspace(data) {
  return {
    plot: {
      id: data.plot.id,
      name: data.plot.name,
      city: data.plot.city,
      share: Boolean(data.plot.share),
      plot_size: Number(data.plot.plot_size ?? 0),
      geometry: data.plot.geometry ?? null,
    },
    zones: data.zones.map((zone) => ({
      id: zone.id,
      client_id: null,
      name: zone.name,
      zone_size: Number(zone.zone_size ?? 0),
      soil_type: zone.soil_type ?? SOIL_TYPES[0],
      rotation_stage: zone.rotation_stage ?? 0,
      last_planting_date: zone.last_planting_date ?? '',
      geometry: zone.geometry ?? null,
      color_hex: zone.color_hex ?? '',
      archived_at: zone.archived_at ?? null,
      active_planting_count: zone.active_planting_count ?? 0,
      historical_planting_count: zone.historical_planting_count ?? 0,
      rotation_history_count: zone.rotation_history_count ?? 0,
      harvest_history_count: zone.harvest_history_count ?? 0,
      principal_plants: zone.principal_plants ?? [],
    })),
    plants: data.plants.map((plant) => ({
      id: plant.id,
      client_id: null,
      name: plant.name,
      type: plant.type ?? null,
      condition: plant.condition,
      plant_date: plant.plant_date,
      variety: plant.variety ?? '',
      quantity: plant.quantity ?? null,
      occupied_area: plant.occupied_area ?? null,
      season: plant.season ?? '',
      harvest_date: plant.harvest_date ?? null,
      notes: plant.notes ?? '',
      marker_position_x: plant.marker_position_x ?? null,
      marker_position_y: plant.marker_position_y ?? null,
      disease: Boolean(plant.disease),
      disease_notes: plant.disease_notes ?? '',
      fk_catalog_plant_id: plant.fk_catalog_plant_id ?? plant.catalogPlant?.id ?? plant.catalog_plant?.id ?? null,
      fk_plant_zone_id: plant.fk_plant_zone_id ?? plant.plant_zone_id ?? plant.plantZone?.id ?? plant.plant_zone?.id ?? null,
      plant_zone: plant.plant_zone ?? plant.plantZone ?? null,
      catalog_plant: plant.catalog_plant ?? plant.catalogPlant ?? null,
    })),
  }
}

function createEmptyFeedback() {
  return { type: 'idle', message: '' }
}

function cloneWorkspace(workspace) {
  return JSON.parse(JSON.stringify(workspace))
}

function reconcileMarkerPositions(plot, zones, plants) {
  if (!plot) return plants
  const { layouts } = buildDesignerStateFromPersistence({
    plotSize: plot.plot_size,
    plotGeometry: plot.geometry,
    zones,
    storedState: null,
  })
  return plants.map((plant) => {
    const position = markerPosition(plant)
    const zoneId = plant.fk_plant_zone_id ?? plant.plant_zone_id
    const shape = layouts[String(zoneId)] ?? layouts[zoneId]
    if (!position || !shape) return plant
    const bounds = getShapeBounds(shape)
    const point = { x: bounds.left + (position.x * bounds.width), y: bounds.top + (position.y * bounds.height) }
    return pointInPolygon(point, shape) ? plant : { ...plant, marker_position_x: null, marker_position_y: null }
  })
}

const INSPECTOR_TYPES = {
  boundary: 'boundary',
  zone: 'zone',
}

const EDITOR_VIEWS = {
  zones: 'zones',
  boundary: 'boundary',
}

const DEFAULT_MAP_VIEW = { center: { lat: 54.6872, lng: 25.2797 }, zoom: 13 }
const MAX_BOUNDARY_POINTS = 12

function roundCoordinate(value, digits = 6) {
  const factor = 10 ** digits
  return Math.round(Number(value) * factor) / factor
}

function createMapGeometryPatch(boundaryPoints, currentMap, mapView) {
  const center = calculateLatLngCenter(boundaryPoints)
    ?? getFiniteLatLng(currentMap?.center)
    ?? getFiniteLatLng(mapView?.center)
    ?? DEFAULT_MAP_VIEW.center

  return {
    provider: currentMap?.provider ?? 'openstreetmap',
    center: {
      lat: roundCoordinate(center.lat),
      lng: roundCoordinate(center.lng),
    },
    zoom: Math.round(Number(mapView?.zoom ?? currentMap?.zoom ?? DEFAULT_MAP_VIEW.zoom)),
    ...(boundaryPoints.length >= 3
      ? {
        boundary: boundaryPoints.map((point) => ({
          lat: roundCoordinate(point.lat),
          lng: roundCoordinate(point.lng),
        })),
      }
      : {}),
  }
}

function createPlotGeometryFromMapBoundary(boundaryPoints, currentGeometry, mapView) {
  const nextMap = createMapGeometryPatch(boundaryPoints, currentGeometry?.map, mapView)

  if (boundaryPoints.length < 3) {
    return {
      ...(currentGeometry ?? {}),
      map: nextMap,
    }
  }

  const lats = boundaryPoints.map((point) => point.lat)
  const lngs = boundaryPoints.map((point) => point.lng)
  const minLat = Math.min(...lats)
  const maxLat = Math.max(...lats)
  const minLng = Math.min(...lngs)
  const maxLng = Math.max(...lngs)
  const latSpan = Math.max(maxLat - minLat, Number.EPSILON)
  const lngSpan = Math.max(maxLng - minLng, Number.EPSILON)

  return {
    points: boundaryPoints.map((point) => ({
      x: roundCoordinate((point.lng - minLng) / lngSpan, 4),
      y: roundCoordinate((maxLat - point.lat) / latSpan, 4),
    })),
    map: nextMap,
  }
}

export default function PlotDetailPage() {
  const { plotId } = useParams()
  const designerCanvasRef = useRef(null)
  const [draftReady, setDraftReady] = useState(false)
  const [draftPlot, setDraftPlot] = useState(null)
  const [draftZones, setDraftZones] = useState([])
  const [draftPlants, setDraftPlants] = useState([])
  const [selectedZoneId, setSelectedZoneId] = useState(null)
  const [zoneForm, setZoneForm] = useState(emptyZoneForm)
  const [zoneError, setZoneError] = useState('')
  const [plantError, setPlantError] = useState('')
  const [saveError, setSaveError] = useState('')
  const [saving, setSaving] = useState(false)
  const [toastMessage, setToastMessage] = useState('')
  const [mapPreviewView, setMapPreviewView] = useState(null)
  const [activeUtilityPanel, setActiveUtilityPanel] = useState(null)
  const [activeInspector, setActiveInspector] = useState(null)
  const [editorView, seteditorView] = useState(EDITOR_VIEWS.zones)
  const [boundaryClosed, setBoundaryClosed] = useState(true)
  // Viewing is safe by default. Editing is always an intentional action so
  // canvas pans and ordinary marker taps cannot mutate the plan.
  const [plotMode, setPlotMode] = useState('view')
  const [workspaceMode, setWorkspaceMode] = useState('view')
  const [viewOptions, setViewOptions] = useState({ showPlants: true, showZoneNames: true, bordersOnly: false })
  const [filters, setFilters] = useState({ year: '', season: '', plant: '', status: '' })
  const historyRef = useRef({ past: [], future: [], current: null, applying: false })
  const [historyState, setHistoryState] = useState({ canUndo: false, canRedo: false })

  const pageState = useAsyncData(
    async () => {
      const plots = await api.listPlots()
      const accessRole = plots.find((entry) => String(entry.id) === String(plotId))?.access_role ?? null
      const [plot, zones, plants] = await Promise.all([
        api.getPlot(plotId),
        api.listPlantZones(plotId),
        api.listPlants(plotId),
      ])

      return {
        plot: plot && typeof plot === 'object' ? plot : null,
        zones: Array.isArray(zones) ? zones : [],
        plants: Array.isArray(plants) ? plants : [],
        accessRole,
      }
    },
    [plotId],
    {
      plot: null,
      zones: [],
      plants: [],
      accessRole: null,
    },
  )

  const canEdit = ['owner', 'editor'].includes(pageState.data.accessRole)
  const canEditPlan = canEdit && plotMode === 'edit'
  const isOwner = pageState.data.accessRole === 'owner'
  const persistedWorkspace = useMemo(() => (
    pageState.data.plot ? createPersistedWorkspace(pageState.data) : null
  ), [pageState.data])
  const persistedSignature = useMemo(() => (
    persistedWorkspace ? createPlotWorkspaceSignature(persistedWorkspace) : ''
  ), [persistedWorkspace])
  const draftSignature = useMemo(() => (
    draftReady && draftPlot
      ? createPlotWorkspaceSignature({
        plot: draftPlot,
        zones: draftZones,
        plants: draftPlants,
      })
      : ''
  ), [draftPlants, draftPlot, draftReady, draftZones])
  const isDirty = Boolean(draftReady && persistedSignature && draftSignature && draftSignature !== persistedSignature)
  const measurementState = useMemo(() => (
    draftPlot
      ? buildDesignerStateFromPersistence({
        plotSize: draftPlot.plot_size,
        plotGeometry: draftPlot.geometry,
        zones: draftZones,
        storedState: null,
      })
      : null
  ), [draftPlot, draftZones])
  const plotMeasurements = useMemo(() => (
    measurementState?.boundary ? buildShapeMetrics(measurementState.boundary) : null
  ), [measurementState])
  const mapBoundaryPoints = useMemo(() => getMapBoundaryPoints(draftPlot?.geometry), [draftPlot?.geometry])
  const mapBoundaryCenter = useMemo(
    () => getMapCenter(draftPlot?.geometry, mapBoundaryPoints),
    [draftPlot?.geometry, mapBoundaryPoints],
  )
  const mapBoundaryArea = useMemo(() => calculateLatLngArea(mapBoundaryPoints), [mapBoundaryPoints])
  const mapBoundaryPerimeter = useMemo(
    () => calculateLatLngPerimeter(mapBoundaryPoints, mapBoundaryPoints.length >= 3),
    [mapBoundaryPoints],
  )
  const hasActiveFilters = Object.values(filters).some(Boolean)
  const filteredPlants = useMemo(() => draftPlants.filter((plant) => {
    if (filters.year && plantingYear(plant) !== filters.year) return false
    if (filters.season && plantingSeason(plant) !== filters.season) return false
    if (filters.plant && plant.name !== filters.plant) return false
    if (filters.status && getPlantStatusSemantic(plant).key !== filters.status) return false
    return true
  }), [draftPlants, filters])
  const visibleZones = useMemo(() => draftZones.filter((zone) => {
    if (zone.archived_at) return false
    if (!hasActiveFilters) return true
    return filteredPlants.some((plant) => sameId(plant.fk_plant_zone_id ?? plant.plant_zone_id, zone.id))
  }), [draftZones, filteredPlants, hasActiveFilters])
  const visiblePlants = useMemo(() => filteredPlants.filter((plant) => (
    visibleZones.some((zone) => sameId(zone.id, plant.fk_plant_zone_id ?? plant.plant_zone_id))
  )), [filteredPlants, visibleZones])

  useUnsavedChangesGuard({
    when: isDirty,
    message: 'Turite neišsaugotų sklypo pakeitimų. Išeiti neišsaugojus juodraščio?',
  })

  useEffect(() => {
    if (!persistedWorkspace) {
      return
    }

    const restoredDraft = loadPlotWorkspaceDraft(plotId, persistedSignature)
    const nextWorkspace = restoredDraft ?? persistedWorkspace
    const nextSelectedZone = selectedZoneId
      ? nextWorkspace.zones.find((zone) => sameId(zone.id, selectedZoneId))
      : null

    setDraftPlot(nextWorkspace.plot)
    setDraftZones(nextWorkspace.zones)
    setDraftPlants(nextWorkspace.plants)
    setSelectedZoneId(nextSelectedZone?.id ?? null)
    setZoneForm(zoneToForm(nextSelectedZone ?? null))
    setActiveInspector(nextSelectedZone ? INSPECTOR_TYPES.zone : null)
    seteditorView(EDITOR_VIEWS.zones)
    setBoundaryClosed(getMapBoundaryPoints(nextWorkspace.plot.geometry).length >= 3)
    setDraftReady(true)
    setZoneError('')
    setPlantError('')
    setSaveError('')
    historyRef.current = {
      past: [],
      future: [],
      current: cloneWorkspace(nextWorkspace),
      applying: false,
    }
    setHistoryState({ canUndo: false, canRedo: false })
  }, [persistedSignature, persistedWorkspace, plotId])

  useEffect(() => {
    if (!draftPlot?.geometry?.map) {
      setMapPreviewView(null)
      return
    }

    setMapPreviewView({
      center: getMapCenter(draftPlot.geometry, getMapBoundaryPoints(draftPlot.geometry)),
      zoom: draftPlot.geometry.map.zoom ?? 13,
    })
  }, [draftPlot?.id, draftPlot?.geometry?.map])

  useEffect(() => {
    if (!draftReady) {
      return
    }

    if (!isDirty) {
      clearPlotWorkspaceDraft(plotId)
      return
    }

    savePlotWorkspaceDraft(plotId, persistedSignature, {
      plot: draftPlot,
      zones: draftZones,
      plants: draftPlants,
    })
  }, [draftPlants, draftPlot, draftReady, draftZones, isDirty, persistedSignature, plotId])

  useEffect(() => {
    if (!draftReady || !draftPlot) return

    const history = historyRef.current
    const current = cloneWorkspace({ plot: draftPlot, zones: draftZones, plants: draftPlants })

    if (history.applying) {
      history.applying = false
      history.current = current
      setHistoryState({ canUndo: history.past.length > 0, canRedo: history.future.length > 0 })
      return
    }

    if (!history.current) {
      history.current = current
      return
    }

    if (createPlotWorkspaceSignature(history.current) === createPlotWorkspaceSignature(current)) return

    history.past = [...history.past, history.current].slice(-20)
    history.current = current
    history.future = []
    setHistoryState({ canUndo: true, canRedo: false })
  }, [draftPlants, draftPlot, draftReady, draftZones])

  useEffect(() => {
    function handleHistoryShortcut(event) {
      if (!canEdit || plotMode !== 'edit' || !(event.ctrlKey || event.metaKey)) return
      if (event.key.toLowerCase() !== 'z' && event.key.toLowerCase() !== 'y') return

      event.preventDefault()
      if (event.key.toLowerCase() === 'y' || event.shiftKey) handleRedo()
      else handleUndo()
    }

    window.addEventListener('keydown', handleHistoryShortcut)
    return () => window.removeEventListener('keydown', handleHistoryShortcut)
  })

  useEffect(() => {
    if (!draftReady) {
      return
    }

    if (!selectedZoneId) {
      setZoneForm(emptyZoneForm)
      return
    }

    const selectedZone = draftZones.find((zone) => sameId(zone.id, selectedZoneId)) ?? null

    if (!selectedZone) {
      setSelectedZoneId(null)
      setZoneForm(emptyZoneForm)
      return
    }

    setZoneForm(zoneToForm(selectedZone))
  }, [draftReady, draftZones, selectedZoneId])

  function createTempId(prefix, existingItems) {
    return createWorkspaceClientId(prefix, existingItems)
  }

  function applyWorkspaceHistorySnapshot(snapshot) {
    const next = cloneWorkspace(snapshot)
    historyRef.current.applying = true
    setDraftPlot(next.plot)
    setDraftZones(next.zones)
    setDraftPlants(next.plants)

    const nextSelectedZone = next.zones.find((zone) => sameId(zone.id, selectedZoneId) && !zone.archived_at) ?? null
    setSelectedZoneId(nextSelectedZone?.id ?? null)
    setZoneForm(zoneToForm(nextSelectedZone))
    if (!nextSelectedZone) setActiveInspector(null)
  }

  function handleUndo() {
    const history = historyRef.current
    if (!history.past.length || !history.current) return

    const previous = history.past.at(-1)
    history.past = history.past.slice(0, -1)
    history.future = [history.current, ...history.future].slice(0, 20)
    history.current = previous
    applyWorkspaceHistorySnapshot(previous)
    setHistoryState({ canUndo: history.past.length > 0, canRedo: true })
  }

  function handleRedo() {
    const history = historyRef.current
    if (!history.future.length || !history.current) return

    const next = history.future[0]
    history.future = history.future.slice(1)
    history.past = [...history.past, history.current].slice(-20)
    history.current = next
    applyWorkspaceHistorySnapshot(next)
    setHistoryState({ canUndo: true, canRedo: history.future.length > 0 })
  }

  function handleZoneSelect(zone) {
    if (!zone) {
      setSelectedZoneId(null)
      setZoneForm(emptyZoneForm)
      setActiveInspector(null)
      return
    }

    setSelectedZoneId(zone.id)
    setZoneForm(zoneToForm(zone))
    setActiveInspector(INSPECTOR_TYPES.zone)
  }

  function handleBoundarySelect() {
    setSelectedZoneId(null)
    setZoneForm(emptyZoneForm)
    setActiveInspector(INSPECTOR_TYPES.boundary)
  }

  function openNewZoneInspector() {
    setSelectedZoneId(null)
    setZoneForm({
      ...emptyZoneForm,
      color_hex: suggestZoneColor(draftZones.filter((zone) => !zone.archived_at).map((zone) => zone.color_hex)),
    })
    setActiveInspector(INSPECTOR_TYPES.zone)
  }

  function changeeditorView(nextView) {
    seteditorView(nextView)

    if (nextView === EDITOR_VIEWS.boundary) {
      setSelectedZoneId(null)
      setZoneForm(emptyZoneForm)
      setActiveInspector(INSPECTOR_TYPES.boundary)
      return
    }

    if (activeInspector === INSPECTOR_TYPES.boundary) {
      setActiveInspector(null)
    }
  }

  function handleWorkspaceModeChange(nextMode) {
    setWorkspaceMode(nextMode)

    if (nextMode === 'edit') {
      setPlotMode('edit')
      changeeditorView(EDITOR_VIEWS.zones)
      return
    }

    if (nextMode === 'view') {
      setPlotMode('view')
      changeeditorView(EDITOR_VIEWS.zones)
      return
    }

    // The zone view intentionally does not reset the plan mode. This keeps
    // in-progress edits and the selected zone intact, matching the previous
    // independent "Zonų vaizdas" control behaviour.
    changeeditorView(EDITOR_VIEWS.zones)
  }

  function commitMapBoundaryPoints(nextBoundaryPoints, nextMapView = mapPreviewView) {
    setSaveError('')
    setZoneError('')

    if (!draftPlot) {
      return
    }

    const nextGeometry = createPlotGeometryFromMapBoundary(
      nextBoundaryPoints,
      draftPlot.geometry,
      nextMapView ?? {
        center: getMapCenter(draftPlot.geometry, nextBoundaryPoints),
        zoom: draftPlot.geometry?.map?.zoom ?? DEFAULT_MAP_VIEW.zoom,
      },
    )

    if (nextBoundaryPoints.length < 3) {
      setDraftPlot((current) => current ? {
        ...current,
        geometry: nextGeometry,
      } : current)
      return
    }

    const nextPlotSize = calculateLatLngArea(nextBoundaryPoints)
    const currentDesignerState = buildDesignerStateFromPersistence({
      plotSize: draftPlot.plot_size,
      plotGeometry: draftPlot.geometry,
      zones: draftZones,
      storedState: null,
    })
    const nextDesignerState = buildDesignerStateFromPersistence({
      plotSize: nextPlotSize,
      plotGeometry: nextGeometry,
      zones: [],
      storedState: null,
    })
    const nextBoundary = nextDesignerState.boundary
    const outsideZoneNames = []

    const nextZones = draftZones.map((zone) => {
      const currentShape = currentDesignerState.layouts[String(zone.id)]

      if (!currentShape) {
        return zone
      }

      if (!isShapeInsideBoundary(currentShape, nextBoundary)) {
        outsideZoneNames.push(zone.name)
        return zone
      }

      const nextZoneGeometry = shapeToGeometry(currentShape, nextBoundary)

      return nextZoneGeometry
        ? {
          ...zone,
          geometry: nextZoneGeometry,
        }
        : zone
    })

    setDraftPlot((current) => current ? {
      ...current,
      plot_size: nextPlotSize,
      geometry: nextGeometry,
    } : current)
    setDraftZones(nextZones)

    if (outsideZoneNames.length) {
      setZoneError(`${outsideZoneNames.length} zonos yra už redaguojamos ribos. Prieš išsaugodami pakoreguokite ribą arba peržiūrėkite zonas.`)
    }
  }

  function handleBoundaryPointAdd(point) {
    if (boundaryClosed || mapBoundaryPoints.length >= MAX_BOUNDARY_POINTS) {
      return
    }

    commitMapBoundaryPoints([...mapBoundaryPoints, point])
  }

  function handleBoundaryPointMove(index, point) {
    commitMapBoundaryPoints(mapBoundaryPoints.map((existingPoint, currentIndex) => (
      currentIndex === index ? point : existingPoint
    )))
  }

  function handleBoundaryPointInsert(index, point) {
    if (mapBoundaryPoints.length >= MAX_BOUNDARY_POINTS) {
      return
    }

    commitMapBoundaryPoints([
      ...mapBoundaryPoints.slice(0, index),
      point,
      ...mapBoundaryPoints.slice(index),
    ])
  }

  function handleBoundaryPointRemove(index) {
    if (boundaryClosed && mapBoundaryPoints.length <= 3) {
      return
    }

    const nextPoints = mapBoundaryPoints.filter((_, currentIndex) => currentIndex !== index)
    setBoundaryClosed(nextPoints.length >= 3 && boundaryClosed)
    commitMapBoundaryPoints(nextPoints)
  }

  function handleBoundaryUndo() {
    if (!mapBoundaryPoints.length || (boundaryClosed && mapBoundaryPoints.length <= 3)) {
      return
    }

    const nextPoints = mapBoundaryPoints.slice(0, -1)
    setBoundaryClosed(nextPoints.length >= 3 && boundaryClosed)
    commitMapBoundaryPoints(nextPoints)
  }

  function handleBoundaryClose() {
    if (mapBoundaryPoints.length >= 3) {
      setBoundaryClosed(true)
    }
  }

  async function handleCanvasZoneCreate(shape, boundaryShape) {
    const clientId = createTempId('draft-zone', draftZones)
    const createdZone = {
      id: clientId,
      client_id: clientId,
      name: zoneForm.name.trim() || `Zona ${draftZones.length + 1}`,
      zone_size: calculateArea(shape),
      soil_type: zoneForm.soil_type,
      rotation_stage: Number(zoneForm.rotation_stage || 0),
      last_planting_date: zoneForm.last_planting_date || '',
      color_hex: normalizeZoneColor(zoneForm.color_hex)
        ?? suggestZoneColor(draftZones.filter((zone) => !zone.archived_at).map((zone) => zone.color_hex)),
      archived_at: null,
      geometry: shapeToGeometry(shape, boundaryShape),
    }

    setZoneError('')
    setDraftZones((current) => [...current, createdZone])
    setSelectedZoneId(createdZone.id)
    setZoneForm(zoneToForm(createdZone))
    setActiveInspector(INSPECTOR_TYPES.zone)
    setToastMessage(`${createdZone.name} pridėta į juodraštį.`)

    return createdZone
  }

  async function handleZoneCreateFromForm() {
    if (!designerCanvasRef.current?.createZoneFromForm) {
      setZoneError('Sklypo redaktorius dar įkeliamas. Bandykite dar kartą.')
      return
    }

    setZoneError('')

    try {
      await designerCanvasRef.current.createZoneFromForm()
    } catch {
      // Canvas create flow reports the error through page state.
    }
  }

  function handleZoneApply(event) {
    event.preventDefault()

    if (!selectedZoneId) {
      return
    }

    if (zoneForm.color_hex && !normalizeZoneColor(zoneForm.color_hex)) {
      setZoneError('Spalva turi būti šešių skaitmenų HEX formatu, pvz., #4F7A5A.')
      return
    }

    setZoneError('')
    setDraftZones((current) => current.map((zone) => (
      sameId(zone.id, selectedZoneId)
        ? {
          ...zone,
          name: zoneForm.name.trim() || zone.name,
          soil_type: zoneForm.soil_type,
          rotation_stage: Number(zoneForm.rotation_stage || 0),
          last_planting_date: zoneForm.last_planting_date || '',
          color_hex: normalizeZoneColor(zoneForm.color_hex) ?? zone.color_hex,
        }
        : zone
    )))
    setToastMessage('Zonos duomenys atnaujinti juodraštyje.')
  }

  function handleZoneDelete() {
    if (!selectedZoneId) {
      return
    }

    const zone = draftZones.find((entry) => sameId(entry.id, selectedZoneId))
    const plantsInZone = draftPlants.filter((plant) => sameId(plant.fk_plant_zone_id, selectedZoneId))
    const protectedCount = plantsInZone.length
      + Number(zone?.historical_planting_count ?? 0)
      + Number(zone?.rotation_history_count ?? 0)
      + Number(zone?.harvest_history_count ?? 0)

    if (protectedCount > 0) {
      setZoneError('Prieš šalindami zoną iš juodraščio pašalinkite joje esančius augalus.')
      return
    }

    setZoneError('')
    setDraftZones((current) => current.filter((zone) => !sameId(zone.id, selectedZoneId)))
    setSelectedZoneId(null)
    setZoneForm(emptyZoneForm)
    setActiveInspector(null)
    setToastMessage('Zona pašalinta iš juodraščio.')
  }

  function handleZoneArchive() {
    if (!selectedZoneId) return

    setDraftZones((current) => current.map((zone) => sameId(zone.id, selectedZoneId)
      ? { ...zone, archived_at: new Date().toISOString() }
      : zone))
    setSelectedZoneId(null)
    setZoneForm(emptyZoneForm)
    setActiveInspector(null)
    setZoneError('')
    setToastMessage('Zona archyvuota juodraštyje. Istoriniai duomenys bus išsaugoti.')
  }

  function handleZoneDuplicate() {
    const source = draftZones.find((zone) => sameId(zone.id, selectedZoneId))
    if (!source) return

    const clientId = createTempId('draft-zone', draftZones)
    const geometry = source.geometry?.points ? {
      ...source.geometry,
      points: source.geometry.points.map((point) => ({
        x: Math.min(0.98, Number(point.x) + 0.025),
        y: Math.min(0.98, Number(point.y) + 0.025),
      })),
    } : source.geometry
    const duplicate = {
      ...source,
      id: clientId,
      client_id: clientId,
      name: `${source.name} kopija`,
      color_hex: suggestZoneColor(draftZones.filter((zone) => !zone.archived_at).map((zone) => zone.color_hex)),
      archived_at: null,
      geometry,
      active_planting_count: 0,
      historical_planting_count: 0,
      rotation_history_count: 0,
      harvest_history_count: 0,
      principal_plants: [],
    }
    setDraftZones((current) => [...current, duplicate])
    handleZoneSelect(duplicate)
    setToastMessage('Zonos kopija pridėta į juodraštį.')
  }

  function handleAddPlantQuickAction() {
    document.querySelector('[data-testid="open-plant-drawer"]')?.click()
  }

  function handleZoneGeometryCommit(zoneId, shape, boundaryShape) {
    setZoneError('')
    const nextZones = draftZones.map((zone) => (
      sameId(zone.id, zoneId)
        ? {
          ...zone,
          zone_size: calculateArea(shape),
          geometry: shapeToGeometry(shape, boundaryShape),
        }
        : zone
    ))
    setDraftZones(nextZones)
    setDraftPlants((current) => reconcileMarkerPositions(draftPlot, nextZones, current))
  }

  function handleBoundaryCommit(nextBoundary, nextLayouts) {
    setSaveError('')
    setDraftPlot((current) => current ? {
      ...current,
      plot_size: calculateArea(nextBoundary),
      geometry: withPreservedMapGeometry(shapeToGeometry(nextBoundary), current.geometry),
    } : current)
    const nextPlot = draftPlot ? {
      ...draftPlot,
      plot_size: calculateArea(nextBoundary),
      geometry: withPreservedMapGeometry(shapeToGeometry(nextBoundary), draftPlot.geometry),
    } : draftPlot
    const nextZones = draftZones.map((zone) => {
      const nextShape = nextLayouts[String(zone.id)]

      if (!nextShape) {
        return zone
      }

      return {
        ...zone,
        zone_size: calculateArea(nextShape),
        geometry: shapeToGeometry(nextShape, nextBoundary),
      }
    })
    setDraftZones(nextZones)
    setDraftPlants((current) => reconcileMarkerPositions(nextPlot, nextZones, current))
  }

  async function handlePlantCreate(payload) {
    const clientId = createTempId('draft-plant', draftPlants)
    const nextPlant = {
      ...payload,
      id: clientId,
      client_id: clientId,
      fk_plant_zone_id: payload.fk_plant_zone_id,
    }

    setPlantError('')
    setDraftPlants((current) => [nextPlant, ...current])
    setToastMessage(`${payload.name} pridėtas į juodraštį.`)

    return nextPlant
  }

  function handlePlantDelete(plantId) {
    setPlantError('')
    setDraftPlants((current) => current.filter((plant) => !sameId(plant.id, plantId)))
    setToastMessage('Augalas pašalintas iš juodraščio.')
  }

  function handleMarkerPositionChange(plantId, position) {
    setDraftPlants((current) => current.map((plant) => (
      sameId(plant.id, plantId)
        ? { ...plant, marker_position_x: position.x, marker_position_y: position.y }
        : plant
    )))
  }

  function handleMarkerPositionReset(plantId) {
    setDraftPlants((current) => current.map((plant) => (
      sameId(plant.id, plantId)
        ? { ...plant, marker_position_x: null, marker_position_y: null }
        : plant
    )))
    setToastMessage('Atkurta automatinė augalo žymos pozicija.')
  }

  function resetDraftToPersisted() {
    if (!persistedWorkspace) {
      return
    }

    clearPlotWorkspaceDraft(plotId)
    setDraftPlot(persistedWorkspace.plot)
    setDraftZones(persistedWorkspace.zones)
    setDraftPlants(persistedWorkspace.plants)
    setSelectedZoneId(null)
    setZoneForm(emptyZoneForm)
    setActiveInspector(null)
    setBoundaryClosed(getMapBoundaryPoints(persistedWorkspace.plot.geometry).length >= 3)
    setZoneError('')
    setPlantError('')
    setSaveError('')
  }

  function handleDiscardDraft() {
    if (!isDirty) {
      return
    }

    const confirmed = window.confirm('Atmesti visus neišsaugotus sklypo pakeitimus?')

    if (!confirmed) {
      return
    }

    resetDraftToPersisted()
    setToastMessage('Neišsaugoti juodraščio pakeitimai atmesti.')
  }

  async function handleSave() {
    if (!draftPlot) {
      return
    }

    setSaveError('')
    setZoneError('')
    setPlantError('')

    const selectedZone = draftZones.find((zone) => sameId(zone.id, selectedZoneId)) ?? null
    const sanitizedPlotGeometry = assertSanitizedGeometryPayload('Sklypo geometrija', draftPlot.geometry ?? null)

    if (mapBoundaryPoints.length > 0 && (!boundaryClosed || mapBoundaryPoints.length < 3)) {
      setSaveError('Prieš išsaugodami sklypo pakeitimus uždarykite ribą žemėlapyje.')
      return
    }

    if (sanitizedPlotGeometry.error) {
      setSaveError(sanitizedPlotGeometry.error)
      return
    }

    const sanitizedZones = []

    for (const zone of draftZones) {
      const sanitizedZoneGeometry = assertSanitizedGeometryPayload(`Zonos „${zone.name}“ geometrija`, zone.geometry ?? null)

      if (sanitizedZoneGeometry.error) {
        setSaveError(sanitizedZoneGeometry.error)
        return
      }

      sanitizedZones.push({
        ...zone,
        geometry: sanitizedZoneGeometry.geometry,
      })
    }

    setSaving(true)

    try {
      const response = await api.commitPlotWorkspace(plotId, {
        plot: {
          plot_size: draftPlot.plot_size,
          geometry: sanitizedPlotGeometry.geometry,
        },
        zones: sanitizedZones.map((zone) => ({
          id: zone.id,
          client_id: zone.client_id ?? (typeof zone.id === 'string' ? zone.id : null),
          name: zone.name,
          zone_size: zone.zone_size,
          soil_type: zone.soil_type,
          rotation_stage: Number(zone.rotation_stage || 0),
          last_planting_date: zone.last_planting_date || null,
          color_hex: normalizeZoneColor(zone.color_hex),
          archived_at: zone.archived_at || null,
          geometry: zone.geometry ?? null,
        })),
        plants: draftPlants.map((plant) => ({
          id: plant.id,
          client_id: plant.client_id ?? (typeof plant.id === 'string' ? plant.id : null),
          name: plant.name,
          type: plant.type ?? null,
          condition: plant.condition,
          plant_date: plant.plant_date,
          variety: plant.variety || null,
          quantity: plant.quantity === '' ? null : plant.quantity ?? null,
          occupied_area: plant.occupied_area === '' ? null : plant.occupied_area ?? null,
          season: plant.season || null,
          harvest_date: plant.harvest_date || null,
          notes: plant.notes || null,
          marker_position_x: plant.marker_position_x ?? null,
          marker_position_y: plant.marker_position_y ?? null,
          disease: Boolean(plant.disease),
          disease_notes: plant.disease_notes || null,
          fk_catalog_plant_id: plant.fk_catalog_plant_id ?? null,
          fk_plant_zone_id: plant.fk_plant_zone_id,
        })),
      })

      // Refetch after a valid empty-success response rather than committing
      // undefined data to the mounted page.
      if (!response?.plot || !Array.isArray(response.zones) || !Array.isArray(response.plants)) {
        await pageState.reload()
        setToastMessage('Plot changes saved.')
        return
      }

      pageState.setData((current) => ({
        ...current,
        plot: response.plot,
        zones: response.zones,
        plants: response.plants,
      }))

      clearPlotWorkspaceDraft(plotId)

      const nextSelectedZone = response.zones.find((zone) => (
        sameId(zone.id, selectedZone?.id)
          || (selectedZone && typeof selectedZone.id === 'string' && zone.name === selectedZone.name)
      )) ?? response.zones[0] ?? null

      setDraftPlot({
        id: response.plot.id,
        name: response.plot.name,
        city: response.plot.city,
        share: Boolean(response.plot.share),
        plot_size: Number(response.plot.plot_size ?? 0),
        geometry: response.plot.geometry ?? null,
      })
      setDraftZones(response.zones.map((zone) => ({
        ...zone,
        client_id: null,
        zone_size: Number(zone.zone_size ?? 0),
      })))
      setDraftPlants(response.plants.map((plant) => ({
        ...plant,
        client_id: null,
        fk_plant_zone_id: plant.fk_plant_zone_id ?? plant.plant_zone_id ?? plant.plantZone?.id ?? plant.plant_zone?.id ?? null,
      })))
      setSelectedZoneId(nextSelectedZone?.id ?? null)
      setZoneForm(zoneToForm(nextSelectedZone))
      setActiveInspector(nextSelectedZone ? INSPECTOR_TYPES.zone : null)
      setBoundaryClosed(getMapBoundaryPoints(response.plot.geometry).length >= 3)
      setToastMessage(response.history_entry?.label ?? 'Sklypo pakeitimai išsaugoti.')
    } catch (requestError) {
      setSaveError(requestError.message)
    } finally {
      setSaving(false)
    }
  }

  if (pageState.loading || !draftReady) {
    return <LoadingState title="Įkeliamas sklypo redaktorius..." />
  }

  if (pageState.error) {
    return <ErrorState error={pageState.error} onRetry={pageState.reload} />
  }

  if (!pageState.data.plot || !draftPlot) {
    return <EmptyState title="Sklypas nerastas" description="Pasirinkto sklypo nepavyko įkelti." />
  }

  const selectedZone = draftZones.find((zone) => sameId(zone.id, selectedZoneId)) ?? null
  const selectedZonePlants = selectedZone
    ? draftPlants.filter((plant) => sameId(plant.fk_plant_zone_id, selectedZone.id))
    : []
  const selectedZoneShape = selectedZone && measurementState
    ? measurementState.layouts[String(selectedZone.id)] ?? measurementState.layouts[selectedZone.id]
    : null
  const selectedZoneMeasurements = selectedZoneShape ? buildShapeMetrics(selectedZoneShape) : null
  const formattedSelectedZoneMeasurements = selectedZoneMeasurements ? {
    area: formatSquareMeters(calculateArea(selectedZoneShape), 1),
    perimeter: formatMeters(selectedZoneMeasurements.perimeter ?? 0),
    sideSummary: selectedZoneMeasurements.sideSummary,
  } : null
  const editorLayers = [
    { id: 'boundary', label: 'Sklypo riba', active: Boolean(measurementState?.boundary), color: '#47633b' },
    { id: 'zones', label: `${visibleZones.length} zonos`, active: visibleZones.length > 0, color: '#b9683f' },
    { id: 'plants', label: `${visiblePlants.length} augalai`, active: visiblePlants.length > 0, color: '#237d52' },
    { id: 'measurements', label: 'Matmenys', active: true, color: '#ef6d22' },
  ]
  const boundaryeditorLayers = [
    { id: 'boundary', label: boundaryClosed ? 'Uždaryta riba' : 'Ribų juodraštis', active: mapBoundaryPoints.length > 0, color: '#47633b' },
    { id: 'corners', label: `${mapBoundaryPoints.length} kampai`, active: mapBoundaryPoints.length > 0, color: '#b9683f' },
    { id: 'center', label: mapBoundaryCenter ? 'Apskaičiuotas centras' : 'Centras laukia', active: Boolean(mapBoundaryCenter), color: '#237d52' },
  ]
  const zoneTimelineItems = visibleZones.slice(0, 5).map((zone) => ({
    id: zone.id,
    label: zone.name,
    meta: `${formatSquareMeters(zone.zone_size ?? 0, 1)} - ${formatSoilType(zone.soil_type)}`,
    tone: sameId(zone.id, selectedZoneId) ? 'amber' : 'leaf',
  }))

  return (
    <div className="page-stack workspace-page workspace-page--editor" data-testid="workspace-page">
      <PlotSectionNav
        plotId={plotId}
        plotName={pageState.data.plot.name}
        sectionKey="editor"
        isOwner={isOwner}
        compact
        meta={(
          <>
            <StatusBadge kind="ownership">{pageState.data.plot.city}</StatusBadge>
            <StatusBadge kind="selection" tone="neutral">{formatSquareMeters(draftPlot.plot_size, 1)}</StatusBadge>
          </>
        )}
        actions={(
          <>
            <Button variant="ghost" onClick={() => api.downloadPlotPdf(plotId, pageState.data.plot?.name)}>
              Eksportuoti PDF
            </Button>
            {canEdit ? (
              <Link to={`/plots/${plotId}/edit`}>
                <Button variant="ghost">Redaguoti metaduomenis</Button>
              </Link>
            ) : null}
            {canEdit ? (
              <Button variant="secondary" onClick={handleDiscardDraft} disabled={!isDirty || saving}>
                Atmesti juodraštį
              </Button>
            ) : null}
            {canEdit ? (
              <Button onClick={handleSave} loading={saving} disabled={!isDirty}>
                {saving ? 'Saugomi sklypo pakeitimai' : 'Išsaugoti sklypo pakeitimus'}
              </Button>
            ) : null}
          </>
        )}
      />

      {saveError ? <span className="field-error">{saveError}</span> : null}
      <SuccessToast message={toastMessage} onDismiss={() => setToastMessage('')} />

      {!canEdit ? (
        <EmptyState
          title="Tik peržiūros prieiga"
          description="Pasirinkite zonas plane, kad peržiūrėtumėte išdėstymą. Išsaugoti ir redaguoti juodraštį gali tik savininkai ir redaktoriai."
        />
      ) : null}

      <div
        className={[
          'plot-editor-layout',
          editorView === EDITOR_VIEWS.boundary ? 'plot-editor-layout--boundary' : 'plot-editor-layout--zones',
          activeUtilityPanel ? 'has-utility-panel' : '',
          activeInspector ? 'has-context-panel' : '',
        ].filter(Boolean).join(' ')}
      >
        <div className="plot-editor-view-toggle" aria-label="editor view">
          <PlotWorkspaceModeSwitch
            value={workspaceMode}
            onChange={handleWorkspaceModeChange}
            canEdit={canEdit}
          />
          <button
            type="button"
            className={`plot-panel-toggle ${editorView === EDITOR_VIEWS.boundary ? 'is-active' : ''}`.trim()}
            onClick={() => changeeditorView(EDITOR_VIEWS.boundary)}
            aria-pressed={editorView === EDITOR_VIEWS.boundary}
          >
            Ribų vaizdas
          </button>
          {canEdit ? (
            <div className="plot-history-controls" role="group" aria-label="Atšaukimas ir pakartojimas">
              <Button size="sm" variant="ghost" onClick={handleUndo} disabled={!historyState.canUndo || plotMode !== 'edit'}>Atšaukti</Button>
              <Button size="sm" variant="ghost" onClick={handleRedo} disabled={!historyState.canRedo || plotMode !== 'edit'}>Pakartoti</Button>
            </div>
          ) : null}
        </div>

        <div className="plot-workspace-panel-toggles" aria-label="Darbo srities paneliai">
          <button
            type="button"
            className={`plot-panel-toggle ${activeUtilityPanel === 'layers' ? 'is-active' : ''}`.trim()}
            onClick={() => setActiveUtilityPanel((current) => (current === 'layers' ? null : 'layers'))}
            aria-expanded={activeUtilityPanel === 'layers'}
            aria-controls="plot-layers-panel"
          >
            Rodiniai
          </button>
          <button
            type="button"
            className={`plot-panel-toggle ${activeInspector === INSPECTOR_TYPES.boundary ? 'is-active' : ''}`.trim()}
            onClick={() => {
              if (editorView !== EDITOR_VIEWS.boundary) {
                handleBoundarySelect()
                return
              }

              setActiveInspector((current) => (current === INSPECTOR_TYPES.boundary ? null : INSPECTOR_TYPES.boundary))
            }}
            aria-expanded={activeInspector === INSPECTOR_TYPES.boundary}
          >
            Ribų informacija
          </button>
          {editorView === EDITOR_VIEWS.zones ? (
            <button
              type="button"
              className={`plot-panel-toggle ${activeInspector === INSPECTOR_TYPES.zone && !selectedZone ? 'is-active' : ''}`.trim()}
              onClick={openNewZoneInspector}
              aria-expanded={activeInspector === INSPECTOR_TYPES.zone && !selectedZone}
            >
              Zonos duomenys
            </button>
          ) : null}
        </div>

        {activeUtilityPanel === 'layers' ? (
        <aside id="plot-layers-panel" className="plot-layers-panel" aria-label="Sklypo rodiniai ir objektai">
          <div className="plot-layers-panel-header">
            <div className="page-stack stack-sm">
              <span className="workspace-section-eyebrow">Rodiniai</span>
              <h2 className="section-title">Sklypo informacija</h2>
            </div>
            <div className="plot-floating-panel-actions">
              <StatusBadge kind="selection" tone={isDirty ? 'warning' : 'neutral'}>
                {isDirty ? 'Juodraštis' : 'Išsaugota'}
              </StatusBadge>
              <button
                type="button"
                className="plot-panel-close"
                onClick={() => setActiveUtilityPanel(null)}
                aria-label="Uždaryti rodinių panelį"
              >
                x
              </button>
            </div>
          </div>

          <MapLayerControl
            title="Matomi rodiniai"
            items={editorView === EDITOR_VIEWS.boundary ? boundaryeditorLayers : editorLayers}
            className="plot-editor-layer-console"
          />

          {editorView === EDITOR_VIEWS.zones ? (
            <PlotPlanControls
              options={viewOptions}
              onOptionsChange={setViewOptions}
              filters={filters}
              onFiltersChange={setFilters}
              plants={draftPlants}
              zones={visibleZones}
              onReset={() => setFilters({ year: '', season: '', plant: '', status: '' })}
              onSelectZone={handleZoneSelect}
            />
          ) : null}

          <div className="plot-layer-metrics">
            {editorView === EDITOR_VIEWS.boundary ? (
              <>
                <MeasurementBadge label="Plotas" value={formatSquareMeters(mapBoundaryArea, 1)} tone="field" />
                <MeasurementBadge label="Perimetras" value={formatMeters(mapBoundaryPerimeter)} tone="earth" />
                <MeasurementBadge label="Taškai" value={mapBoundaryPoints.length} tone="amber" className="measurement-badge-wide" />
              </>
            ) : (
              <>
                <MeasurementBadge label="Plotas" value={formatSquareMeters(calculateArea(measurementState?.boundary), 1)} tone="field" />
                <MeasurementBadge label="Perimetras" value={formatMeters(plotMeasurements?.perimeter ?? 0)} tone="earth" />
                <MeasurementBadge label="Kraštinės" value={plotMeasurements?.sideSummary || 'Geometrijos nėra'} tone="amber" className="measurement-badge-wide" />
              </>
            )}
          </div>

          {editorView === EDITOR_VIEWS.boundary ? (
          <div className="plot-layer-section">
            <div className="plot-layer-section-head">
              <strong>Ribų informacija</strong>
              <span>{mapBoundaryPoints.length}</span>
            </div>
            {mapBoundaryPoints.length > 0 ? (
              <div className="plot-boundary-point-list">
                {mapBoundaryPoints.map((point, index) => (
                  <button
                    key={`edit-boundary-point-${index}`}
                    type="button"
                    title={`Šalinti tašką ${index + 1}`}
                    onClick={() => handleBoundaryPointRemove(index)}
                    disabled={!canEdit || (boundaryClosed && mapBoundaryPoints.length <= 3)}
                  >
                    <span>{index + 1}</span>
                    <strong>{roundCoordinate(point.lat)}, {roundCoordinate(point.lng)}</strong>
                  </button>
                ))}
              </div>
            ) : (
              <EmptyStatePanel
                title="Riba žemėlapyje nenurodyta"
                description="Spustelėkite žemėlapį ir pridėkite bent tris ribos kampus."
                tone="subtle"
              />
            )}
          </div>
          ) : (
          <>
          <div className="plot-layer-section">
            <div className="plot-layer-section-head">
              <strong>Zonos</strong>
              <span>{visibleZones.length}</span>
            </div>
            {visibleZones.length > 0 ? (
              <div className="plot-layer-object-list" role="list">
                {visibleZones.map((zone, index) => {
                  const isSelected = sameId(zone.id, selectedZoneId)
                  const plantCount = draftPlants.filter((plant) => sameId(plant.fk_plant_zone_id, zone.id)).length

                  return (
                    <button
                      key={zone.id}
                      type="button"
                      className={`plot-layer-object ${isSelected ? 'is-selected' : ''}`.trim()}
                      onClick={() => handleZoneSelect(zone)}
                    >
                      <span className="plot-layer-object-index">{index + 1}</span>
                      <span className="plot-layer-object-copy">
                        <strong>{zone.name}</strong>
                        <small>{formatSquareMeters(zone.zone_size ?? 0, 1)} - {formatSoilType(zone.soil_type)}</small>
                      </span>
                      <span className="plot-layer-object-count">{plantCount}</span>
                    </button>
                  )
                })}
              </div>
            ) : (
              <EmptyStatePanel
                title="Zonų dar nėra"
                description="Pirmą auginimo zoną nubrėžkite tiesiai plane."
                tone="subtle"
              />
            )}
          </div>

          <div className="plot-layer-section">
            <div className="plot-layer-section-head">
              <strong>Planavimo seka</strong>
              <span>{zoneTimelineItems.length}</span>
            </div>
            <GardenTimeline items={zoneTimelineItems} emptyText="Zonų dar nenubrėžta." />
          </div>
          </>
          )}
        </aside>
        ) : null}

        <section className={`plot-editor-main ${editorView === EDITOR_VIEWS.boundary ? 'plot-editor-main--map' : ''}`.trim()}>
          {editorView === EDITOR_VIEWS.boundary ? (
            <>
            <PlotLocationMap
              mode="boundary"
              boundaryClosed={boundaryClosed}
              selectedLocation={mapBoundaryCenter}
              boundaryPoints={mapBoundaryPoints}
              view={mapPreviewView ?? {
                center: mapBoundaryCenter ?? DEFAULT_MAP_VIEW.center,
                zoom: draftPlot.geometry?.map?.zoom ?? DEFAULT_MAP_VIEW.zoom,
              }}
              readOnly={!canEditPlan}
              className="plot-location-map--workspace"
              onBoundaryPointAdd={handleBoundaryPointAdd}
              onBoundaryPointInsert={handleBoundaryPointInsert}
              onBoundaryPointMove={handleBoundaryPointMove}
              onBoundaryPointRemove={handleBoundaryPointRemove}
              onViewChange={setMapPreviewView}
            />
            {!boundaryClosed && mapBoundaryPoints.length >= 3 ? (
              <Button
                className="plot-mobile-floating-action plot-mobile-floating-action--boundary"
                aria-label="Greitai uždaryti ribą"
                onClick={handleBoundaryClose}
                disabled={!canEditPlan}
              >
                Uždaryti ribą
              </Button>
            ) : null}
            </>
          ) : (
          <>
          {hasActiveFilters && visibleZones.length === 0 ? (
            <div className="plot-filter-empty-state">
              <EmptyStatePanel title="Nėra filtro rezultatų" description={plotPlanText('noFilterResults')} tone="subtle" />
              <Button size="sm" variant="secondary" onClick={() => setFilters({ year: '', season: '', plant: '', status: '' })}>{plotPlanText('resetFilters')}</Button>
            </div>
          ) : null}
          <PlotDesignerCanvas
            ref={designerCanvasRef}
            plotId={plotId}
            plotName={pageState.data.plot.name}
            plotSize={draftPlot.plot_size}
            plotGeometry={draftPlot.geometry}
            zones={visibleZones}
            plants={visiblePlants}
            canEdit={canEditPlan}
            mobile={typeof window !== 'undefined' && window.matchMedia?.('(max-width: 768px)').matches}
            activeZoneId={selectedZoneId}
            persistState={false}
            showSaveAction={false}
            isLayoutSaveDisabled={!isDirty}
            isLayoutSaving={saving}
            layoutSaveFeedback={createEmptyFeedback()}
            showLayerConsole={false}
            mapFirstHud
            showPlantMarkers={viewOptions.showPlants}
            showZoneNames={viewOptions.showZoneNames}
            bordersOnly={viewOptions.bordersOnly}
            onMarkerPositionChange={handleMarkerPositionChange}
            onMarkerPositionReset={handleMarkerPositionReset}
            onSaveLayout={handleSave}
            onSelectZone={handleZoneSelect}
            onSelectBoundary={handleBoundarySelect}
            onCreateZone={handleCanvasZoneCreate}
            onZoneCreateBlokuota={setZoneError}
            onZoneGeometryCommit={handleZoneGeometryCommit}
            onBoundaryCommit={handleBoundaryCommit}
          />
          {canEdit ? (
            <Button
              className="plot-mobile-floating-action plot-mobile-floating-action--zone"
              aria-label="Greitai pridėti zoną"
              onClick={handleZoneCreateFromForm}
            >
              Pridėti zoną
            </Button>
          ) : null}
          </>
          )}
        </section>

        {activeInspector ? (
        <InspectorPanel
          title={activeInspector === INSPECTOR_TYPES.boundary ? 'Sklypo ribų informacija' : selectedZone ? 'Zonos informacija' : 'Zonos juodraštis'}
          description={activeInspector === INSPECTOR_TYPES.boundary
            ? 'Sklypo riba, išsaugota žemėlapio peržiūra ir viso sklypo matmenys.'
            : 'Zonos duomenys, matmenys, augalų išdėstymas ir juodraščio pakeitimai.'}
          meta={(
            <div className="plot-floating-panel-actions">
              <StatusBadge kind="selection" tone={selectedZone || activeInspector === INSPECTOR_TYPES.boundary ? 'soft' : 'neutral'}>
                {activeInspector === INSPECTOR_TYPES.boundary ? 'Pasirinkta riba' : selectedZone ? 'Pasirinkta zona' : 'Juodraštis'}
              </StatusBadge>
              <button
                type="button"
                className="plot-panel-close"
                onClick={() => handleZoneSelect(null)}
                aria-label="Uždaryti inspektorių"
              >
                x
              </button>
            </div>
          )}
          className="plot-context-rail"
        >
          {activeInspector === INSPECTOR_TYPES.boundary ? (
          <>
          {editorView === EDITOR_VIEWS.boundary ? (
            <>
          <InspectorSection
            title="Ribų informacija"
            description="Žemėlapio ribos juodraščio matmenys atnaujinami judinant kampus."
            meta={(
              <StatusBadge kind="selection" tone={boundaryClosed ? 'success' : 'warning'}>
                {boundaryClosed ? 'Uždaryta' : 'Braižoma'}
              </StatusBadge>
            )}
          >
            <div className="plot-layer-metrics">
              <MeasurementBadge label="Plotas" value={formatSquareMeters(mapBoundaryArea, 1)} tone="field" />
              <MeasurementBadge label="Perimetras" value={formatMeters(mapBoundaryPerimeter)} tone="earth" />
              <MeasurementBadge label="Taškai" value={mapBoundaryPoints.length} tone="amber" className="measurement-badge-wide" />
            </div>
            <div className="plot-boundary-center-readout">
              <span className="designer-toolbar-kicker">Centras</span>
              <strong>
                {mapBoundaryCenter
                  ? `${roundCoordinate(mapBoundaryCenter.lat)}, ${roundCoordinate(mapBoundaryCenter.lng)}`
                  : 'Apskaičiuojama po 3 taškų'}
              </strong>
            </div>
          </InspectorSection>

          <InspectorSection
            title="Ribų valdikliai"
            description="Kampų pakeitimai lieka šiame juodraštyje iki sklypo pakeitimų išsaugojimo."
          >
            <div className="form-actions">
              <Button
                variant="secondary"
                onClick={handleBoundaryClose}
                disabled={!canEdit || boundaryClosed || mapBoundaryPoints.length < 3}
              >
                Uždaryti ribą
              </Button>
              <Button
                variant="ghost"
                onClick={handleBoundaryUndo}
                disabled={!canEdit || !mapBoundaryPoints.length || (boundaryClosed && mapBoundaryPoints.length <= 3)}
              >
                Atšaukti
              </Button>
            </div>
            {mapBoundaryPoints.length < 3 ? (
              <span className="field-error">Prieš išsaugodami pridėkite bent 3 ribos taškus.</span>
            ) : null}
          </InspectorSection>
            </>
          ) : (
          <>
          {mapBoundaryPoints.length >= 3 ? (
            <InspectorSection
              title="Ribų vaizdas"
              description="Išsaugotos sklypo ribos peržiūra su žemėlapio taškais ir kraštinių matmenimis."
              meta={(
                <StatusBadge kind="selection" tone="soft">
                  {mapBoundaryPoints.length} taškai
                </StatusBadge>
              )}
            >
              <PlotLocationMap
                mode="preview"
                boundaryClosed
                boundaryPoints={mapBoundaryPoints}
                selectedLocation={mapBoundaryCenter}
                fitBoundary
                view={mapPreviewView ?? {
                  center: mapBoundaryCenter,
                  zoom: draftPlot.geometry?.map?.zoom ?? 13,
                }}
                readOnly
                className="plot-location-map--compact"
                onViewChange={setMapPreviewView}
              />
            </InspectorSection>
          ) : null}

          <InspectorSection
            title="Sklypo matmenys"
            description="Šios geometrijos reikšmės atnaujinamos pakeitus ribą plane."
          >
            <div className="plot-layer-metrics">
              <MeasurementBadge label="Plotas" value={formatSquareMeters(calculateArea(measurementState?.boundary), 1)} tone="field" />
              <MeasurementBadge label="Perimetras" value={formatMeters(plotMeasurements?.perimeter ?? 0)} tone="earth" />
              <MeasurementBadge label="Kraštinės" value={plotMeasurements?.sideSummary || 'Geometrijos nėra'} tone="amber" className="measurement-badge-wide" />
            </div>
          </InspectorSection>
          </>
          )}
          </>
          ) : (
          <>
          <InspectorSection
            title="Pasirinkta zona"
            description="Geometrija, matmenys, dirvožemis ir augalų kiekis pateikiami kartu."
            meta={(
              <StatusBadge kind="selection" tone={selectedZone ? 'soft' : 'neutral'}>
                {selectedZone ? 'Aktyvi' : 'Nėra'}
              </StatusBadge>
            )}
          >
            {selectedZone ? (
              <details className="plot-zone-quick-actions">
                <summary aria-label="Zonos veiksmai">⋯</summary>
                <div role="menu">
                  <button type="button" onClick={() => setActiveInspector(INSPECTOR_TYPES.zone)}>{plotPlanText('viewInformation')}</button>
                  {canEditPlan ? <button type="button" onClick={() => document.getElementById('zone-name')?.focus()}>{plotPlanText('editZone')}</button> : null}
                  {canEditPlan ? <button type="button" onClick={() => document.querySelector('.zone-color-control input[type="text"]')?.focus()}>{plotPlanText('changeColor')}</button> : null}
                  {canEditPlan ? <button type="button" onClick={handleAddPlantQuickAction}>{plotPlanText('addPlant')}</button> : null}
                  {canEditPlan ? <button type="button" onClick={handleZoneDuplicate}>{plotPlanText('duplicateZone')}</button> : null}
                  {canEditPlan ? <button type="button" onClick={handleZoneArchive}>{plotPlanText('archiveZone')}</button> : null}
                  {canEditPlan ? <button type="button" className="is-danger" onClick={handleZoneDelete}>{plotPlanText('deleteZone')}</button> : null}
                </div>
              </details>
            ) : null}
            <ZoneInspector
              zone={selectedZone}
              measurements={formattedSelectedZoneMeasurements}
              plantCount={selectedZonePlants.length}
              emptyTitle="Pasirinkite arba nubrėžkite zoną"
              emptyDescription="Pasirinkite zoną plane, kad galėtumėte redaguoti jos duomenis ir sodinti tiesiai į ją."
            />
          </InspectorSection>

          <InspectorSection
            title={selectedZone ? 'Zonos duomenys' : 'Naujos zonos juodraštis'}
            description="Prieš išsaugodami visą sklypą pritaikykite zonos duomenų pakeitimus juodraščiui."
          >
            <ZoneColorControl
              value={zoneForm.color_hex}
              onChange={(color_hex) => setZoneForm((current) => ({ ...current, color_hex }))}
              usedColors={draftZones.filter((zone) => !sameId(zone.id, selectedZoneId) && !zone.archived_at).map((zone) => zone.color_hex)}
              disabled={!canEditPlan}
            />
            <form className="input-grid" onSubmit={handleZoneApply}>
              <div className="field field-span-2">
                <label htmlFor="zone-name">Zonos pavadinimas</label>
                <input
                  id="zone-name"
                  value={zoneForm.name}
                  onChange={(event) => setZoneForm((current) => ({ ...current, name: event.target.value }))}
                  disabled={!canEditPlan}
                />
              </div>
              <div className="field">
                <label htmlFor="zone-soil-type">Dirvožemio tipas</label>
                <select
                  id="zone-soil-type"
                  value={zoneForm.soil_type}
                  onChange={(event) => setZoneForm((current) => ({ ...current, soil_type: event.target.value }))}
                  disabled={!canEditPlan}
                >
                  {SOIL_TYPES.map((soilType) => (
                    <option key={soilType} value={soilType}>{formatSoilType(soilType)}</option>
                  ))}
                </select>
              </div>
              <div className="field">
                <label htmlFor="zone-rotation-stage">Rotacijos etapas</label>
                <input
                  id="zone-rotation-stage"
                  type="number"
                  min="0"
                  step="1"
                  value={zoneForm.rotation_stage}
                  onChange={(event) => setZoneForm((current) => ({ ...current, rotation_stage: event.target.value }))}
                  disabled={!canEditPlan}
                />
              </div>
              <details className="advanced-zone-details field-span-2" open={Boolean(zoneForm.last_planting_date)}>
                <summary>Papildomi sodinimo duomenys</summary>
                <div className="field">
                  <label htmlFor="zone-last-planting-date">Paskutinė sodinimo data</label>
                  <input
                    id="zone-last-planting-date"
                    type="date"
                    value={zoneForm.last_planting_date}
                    onChange={(event) => setZoneForm((current) => ({ ...current, last_planting_date: event.target.value }))}
                    disabled={!canEditPlan}
                  />
                </div>
              </details>

              {zoneError ? <span className="field-error">{zoneError}</span> : null}

              <div className="form-actions">
                {selectedZone ? (
                  <>
                    <Button type="submit" variant="secondary" disabled={!canEditPlan}>Pritaikyti zonos duomenis</Button>
                    <Button variant="ghost" onClick={openNewZoneInspector} disabled={!canEditPlan}>Naujos zonos juodraštis</Button>
                    <Button variant="danger" onClick={handleZoneDelete} disabled={!canEditPlan}>Šalinti zoną</Button>
                  </>
                ) : (
                  <>
                    <Button onClick={handleZoneCreateFromForm} variant="secondary" disabled={!canEditPlan}>Pridėti zoną į juodraštį</Button>
                    <Button variant="ghost" onClick={() => setZoneForm(emptyZoneForm)}>Išvalyti formą</Button>
                  </>
                )}
              </div>
            </form>
          </InspectorSection>

          <InspectorSection
            title="Augalai zonoje"
            description="Sodinimas lieka susietas su pasirinkta zona ir išsaugomas kartu su išdėstymu."
          >
            {selectedZone ? (
              selectedZonePlants.length > 0 ? (
                <div className="plot-zone-plant-list">
                  {selectedZonePlants.map((plant) => (
                    <div key={plant.id} className="plot-zone-plant-card">
                      <div className="plot-zone-plant-copy">
                        <strong>{plant.name}</strong>
                        <DefinitionList
                          items={[
                            {
                              label: 'Katalogas',
                              value: plant.catalog_plant?.name ?? plant.catalogPlant?.name ?? plant.type ?? 'Rankinis augalas',
                            },
                          ]}
                        />
                      </div>
                      <PlantStatusBadge status={plant.condition} careLinked={plant.fk_catalog_plant_id !== null} />
                      <div className="plot-zone-plant-actions">
                        {Number.isFinite(Number(plant.id)) ? (
                          <Link to={`/plots/${plotId}/plants/${plant.id}`}>
                            <Button variant="ghost" size="sm">Atidaryti</Button>
                          </Link>
                        ) : null}
                        {canEditPlan ? (
                          <Button variant="ghost" size="sm" onClick={() => handlePlantDelete(plant.id)}>Šalinti</Button>
                        ) : null}
                      </div>
                    </div>
                  ))}
                </div>
              ) : (
                <EmptyStatePanel
                  title="Augalų dar nepasodinta"
                  description="Naudokite žemiau esantį sodinimo procesą, kad pridėtumėte pirmą augalą į pasirinktą zoną."
                  tone="subtle"
                />
              )
            ) : (
              <EmptyStatePanel
                title="Reikia pasirinkti zoną"
                description="Prieš sodindami augalus pasirinkite zoną, kad kitas žingsnis būtų aiškus."
                tone="subtle"
              />
            )}
          </InspectorSection>

          {plantError ? <span className="field-error">{plantError}</span> : null}
          <PlotPlantingDrawer
            selectedZone={selectedZone}
            canEdit={canEditPlan}
            busy={saving}
            onCreatePlant={handlePlantCreate}
          />

          </>
          )}
        </InspectorPanel>
        ) : null}
      </div>
    </div>
  )
}
