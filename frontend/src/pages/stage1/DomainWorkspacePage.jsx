import { useEffect, useMemo, useState } from 'react'
import { Link } from 'react-router-dom'
import PageHeader from '../../components/layout/PageHeader.jsx'
import { ErrorState, LoadingState, SuccessToast } from '../../components/shared/StatusView.jsx'
import Badge from '../../components/ui/Badge.jsx'
import Button from '../../components/ui/Button.jsx'
import { useWorkspace } from '../../context/useWorkspace.js'
import { api } from '../../lib/api.js'
import { useAsyncData } from '../../lib/hooks/useAsyncData.js'
import { useI18n } from '../../i18n/i18n-context.js'
import { domainDefinitions, makeInitialForm } from './domainDefinitions.js'

function displayName(item, definition) {
  return item.name || item.title || item.crop?.name || `${definition.item} ${item.id}`
}

function contextParams(active) {
  return active ? { [`${active.type}_id`]: active.id } : {}
}

export default function DomainWorkspacePage({ resource }) {
  const definition = domainDefinitions[resource]
  const { active } = useWorkspace()
  const { formatArea, formatDate, formatDateTime } = useI18n()
  const [query, setQuery] = useState('')
  const [isFormOpen, setIsFormOpen] = useState(false)
  const [form, setForm] = useState(() => makeInitialForm(definition))
  const [editingId, setEditingId] = useState(null)
  const [pendingId, setPendingId] = useState(null)
  const [mutationError, setMutationError] = useState('')
  const [toast, setToast] = useState('')

  const pageState = useAsyncData(
    () => api.listV1(resource, { ...contextParams(active), search: query || undefined }),
    [resource, active?.type, active?.id, query],
    [],
  )

  useEffect(() => {
    setForm(makeInitialForm(definition))
    setEditingId(null)
    setIsFormOpen(false)
    setMutationError('')
  }, [definition, active?.id])

  const rows = useMemo(() => pageState.data ?? [], [pageState.data])

  function startCreate() {
    const initial = makeInitialForm(definition)
    if (active) initial[`${active.type}_id`] = active.id
    setForm(initial)
    setEditingId(null)
    setMutationError('')
    setIsFormOpen(true)
  }

  function startEdit(item) {
    const initial = makeInitialForm(definition)
    definition.fields.forEach((field) => { initial[field.name] = item[field.name] ?? initial[field.name] })
    setForm(initial)
    setEditingId(item.id)
    setMutationError('')
    setIsFormOpen(true)
  }

  async function submit(event) {
    event.preventDefault()
    if (pendingId) return
    setPendingId(editingId || 'create')
    setMutationError('')
    try {
      const saved = editingId
        ? await api.updateV1(resource, editingId, form)
        : await api.createV1(resource, form)
      pageState.setData((current) => editingId
        ? current.map((item) => (String(item.id) === String(editingId) ? { ...item, ...saved } : item))
        : [saved, ...current])
      setToast(`${definition.item[0].toUpperCase()}${definition.item.slice(1)} saved.`)
      setIsFormOpen(false)
      setEditingId(null)
      setForm(makeInitialForm(definition))
    } catch (error) {
      setMutationError(error.message)
    } finally {
      setPendingId(null)
    }
  }

  async function remove(item) {
    if (pendingId || !window.confirm(`Delete ${displayName(item, definition)}?`)) return
    setPendingId(item.id)
    setMutationError('')
    try {
      await api.deleteV1(resource, item.id)
      pageState.setData((current) => current.filter((entry) => String(entry.id) !== String(item.id)))
      setToast(`${definition.item[0].toUpperCase()}${definition.item.slice(1)} deleted.`)
    } catch (error) {
      setMutationError(error.message)
    } finally {
      setPendingId(null)
    }
  }

  async function transition(item, action) {
    if (pendingId) return
    setPendingId(item.id)
    setMutationError('')
    try {
      const updated = await api.transitionV1(resource, item.id, action)
      pageState.setData((current) => current.map((entry) => String(entry.id) === String(item.id) ? { ...entry, ...updated } : entry))
      setToast(`${definition.item[0].toUpperCase()}${definition.item.slice(1)} ${action}d.`)
    } catch (error) {
      setMutationError(error.message)
    } finally {
      setPendingId(null)
    }
  }

  return (
    <div className="page-stack stage1-page">
      <PageHeader
        eyebrow={active ? `${active.type} workspace` : 'Yava workspace'}
        title={definition.title}
        description={definition.description}
        actions={<Button onClick={startCreate}>Add {definition.item}</Button>}
      />
      <SuccessToast message={toast} onDismiss={() => setToast('')} />
      <div className="stage1-toolbar">
        <label className="stage1-search">
          <span className="sr-only">Search {definition.title}</span>
          <input value={query} onChange={(event) => setQuery(event.target.value)} placeholder={`Search ${definition.title.toLowerCase()}`} />
        </label>
        <span className="stage1-count">{rows.length} results</span>
      </div>

      {mutationError ? <ErrorState description={mutationError} /> : null}
      {pageState.loading ? <LoadingState title={`Loading ${definition.title.toLowerCase()}…`} /> : null}
      {pageState.error ? <ErrorState error={pageState.error} onRetry={pageState.reload} /> : null}

      {!pageState.loading && !pageState.error && rows.length === 0 ? (
        <section className="stage1-empty">
          <span className="stage1-empty-mark" aria-hidden="true">Y</span>
          <h2>Start here</h2>
          <p>{definition.empty}</p>
          <Button onClick={startCreate}>Add {definition.item}</Button>
        </section>
      ) : null}

      <div className="stage1-card-grid">
        {rows.map((item) => (
          <article className="stage1-record-card" key={item.id}>
            <div className="stage1-record-head">
              <div>
                <span className="eyebrow">{definition.item}</span>
                <h2>{displayName(item, definition)}</h2>
              </div>
              <Badge tone={item.status === 'approved' || item.status === 'active' ? 'success' : 'neutral'}>{item.status || 'active'}</Badge>
            </div>
            {item.description || item.notes || item.purpose ? <p>{item.description || item.notes || item.purpose}</p> : null}
            <dl className="stage1-record-meta">
              {item.area_square_metres || item.total_area_square_metres ? <><dt>Area</dt><dd>{formatArea(item.area_square_metres || item.total_area_square_metres)}</dd></> : null}
              {item.starts_on ? <><dt>Starts</dt><dd>{formatDate(item.starts_on)}</dd></> : null}
              {item.starts_at ? <><dt>Time</dt><dd>{formatDateTime(item.starts_at, {}, item.timezone || active?.timezone)}</dd></> : null}
              {item.quantity !== undefined ? <><dt>Available</dt><dd>{item.quantity} {item.unit}</dd></> : null}
              {item.role ? <><dt>Your role</dt><dd>{item.role}</dd></> : null}
            </dl>
            <div className="stage1-card-actions">
              {resource === 'fields' ? <Link className="button button-primary button-sm" to={`/fields/${item.id}/editor`}>Open editor</Link> : null}
              {resource === 'communities' ? <Link className="button button-secondary button-sm" to={`/communities/${item.id}/members`}>Members</Link> : null}
              {resource === 'farms' ? <Link className="button button-secondary button-sm" to={`/farms/${item.id}/members`}>Members</Link> : null}
              {resource === 'reservations' && item.status === 'pending' ? <Button size="sm" onClick={() => transition(item, 'approve')} loading={pendingId === item.id}>Approve</Button> : null}
              {resource === 'tasks' && item.status !== 'completed' ? <Button size="sm" onClick={() => transition(item, 'complete')} loading={pendingId === item.id}>Complete</Button> : null}
              <Button size="sm" variant="ghost" onClick={() => startEdit(item)}>Edit</Button>
              <Button size="sm" variant="danger" onClick={() => remove(item)} loading={pendingId === item.id}>Delete</Button>
            </div>
          </article>
        ))}
      </div>

      {isFormOpen ? (
        <div className="stage1-modal-layer" role="presentation">
          <button className="stage1-modal-backdrop" aria-label="Close form" onClick={() => setIsFormOpen(false)} />
          <section className="stage1-modal" role="dialog" aria-modal="true" aria-labelledby="stage1-form-title">
            <div className="stage1-modal-head">
              <h2 id="stage1-form-title">{editingId ? 'Edit' : 'Add'} {definition.item}</h2>
              <button type="button" className="stage1-close" aria-label="Close" onClick={() => setIsFormOpen(false)}>×</button>
            </div>
            <form onSubmit={submit} className="stage1-form">
              {definition.fields.map((field) => (
                <label key={field.name} className={field.type === 'checkbox' ? 'stage1-checkbox' : 'field'}>
                  <span>{field.label}</span>
                  {field.type === 'textarea' ? (
                    <textarea value={form[field.name]} required={field.required} onChange={(event) => setForm((current) => ({ ...current, [field.name]: event.target.value }))} />
                  ) : field.type === 'checkbox' ? (
                    <input type="checkbox" checked={Boolean(form[field.name])} onChange={(event) => setForm((current) => ({ ...current, [field.name]: event.target.checked }))} />
                  ) : (
                    <input type={field.type || 'text'} value={form[field.name]} required={field.required} onChange={(event) => setForm((current) => ({ ...current, [field.name]: event.target.value }))} />
                  )}
                </label>
              ))}
              {mutationError ? <p className="field-error" role="alert">{mutationError}</p> : null}
              <div className="form-actions stage1-form-actions">
                <Button type="submit" loading={Boolean(pendingId)}>{pendingId ? 'Saving…' : 'Save'}</Button>
                <Button variant="secondary" onClick={() => setIsFormOpen(false)} disabled={Boolean(pendingId)}>Cancel</Button>
              </div>
            </form>
          </section>
        </div>
      ) : null}
    </div>
  )
}
