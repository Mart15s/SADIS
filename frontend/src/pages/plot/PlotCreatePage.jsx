import { useEffect, useMemo, useRef, useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import PageHeader from '../../components/layout/PageHeader.jsx'
import PlotDesignerCanvas from '../../components/plot/PlotDesignerCanvas.jsx'
import PlotLocationMap from '../../components/plot/PlotLocationMap.jsx'
import {
  ErrorState,
  ProcessingState,
  SuccessToast,
} from '../../components/shared/StatusView.jsx'
import Button from '../../components/ui/Button.jsx'
import FormSection from '../../components/ui/FormSection.jsx'
import ModeToggleGroup from '../../components/ui/ModeToggleGroup.jsx'
import SectionCard from '../../components/ui/SectionCard.jsx'
import StatusBadge from '../../components/ui/StatusBadge.jsx'
import Surface from '../../components/ui/Surface.jsx'
import { api } from '../../lib/api.js'
import { SOIL_TYPES } from '../../lib/constants.js'
import { assertSanitizedGeometryPayload } from '../../lib/plotGeometry.js'
import { calculateArea, shapeToGeometry } from '../../lib/plotDesigner.js'
import { calculateLatLngArea, calculateLatLngCenter, calculateLatLngPerimeter } from '../../lib/geoMeasurements.js'
import { formatMeters, formatSquareMeters } from '../../lib/plotMeasurements.js'

const CREATE_DRAFT_KEY = 'sad-plot-create-draft-v1'
const DEFAULT_LOCATION = { lat: 54.6872, lng: 25.2797 }

const emptyForm = {
  name: '',
  city: '',
  plot_size: '',
  creation_date: new Date().toISOString().slice(0, 10),
  description: '',
  share: false,
}

const emptyZoneForm = {
  name: '',
  soil_type: SOIL_TYPES[0],
  rotation_stage: 0,
  last_planting_date: '',
}

function sameId(left, right) {
  return String(left ?? '') === String(right ?? '')
}

function roundCoordinate(value, digits = 6) {
  const factor = 10 ** digits
  return Math.round(Number(value) * factor) / factor
}

function roundArea(value) {
  return Math.max(Math.round(Number(value || 0) * 100) / 100, 0.01)
}

function estimateAreaFromBoundary(boundaryPoints) {
  if (boundaryPoints.length < 3) {
    return 0
  }

  return roundArea(calculateLatLngArea(boundaryPoints))
}

function normalizeBoundaryToGeometry(boundaryPoints, boundaryCenter, mapView) {
  if (boundaryPoints.length < 3) {
    return null
  }

  const lats = boundaryPoints.map((point) => point.lat)
  const lngs = boundaryPoints.map((point) => point.lng)
  const minLat = Math.min(...lats)
  const maxLat = Math.max(...lats)
  const minLng = Math.min(...lngs)
  const maxLng = Math.max(...lngs)
  const latSpan = Math.max(maxLat - minLat, Number.EPSILON)
  const lngSpan = Math.max(maxLng - minLng, Number.EPSILON)
  const center = boundaryCenter ?? {
    lat: (minLat + maxLat) / 2,
    lng: (minLng + maxLng) / 2,
  }

  return {
    points: boundaryPoints.map((point) => ({
      x: roundCoordinate((point.lng - minLng) / lngSpan, 4),
      y: roundCoordinate((maxLat - point.lat) / latSpan, 4),
    })),
    map: {
      provider: 'openstreetmap',
      center: {
        lat: roundCoordinate(center.lat),
        lng: roundCoordinate(center.lng),
      },
      zoom: mapView?.zoom ?? 13,
      boundary: boundaryPoints.map((point) => ({
        lat: roundCoordinate(point.lat),
        lng: roundCoordinate(point.lng),
      })),
    },
  }
}

function loadCreateDraft() {
  if (typeof window === 'undefined') {
    return null
  }

  try {
    const raw = window.localStorage.getItem(CREATE_DRAFT_KEY)
    return raw ? JSON.parse(raw) : null
  } catch {
    return null
  }
}

function saveCreateDraft(draft) {
  if (typeof window === 'undefined') {
    return
  }

  window.localStorage.setItem(CREATE_DRAFT_KEY, JSON.stringify(draft))
}

function clearCreateDraft() {
  if (typeof window === 'undefined') {
    return
  }

  window.localStorage.removeItem(CREATE_DRAFT_KEY)
}

function zoneToForm(zone) {
  return {
    name: zone?.name ?? '',
    soil_type: zone?.soil_type ?? SOIL_TYPES[0],
    rotation_stage: zone?.rotation_stage ?? 0,
    last_planting_date: zone?.last_planting_date ?? '',
  }
}

function hasMeaningfulDraft(draft) {
  return Boolean(
    draft?.boundaryPoints?.length
    || draft?.designerGeometry
    || draft?.draftZones?.length
    || draft?.form?.name
    || draft?.form?.city
    || draft?.form?.plot_size
    || draft?.form?.description
    || draft?.form?.share,
  )
}

function getInitialCreateStep(draft) {
  if (['zones', 'summary'].includes(draft?.step) && draft?.boundaryClosed && (draft?.boundaryPoints?.length ?? 0) >= 3) {
    return draft.step
  }

  return 'boundary'
}

export default function PlotCreatePage() {
  const navigate = useNavigate()
  const designerCanvasRef = useRef(null)
  const restoredDraft = useMemo(loadCreateDraft, [])
  const [step, setStep] = useState(getInitialCreateStep(restoredDraft))
  const [mapMode, setMapMode] = useState('boundary')
  const [mapView, setMapView] = useState(restoredDraft?.mapView ?? { center: DEFAULT_LOCATION, zoom: 13 })
  const [boundaryPoints, setBoundaryPoints] = useState(restoredDraft?.boundaryPoints ?? [])
  const [boundaryClosed, setBoundaryClosed] = useState(Boolean(
    restoredDraft?.boundaryClosed
    || (['zones', 'summary'].includes(restoredDraft?.step) && (restoredDraft?.boundaryPoints?.length ?? 0) >= 3),
  ))
  const [designerGeometry, setDesignerGeometry] = useState(restoredDraft?.designerGeometry ?? null)
  const [form, setForm] = useState({ ...emptyForm, ...(restoredDraft?.form ?? {}) })
  const [draftZones, setDraftZones] = useState(restoredDraft?.draftZones ?? [])
  const [selectedZoneId, setSelectedZoneId] = useState(restoredDraft?.selectedZoneId ?? null)
  const [zoneForm, setZoneForm] = useState(restoredDraft?.zoneForm ?? emptyZoneForm)
  const [zoneError, setZoneError] = useState('')
  const [saveError, setSaveError] = useState('')
  const [submitting, setSubmitting] = useState(false)
  const [toastMessage, setToastMessage] = useState(hasMeaningfulDraft(restoredDraft) ? 'Restored unsaved plot boundary.' : '')
  const [cityLookupStatus, setCityLookupStatus] = useState('')
  const [cityManuallyEdited, setCityManuallyEdited] = useState(Boolean(restoredDraft?.form?.city))

  const estimatedArea = useMemo(() => estimateAreaFromBoundary(boundaryPoints), [boundaryPoints])
  const estimatedPerimeter = useMemo(
    () => calculateLatLngPerimeter(boundaryPoints, boundaryPoints.length >= 3),
    [boundaryPoints],
  )
  const calculatedCenter = useMemo(() => (
    boundaryPoints.length >= 3 ? calculateLatLngCenter(boundaryPoints) : null
  ), [boundaryPoints])
  const mapGeometry = useMemo(
    () => normalizeBoundaryToGeometry(boundaryPoints, calculatedCenter, mapView),
    [boundaryPoints, calculatedCenter, mapView],
  )
  const effectivePlotSize = Number(form.plot_size) || estimatedArea || 96
  const planGeometry = designerGeometry
    ? { ...designerGeometry, map: mapGeometry?.map ?? designerGeometry.map }
    : mapGeometry
  const selectedZone = draftZones.find((zone) => sameId(zone.id, selectedZoneId)) ?? null
  const activeMode = step === 'zones' ? 'plan' : 'map'
  const isBoundaryReady = boundaryClosed && boundaryPoints.length >= 3 && Boolean(mapGeometry)

  useEffect(() => {
    const draft = {
      step,
      mapMode,
      mapView,
      boundaryPoints,
      boundaryClosed,
      designerGeometry,
      form,
      draftZones,
      selectedZoneId,
      zoneForm,
    }

    if (hasMeaningfulDraft(draft)) {
      saveCreateDraft(draft)
      return
    }

    clearCreateDraft()
  }, [boundaryClosed, boundaryPoints, designerGeometry, draftZones, form, mapMode, mapView, selectedZoneId, step, zoneForm])

  useEffect(() => {
    if (boundaryPoints.length < 3 || !estimatedArea) {
      return
    }

    setForm((current) => ({
      ...current,
      plot_size: String(estimatedArea),
    }))
  }, [boundaryPoints.length, estimatedArea])

  useEffect(() => {
    if (!calculatedCenter || cityManuallyEdited) {
      return undefined
    }

    let cancelled = false
    setCityLookupStatus('Detecting city from coordinates...')

    api.reverseGeocode({
      lat: roundCoordinate(calculatedCenter.lat),
      lng: roundCoordinate(calculatedCenter.lng),
    })
      .then((result) => {
        if (cancelled) {
          return
        }

        if (result?.city) {
          setForm((current) => ({
            ...current,
            city: result.city,
          }))
          setCityLookupStatus(`City detected: ${result.city}`)
          return
        }

        setCityLookupStatus('City not detected. Enter it manually.')
      })
      .catch(() => {
        if (!cancelled) {
          setCityLookupStatus('City not detected. Enter it manually.')
        }
      })

    return () => {
      cancelled = true
    }
  }, [calculatedCenter, cityManuallyEdited])

  useEffect(() => {
    setZoneForm(zoneToForm(selectedZone))
  }, [selectedZone])

  function handleFormChange(event) {
    const { name, type, checked, value } = event.target
    if (name === 'city') {
      setCityManuallyEdited(true)
      setCityLookupStatus('')
    }
    setForm((current) => ({
      ...current,
      [name]: type === 'checkbox' ? checked : value,
    }))
  }

  function handleBoundaryPointAdd(point) {
    setDesignerGeometry(null)
    setBoundaryClosed(false)
    setBoundaryPoints((current) => (current.length >= 12 ? current : [...current, point]))
  }

  function handleBoundaryPointMove(index, point) {
    setDesignerGeometry(null)
    setBoundaryPoints((current) => current.map((existingPoint, currentIndex) => (
      currentIndex === index ? point : existingPoint
    )))
  }

  function handleBoundaryPointInsert(index, point) {
    setDesignerGeometry(null)
    setBoundaryPoints((current) => [
      ...current.slice(0, index),
      point,
      ...current.slice(index),
    ])
  }

  function handleBoundaryPointRemove(index) {
    setDesignerGeometry(null)
    const shouldKeepClosed = boundaryClosed && boundaryPoints.length > 3

    setBoundaryPoints((current) => (
      boundaryClosed && current.length <= 3
        ? current
        : current.filter((_, currentIndex) => currentIndex !== index)
    ))

    if (!shouldKeepClosed) {
      setBoundaryClosed(false)
    }
  }

  function handleBoundaryUndo() {
    setDesignerGeometry(null)
    setBoundaryClosed(false)
    setBoundaryPoints((current) => current.slice(0, -1))
  }

  function handleBoundaryClear() {
    setDesignerGeometry(null)
    setBoundaryClosed(false)
    setBoundaryPoints([])
    setDraftZones([])
    setSelectedZoneId(null)
    setZoneForm(emptyZoneForm)
  }

  function handleBoundaryClose() {
    if (boundaryPoints.length < 3) {
      return
    }

    setBoundaryClosed(true)
    setMapMode('boundary')
  }

  function handleStepChange(nextStep) {
    if (['zones', 'summary'].includes(nextStep) && !isBoundaryReady) {
      return
    }

    setStep(nextStep)
    setMapMode('boundary')
  }

  function createDraftZoneId() {
    return `draft-zone-${Date.now()}-${draftZones.length + 1}`
  }

  async function handleCanvasZoneCreate(shape, boundaryShape) {
    const clientId = createDraftZoneId()
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
    setToastMessage(`Added ${createdZone.name} to the draft.`)

    return createdZone
  }

  async function handleZoneCreateFromForm() {
    if (!designerCanvasRef.current?.createZoneFromForm) {
      setZoneError('The plot designer is still loading. Please try again.')
      return
    }

    setZoneError('')
    await designerCanvasRef.current.createZoneFromForm()
  }

  function handleZoneSelect(zone) {
    setSelectedZoneId(zone?.id ?? null)
  }

  function handleZoneApply(event) {
    event.preventDefault()

    if (!selectedZoneId) {
      return
    }

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

    setDraftZones((current) => current.filter((zone) => !sameId(zone.id, selectedZoneId)))
    setSelectedZoneId(null)
    setZoneForm(emptyZoneForm)
    setToastMessage('Zone removed from the draft.')
  }

  function handleZoneGeometryCommit(zoneId, shape, boundaryShape) {
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
    const nextGeometry = shapeToGeometry(nextBoundary)

    setForm((current) => ({
      ...current,
      plot_size: String(calculateArea(nextBoundary)),
    }))

    if (nextGeometry) {
      setDesignerGeometry({
        ...nextGeometry,
        map: planGeometry?.map ?? null,
      })
    }

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

  async function handleSave(event) {
    event.preventDefault()
    setSaveError('')
    setZoneError('')

    if (!isBoundaryReady || !planGeometry) {
      setSaveError('Draw and close the plot boundary before saving.')
      return
    }

    const sanitizedPlot = assertSanitizedGeometryPayload('Plot geometry', planGeometry)

    if (sanitizedPlot.error) {
      setSaveError(sanitizedPlot.error)
      return
    }

    const sanitizedZones = []

    for (const zone of draftZones) {
      const sanitizedZone = assertSanitizedGeometryPayload(`Zone "${zone.name}" geometry`, zone.geometry ?? null)

      if (sanitizedZone.error) {
        setSaveError(sanitizedZone.error)
        return
      }

      sanitizedZones.push({
        ...zone,
        geometry: sanitizedZone.geometry,
      })
    }

    const fullPlotGeometry = {
      ...planGeometry,
      points: sanitizedPlot.geometry.points,
    }

    setSubmitting(true)

    try {
      const created = await api.createPlot({
        ...form,
        plot_size: Number(form.plot_size || estimatedArea || effectivePlotSize),
        share: Boolean(form.share),
        geometry: fullPlotGeometry,
      })

      if (sanitizedZones.length > 0) {
        await api.commitPlotWorkspace(created.id, {
          plot: {
            plot_size: Number(form.plot_size || estimatedArea || effectivePlotSize),
            geometry: fullPlotGeometry,
          },
          zones: sanitizedZones.map((zone) => ({
            id: zone.id,
            client_id: zone.client_id,
            name: zone.name,
            zone_size: zone.zone_size,
            soil_type: zone.soil_type,
            rotation_stage: Number(zone.rotation_stage || 0),
            last_planting_date: zone.last_planting_date || null,
            geometry: zone.geometry,
          })),
          plants: [],
        })
      }

      clearCreateDraft()
      navigate(`/plots/${created.id}`)
    } catch (requestError) {
      setSaveError(requestError.message)
    } finally {
      setSubmitting(false)
    }
  }

  function resetDraft() {
    clearCreateDraft()
    setStep('boundary')
    setMapMode('boundary')
    setMapView({ center: DEFAULT_LOCATION, zoom: 13 })
    setBoundaryPoints([])
    setBoundaryClosed(false)
    setDesignerGeometry(null)
    setForm(emptyForm)
    setDraftZones([])
    setSelectedZoneId(null)
    setZoneForm(emptyZoneForm)
    setCityManuallyEdited(false)
    setCityLookupStatus('')
    setSaveError('')
    setZoneError('')
  }

  const stepOptions = [
    { value: 'boundary', label: '1. Boundary' },
    { value: 'zones', label: '2. Zones', disabled: !isBoundaryReady },
    { value: 'summary', label: '3. Summary', disabled: !isBoundaryReady },
  ]

  return (
    <div className="page-stack plot-create-page">
      <PageHeader
        eyebrow="Plot creation"
        title="Create plot from map boundary"
        description="Mark the plot corners on the map first. Center coordinates, area, and perimeter are calculated automatically."
        meta={(
          <>
            <StatusBadge kind="selection" tone={activeMode === 'map' ? 'success' : 'neutral'}>
              {activeMode === 'map' ? 'Draw boundary' : 'Create zones'}
            </StatusBadge>
            <StatusBadge kind="status" tone={isBoundaryReady ? 'success' : 'warning'}>
              {boundaryPoints.length} points
            </StatusBadge>
          </>
        )}
        actions={(
          <>
            <Link to="/plots">
              <Button variant="ghost">Back to plots</Button>
            </Link>
            <Button variant="secondary" onClick={resetDraft}>Clear draft</Button>
          </>
        )}
      />

      <SuccessToast message={toastMessage} onDismiss={() => setToastMessage('')} />

      <SectionCard>
        <div className="plot-create-stepbar">
          <ModeToggleGroup
            ariaLabel="Plot creation steps"
            value={step}
            onChange={handleStepChange}
            options={stepOptions}
          />
        </div>
      </SectionCard>

      {step === 'boundary' ? (
        <SectionCard
          title="Draw plot boundary"
          description="Click the map to place corners. Close the boundary after at least 3 points, then drag corners to adjust."
        >
          <div className="plot-create-map-layout">
            <PlotLocationMap
              mode={mapMode}
              boundaryClosed={boundaryClosed}
              selectedLocation={calculatedCenter}
              boundaryPoints={boundaryPoints}
              view={mapView}
              onBoundaryPointAdd={handleBoundaryPointAdd}
              onBoundaryPointInsert={handleBoundaryPointInsert}
              onBoundaryPointMove={handleBoundaryPointMove}
              onBoundaryPointRemove={handleBoundaryPointRemove}
              onViewChange={setMapView}
            />

            <Surface as="aside" className="plot-create-side-panel">
              <div className="plot-create-side-block">
                <span className="designer-toolbar-kicker">Plot center</span>
                <strong>
                  {calculatedCenter
                    ? `${roundCoordinate(calculatedCenter.lat)}, ${roundCoordinate(calculatedCenter.lng)}`
                    : 'Calculated after 3 points'}
                </strong>
              </div>
              <div className="plot-create-side-block">
                <span className="designer-toolbar-kicker">Boundary</span>
                <strong>{boundaryPoints.length} points</strong>
                <span className="section-copy">
                  {isBoundaryReady
                    ? 'Boundary is ready. Continue to zones.'
                    : boundaryPoints.length >= 3
                      ? 'Close the boundary or adjust corners.'
                      : 'Mark at least 3 plot corners.'}
                </span>
              </div>
              <div className="plot-create-side-block">
                <span className="designer-toolbar-kicker">Measurements</span>
                <strong>{formatSquareMeters(estimatedArea, 1)}</strong>
                <span className="section-copy">{formatMeters(estimatedPerimeter)} perimeter</span>
              </div>
              <div className="form-actions">
                <Button
                  variant="secondary"
                  onClick={handleBoundaryClose}
                  disabled={boundaryPoints.length < 3 || boundaryClosed}
                >
                  Close boundary
                </Button>
                <Button variant="ghost" onClick={() => setBoundaryClosed(false)} disabled={!boundaryClosed}>
                  Edit boundary
                </Button>
                <Button variant="ghost" onClick={handleBoundaryUndo} disabled={boundaryPoints.length === 0}>
                  Undo last point
                </Button>
                <Button variant="ghost" onClick={handleBoundaryClear} disabled={boundaryPoints.length === 0}>
                  Clear boundary
                </Button>
                <Button
                  onClick={() => setStep('zones')}
                  disabled={!isBoundaryReady}
                >
                  Create zones
                </Button>
              </div>
              {boundaryPoints.length ? (
                <div className="plot-boundary-point-list">
                  {boundaryPoints.map((point, index) => (
                    <button
                      key={`remove-boundary-point-${index}`}
                      type="button"
                      title={`Remove point ${index + 1}`}
                      onClick={() => handleBoundaryPointRemove(index)}
                      disabled={boundaryClosed && boundaryPoints.length <= 3}
                    >
                      <span>{index + 1}</span>
                      <strong>{roundCoordinate(point.lat)}, {roundCoordinate(point.lng)}</strong>
                    </button>
                  ))}
                </div>
              ) : null}
            </Surface>
          </div>
        </SectionCard>
      ) : null}

      {step === 'zones' ? (
        <div className="plot-create-zones-layout">
          <section className="plot-create-designer">
            <PlotDesignerCanvas
              ref={designerCanvasRef}
              plotId="new-plot-draft"
              plotName={form.name || 'New plot'}
              plotSize={effectivePlotSize}
              plotGeometry={planGeometry}
              zones={draftZones}
              plants={[]}
              canEdit
              activeZoneId={selectedZoneId}
              persistState={false}
              showSaveAction={false}
              isLayoutSaveDisabled={false}
              isLayoutSaving={submitting}
              layoutSaveFeedback={{ type: 'idle', message: '' }}
              onSaveLayout={() => setStep('summary')}
              onSelectZone={handleZoneSelect}
              onCreateZone={handleCanvasZoneCreate}
              onZoneCreateBlocked={setZoneError}
              onZoneGeometryCommit={handleZoneGeometryCommit}
              onBoundaryCommit={handleBoundaryCommit}
            />
          </section>

          <aside className="plot-create-zone-panel page-stack">
            <SectionCard
              title={selectedZone ? 'Zone details' : 'New zone'}
              description="Draw a zone inside the marked plot boundary, then refine its details before the final save."
            >
              <form className="input-grid" onSubmit={handleZoneApply}>
                <div className="field field-span-2">
                  <label htmlFor="create-zone-name">Zone name</label>
                  <input
                    id="create-zone-name"
                    value={zoneForm.name}
                    onChange={(event) => setZoneForm((current) => ({ ...current, name: event.target.value }))}
                  />
                </div>
                <div className="field">
                  <label htmlFor="create-zone-soil">Soil type</label>
                  <select
                    id="create-zone-soil"
                    value={zoneForm.soil_type}
                    onChange={(event) => setZoneForm((current) => ({ ...current, soil_type: event.target.value }))}
                  >
                    {SOIL_TYPES.map((soilType) => (
                      <option key={soilType} value={soilType}>{soilType}</option>
                    ))}
                  </select>
                </div>
                <div className="field">
                  <label htmlFor="create-zone-rotation">Rotation stage</label>
                  <input
                    id="create-zone-rotation"
                    type="number"
                    min="0"
                    step="1"
                    value={zoneForm.rotation_stage}
                    onChange={(event) => setZoneForm((current) => ({ ...current, rotation_stage: event.target.value }))}
                  />
                </div>
                <details className="advanced-zone-details field-span-2" open={Boolean(zoneForm.last_planting_date)}>
                  <summary>Optional planting data</summary>
                  <div className="field">
                    <label htmlFor="create-zone-last-date">Last planting date</label>
                    <input
                      id="create-zone-last-date"
                      type="date"
                      value={zoneForm.last_planting_date}
                      onChange={(event) => setZoneForm((current) => ({ ...current, last_planting_date: event.target.value }))}
                    />
                  </div>
                </details>

                {zoneError ? <span className="field-error">{zoneError}</span> : null}

                <div className="form-actions field-span-2">
                  {selectedZone ? (
                    <>
                      <Button type="submit" variant="secondary">Apply details</Button>
                      <Button variant="ghost" onClick={() => setSelectedZoneId(null)}>New zone</Button>
                      <Button variant="danger" onClick={handleZoneDelete}>Delete zone</Button>
                    </>
                  ) : (
                    <>
                      <Button variant="secondary" onClick={handleZoneCreateFromForm}>Add zone</Button>
                      <Button variant="ghost" onClick={() => setZoneForm(emptyZoneForm)}>Clear</Button>
                    </>
                  )}
                </div>
              </form>
            </SectionCard>

            <SectionCard
              title="Plan status"
              description={`${draftZones.length} zones are ready for the final plot save.`}
            >
              <div className="form-actions">
                <Button variant="ghost" onClick={() => setStep('boundary')}>Back to boundary</Button>
                <Button onClick={() => setStep('summary')} disabled={!isBoundaryReady}>Review summary</Button>
              </div>
            </SectionCard>
          </aside>
        </div>
      ) : null}

      {step === 'summary' ? (
        <form className="page-stack" onSubmit={handleSave}>
          <FormSection
            title="Plot summary and save"
            description="Review plot details, calculated center, boundary, and zones before saving the plan."
          >
            <div className="input-grid">
              <div className="field">
                <label htmlFor="new-plot-name">Name</label>
                <input id="new-plot-name" name="name" value={form.name} onChange={handleFormChange} required />
              </div>
              <div className="field">
                <label htmlFor="new-plot-city">City</label>
                <input
                  id="new-plot-city"
                  name="city"
                  value={form.city}
                  onChange={handleFormChange}
                  placeholder={calculatedCenter ? 'Not detected' : 'Enter city'}
                  required
                />
                {cityLookupStatus ? <span className="field-hint">{cityLookupStatus}</span> : null}
              </div>
              <div className="field">
                <label htmlFor="new-plot-size">Plot size (m2)</label>
                <input
                  id="new-plot-size"
                  name="plot_size"
                  type="number"
                  min="0.01"
                  step="0.01"
                  value={form.plot_size}
                  onChange={handleFormChange}
                  required
                />
              </div>
              <div className="field">
                <label htmlFor="new-plot-date">Creation date</label>
                <input
                  id="new-plot-date"
                  name="creation_date"
                  type="date"
                  value={form.creation_date}
                  onChange={handleFormChange}
                  required
                />
              </div>
              <div className="field field-span-2">
                <label htmlFor="new-plot-description">Description</label>
                <textarea
                  id="new-plot-description"
                  name="description"
                  value={form.description}
                  onChange={handleFormChange}
                />
              </div>
              <label className="field field-span-2">
                <span>Community sharing</span>
                <select
                  name="share"
                  value={String(form.share)}
                  onChange={(event) => {
                    setForm((current) => ({
                      ...current,
                      share: event.target.value === 'true',
                    }))
                  }}
                >
                  <option value="false">Private</option>
                  <option value="true">Shared</option>
                </select>
              </label>
            </div>

            <div className="plot-create-summary-grid">
              <article className="designer-stat-card">
                <span className="designer-stat-label">Plot center</span>
                <strong className="designer-stat-value designer-stat-value--summary">
                  {calculatedCenter
                    ? `${roundCoordinate(calculatedCenter.lat)}, ${roundCoordinate(calculatedCenter.lng)}`
                    : 'Not calculated'}
                </strong>
              </article>
              <article className="designer-stat-card">
                <span className="designer-stat-label">City</span>
                <strong className="designer-stat-value designer-stat-value--summary">
                  {form.city?.trim() || 'Not detected'}
                </strong>
              </article>
              <article className="designer-stat-card">
                <span className="designer-stat-label">Boundary</span>
                <strong className="designer-stat-value">{boundaryPoints.length} points</strong>
              </article>
              <article className="designer-stat-card">
                <span className="designer-stat-label">Area</span>
                <strong className="designer-stat-value">{formatSquareMeters(estimatedArea, 1)}</strong>
              </article>
              <article className="designer-stat-card">
                <span className="designer-stat-label">Zones</span>
                <strong className="designer-stat-value">{draftZones.length}</strong>
              </article>
            </div>

            {saveError ? <ErrorState description={saveError} /> : null}

            {submitting ? (
              <ProcessingState
                title="Creating plot"
                description="Saving the mapped boundary and draft zones."
                steps={['Creating plot', 'Saving zone draft', 'Opening plot workspace']}
                compact
              />
            ) : null}

            <div className="form-actions">
              <Button variant="ghost" onClick={() => setStep('zones')}>Back to zones</Button>
              <Button type="submit" loading={submitting}>Save plot</Button>
            </div>
          </FormSection>
        </form>
      ) : null}
    </div>
  )
}
