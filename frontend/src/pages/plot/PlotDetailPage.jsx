import React, { useEffect, useRef, useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import PlotDesignerCanvas from '../../components/plot/PlotDesignerCanvas.jsx'
import PlotPlantingDrawer from '../../components/plot/PlotPlantingDrawer.jsx'
import PageHeader from '../../components/layout/PageHeader.jsx'
import {
  EmptyState,
  ErrorState,
  LoadingState,
  ProcessingState,
  SuccessToast,
} from '../../components/shared/StatusView.jsx'
import Button from '../../components/ui/Button.jsx'
import EmptyStatePanel from '../../components/ui/EmptyStatePanel.jsx'
import StatusBadge from '../../components/ui/StatusBadge.jsx'
import { api } from '../../lib/api.js'
import {
  ACCESS_ROLES,
  SOIL_TYPES,
  formatDate,
  safeNumber,
} from '../../lib/constants.js'
import { useAsyncData } from '../../lib/hooks/useAsyncData.js'
import { calculateArea, geometryEquals, shapeToGeometry } from '../../lib/plotDesigner.js'

const emptyZoneForm = {
  name: '',
  zone_size: '',
  soil_type: SOIL_TYPES[0],
  rotation_stage: 0,
  last_planting_date: '',
}

function zoneToForm(zone) {
  return {
    name: zone.name ?? '',
    zone_size: zone.zone_size ?? '',
    soil_type: zone.soil_type ?? SOIL_TYPES[0],
    rotation_stage: zone.rotation_stage ?? 0,
    last_planting_date: zone.last_planting_date ?? '',
  }
}

const emptyRotationPlanForm = {
  planning_date: new Date().toISOString().slice(0, 10),
}

const emptyShareForm = {
  recipient_email: '',
  role: ACCESS_ROLES[0],
}

export default function PlotDetailPage() {
  const { plotId } = useParams()
  const designerCanvasRef = useRef(null)
  const [selectedZoneId, setSelectedZoneId] = useState(null)
  const [zoneForm, setZoneForm] = useState(emptyZoneForm)
  const [rotationPlanForm, setRotationPlanForm] = useState(emptyRotationPlanForm)
  const [rotationDraft, setRotationDraft] = useState(null)
  const [shareForm, setShareForm] = useState(emptyShareForm)
  const [zoneError, setZoneError] = useState('')
  const [boundaryError, setBoundaryError] = useState('')
  const [plantError, setPlantError] = useState('')
  const [rotationError, setRotationError] = useState('')
  const [rotationFeedback, setRotationFeedback] = useState('')
  const [shareError, setShareError] = useState('')
  const [busy, setBusy] = useState(false)
  const [zoneBusy, setZoneBusy] = useState(false)
  const [boundaryBusy, setBoundaryBusy] = useState(false)
  const [rotationBusy, setRotationBusy] = useState(false)
  const [layoutSaving, setLayoutSaving] = useState(false)
  const [layoutSaveFeedback, setLayoutSaveFeedback] = useState({ type: 'idle', message: '' })
  const [toastMessage, setToastMessage] = useState('')

  const pageState = useAsyncData(
    async () => {
      const plots = await api.listPlots()
      const accessRole = plots.find((entry) => String(entry.id) === String(plotId))?.access_role ?? null
      const [plot, zones, plants, rotations, access] = await Promise.all([
        api.getPlot(plotId),
        api.listPlantZones(plotId),
        api.listPlants(plotId),
        api.listRotations(plotId),
        accessRole === 'owner' ? api.listAccessRights(plotId) : Promise.resolve([]),
      ])

      return {
        plot,
        zones,
        plants,
        rotations,
        access,
        accessRole,
      }
    },
    [plotId],
    {
      plot: null,
      zones: [],
      plants: [],
      rotations: [],
      access: [],
      accessRole: null,
    },
  )

  const canEdit = ['owner', 'editor'].includes(pageState.data.accessRole)
  const isOwner = pageState.data.accessRole === 'owner'

  useEffect(() => {
    setLayoutSaving(false)
    setLayoutSaveFeedback({ type: 'idle', message: '' })
    setRotationDraft(null)
    setRotationError('')
    setRotationFeedback('')
    setRotationPlanForm(emptyRotationPlanForm)
  }, [plotId])

  useEffect(() => {
    if (!toastMessage) {
      return undefined
    }

    const timeoutId = window.setTimeout(() => {
      setToastMessage('')
    }, 2600)

    return () => window.clearTimeout(timeoutId)
  }, [toastMessage])

  useEffect(() => {
    if (!pageState.data.zones.length) {
      setSelectedZoneId(null)
      setZoneForm(emptyZoneForm)
      return
    }

    const selectedZone = pageState.data.zones.find((zone) => zone.id === selectedZoneId)

    if (selectedZoneId && !selectedZone) {
      setSelectedZoneId(null)
      setZoneForm(emptyZoneForm)
    }
  }, [pageState.data.zones, selectedZoneId])

  function updateStateKey(key, updater) {
    pageState.setData((current) => ({
      ...current,
      [key]: updater(current[key]),
    }))
  }

  function syncSelectedZone(updatedZone) {
    if (String(updatedZone.id) === String(selectedZoneId)) {
      setZoneForm(zoneToForm(updatedZone))
    }
  }

  function applyZoneUpdate(updatedZone) {
    updateStateKey('zones', (zones) => zones.map((zone) => (
      zone.id === updatedZone.id ? updatedZone : zone
    )))
    syncSelectedZone(updatedZone)
  }

  function beginNewZoneDraft() {
    setSelectedZoneId(null)
    setZoneForm(emptyZoneForm)
    setZoneError('')
  }

  function handleZoneSelect(zone) {
    if (!zone) {
      beginNewZoneDraft()
      return
    }

    setSelectedZoneId(zone.id)
    setZoneForm(zoneToForm(zone))
  }

  async function handleCanvasZoneCreate(shape, boundaryShape) {
    setZoneBusy(true)
    setZoneError('')
    setLayoutSaveFeedback({ type: 'idle', message: '' })

    try {
      const created = await api.createPlantZone(plotId, {
        name: zoneForm.name.trim() || `Zone ${pageState.data.zones.length + 1}`,
        zone_size: calculateArea(shape),
        soil_type: zoneForm.soil_type,
        rotation_stage: Number(zoneForm.rotation_stage || 0),
        last_planting_date: zoneForm.last_planting_date || null,
        geometry: shapeToGeometry(shape, boundaryShape),
      })

      updateStateKey('zones', (zones) => [...zones, created])
      setSelectedZoneId(created.id)
      setZoneForm(zoneToForm(created))
      setToastMessage(`Created ${created.name}.`)

      return created
    } catch (requestError) {
      setZoneError(requestError.message)
      throw requestError
    } finally {
      setZoneBusy(false)
    }
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
      // The canvas create flow already reports request errors through page state.
    }
  }

  async function handleZoneSubmit(event) {
    event.preventDefault()

    if (!selectedZoneId) {
      return
    }

    setZoneBusy(true)
    setZoneError('')

    try {
      const updated = await api.updatePlantZone(plotId, selectedZoneId, {
        name: zoneForm.name,
        soil_type: zoneForm.soil_type,
        rotation_stage: Number(zoneForm.rotation_stage || 0),
        last_planting_date: zoneForm.last_planting_date || null,
      })

      applyZoneUpdate(updated)
      setToastMessage(`Saved ${updated.name}.`)
    } catch (requestError) {
      setZoneError(requestError.message)
    } finally {
      setZoneBusy(false)
    }
  }

  async function handleZoneDelete() {
    if (!selectedZoneId) {
      return
    }

    setZoneBusy(true)
    setZoneError('')
    setLayoutSaveFeedback({ type: 'idle', message: '' })

    try {
      await api.deletePlantZone(plotId, selectedZoneId)
      updateStateKey('zones', (zones) => zones.filter((zone) => zone.id !== selectedZoneId))
      beginNewZoneDraft()
      setToastMessage('Zone removed.')
    } catch (requestError) {
      setZoneError(requestError.message)
    } finally {
      setZoneBusy(false)
    }
  }

  async function handleZoneGeometryCommit(zoneId, shape, boundaryShape) {
    const currentZone = pageState.data.zones.find((zone) => String(zone.id) === String(zoneId))
    const nextArea = calculateArea(shape)
    const nextGeometry = shapeToGeometry(shape, boundaryShape)

    if (
      !currentZone
      || (
        Math.abs(Number(currentZone.zone_size) - nextArea) < 0.01
        && geometryEquals(currentZone.geometry, nextGeometry)
      )
    ) {
      return
    }

    setZoneBusy(true)
    setZoneError('')
    setLayoutSaveFeedback({ type: 'idle', message: '' })

    try {
      const updated = await api.updatePlantZone(plotId, zoneId, {
        zone_size: nextArea,
        geometry: nextGeometry,
      })

      applyZoneUpdate(updated)
    } catch (requestError) {
      setZoneError(requestError.message)
    } finally {
      setZoneBusy(false)
    }
  }

  async function handleBoundaryCommit(nextBoundary, nextLayouts) {
    setBoundaryBusy(true)
    setBoundaryError('')
    setLayoutSaveFeedback({ type: 'idle', message: '' })

    try {
      const nextPlotSize = calculateArea(nextBoundary)
      const nextPlotGeometry = shapeToGeometry(nextBoundary)
      const plotChanged = (
        Math.abs(Number(pageState.data.plot.plot_size) - nextPlotSize) >= 0.01
        || !geometryEquals(pageState.data.plot.geometry, nextPlotGeometry)
      )
      const zonesToUpdate = pageState.data.zones
        .map((zone) => {
          const nextShape = nextLayouts[String(zone.id)]

          if (!nextShape) {
            return null
          }

          const nextZoneArea = calculateArea(nextShape)
          const nextZoneGeometry = shapeToGeometry(nextShape, nextBoundary)
          const changed = (
            Math.abs(Number(zone.zone_size) - nextZoneArea) >= 0.01
            || !geometryEquals(zone.geometry, nextZoneGeometry)
          )

          return changed
            ? {
              zone,
              payload: {
                zone_size: nextZoneArea,
                geometry: nextZoneGeometry,
              },
            }
            : null
        })
        .filter(Boolean)

      const [updatedPlot, updatedZones] = await Promise.all([
        plotChanged
          ? api.updatePlot(plotId, {
            plot_size: nextPlotSize,
            geometry: nextPlotGeometry,
          })
          : Promise.resolve(pageState.data.plot),
        Promise.all(
          zonesToUpdate.map(async ({ zone, payload }) => api.updatePlantZone(plotId, zone.id, payload)),
        ),
      ])

      const updatedZonesById = Object.fromEntries(updatedZones.map((zone) => [zone.id, zone]))

      pageState.setData((current) => ({
        ...current,
        plot: updatedPlot,
        zones: current.zones.map((zone) => updatedZonesById[zone.id] ?? zone),
      }))

      if (selectedZoneId && updatedZonesById[selectedZoneId]) {
        syncSelectedZone(updatedZonesById[selectedZoneId])
      }

      setToastMessage('Plot boundary and zones synchronized.')
    } catch (requestError) {
      setBoundaryError(requestError.message)
    } finally {
      setBoundaryBusy(false)
    }
  }

  async function handleLayoutSave() {
    setLayoutSaving(true)
    setLayoutSaveFeedback({ type: 'idle', message: '' })
    setBoundaryError('')
    setZoneError('')

    try {
      const [updatedPlot, updatedZones] = await Promise.all([
        api.updatePlot(plotId, {
          plot_size: Number(pageState.data.plot.plot_size),
          geometry: pageState.data.plot.geometry ?? null,
        }),
        Promise.all(
          pageState.data.zones.map((zone) => api.updatePlantZone(plotId, zone.id, {
            zone_size: Number(zone.zone_size),
            geometry: zone.geometry ?? null,
          })),
        ),
      ])

      const updatedZonesById = Object.fromEntries(updatedZones.map((zone) => [zone.id, zone]))

      pageState.setData((current) => ({
        ...current,
        plot: updatedPlot,
        zones: current.zones.map((zone) => updatedZonesById[zone.id] ?? zone),
      }))

      if (selectedZoneId && updatedZonesById[selectedZoneId]) {
        syncSelectedZone(updatedZonesById[selectedZoneId])
      }

      setLayoutSaveFeedback({ type: 'success', message: 'Plot layout saved' })
      setToastMessage('Layout saved successfully.')
    } catch (requestError) {
      setLayoutSaveFeedback({ type: 'error', message: requestError.message })
    } finally {
      setLayoutSaving(false)
    }
  }

  async function handlePlantCreate(payload) {
    setBusy(true)
    setPlantError('')

    try {
      const created = await api.createPlant(plotId, payload)

      updateStateKey('plants', (plants) => [created, ...plants])
      setToastMessage(`Added ${created.name}.`)

      return created
    } catch (requestError) {
      setPlantError(requestError.message)
      throw requestError
    } finally {
      setBusy(false)
    }
  }

  async function handlePlantDelete(plantId) {
    setBusy(true)
    setPlantError('')

    try {
      await api.deletePlant(plotId, plantId)
      updateStateKey('plants', (plants) => plants.filter((plant) => plant.id !== plantId))
      setToastMessage('Plant removed.')
    } catch (requestError) {
      setPlantError(requestError.message)
    } finally {
      setBusy(false)
    }
  }

  async function handleRotationPlanGenerate(event) {
    event.preventDefault()
    setRotationBusy(true)
    setRotationError('')
    setRotationFeedback('')

    try {
      const created = await api.createRotationPlan(plotId, {
        planning_date: rotationPlanForm.planning_date,
      })
      setRotationDraft(created.draft)
      setRotationFeedback('Rotation scheme generated. Review the preview before confirming it.')
    } catch (requestError) {
      setRotationError(requestError.message)
    } finally {
      setRotationBusy(false)
    }
  }

  async function handleRotationPlanConfirm() {
    if (!rotationDraft?.id) {
      return
    }

    setRotationBusy(true)
    setRotationError('')
    setRotationFeedback('')

    try {
      const confirmed = await api.confirmRotationPlan(plotId, rotationDraft.id)
      updateStateKey('plants', () => confirmed.plants ?? [])
      updateStateKey('zones', () => confirmed.plant_zones ?? [])
      updateStateKey('rotations', () => confirmed.rotation_history ?? [])
      setRotationDraft(null)
      setRotationFeedback('Rotation scheme confirmed and saved to history.')
      setToastMessage('Rotation scheme confirmed.')
    } catch (requestError) {
      setRotationError(requestError.message)
    } finally {
      setRotationBusy(false)
    }
  }

  async function handleRotationPlanReject() {
    if (!rotationDraft?.id) {
      return
    }

    setRotationBusy(true)
    setRotationError('')
    setRotationFeedback('')

    try {
      await api.rejectRotationPlan(plotId, rotationDraft.id)
      setRotationDraft(null)
      setRotationFeedback('Rotation draft rejected. The previous plot state was kept unchanged.')
      setToastMessage('Rotation draft rejected.')
    } catch (requestError) {
      setRotationError(requestError.message)
    } finally {
      setRotationBusy(false)
    }
  }

  async function handleShareSubmit(event) {
    event.preventDefault()
    setBusy(true)
    setShareError('')

    try {
      const response = await api.sharePlot(plotId, shareForm)
      updateStateKey('access', (access) => [response.access_right, ...access])
      setShareForm(emptyShareForm)
      setToastMessage('Plot access shared.')
    } catch (requestError) {
      setShareError(requestError.message)
    } finally {
      setBusy(false)
    }
  }

  async function handleAccessRevoke(accessRightId) {
    setBusy(true)
    setShareError('')

    try {
      await api.revokeAccessRight(accessRightId)
      updateStateKey('access', (access) => access.filter((entry) => entry.access_right_id !== accessRightId))
      setToastMessage('Access revoked.')
    } catch (requestError) {
      setShareError(requestError.message)
    } finally {
      setBusy(false)
    }
  }

  async function handleExport() {
    await api.downloadPlotPdf(plotId, pageState.data.plot?.name)
    setToastMessage('PDF export started.')
  }

  if (pageState.loading) {
    return <LoadingState title="Loading plot workspace..." />
  }

  if (pageState.error) {
    return <ErrorState error={pageState.error} onRetry={pageState.reload} />
  }

  if (!pageState.data.plot) {
    return <EmptyState title="Plot not found" description="The requested plot could not be loaded." />
  }

  const selectedZone = pageState.data.zones.find((zone) => String(zone.id) === String(selectedZoneId)) ?? null
  const selectedZonePlants = selectedZone
    ? pageState.data.plants.filter((plant) => (
      String(plant.fk_plant_zone_id ?? plant.plant_zone_id ?? plant.plant_zone?.id ?? plant.plantZone?.id ?? '')
        === String(selectedZone.id)
    ))
    : []
  const workspaceBusy = zoneBusy || boundaryBusy || layoutSaving
  const plotMeta = [
    <StatusBadge key="city" kind="ownership">{pageState.data.plot.city}</StatusBadge>,
    <StatusBadge key="size" kind="selection" tone="neutral">{safeNumber(pageState.data.plot.plot_size, 1)} m2</StatusBadge>,
    <StatusBadge key="role" kind="status" tone={canEdit ? 'success' : 'warning'}>{pageState.data.accessRole ?? 'viewer'}</StatusBadge>,
    <StatusBadge key="share" kind="connection" tone={pageState.data.plot.share ? 'success' : 'neutral'}>{pageState.data.plot.share ? 'Shared to community' : 'Private'}</StatusBadge>,
  ]
  const plotModules = [
    {
      key: 'calendar',
      title: 'Calendar',
      description: 'Generate recommendation-based work from weather, care intervals, and current plot state.',
      action: 'Open planning workspace',
      to: `/plots/${plotId}/calendar`,
      primary: true,
      kicker: 'Core module',
    },
    {
      key: 'history',
      title: 'History',
      description: 'Review planning snapshots and the record of meaningful plot changes over time.',
      action: 'Review snapshots',
      to: `/plots/${plotId}/history`,
      primary: false,
      kicker: 'Planning record',
    },
    {
      key: 'harvests',
      title: 'Harvests',
      description: 'Track harvest output and connect day-level work back to real garden results.',
      action: 'Open harvest log',
      to: `/plots/${plotId}/harvests`,
      primary: false,
      kicker: 'Yield tracking',
    },
    {
      key: 'analytics',
      title: 'Analytics',
      description: 'Inspect plot-level performance signals, workload patterns, and productivity trends.',
      action: 'View analytics',
      to: `/plots/${plotId}/analytics`,
      primary: false,
      kicker: 'Insights',
    },
  ]

  return (
    <div className="page-stack workspace-page workspace-page--canvas-first" data-testid="workspace-page">
      <PageHeader
        eyebrow="Plot workspace"
        title={pageState.data.plot.name}
        description="A focused workspace where the canvas stays dominant, live zone context stays on the right, and planning tools drop into a calmer lower dock."
        meta={plotMeta}
        actions={(
          <>
            <Button variant="secondary" onClick={handleExport}>Export PDF</Button>
            {canEdit ? (
              <Link to={`/plots/${plotId}/edit`}>
                <Button variant="ghost">Edit metadata</Button>
              </Link>
            ) : null}
          </>
        )}
      />

      <section className="panel workspace-overview-band">
        <div className="workspace-overview-copy">
          <span className="workspace-overview-kicker">Live editor workspace</span>
          <h2 className="workspace-overview-title">Edit the plot on canvas first, then drop into planning and collaboration without breaking flow.</h2>
          <p className="section-copy">
            The canvas owns the page. The right rail only carries active zone work, while modules, plants, sharing, and rotation live together below as a secondary workspace.
          </p>
        </div>

        <div className="workspace-overview-stats">
          <article className="workspace-overview-stat">
            <span className="workspace-overview-stat-label">Zones</span>
            <strong className="workspace-overview-stat-value">{pageState.data.zones.length}</strong>
            <span className="workspace-overview-stat-note">Mapped editing targets</span>
          </article>
          <article className="workspace-overview-stat">
            <span className="workspace-overview-stat-label">Plants</span>
            <strong className="workspace-overview-stat-value">{pageState.data.plants.length}</strong>
            <span className="workspace-overview-stat-note">Placed records in this plot</span>
          </article>
          <article className="workspace-overview-stat">
            <span className="workspace-overview-stat-label">Created</span>
            <strong className="workspace-overview-stat-value">{formatDate(pageState.data.plot.creation_date)}</strong>
            <span className="workspace-overview-stat-note">Original plot snapshot</span>
          </article>
          <article className="workspace-overview-stat">
            <span className="workspace-overview-stat-label">Visibility</span>
            <strong className="workspace-overview-stat-value">{pageState.data.plot.share ? 'Shared' : 'Private'}</strong>
            <span className="workspace-overview-stat-note">Community state</span>
          </article>
        </div>
      </section>

      <SuccessToast message={toastMessage} onDismiss={() => setToastMessage('')} />

      {workspaceBusy ? (
        <ProcessingState
          title={layoutSaving ? 'Saving workspace layout' : 'Syncing plot changes'}
          description="Your latest geometry and metadata changes are being synchronized with the backend."
          steps={layoutSaving ? ['Preparing geometry', 'Persisting plot layout', 'Refreshing workspace state'] : ['Updating selected zones', 'Validating plot bounds', 'Refreshing workspace data']}
        />
      ) : null}

      {rotationBusy ? (
        <ProcessingState
          title={rotationDraft ? 'Finalizing rotation decision' : 'Generating rotation scheme'}
          description="The workspace is evaluating valid zone targets and preparing a safe rotation preview."
          steps={['Collecting plant state', 'Checking zone compatibility', 'Preparing preview']}
          compact
        />
      ) : null}

      <div className="plot-editor-layout">
        <section className="panel plot-editor-main plot-workspace-main--canvas-first page-stack">
          <div className="plot-editor-head">
            <div className="page-stack">
              <span className="workspace-section-eyebrow">Canvas editor</span>
              <h3 className="section-title">Plot designer</h3>
              <p className="section-copy">
                The designer keeps the geometry workspace dominant on desktop, with only active editing context sitting beside it.
              </p>
            </div>
            <div className="workspace-status">
              {selectedZone ? <StatusBadge kind="selection">Active zone: {selectedZone.name}</StatusBadge> : <StatusBadge kind="selection" tone="neutral">No zone selected</StatusBadge>}
              {zoneBusy ? <StatusBadge kind="status" tone="warning">Syncing zones</StatusBadge> : null}
              {boundaryBusy ? <StatusBadge kind="status" tone="warning">Syncing plot bounds</StatusBadge> : null}
              {layoutSaveFeedback?.type === 'success' ? <StatusBadge kind="status" tone="success">{layoutSaveFeedback.message}</StatusBadge> : null}
            </div>
          </div>

          {zoneError ? <span className="field-error">{zoneError}</span> : null}
          {boundaryError ? <span className="field-error">{boundaryError}</span> : null}

          <PlotDesignerCanvas
            ref={designerCanvasRef}
            plotId={plotId}
            plotName={pageState.data.plot.name}
            plotSize={pageState.data.plot.plot_size}
            plotGeometry={pageState.data.plot.geometry}
            zones={pageState.data.zones}
            plants={pageState.data.plants}
            canEdit={canEdit}
            activeZoneId={selectedZoneId}
            isLayoutSaveDisabled={layoutSaving || zoneBusy || boundaryBusy}
            isLayoutSaving={layoutSaving}
            layoutSaveFeedback={layoutSaveFeedback}
            onSaveLayout={handleLayoutSave}
            onSelectZone={handleZoneSelect}
            onCreateZone={handleCanvasZoneCreate}
            onZoneGeometryCommit={handleZoneGeometryCommit}
            onBoundaryCommit={handleBoundaryCommit}
          />
        </section>

        <aside className="plot-context-rail page-stack plot-workspace-sidebar--canvas-first">
          <section className="panel page-stack workspace-context-card">
            <div className="workspace-context-card-head">
              <div className="page-stack">
                <span className="workspace-section-eyebrow">Context rail</span>
                <h3 className="section-title">{selectedZone ? selectedZone.name : 'Zone context'}</h3>
                <p className="section-copy">
                  Read-only context stays here so you can orient quickly before editing the zone form or planting into the active area.
                </p>
              </div>
              <StatusBadge kind="selection" tone={selectedZone ? 'soft' : canEdit ? 'warning' : 'neutral'}>
                {selectedZone ? 'Zone active' : canEdit ? 'Select or draw a zone' : 'Read-only selection'}
              </StatusBadge>
            </div>

            {selectedZone ? (
              <>
                <div className="workspace-context-stats workspace-context-stats-compact">
                  <article className="workspace-context-stat">
                    <span className="workspace-context-stat-label">Area</span>
                    <strong className="workspace-context-stat-value">{safeNumber(selectedZone.zone_size, 2)} m2</strong>
                  </article>
                  <article className="workspace-context-stat">
                    <span className="workspace-context-stat-label">Plants</span>
                    <strong className="workspace-context-stat-value">{selectedZonePlants.length}</strong>
                  </article>
                  <article className="workspace-context-stat">
                    <span className="workspace-context-stat-label">Last planted</span>
                    <strong className="workspace-context-stat-value">{selectedZone.last_planting_date ? formatDate(selectedZone.last_planting_date) : 'Not set'}</strong>
                  </article>
                </div>

                <div className="inline-note inline-note-compact">
                  Editable fields such as soil type, rotation stage, and planting date stay in the form below so the summary here stays quick to scan.
                </div>

                {selectedZonePlants.length > 0 ? (
                  <div className="workspace-context-list">
                    <span className="workspace-context-list-label">Current placement</span>
                    <div className="workspace-context-list-items">
                      {selectedZonePlants.slice(0, 4).map((plant) => (
                        <span key={plant.id} className="workspace-context-pill">
                          {plant.name}
                        </span>
                      ))}
                      {selectedZonePlants.length > 4 ? (
                        <span className="workspace-context-pill workspace-context-pill--muted">
                          +{selectedZonePlants.length - 4} more
                        </span>
                      ) : null}
                    </div>
                  </div>
                ) : (
                  <EmptyStatePanel
                    title="Nothing planted in this zone yet"
                    description="Use the planting panel below to place the next plant directly into the active zone."
                    tone="subtle"
                  />
                )}
              </>
            ) : (
              <EmptyStatePanel
                title={canEdit ? 'Select or draw a zone' : 'Select a zone to inspect it'}
                description={canEdit
                  ? 'Click an existing zone on the canvas, or switch the designer into Draw zone mode to create a new editable area.'
                  : 'Zone geometry and metadata stay visible here once you pick a zone on the canvas.'}
                tone="subtle"
                action={canEdit ? (
                  <Button onClick={handleZoneCreateFromForm} disabled={zoneBusy}>
                    {zoneBusy ? 'Preparing zone...' : 'Create zone from current form'}
                  </Button>
                ) : null}
              />
            )}
          </section>

          <section className="panel page-stack workspace-context-card">
            <div className="list-head">
              <div className="page-stack">
                <h3 className="section-title">Editable details</h3>
                <p className="section-copy">
                  {selectedZoneId
                    ? 'Update zone metadata here while geometry stays visible on the canvas.'
                    : 'Prepare the next zone draft here, then place it on the canvas when you are ready.'}
                </p>
              </div>
              <StatusBadge kind="selection" tone={selectedZoneId ? 'soft' : 'neutral'}>
                {selectedZoneId ? 'Edit mode' : 'Create mode'}
              </StatusBadge>
            </div>

            <form className="input-grid" onSubmit={handleZoneSubmit}>
              <div className="field">
                <label htmlFor="zone-name">Zone name</label>
                <input
                  id="zone-name"
                  value={zoneForm.name}
                  onChange={(event) => setZoneForm((current) => ({ ...current, name: event.target.value }))}
                  placeholder="North bed, Herb strip..."
                  required={Boolean(selectedZoneId) && canEdit}
                  disabled={!canEdit}
                />
              </div>

              <div className="field">
                <label htmlFor="soil-type">Soil type</label>
                <select
                  id="soil-type"
                  value={zoneForm.soil_type}
                  onChange={(event) => setZoneForm((current) => ({ ...current, soil_type: event.target.value }))}
                  disabled={!canEdit}
                >
                  {SOIL_TYPES.map((soil) => (
                    <option key={soil} value={soil}>
                      {soil}
                    </option>
                  ))}
                </select>
              </div>

              <div className="field">
                <label htmlFor="rotation-stage">Rotation stage</label>
                <input
                  id="rotation-stage"
                  type="number"
                  min="0"
                  value={zoneForm.rotation_stage}
                  onChange={(event) => setZoneForm((current) => ({ ...current, rotation_stage: event.target.value }))}
                  disabled={!canEdit}
                />
              </div>

              <div className="field field-span-2">
                <label htmlFor="zone-last-planting">Last planting date</label>
                <input
                  id="zone-last-planting"
                  type="date"
                  value={zoneForm.last_planting_date}
                  onChange={(event) => setZoneForm((current) => ({ ...current, last_planting_date: event.target.value }))}
                  disabled={!canEdit}
                />
              </div>

              <div className="inline-note inline-note-compact field-span-2">
                Zone area follows the canvas geometry automatically, so there is only one source of truth for layout size.
              </div>

              {zoneError ? <span className="field-error">{zoneError}</span> : null}
              {!canEdit ? (
                <div className="inline-note inline-note-compact">
                  Your current access is read only. Select zones on the canvas to inspect them, while edits remain disabled in this rail.
                </div>
              ) : null}

              <div className="form-actions">
                {selectedZoneId && canEdit ? (
                  <>
                    <Button type="submit" disabled={zoneBusy}>
                      {zoneBusy ? 'Saving...' : 'Save zone changes'}
                    </Button>
                    <Button variant="secondary" onClick={beginNewZoneDraft}>
                      New zone draft
                    </Button>
                    <Button variant="danger" onClick={handleZoneDelete} disabled={zoneBusy}>
                      Delete zone
                    </Button>
                  </>
                ) : null}

                {!selectedZoneId && canEdit ? (
                  <>
                    <Button onClick={handleZoneCreateFromForm} disabled={zoneBusy}>
                      {zoneBusy ? 'Adding...' : 'Add a new zone'}
                    </Button>
                    <Button variant="secondary" onClick={() => setZoneForm(emptyZoneForm)} disabled={zoneBusy}>
                      Clear form
                    </Button>
                  </>
                ) : null}
              </div>
            </form>
          </section>

          {canEdit ? (
            <div className="page-stack">
              {plantError ? <span className="field-error">{plantError}</span> : null}
              <PlotPlantingDrawer
                selectedZone={selectedZone}
                canEdit={canEdit}
                busy={busy}
                onCreatePlant={handlePlantCreate}
              />
            </div>
          ) : null}
        </aside>
      </div>

      <section className="panel workspace-dock workspace-dock-shell page-stack">
        <div className="workspace-dock-header">
          <div className="page-stack">
            <span className="workspace-section-eyebrow">Secondary workspace</span>
            <h3 className="section-title">Planning, tracking, and collaboration live together below the canvas.</h3>
            <p className="section-copy">
              These tools stay easy to scan and close at hand, but they no longer compete with the live editing rail.
            </p>
          </div>
        </div>

        <section className="workspace-module-panel">
          <div className="list-head">
            <div className="page-stack">
              <span className="workspace-action-group-label">Plot modules</span>
              <h3 className="section-title">Core plot navigation</h3>
              <p className="section-copy">
                Calendar leads this set because it is one of the strongest plot workflows, while history, harvests, and analytics stay one click away.
              </p>
            </div>
            <StatusBadge kind="selection" tone="neutral">{plotModules.length} modules</StatusBadge>
          </div>

          <div className="workspace-module-grid">
            {plotModules.map((module) => (
              <Link
                key={module.key}
                to={module.to}
                className={`workspace-module-card ${module.primary ? 'workspace-module-card-primary' : ''}`.trim()}
              >
                <span className="workspace-module-kicker">{module.kicker}</span>
                <strong className="workspace-module-title">{module.title}</strong>
                <p className="workspace-module-description">{module.description}</p>
                <span className="workspace-module-action">{module.action}</span>
              </Link>
            ))}
          </div>
        </section>

        <div className={`workspace-secondary-grid ${isOwner ? 'workspace-secondary-grid--with-sidecard' : ''}`.trim()}>
          <section className="page-stack workspace-dock-card workspace-dock-card--wide">
            <div className="list-head">
              <div className="page-stack">
                <h3 className="section-title">Plants in this plot</h3>
                <p className="section-copy">
                  Review placed plants after editing, then open individual plant details only when you need deeper condition or lifecycle history.
                </p>
              </div>
              <StatusBadge kind="selection" tone={selectedZone ? 'soft' : 'neutral'}>
                {selectedZone ? `${selectedZonePlants.length} in ${selectedZone.name}` : `${pageState.data.plants.length} total`}
              </StatusBadge>
            </div>

            {pageState.data.plants.length === 0 ? (
              <EmptyState title="No plants yet" description="Place the first plant from the selected-zone rail to start building this plot plan." />
            ) : (
              <div className="table-wrap">
                <table>
                  <thead>
                    <tr>
                      <th>Name</th>
                      <th>Zone</th>
                      <th>Catalog</th>
                      <th>Condition</th>
                      <th />
                    </tr>
                  </thead>
                  <tbody>
                    {pageState.data.plants.map((plant) => (
                      <tr key={plant.id}>
                        <td>{plant.name}</td>
                        <td>{plant.plant_zone?.name ?? plant.plantZone?.name ?? 'Unknown zone'}</td>
                        <td>{plant.catalog_plant?.name ?? plant.catalogPlant?.name ?? 'Manual record'}</td>
                        <td>{plant.condition}</td>
                        <td>
                          <div className="row-actions">
                            <Link to={`/plots/${plotId}/plants/${plant.id}`}>
                              <Button variant="ghost">Open</Button>
                            </Link>
                            {canEdit ? (
                              <Button variant="danger" onClick={() => handlePlantDelete(plant.id)} disabled={busy}>
                                Delete
                              </Button>
                            ) : null}
                          </div>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </section>

          {isOwner ? (
            <section className="page-stack workspace-dock-card workspace-dock-card--side">
              <div className="list-head">
                <div className="page-stack">
                  <h3 className="section-title">Sharing</h3>
                  <p className="section-copy">
                    Invite collaborators after the layout is in good shape, without pulling access management into the live editing rail.
                  </p>
                </div>
                <StatusBadge kind="selection" tone={pageState.data.access.length > 0 ? 'soft' : 'neutral'}>
                  {pageState.data.access.length}
                </StatusBadge>
              </div>

              <form className="input-grid" onSubmit={handleShareSubmit}>
                <div className="field field-span-2">
                  <label htmlFor="recipient-email">Recipient email</label>
                  <input
                    id="recipient-email"
                    value={shareForm.recipient_email}
                    onChange={(event) => setShareForm((current) => ({ ...current, recipient_email: event.target.value }))}
                    required
                  />
                </div>
                <div className="field field-span-2">
                  <label htmlFor="share-role">Role</label>
                  <select
                    id="share-role"
                    value={shareForm.role}
                    onChange={(event) => setShareForm((current) => ({ ...current, role: event.target.value }))}
                  >
                    {ACCESS_ROLES.map((role) => (
                      <option key={role} value={role}>
                        {role}
                      </option>
                    ))}
                  </select>
                </div>
                {shareError ? <span className="field-error">{shareError}</span> : null}
                <Button type="submit" disabled={busy}>
                  {busy ? 'Sharing...' : 'Share plot'}
                </Button>
              </form>

              <div className="inline-note">
                Owners can grant viewer or editor access here and revoke it below.
              </div>

              {pageState.data.access.length > 0 ? (
                <div className="table-wrap">
                  <table>
                    <thead>
                      <tr>
                        <th>Name</th>
                        <th>Email</th>
                        <th>Role</th>
                        <th />
                      </tr>
                    </thead>
                    <tbody>
                      {pageState.data.access.map((entry) => (
                        <tr key={entry.access_right_id}>
                          <td>{entry.name || 'Unknown'}</td>
                          <td>{entry.email}</td>
                          <td>{entry.role}</td>
                          <td>
                            <Button
                              variant="danger"
                              onClick={() => handleAccessRevoke(entry.access_right_id)}
                              disabled={busy}
                            >
                              Revoke
                            </Button>
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              ) : (
                <EmptyState title="No shared users" description="Only the owner currently has access to this plot." />
              )}
            </section>
          ) : null}
        </div>

        <section className="page-stack workspace-dock-card workspace-dock-card--full">
          <div className="list-head">
            <div className="page-stack">
              <h3 className="section-title">Rotation planning</h3>
              <p className="section-copy">
                Generate plot-wide rotation suggestions here after finishing geometry edits, then confirm or reject the draft without crowding the editor rail.
              </p>
            </div>
            <StatusBadge kind="status" tone={rotationDraft ? 'warning' : 'neutral'}>
              {rotationDraft ? 'Draft ready' : 'Idle'}
            </StatusBadge>
          </div>

          {canEdit ? (
            <form className="input-grid workspace-rotation-form" onSubmit={handleRotationPlanGenerate}>
              <div className="field">
                <label htmlFor="rotation-planning-date">Planning date</label>
                <input
                  id="rotation-planning-date"
                  type="date"
                  value={rotationPlanForm.planning_date}
                  onChange={(event) => setRotationPlanForm((current) => ({ ...current, planning_date: event.target.value }))}
                  required
                />
              </div>
              {rotationError ? <span className="field-error">{rotationError}</span> : null}
              {rotationFeedback ? <div className="inline-note">{rotationFeedback}</div> : null}
              <div className="form-actions">
                <Button type="submit" loading={rotationBusy} disabled={pageState.data.plants.length === 0 || pageState.data.zones.length === 0}>
                  {rotationBusy ? 'Generating rotation scheme' : 'Generate rotation scheme'}
                </Button>
              </div>
            </form>
          ) : null}

          {rotationDraft ? (
            <div className="stack workspace-rotation-preview" data-testid="rotation-plan-preview">
              <div className="list-head">
                <div className="stack">
                  <strong>Generated scheme preview</strong>
                  <span className="muted">
                    Planning date {formatDate(rotationDraft.planning_date)}
                  </span>
                </div>
                <span className={`badge ${rotationDraft.plan?.status === 'ready' ? 'badge-soft' : 'badge-warning'}`}>
                  {rotationDraft.plan?.status === 'ready' ? 'Ready to confirm' : 'Needs adjustments'}
                </span>
              </div>

              <div className="inline-note">
                Assigned {rotationDraft.plan?.summary?.assigned_plant_count ?? 0} of {rotationDraft.plan?.summary?.plant_count ?? 0} plants.
              </div>

              {(rotationDraft.plan?.plants ?? []).map((entry) => {
                const selectedTarget = entry.selected_target_zone
                const otherAlternatives = (entry.alternatives ?? []).filter((alternative) => alternative.zone_id !== selectedTarget?.zone_id)
                const blockedCandidates = (entry.candidate_zones ?? []).filter((candidate) => candidate.verdict === 'invalid')

                return (
                  <div key={entry.plant.id} className="card workspace-rotation-entry">
                    <div className="list-head">
                      <div className="stack">
                        <strong>{entry.plant.name}</strong>
                        <span className="muted">
                          Current zone: {entry.current_zone?.name ?? 'Not assigned'}
                        </span>
                      </div>
                      <span className={`badge ${selectedTarget ? 'badge-soft' : 'badge-warning'}`}>
                        {selectedTarget ? 'Target selected' : 'No valid target'}
                      </span>
                    </div>

                    {selectedTarget ? (
                      <div className="stack">
                        <strong>
                          Suggested target zone: {selectedTarget.zone_name} (score {selectedTarget.score})
                        </strong>
                        <span className="muted">{selectedTarget.passed_reasons?.join(' ')}</span>
                      </div>
                    ) : (
                      <div className="inline-note">
                        No valid target zone was found for this plant in the current scheme.
                      </div>
                    )}

                    {otherAlternatives.length > 0 ? (
                      <div className="stack">
                        <strong>Other valid target zones</strong>
                        {otherAlternatives.map((alternative) => (
                          <div key={alternative.zone_id} className="card workspace-rotation-subcard">
                            <div className="list-head">
                              <span>{alternative.zone_name}</span>
                              <span>Score {alternative.score}</span>
                            </div>
                            <span className="muted">{alternative.passed_reasons?.join(' ')}</span>
                          </div>
                        ))}
                      </div>
                    ) : null}

                    {blockedCandidates.length > 0 ? (
                      <div className="stack">
                        <strong>Rejected target zones</strong>
                        {blockedCandidates.map((candidate) => (
                          <div key={candidate.zone_id} className="card workspace-rotation-subcard">
                            <div className="list-head">
                              <span>{candidate.zone_name}</span>
                              <span>Score {candidate.score}</span>
                            </div>
                            <span className="muted">{candidate.blocking_reasons?.join(' ')}</span>
                          </div>
                        ))}
                      </div>
                    ) : null}

                    {entry.fallback_solutions?.length ? (
                      <div className="stack">
                        <strong>Fallback solutions</strong>
                        {entry.fallback_solutions.map((solution) => (
                          <span key={solution} className="muted">{solution}</span>
                        ))}
                      </div>
                    ) : null}
                  </div>
                )
              })}

              {canEdit ? (
                <div className="form-actions">
                  <Button
                    onClick={handleRotationPlanConfirm}
                    loading={rotationBusy}
                    disabled={rotationDraft.plan?.status !== 'ready'}
                  >
                    {rotationBusy ? 'Saving scheme' : 'Confirm scheme'}
                  </Button>
                  <Button variant="secondary" onClick={handleRotationPlanReject} disabled={rotationBusy}>
                    Reject scheme
                  </Button>
                </div>
              ) : null}
            </div>
          ) : null}

          <div className="workspace-history-block">
            <div>
              <h4>Confirmed rotation history</h4>
              <p className="section-copy">
                Planning history snapshots remain available from the history page, while confirmed rotation assignments stay previewable here.
              </p>
            </div>
            {pageState.data.rotations.length === 0 ? (
              <EmptyState title="No confirmed rotation plans" description="Generate and confirm a scheme to create the first rotation history records." />
            ) : (
              <div className="table-wrap">
                <table>
                  <thead>
                    <tr>
                      <th>From</th>
                      <th>To</th>
                      <th>Zone</th>
                      <th>Plant</th>
                    </tr>
                  </thead>
                  <tbody>
                    {pageState.data.rotations.map((rotation) => (
                      <tr key={rotation.id}>
                        <td>{formatDate(rotation.from_date)}</td>
                        <td>{formatDate(rotation.to_date)}</td>
                        <td>{rotation.plant_zone?.name ?? rotation.fk_plant_zone_id}</td>
                        <td>{rotation.plant?.name ?? rotation.fk_plant_id}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </div>
        </section>
      </section>
    </div>
  )
}
