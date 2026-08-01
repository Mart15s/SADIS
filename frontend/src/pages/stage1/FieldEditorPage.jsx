import { useEffect, useMemo, useReducer, useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import PageHeader from '../../components/layout/PageHeader.jsx'
import { ErrorState, LoadingState, SuccessToast } from '../../components/shared/StatusView.jsx'
import Button from '../../components/ui/Button.jsx'
import { api } from '../../lib/api.js'
import { useAsyncData } from '../../lib/hooks/useAsyncData.js'
import { useUnsavedChangesGuard } from '../../lib/hooks/useUnsavedChangesGuard.js'
import { fieldZoneKey, normalizeFieldWorkspace, safeWorkspacePoints, serializeFieldWorkspace } from '../../lib/fieldWorkspace.js'

const emptyDraft = { geometry: [], zones: [], markers: [], client_revision: 0 }

function draftReducer(state, action) {
  if (action.type === 'reset') return { present: action.value, past: [], future: [] }
  if (action.type === 'undo' && state.past.length) return { present: state.past.at(-1), past: state.past.slice(0, -1), future: [state.present, ...state.future] }
  if (action.type === 'redo' && state.future.length) return { present: state.future[0], past: [...state.past, state.present], future: state.future.slice(1) }
  if (action.type === 'change') return { present: action.value, past: [...state.past.slice(-24), state.present], future: [] }
  return state
}

export default function FieldEditorPage() {
  const { fieldId } = useParams()
  const navigate = useNavigate()
  const storageKey = `yava-field-draft:${fieldId}`
  const [draftState, dispatch] = useReducer(draftReducer, { present: emptyDraft, past: [], future: [] })
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

  useUnsavedChangesGuard({ when: dirty, message: 'You have unsaved field changes. Leave without saving?' })

  useEffect(() => {
    if (!pageState.data) return
    let recovered = null
    try { recovered = JSON.parse(localStorage.getItem(storageKey)) } catch { /* ignore invalid local recovery */ }
    const serverDraft = normalizeFieldWorkspace(pageState.data)
    const initial = recovered ? normalizeFieldWorkspace(recovered) : serverDraft
    dispatch({ type: 'reset', value: initial })
    setSavedSignature(JSON.stringify(serverDraft))
  }, [pageState.data, storageKey])

  useEffect(() => {
    if (dirty) localStorage.setItem(storageKey, JSON.stringify(draft))
  }, [dirty, draft, storageKey])

  const polygon = useMemo(() => draft.geometry.map((point) => `${point.x},${point.y}`).join(' '), [draft.geometry])

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
    const zone = { client_id: nextId, name: `Zone ${draft.zones.length + 1}`, color: '#DA743A', geometry: [{ x: 30, y: 30 }, { x: 60, y: 30 }, { x: 60, y: 60 }, { x: 30, y: 60 }] }
    dispatch({ type: 'change', value: { ...draft, zones: [...draft.zones, zone] } })
    setSelectedZone(nextId)
    setPanelOpen(true)
  }

  function updateZone(name) {
    dispatch({ type: 'change', value: { ...draft, zones: draft.zones.map((zone) => fieldZoneKey(zone) === String(selectedZone) ? { ...zone, name } : zone) } })
  }

  async function save() {
    if (saving || draft.geometry.length < 3) return
    setSaving(true)
    setError('')
    try {
      const saved = await api.putV1Path(`fields/${fieldId}/workspace`, serializeFieldWorkspace(draft))
      const next = { ...draft, client_revision: saved?.workspace_revision ?? draft.client_revision + 1 }
      dispatch({ type: 'reset', value: next })
      setSavedSignature(JSON.stringify(next))
      localStorage.removeItem(storageKey)
      setToast('Field workspace saved.')
    } catch (requestError) { setError(requestError.message) }
    finally { setSaving(false) }
  }

  function cancel() {
    if (dirty && !window.confirm('Discard the recoverable field draft?')) return
    localStorage.removeItem(storageKey)
    navigate('/fields')
  }

  if (pageState.loading) return <LoadingState title="Loading field editor…" />
  if (pageState.error) return <ErrorState error={pageState.error} onRetry={pageState.reload} />

  return <div className="field-editor-page">
    <PageHeader eyebrow="Field workspace" title={pageState.data?.name || 'Field editor'} description="Draw the field boundary, add optional zones, and save one recoverable workspace revision." actions={<><Button variant="secondary" onClick={cancel}>Cancel</Button><Button onClick={save} loading={saving} disabled={!dirty || draft.geometry.length < 3}>Save field</Button></>} />
    <SuccessToast message={toast} onDismiss={() => setToast('')} />
    {error ? <ErrorState description={error} /> : null}
    <div className="field-editor">
      <div className="field-editor-toolbar" role="toolbar" aria-label="Field editing tools">
        <button className={mode === 'boundary' ? 'is-active' : ''} onClick={() => setMode('boundary')} type="button">Boundary</button>
        <button className={mode === 'zones' ? 'is-active' : ''} onClick={() => setMode('zones')} type="button">Zones</button>
        <button onClick={() => dispatch({ type: 'undo' })} disabled={!draftState.past.length} type="button">Undo</button>
        <button onClick={() => dispatch({ type: 'redo' })} disabled={!draftState.future.length} type="button">Redo</button>
        <button onClick={() => setPanelOpen((value) => !value)} type="button">Layers</button>
      </div>
      <button className="field-canvas" type="button" aria-label="Field map canvas" onClick={canvasClick}>
        <svg viewBox="0 0 100 100" preserveAspectRatio="none" aria-hidden="true">
          <defs><pattern id="field-grid" width="10" height="10" patternUnits="userSpaceOnUse"><path d="M10 0H0V10" fill="none" stroke="rgba(50,51,45,.12)" strokeWidth=".3" /></pattern></defs>
          <rect width="100" height="100" fill="url(#field-grid)" />
          {polygon ? <polygon points={polygon} fill="rgba(238,109,35,.2)" stroke="#A8531B" strokeWidth=".8" vectorEffect="non-scaling-stroke" /> : null}
          {draft.zones.map((zone) => <polygon key={fieldZoneKey(zone)} points={safeWorkspacePoints(zone.geometry).map((point) => `${point.x},${point.y}`).join(' ')} fill={`${zone.color || '#DA743A'}55`} stroke={zone.color || '#DA743A'} strokeWidth=".6" vectorEffect="non-scaling-stroke" />)}
          {draft.geometry.map((point, index) => <circle key={`${point.x}-${point.y}-${index}`} cx={point.x} cy={point.y} r="1.2" fill="#A8531B" />)}
        </svg>
        {draft.geometry.length < 3 ? <span className="field-canvas-hint">Tap at least three points to draw the field boundary</span> : null}
      </button>
      {mode === 'zones' ? <Button className="field-editor-fab" onClick={addZone}>Add zone</Button> : null}
      <aside className={`field-editor-sheet ${panelOpen ? 'is-open' : ''}`} aria-label="Field details">
        <div className="field-editor-sheet-head"><strong>Field details</strong><button type="button" aria-label="Close details" onClick={() => setPanelOpen(false)}>×</button></div>
        <p>{draft.geometry.length} boundary points · {draft.zones.length} zones</p>
        {draft.zones.map((zone) => <label className="field" key={fieldZoneKey(zone)}><span>Zone name</span><input value={zone.name || ''} onFocus={() => setSelectedZone(fieldZoneKey(zone))} onChange={(event) => { setSelectedZone(fieldZoneKey(zone)); updateZone(event.target.value) }} /></label>)}
        <Link to="/crop-seasons">Plan crop seasons</Link>
      </aside>
    </div>
  </div>
}
