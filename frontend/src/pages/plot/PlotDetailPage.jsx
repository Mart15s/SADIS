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
import PlotSectionNav from '../../components/plot/PlotSectionNav.jsx'
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
import { SOIL_TYPES } from '../../lib/constants.js'
import { useAsyncData } from '../../lib/hooks/useAsyncData.js'
import { useUnsavedChangesGuard } from '../../lib/hooks/useUnsavedChangesGuard.js'
import {
  clearPlotWorkspaceDraft,
  createWorkspaceClientId,
  createPlotWorkspaceSignature,
  loadPlotWorkspaceDraft,
  savePlotWorkspaceDraft,
} from '../../lib/plotWorkspaceDraft.js'
import { buildDesignerStateFromPersistence, calculateArea, shapeToGeometry } from '../../lib/plotDesigner.js'
import { assertSanitizedGeometryPayload } from '../../lib/plotGeometry.js'
import { calculateLatLngCenter } from '../../lib/geoMeasurements.js'
import { buildShapeMetrics, formatMeters, formatSquareMeters } from '../../lib/plotMeasurements.js'

const emptyZoneForm = {
  name: '',
  soil_type: SOIL_TYPES[0],
  rotation_stage: 0,
  last_planting_date: '',
}

function zoneToForm(zone) {
  return {
    name: zone?.name ?? '',
    soil_type: zone?.soil_type ?? SOIL_TYPES[0],
    rotation_stage: zone?.rotation_stage ?? 0,
    last_planting_date: zone?.last_planting_date ?? '',
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
    })),
    plants: data.plants.map((plant) => ({
      id: plant.id,
      client_id: null,
      name: plant.name,
      type: plant.type ?? null,
      condition: plant.condition,
      plant_date: plant.plant_date,
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
        plot,
        zones,
        plants,
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

  useUnsavedChangesGuard({
    when: isDirty,
    message: 'You have unsaved plot changes. Leave without saving this draft?',
  })

  useEffect(() => {
    if (!persistedWorkspace) {
      return
    }

    const restoredDraft = loadPlotWorkspaceDraft(plotId, persistedSignature)
    const nextWorkspace = restoredDraft ?? persistedWorkspace
    const nextSelectedZone = nextWorkspace.zones.find((zone) => sameId(zone.id, selectedZoneId))

    setDraftPlot(nextWorkspace.plot)
    setDraftZones(nextWorkspace.zones)
    setDraftPlants(nextWorkspace.plants)
    setSelectedZoneId(nextSelectedZone?.id ?? nextWorkspace.zones[0]?.id ?? null)
    setZoneForm(zoneToForm(nextSelectedZone ?? nextWorkspace.zones[0] ?? null))
    setDraftReady(true)
    setZoneError('')
    setPlantError('')
    setSaveError('')
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

  function handleZoneSelect(zone) {
    if (!zone) {
      setSelectedZoneId(null)
      setZoneForm(emptyZoneForm)
      return
    }

    setSelectedZoneId(zone.id)
    setZoneForm(zoneToForm(zone))
  }

  async function handleCanvasZoneCreate(shape, boundaryShape) {
    const clientId = createTempId('draft-zone', draftZones)
    const createdZone = {
      id: clientId,
      client_id: clientId,
      name: zoneForm.name.trim() || `Zone ${draftZones.length + 1}`,
      zone_size: calculateArea(shape),
      soil_type: zoneForm.soil_type,
      rotation_stage: Number(zoneForm.rotation_stage || 0),
      last_planting_date: zoneForm.last_planting_date || '',
      geometry: shapeToGeometry(shape, boundaryShape),
    }

    setZoneError('')
    setDraftZones((current) => [...current, createdZone])
    setSelectedZoneId(createdZone.id)
    setZoneForm(zoneToForm(createdZone))
    setToastMessage(`Added ${createdZone.name} to the draft.`)

    return createdZone
  }

  async function handleZoneCreateFromForm() {
    if (!designerCanvasRef.current?.createZoneFromForm) {
      setZoneError('The plot designer is still loading. Please try again.')
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

    setZoneError('')
    setDraftZones((current) => current.map((zone) => (
      sameId(zone.id, selectedZoneId)
        ? {
          ...zone,
          name: zoneForm.name.trim() || zone.name,
          soil_type: zoneForm.soil_type,
          rotation_stage: Number(zoneForm.rotation_stage || 0),
          last_planting_date: zoneForm.last_planting_date || '',
        }
        : zone
    )))
    setToastMessage('Zone details updated in the draft.')
  }

  function handleZoneDelete() {
    if (!selectedZoneId) {
      return
    }

    const plantsInZone = draftPlants.filter((plant) => sameId(plant.fk_plant_zone_id, selectedZoneId))

    if (plantsInZone.length > 0) {
      setZoneError('Remove the plants in this zone before deleting it from the draft.')
      return
    }

    setZoneError('')
    setDraftZones((current) => current.filter((zone) => !sameId(zone.id, selectedZoneId)))
    setSelectedZoneId(null)
    setZoneForm(emptyZoneForm)
    setToastMessage('Zone removed from the draft.')
  }

  function handleZoneGeometryCommit(zoneId, shape, boundaryShape) {
    setZoneError('')
    setDraftZones((current) => current.map((zone) => (
      sameId(zone.id, zoneId)
        ? {
          ...zone,
          zone_size: calculateArea(shape),
          geometry: shapeToGeometry(shape, boundaryShape),
        }
        : zone
    )))
  }

  function handleBoundaryCommit(nextBoundary, nextLayouts) {
    setSaveError('')
    setDraftPlot((current) => current ? {
      ...current,
      plot_size: calculateArea(nextBoundary),
      geometry: withPreservedMapGeometry(shapeToGeometry(nextBoundary), current.geometry),
    } : current)
    setDraftZones((current) => current.map((zone) => {
      const nextShape = nextLayouts[String(zone.id)]

      if (!nextShape) {
        return zone
      }

      return {
        ...zone,
        zone_size: calculateArea(nextShape),
        geometry: shapeToGeometry(nextShape, nextBoundary),
      }
    }))
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
    setToastMessage(`Added ${payload.name} to the draft.`)

    return nextPlant
  }

  function handlePlantDelete(plantId) {
    setPlantError('')
    setDraftPlants((current) => current.filter((plant) => !sameId(plant.id, plantId)))
    setToastMessage('Plant removed from the draft.')
  }

  function resetDraftToPersisted() {
    if (!persistedWorkspace) {
      return
    }

    clearPlotWorkspaceDraft(plotId)
    setDraftPlot(persistedWorkspace.plot)
    setDraftZones(persistedWorkspace.zones)
    setDraftPlants(persistedWorkspace.plants)
    setSelectedZoneId(persistedWorkspace.zones[0]?.id ?? null)
    setZoneForm(zoneToForm(persistedWorkspace.zones[0] ?? null))
    setZoneError('')
    setPlantError('')
    setSaveError('')
  }

  function handleDiscardDraft() {
    if (!isDirty) {
      return
    }

    const confirmed = window.confirm('Discard all unsaved plot changes?')

    if (!confirmed) {
      return
    }

    resetDraftToPersisted()
    setToastMessage('Unsaved draft changes were discarded.')
  }

  async function handleSave() {
    if (!draftPlot) {
      return
    }

    setSaveError('')
    setZoneError('')
    setPlantError('')

    const selectedZone = draftZones.find((zone) => sameId(zone.id, selectedZoneId)) ?? null
    const sanitizedPlotGeometry = assertSanitizedGeometryPayload('Plot geometry', draftPlot.geometry ?? null)

    if (sanitizedPlotGeometry.error) {
      setSaveError(sanitizedPlotGeometry.error)
      return
    }

    const sanitizedZones = []

    for (const zone of draftZones) {
      const sanitizedZoneGeometry = assertSanitizedGeometryPayload(`Zone "${zone.name}" geometry`, zone.geometry ?? null)

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
          geometry: zone.geometry ?? null,
        })),
        plants: draftPlants.map((plant) => ({
          id: plant.id,
          client_id: plant.client_id ?? (typeof plant.id === 'string' ? plant.id : null),
          name: plant.name,
          type: plant.type ?? null,
          condition: plant.condition,
          plant_date: plant.plant_date,
          disease: Boolean(plant.disease),
          disease_notes: plant.disease_notes || null,
          fk_catalog_plant_id: plant.fk_catalog_plant_id ?? null,
          fk_plant_zone_id: plant.fk_plant_zone_id,
        })),
      })

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
      setToastMessage(response.history_entry?.label ?? 'Plot changes saved.')
    } catch (requestError) {
      setSaveError(requestError.message)
    } finally {
      setSaving(false)
    }
  }

  if (pageState.loading || !draftReady) {
    return <LoadingState title="Loading plot editor..." />
  }

  if (pageState.error) {
    return <ErrorState error={pageState.error} onRetry={pageState.reload} />
  }

  if (!pageState.data.plot || !draftPlot) {
    return <EmptyState title="Plot not found" description="The requested plot could not be loaded." />
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
    { id: 'boundary', label: 'Plot boundary', active: Boolean(measurementState?.boundary), color: '#47633b' },
    { id: 'zones', label: `${draftZones.length} zones`, active: draftZones.length > 0, color: '#b9683f' },
    { id: 'plants', label: `${draftPlants.length} plants`, active: draftPlants.length > 0, color: '#237d52' },
    { id: 'measurements', label: 'Dimensions', active: true, color: '#ef6d22' },
  ]
  const zoneTimelineItems = draftZones.slice(0, 5).map((zone) => ({
    id: zone.id,
    label: zone.name,
    meta: `${formatSquareMeters(zone.zone_size ?? 0, 1)} - ${zone.soil_type}`,
    tone: sameId(zone.id, selectedZoneId) ? 'amber' : 'leaf',
  }))

  return (
    <div className="page-stack workspace-page workspace-page--editor" data-testid="workspace-page">
      <PlotSectionNav
        plotId={plotId}
        plotName={pageState.data.plot.name}
        sectionKey="editor"
        isOwner={isOwner}
        description="Edit the plot in draft first, keep the canvas dominant, and commit one clean saved version only when the workspace is ready."
        meta={(
          <>
            <StatusBadge kind="ownership">{pageState.data.plot.city}</StatusBadge>
            <StatusBadge kind="selection" tone="neutral">{formatSquareMeters(draftPlot.plot_size, 1)}</StatusBadge>
            <StatusBadge kind="status" tone={canEdit ? 'success' : 'warning'}>{pageState.data.accessRole ?? 'viewer'}</StatusBadge>
            <StatusBadge kind="connection" tone={isDirty ? 'warning' : 'neutral'}>
              {isDirty ? 'Unsaved draft' : 'Saved'}
            </StatusBadge>
          </>
        )}
        actions={(
          <>
            <Button variant="ghost" onClick={() => api.downloadPlotPdf(plotId, pageState.data.plot?.name)}>
              Export PDF
            </Button>
            {canEdit ? (
              <Link to={`/plots/${plotId}/edit`}>
                <Button variant="ghost">Edit metadata</Button>
              </Link>
            ) : null}
            {canEdit ? (
              <Button variant="secondary" onClick={handleDiscardDraft} disabled={!isDirty || saving}>
                Discard draft
              </Button>
            ) : null}
            {canEdit ? (
              <Button onClick={handleSave} loading={saving} disabled={!isDirty}>
                {saving ? 'Saving plot changes' : 'Save plot changes'}
              </Button>
            ) : null}
          </>
        )}
      />

      {saveError ? <span className="field-error">{saveError}</span> : null}
      <SuccessToast message={toastMessage} onDismiss={() => setToastMessage('')} />

      <section className="plot-editor-intro">
        <div className="plot-editor-intro-copy">
          <span className="workspace-section-eyebrow">Draft editing</span>
          <h2 className="workspace-overview-title">Plot plan is the primary work surface.</h2>
          <p className="section-copy">
            Zone movement, geometry changes, and plant placement stay in draft until you commit them. The map, dimensions, and inspector stay visible as editor controls.
          </p>
        </div>
        <div className="plot-editor-intro-stats">
          <MeasurementBadge label="Plot area" value={formatSquareMeters(calculateArea(measurementState?.boundary), 1)} tone="field" />
          <MeasurementBadge label="Plot perimeter" value={formatMeters(plotMeasurements?.perimeter ?? 0)} tone="earth" />
          <MeasurementBadge label="Side lengths" value={plotMeasurements?.sideSummary || 'No side lengths available.'} tone="amber" className="measurement-badge-wide" />
        </div>
      </section>

      <MapLayerControl title="Editor layers" items={editorLayers} className="plot-editor-layer-console" />

      {!canEdit ? (
        <EmptyState
          title="Read-only editor access"
          description="Select zones on the canvas to inspect the layout. Saving and draft editing are reserved for owners and editors."
        />
      ) : null}

      <div className="plot-editor-layout">
        <section className="plot-editor-main">
          <PlotDesignerCanvas
            ref={designerCanvasRef}
            plotId={plotId}
            plotName={pageState.data.plot.name}
            plotSize={draftPlot.plot_size}
            plotGeometry={draftPlot.geometry}
            zones={draftZones}
            plants={draftPlants}
            canEdit={canEdit}
            activeZoneId={selectedZoneId}
            persistState={false}
            showSaveAction={false}
            isLayoutSaveDisabled={!isDirty}
            isLayoutSaving={saving}
            layoutSaveFeedback={createEmptyFeedback()}
            onSaveLayout={handleSave}
            onSelectZone={handleZoneSelect}
            onCreateZone={handleCanvasZoneCreate}
            onZoneCreateBlocked={setZoneError}
            onZoneGeometryCommit={handleZoneGeometryCommit}
            onBoundaryCommit={handleBoundaryCommit}
          />
        </section>

        <InspectorPanel
          title="Plot inspector"
          description="Selection, metrics, plant placement, and advanced zone details stay in one rail."
          meta={<StatusBadge kind="selection" tone={selectedZone ? 'soft' : 'neutral'}>{selectedZone ? 'Zone selected' : 'No selection'}</StatusBadge>}
          className="plot-context-rail"
        >
          {mapBoundaryPoints.length >= 3 ? (
            <InspectorSection
              title="Boundary map"
              description="Saved plot boundary preview with map points and side measurements."
              meta={(
                <StatusBadge kind="selection" tone="soft">
                  {mapBoundaryPoints.length} points
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
            title="Selected zone"
            description="Geometry, dimensions, soil, and plant count stay together."
            meta={(
              <StatusBadge kind="selection" tone={selectedZone ? 'soft' : 'neutral'}>
                {selectedZone ? 'Active' : 'None'}
              </StatusBadge>
            )}
          >
            <ZoneInspector
              zone={selectedZone}
              measurements={formattedSelectedZoneMeasurements}
              plantCount={selectedZonePlants.length}
              emptyTitle="Select or draw a zone"
              emptyDescription="Choose a zone on the canvas to edit its details and plant directly into it."
            />
          </InspectorSection>

          <InspectorSection
            title={selectedZone ? 'Zone details' : 'New zone draft'}
            description="Apply detail changes to the draft before the main plot save."
          >
            <form className="input-grid" onSubmit={handleZoneApply}>
              <div className="field field-span-2">
                <label htmlFor="zone-name">Zone name</label>
                <input
                  id="zone-name"
                  value={zoneForm.name}
                  onChange={(event) => setZoneForm((current) => ({ ...current, name: event.target.value }))}
                  disabled={!canEdit}
                />
              </div>
              <div className="field">
                <label htmlFor="zone-soil-type">Soil type</label>
                <select
                  id="zone-soil-type"
                  value={zoneForm.soil_type}
                  onChange={(event) => setZoneForm((current) => ({ ...current, soil_type: event.target.value }))}
                  disabled={!canEdit}
                >
                  {SOIL_TYPES.map((soilType) => (
                    <option key={soilType} value={soilType}>{soilType}</option>
                  ))}
                </select>
              </div>
              <div className="field">
                <label htmlFor="zone-rotation-stage">Rotation stage</label>
                <input
                  id="zone-rotation-stage"
                  type="number"
                  min="0"
                  step="1"
                  value={zoneForm.rotation_stage}
                  onChange={(event) => setZoneForm((current) => ({ ...current, rotation_stage: event.target.value }))}
                  disabled={!canEdit}
                />
              </div>
              <details className="advanced-zone-details field-span-2" open={Boolean(zoneForm.last_planting_date)}>
                <summary>Optional planting data</summary>
                <div className="field">
                  <label htmlFor="zone-last-planting-date">Last planting date</label>
                  <input
                    id="zone-last-planting-date"
                    type="date"
                    value={zoneForm.last_planting_date}
                    onChange={(event) => setZoneForm((current) => ({ ...current, last_planting_date: event.target.value }))}
                    disabled={!canEdit}
                  />
                </div>
              </details>

              {zoneError ? <span className="field-error">{zoneError}</span> : null}

              <div className="form-actions">
                {selectedZone ? (
                  <>
                    <Button type="submit" variant="secondary">Apply zone details</Button>
                    <Button variant="ghost" onClick={() => handleZoneSelect(null)}>New zone draft</Button>
                    <Button variant="danger" onClick={handleZoneDelete}>Delete zone</Button>
                  </>
                ) : (
                  <>
                    <Button onClick={handleZoneCreateFromForm} variant="secondary">Add zone to draft</Button>
                    <Button variant="ghost" onClick={() => setZoneForm(emptyZoneForm)}>Clear form</Button>
                  </>
                )}
              </div>
            </form>
          </InspectorSection>

          <InspectorSection
            title="Plants in zone"
            description="Placement stays attached to the selected zone and saves with the layout."
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
                              label: 'Catalog',
                              value: plant.catalog_plant?.name ?? plant.catalogPlant?.name ?? plant.type ?? 'Manual plant',
                            },
                          ]}
                        />
                      </div>
                      <PlantStatusBadge status={plant.condition} careLinked={plant.fk_catalog_plant_id !== null} />
                      <div className="plot-zone-plant-actions">
                        {Number.isFinite(Number(plant.id)) ? (
                          <Link to={`/plots/${plotId}/plants/${plant.id}`}>
                            <Button variant="ghost" size="sm">Open</Button>
                          </Link>
                        ) : null}
                        {canEdit ? (
                          <Button variant="ghost" size="sm" onClick={() => handlePlantDelete(plant.id)}>Remove</Button>
                        ) : null}
                      </div>
                    </div>
                  ))}
                </div>
              ) : (
                <EmptyStatePanel
                  title="No plants placed yet"
                  description="Use the placement flow below to add the first plant into this selected zone."
                  tone="subtle"
                />
              )
            ) : (
              <EmptyStatePanel
                title="Zone required"
                description="Select a zone before placing plants so the next step is always clear."
                tone="subtle"
              />
            )}
          </InspectorSection>

          {plantError ? <span className="field-error">{plantError}</span> : null}
          <PlotPlantingDrawer
            selectedZone={selectedZone}
            canEdit={canEdit}
            busy={saving}
            onCreatePlant={handlePlantCreate}
          />

          <InspectorSection
            title="Zone sequence"
            description="Quick map-oriented summary of the current draft zones."
          >
            <GardenTimeline items={zoneTimelineItems} emptyText="No zones have been drawn yet." />
          </InspectorSection>
        </InspectorPanel>
      </div>
    </div>
  )
}
