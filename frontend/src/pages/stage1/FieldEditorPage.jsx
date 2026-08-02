import { useEffect, useMemo, useReducer, useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import PageHeader from '../../components/layout/PageHeader.jsx'
import { ErrorState, LoadingState, SuccessToast } from '../../components/shared/StatusView.jsx'
import Button from '../../components/ui/Button.jsx'
import { api } from '../../lib/api.js'
import { useAsyncData } from '../../lib/hooks/useAsyncData.js'
import { useUnsavedChangesGuard } from '../../lib/hooks/useUnsavedChangesGuard.js'
import {
  fieldZoneKey,
  normalizeFieldWorkspace,
  safeWorkspacePoints,
  serializeFieldWorkspace,
} from '../../lib/fieldWorkspace.js'

const emptyDraft = { geometry: [], zones: [], markers: [], client_revision: 0 }

function draftReducer(state, action) {
  if (action.type === 'reset') return { present: action.value, past: [], future: [] }
  if (action.type === 'undo' && state.past.length)
    return {
      present: state.past.at(-1),
      past: state.past.slice(0, -1),
      future: [state.present, ...state.future],
    }
  if (action.type === 'redo' && state.future.length)
    return {
      present: state.future[0],
      past: [...state.past, state.present],
      future: state.future.slice(1),
    }
  if (action.type === 'change')
    return { present: action.value, past: [...state.past.slice(-24), state.present], future: [] }
  return state
}

export default function FieldEditorPage() {
  const { fieldId } = useParams()
  const navigate = useNavigate()
  const storageKey = `yava-field-draft:${fieldId}`
  const [draftState, dispatch] = useReducer(draftReducer, {
    present: emptyDraft,
    past: [],
    future: [],
  })
  const [savedSignature, setSavedSignature] = useState(JSON.stringify(emptyDraft))
  const [mode, setMode] = useState('boundary')
  const [selectedZone, setSelectedZone] = useState(null)
  const [panelOpen, setPanelOpen] = useState(false)
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState('')
  const [toast, setToast] = useState('')
  const pageState = useAsyncData(() => api.getV1('fields', fieldId), [fieldId], null)
  const draft = draftState.present
  const dirty = JSON.stringify(draft) !== savedSignature

  useUnsavedChangesGuard({
    when: dirty,
    message: 'You have unsaved field changes. Leave without saving?',
  })

  useEffect(() => {
    if (!pageState.data) return
    let recovered = null
    try {
      recovered = JSON.parse(localStorage.getItem(storageKey))
    } catch {
      /* ignore invalid local recovery */
    }
    const serverDraft = normalizeFieldWorkspace(pageState.data)
    const initial = recovered ? normalizeFieldWorkspace(recovered) : serverDraft
    dispatch({ type: 'reset', value: initial })
    setSavedSignature(JSON.stringify(serverDraft))
  }, [pageState.data, storageKey])

  useEffect(() => {
    if (dirty) localStorage.setItem(storageKey, JSON.stringify(draft))
  }, [dirty, draft, storageKey])

  const polygon = useMemo(
    () => draft.geometry.map((point) => `${point.x},${point.y}`).join(' '),
    [draft.geometry],
  )

  function canvasClick(event) {
    if (mode !== 'boundary') return
    const rect = event.currentTarget.getBoundingClientRect()
    const point = {
      x: Math.round(((event.clientX - rect.left) / rect.width) * 1000) / 10,
      y: Math.round(((event.clientY - rect.top) / rect.height) * 1000) / 10,
    }
    dispatch({ type: 'change', value: { ...draft, geometry: [...draft.geometry, point] } })
  }

  function addZone() {
    const nextId = `draft-${Date.now()}`
    const zone = {
      client_id: nextId,
      name: `Zone ${draft.zones.length + 1}`,
      color: '#DA743A',
      geometry: [
        { x: 30, y: 30 },
        { x: 60, y: 30 },
        { x: 60, y: 60 },
        { x: 30, y: 60 },
      ],
    }
    dispatch({ type: 'change', value: { ...draft, zones: [...draft.zones, zone] } })
    setSelectedZone(nextId)
    setPanelOpen(true)
  }

  function updateBoundaryPoint(index, coordinate, value) {
    const numeric = Math.max(0, Math.min(100, Number(value)))
    dispatch({
      type: 'change',
      value: {
        ...draft,
        geometry: draft.geometry.map((point, pointIndex) =>
          pointIndex === index ? { ...point, [coordinate]: numeric } : point,
        ),
      },
    })
  }

  function removeBoundaryPoint(index) {
    dispatch({
      type: 'change',
      value: { ...draft, geometry: draft.geometry.filter((_, pointIndex) => pointIndex !== index) },
    })
  }

  function updateZone(zoneId, changes) {
    dispatch({
      type: 'change',
      value: {
        ...draft,
        zones: draft.zones.map((zone) =>
          fieldZoneKey(zone) === String(zoneId) ? { ...zone, ...changes } : zone,
        ),
      },
    })
  }

  function updateZonePoint(zoneId, index, coordinate, value) {
    const zone = draft.zones.find((item) => fieldZoneKey(item) === String(zoneId))
    if (!zone) return
    const numeric = Math.max(0, Math.min(100, Number(value)))
    const geometry = safeWorkspacePoints(zone.geometry).map((point, pointIndex) =>
      pointIndex === index ? { ...point, [coordinate]: numeric } : point,
    )
    updateZone(zoneId, { geometry })
  }

  function removeZone(zoneId) {
    dispatch({
      type: 'change',
      value: {
        ...draft,
        zones: draft.zones.filter((zone) => fieldZoneKey(zone) !== String(zoneId)),
      },
    })
    if (String(selectedZone) === String(zoneId)) setSelectedZone(null)
  }

  function updateMarker(markerId, coordinate, value) {
    const numeric = Math.max(0, Math.min(100, Number(value)))
    dispatch({
      type: 'change',
      value: {
        ...draft,
        markers: draft.markers.map((marker, index) =>
          String(marker.id ?? index) === String(markerId)
            ? { ...marker, position: { ...(marker.position || {}), [coordinate]: numeric } }
            : marker,
        ),
      },
    })
  }

  async function save() {
    if (saving || draft.geometry.length < 3) return
    setSaving(true)
    setError('')
    try {
      const saved = await api.putV1Path(
        `fields/${fieldId}/workspace`,
        serializeFieldWorkspace(draft),
      )
      const next = {
        ...draft,
        client_revision: saved?.workspace_revision ?? draft.client_revision + 1,
      }
      dispatch({ type: 'reset', value: next })
      setSavedSignature(JSON.stringify(next))
      localStorage.removeItem(storageKey)
      setToast('Field workspace saved.')
    } catch (requestError) {
      setError(requestError.message)
    } finally {
      setSaving(false)
    }
  }

  function cancel() {
    if (dirty && !window.confirm('Discard the recoverable field draft?')) return
    localStorage.removeItem(storageKey)
    navigate('/fields')
  }

  if (pageState.loading) return <LoadingState title="Loading field editor…" />
  if (pageState.error) return <ErrorState error={pageState.error} onRetry={pageState.reload} />

  return (
    <div className="field-editor-page">
      <PageHeader
        eyebrow="Field workspace"
        title={pageState.data?.name || 'Field editor'}
        description="Draw the field boundary, add optional zones, and save one recoverable workspace revision."
        actions={
          <>
            <Button variant="secondary" onClick={cancel}>
              Cancel
            </Button>
            <Button onClick={save} loading={saving} disabled={!dirty || draft.geometry.length < 3}>
              Save field
            </Button>
          </>
        }
      />
      <SuccessToast message={toast} onDismiss={() => setToast('')} />
      {error ? <ErrorState description={error} /> : null}
      <div className="field-editor">
        <div className="field-editor-toolbar" role="toolbar" aria-label="Field editing tools">
          <button
            className={mode === 'boundary' ? 'is-active' : ''}
            onClick={() => setMode('boundary')}
            type="button"
          >
            Boundary
          </button>
          <button
            className={mode === 'zones' ? 'is-active' : ''}
            onClick={() => setMode('zones')}
            type="button"
          >
            Zones
          </button>
          <button
            onClick={() => dispatch({ type: 'undo' })}
            disabled={!draftState.past.length}
            type="button"
          >
            Undo
          </button>
          <button
            onClick={() => dispatch({ type: 'redo' })}
            disabled={!draftState.future.length}
            type="button"
          >
            Redo
          </button>
          <button
            onClick={() =>
              dispatch({
                type: 'change',
                value: { ...draft, geometry: draft.geometry.slice(0, -1) },
              })
            }
            disabled={!draft.geometry.length}
            type="button"
          >
            Remove last point
          </button>
          <button
            onClick={() => {
              setMode('zones')
              setPanelOpen(true)
            }}
            disabled={draft.geometry.length < 3}
            type="button"
          >
            Close boundary
          </button>
          <button onClick={() => setPanelOpen((value) => !value)} type="button">
            Layers
          </button>
        </div>
        <button
          className="field-canvas"
          type="button"
          aria-label="Field map canvas. Tap to add a boundary point."
          aria-describedby="field-canvas-instructions"
          onClick={canvasClick}
        >
          <svg viewBox="0 0 100 100" preserveAspectRatio="none" aria-hidden="true">
            <defs>
              <pattern id="field-grid" width="10" height="10" patternUnits="userSpaceOnUse">
                <path d="M10 0H0V10" fill="none" stroke="rgba(50,51,45,.12)" strokeWidth=".3" />
              </pattern>
            </defs>
            <rect width="100" height="100" fill="url(#field-grid)" />
            {polygon ? (
              <polygon
                points={polygon}
                fill="rgba(238,109,35,.2)"
                stroke="#A8531B"
                strokeWidth=".8"
                vectorEffect="non-scaling-stroke"
              />
            ) : null}
            {draft.zones.map((zone) => (
              <polygon
                key={fieldZoneKey(zone)}
                points={safeWorkspacePoints(zone.geometry)
                  .map((point) => `${point.x},${point.y}`)
                  .join(' ')}
                fill={`${zone.color || '#DA743A'}55`}
                stroke={zone.color || '#DA743A'}
                strokeWidth=".6"
                vectorEffect="non-scaling-stroke"
              />
            ))}
            {draft.geometry.map((point, index) => (
              <circle
                key={`${point.x}-${point.y}-${index}`}
                cx={point.x}
                cy={point.y}
                r="1.2"
                fill="#A8531B"
              />
            ))}
          </svg>
          {draft.geometry.length < 3 ? (
            <span className="field-canvas-hint" id="field-canvas-instructions">
              Tap at least three points to draw the field boundary
            </span>
          ) : (
            <span className="sr-only" id="field-canvas-instructions">
              Use Close boundary when the polygon is ready. Coordinates can be edited in Field
              details.
            </span>
          )}
        </button>
        {mode === 'zones' ? (
          <Button className="field-editor-fab" onClick={addZone}>
            Add zone
          </Button>
        ) : null}
        <aside
          className={`field-editor-sheet ${panelOpen ? 'is-open' : ''}`}
          aria-label="Field details"
        >
          <div className="field-editor-sheet-head">
            <strong>Field details</strong>
            <button type="button" aria-label="Close details" onClick={() => setPanelOpen(false)}>
              ×
            </button>
          </div>
          <p>
            {draft.geometry.length} boundary points · {draft.zones.length} zones
          </p>
          <details className="field-editor-coordinate-group">
            <summary>Boundary coordinates</summary>
            {draft.geometry.map((point, index) => (
              <div className="field-editor-coordinate-row" key={`boundary-${index}`}>
                <span>Point {index + 1}</span>
                <label>
                  <span className="sr-only">Point {index + 1} X coordinate</span>
                  <input
                    type="number"
                    min="0"
                    max="100"
                    step="0.1"
                    value={point.x}
                    onChange={(event) => updateBoundaryPoint(index, 'x', event.target.value)}
                  />
                </label>
                <label>
                  <span className="sr-only">Point {index + 1} Y coordinate</span>
                  <input
                    type="number"
                    min="0"
                    max="100"
                    step="0.1"
                    value={point.y}
                    onChange={(event) => updateBoundaryPoint(index, 'y', event.target.value)}
                  />
                </label>
                <button
                  type="button"
                  aria-label={`Remove boundary point ${index + 1}`}
                  onClick={() => removeBoundaryPoint(index)}
                >
                  ×
                </button>
              </div>
            ))}
          </details>
          {draft.zones.map((zone) => {
            const zoneId = fieldZoneKey(zone)
            return (
              <details
                className="field-editor-coordinate-group"
                key={zoneId}
                open={String(selectedZone) === zoneId}
                onToggle={(event) => {
                  if (event.currentTarget.open) setSelectedZone(zoneId)
                }}
              >
                <summary>{zone.name || 'Unnamed zone'}</summary>
                <label className="field">
                  <span>Zone name</span>
                  <input
                    value={zone.name || ''}
                    onFocus={() => setSelectedZone(zoneId)}
                    onChange={(event) => updateZone(zoneId, { name: event.target.value })}
                  />
                </label>
                {safeWorkspacePoints(zone.geometry).map((point, index) => (
                  <div className="field-editor-coordinate-row" key={`${zoneId}-${index}`}>
                    <span>Point {index + 1}</span>
                    <label>
                      <span className="sr-only">Zone point {index + 1} X coordinate</span>
                      <input
                        type="number"
                        min="0"
                        max="100"
                        step="0.1"
                        value={point.x}
                        onChange={(event) =>
                          updateZonePoint(zoneId, index, 'x', event.target.value)
                        }
                      />
                    </label>
                    <label>
                      <span className="sr-only">Zone point {index + 1} Y coordinate</span>
                      <input
                        type="number"
                        min="0"
                        max="100"
                        step="0.1"
                        value={point.y}
                        onChange={(event) =>
                          updateZonePoint(zoneId, index, 'y', event.target.value)
                        }
                      />
                    </label>
                  </div>
                ))}
                <Button size="sm" variant="danger" onClick={() => removeZone(zoneId)}>
                  Remove zone
                </Button>
              </details>
            )
          })}
          {draft.markers.length ? (
            <details className="field-editor-coordinate-group">
              <summary>Markers</summary>
              {draft.markers.map((marker, index) => {
                const markerId = String(marker.id ?? index)
                const position = marker.position || {}
                return (
                  <div className="field-editor-marker" key={markerId}>
                    <strong>{marker.label || marker.type || `Marker ${index + 1}`}</strong>
                    <div className="field-editor-coordinate-row">
                      <label>
                        <span className="sr-only">Marker X coordinate</span>
                        <input
                          type="number"
                          min="0"
                          max="100"
                          step="0.1"
                          value={position.x ?? 50}
                          onChange={(event) => updateMarker(markerId, 'x', event.target.value)}
                        />
                      </label>
                      <label>
                        <span className="sr-only">Marker Y coordinate</span>
                        <input
                          type="number"
                          min="0"
                          max="100"
                          step="0.1"
                          value={position.y ?? 50}
                          onChange={(event) => updateMarker(markerId, 'y', event.target.value)}
                        />
                      </label>
                    </div>
                  </div>
                )
              })}
            </details>
          ) : null}
          <Link to="/crop-seasons">Plan crop seasons</Link>
        </aside>
        <div className="field-editor-mobile-actions" aria-label="Field save actions">
          <Button variant="secondary" onClick={cancel}>
            Cancel
          </Button>
          <Button onClick={save} loading={saving} disabled={!dirty || draft.geometry.length < 3}>
            Save field
          </Button>
        </div>
      </div>
    </div>
  )
}
