import { useEffect, useMemo, useState } from 'react'
import { Link } from 'react-router-dom'
import PageHeader from '../../components/layout/PageHeader.jsx'
import { ErrorState, LoadingState, SuccessToast } from '../../components/shared/StatusView.jsx'
import Badge from '../../components/ui/Badge.jsx'
import Button from '../../components/ui/Button.jsx'
import { Dialog } from '../../components/ui/Dialog.jsx'
import { useWorkspace } from '../../context/useWorkspace.js'
import { api } from '../../lib/api.js'
import { useAsyncData } from '../../lib/hooks/useAsyncData.js'
import { useI18n } from '../../i18n/i18n-context.js'
import { domainDefinitions, makeInitialForm } from './domainDefinitions.js'
import CommunityAccessPanel from './CommunityAccessPanel.jsx'

function displayName(item, definition) {
  return item.name || item.title || item.crop?.name || `${definition.item} ${item.id}`
}

function contextParams(active) {
  return active ? { [`${active.type}_id`]: active.id } : {}
}

function optionLabel(item, fallback) {
  return item.name || item.title || item.user?.email || item.email || fallback
}

const recordActionDefinitions = {
  variety: {
    title: 'Add variety',
    submitLabel: 'Add variety',
    fields: [
      { name: 'name', label: 'Variety name', required: true },
      { name: 'description', label: 'Description', type: 'textarea' },
    ],
  },
  condition: {
    title: 'Record condition',
    submitLabel: 'Record condition',
    fields: [
      { name: 'condition', label: 'Condition', required: true },
      {
        name: 'severity',
        label: 'Severity (optional)',
        type: 'select',
        options: ['', '1', '2', '3', '4', '5'],
      },
      { name: 'observed_at', label: 'Observed at', type: 'datetime-local' },
      { name: 'notes', label: 'Notes', type: 'textarea' },
    ],
  },
  harvest: {
    title: 'Record harvest',
    submitLabel: 'Record harvest',
    fields: [
      { name: 'quantity', label: 'Quantity', type: 'number', step: 'any', required: true },
      { name: 'unit', label: 'Unit', required: true, defaultValue: 'kg' },
      { name: 'harvested_on', label: 'Harvested on', type: 'date', required: true },
      { name: 'quality_grade', label: 'Quality grade' },
      { name: 'notes', label: 'Notes', type: 'textarea' },
    ],
  },
  movement: {
    title: 'Record inventory movement',
    submitLabel: 'Record movement',
    fields: [
      {
        name: 'type',
        label: 'Movement type',
        type: 'select',
        required: true,
        defaultValue: 'receipt',
        options: ['receipt', 'issue', 'consumption', 'return', 'adjustment_in', 'adjustment_out'],
      },
      { name: 'quantity', label: 'Quantity', type: 'number', step: 'any', required: true },
      { name: 'field_id', label: 'Field (optional)', optionsKey: 'fields' },
      {
        name: 'crop_season_id',
        label: 'Crop season (optional)',
        optionsKey: 'seasons',
      },
      { name: 'occurred_at', label: 'Occurred at', type: 'datetime-local' },
      { name: 'notes', label: 'Notes', type: 'textarea' },
    ],
  },
}

function makeRecordActionForm(type) {
  const definition = recordActionDefinitions[type]

  return Object.fromEntries(
    definition.fields.map((field) => [field.name, field.defaultValue ?? '']),
  )
}

function compactPayload(form) {
  return Object.fromEntries(
    Object.entries(form).filter(
      ([, value]) => value !== '' && value !== null && value !== undefined,
    ),
  )
}

function movementReferenceLabel(options, id, fallback) {
  const match = options?.find((option) => String(option.id) === String(id))
  return match ? optionLabel(match, fallback) : fallback
}

function MovementHistory({ item, references = {}, showEmpty = false }) {
  const movements = (item.movements || []).slice(0, 3)
  if (!movements.length && !showEmpty) return null

  return (
    <section className="stage1-record-history" aria-label={`Recent movements for ${item.name}`}>
      <h3>Recent movements</h3>
      {!movements.length ? <p>No movements recorded yet.</p> : null}
      {movements.length ? (
        <ul>
          {movements.map((movement, index) => {
            const fieldLabel = movement.field_id
              ? movementReferenceLabel(
                  references.fields,
                  movement.field_id,
                  `Field #${movement.field_id}`,
                )
              : null
            const seasonLabel = movement.crop_season_id
              ? movementReferenceLabel(
                  references.seasons,
                  movement.crop_season_id,
                  `Crop season #${movement.crop_season_id}`,
                )
              : null

            return (
              <li key={movement.id || `${movement.type}-${movement.occurred_at || index}`}>
                <strong>{(movement.type || 'movement').replaceAll('_', ' ')}</strong> —{' '}
                {movement.quantity} {item.unit}
                {fieldLabel ? ` · ${fieldLabel}` : ''}
                {seasonLabel ? ` · ${seasonLabel}` : ''}
                {movement.notes ? ` · ${movement.notes}` : ''}
              </li>
            )
          })}
        </ul>
      ) : null}
    </section>
  )
}

export default function DomainWorkspacePage({ resource }) {
  const definition = domainDefinitions[resource]
  const { active, contexts, reload: reloadContexts } = useWorkspace()
  const { formatArea, formatDate, formatDateTime } = useI18n()
  const [query, setQuery] = useState('')
  const [isFormOpen, setIsFormOpen] = useState(false)
  const [form, setForm] = useState(() => makeInitialForm(definition))
  const [editingId, setEditingId] = useState(null)
  const [pendingId, setPendingId] = useState(null)
  const [mutationError, setMutationError] = useState('')
  const [mutationDetails, setMutationDetails] = useState({})
  const [toast, setToast] = useState('')
  const [referenceOptions, setReferenceOptions] = useState({})
  const [recordAction, setRecordAction] = useState(null)
  const [recordActionForm, setRecordActionForm] = useState({})
  const [recordActionError, setRecordActionError] = useState('')
  const [recordActionDetails, setRecordActionDetails] = useState({})
  const [recordActionPending, setRecordActionPending] = useState(false)
  const [recordActionLoading, setRecordActionLoading] = useState(false)
  const [recordActionReferences, setRecordActionReferences] = useState({})
  const [rotationWarnings, setRotationWarnings] = useState([])
  const contextAllowed =
    !active || !definition.contextTypes || definition.contextTypes.includes(active.type)
  const hasRequiredContext = !definition.requiresContext || (active && contextAllowed)
  const canManageActive =
    !definition.managePermission || active?.permissions?.includes(definition.managePermission)
  const canTransition =
    !definition.transitionPermission ||
    active?.permissions?.includes(definition.transitionPermission)

  function canManageItem(item) {
    if (!['farms', 'communities'].includes(resource)) return canManageActive
    const itemContext = contexts.find(
      (context) => context.type === resource.slice(0, -1) && String(context.id) === String(item.id),
    )
    return Boolean(itemContext?.permissions?.includes('manage_members'))
  }

  const pageState = useAsyncData(
    () =>
      hasRequiredContext
        ? api.listV1(resource, {
            ...contextParams(contextAllowed ? active : null),
            search: query || undefined,
          })
        : Promise.resolve([]),
    [resource, active?.type, active?.id, contextAllowed, hasRequiredContext, query],
    [],
  )

  useEffect(() => {
    setForm(makeInitialForm(definition))
    setEditingId(null)
    setIsFormOpen(false)
    setMutationError('')
    setMutationDetails({})
    setRecordAction(null)
  }, [definition, active?.id, active?.type])

  useEffect(() => {
    if (!isFormOpen) {
      setReferenceOptions({})
      return undefined
    }

    let cancelled = false
    const safely = (promise) => promise.catch(() => [])

    async function loadReferences() {
      let next = {}

      if (resource === 'crop-seasons' && active?.type === 'farm') {
        const [fields, crops, selectedField] = await Promise.all([
          safely(api.listV1('fields', { farm_id: active.id })),
          safely(api.listV1('crops', { farm_id: active.id })),
          form.field_id ? safely(api.getV1('fields', form.field_id)) : Promise.resolve(null),
        ])
        const selectedCrop = crops.find((crop) => String(crop.id) === String(form.crop_id))
        next = {
          fields,
          crops,
          zones: selectedField?.zones || [],
          varieties: selectedCrop?.varieties || [],
        }
      } else if (resource === 'tasks' && active) {
        const membersPath = `${active.type === 'farm' ? 'farms' : 'communities'}/${active.id}/members`
        const [fields, seasons, members, resources] = await Promise.all([
          active.type === 'farm'
            ? safely(api.listV1('fields', { farm_id: active.id }))
            : Promise.resolve([]),
          active.type === 'farm'
            ? safely(api.listV1('crop-seasons', { farm_id: active.id }))
            : Promise.resolve([]),
          safely(api.listV1Path(membersPath)),
          safely(api.listV1('resources', { [`${active.type}_id`]: active.id })),
        ])
        next = { fields, seasons, members, resources }
      } else if (resource === 'reservations' && active?.type === 'community') {
        const [resources, farms, fields] = await Promise.all([
          safely(api.listV1('resources', { community_id: active.id })),
          safely(api.listV1('farms')),
          form.farm_id
            ? safely(api.listV1('fields', { farm_id: form.farm_id }))
            : Promise.resolve([]),
        ])
        next = { resources, farms, fields }
      }

      if (!cancelled) setReferenceOptions(next)
    }

    loadReferences()
    return () => {
      cancelled = true
    }
  }, [active, form.crop_id, form.farm_id, form.field_id, isFormOpen, resource])

  useEffect(() => {
    if (!recordAction) {
      setRecordActionReferences({})
      setRotationWarnings([])
      return undefined
    }

    let cancelled = false
    const safely = (promise) => promise.catch(() => [])

    async function loadRecordActionData() {
      setRecordActionLoading(true)
      try {
        if (recordAction.type === 'rotation') {
          const warnings = await api.listV1Path(
            `crop-seasons/${recordAction.item.id}/rotation-warnings`,
          )
          if (!cancelled) setRotationWarnings(warnings)
        } else if (recordAction.type === 'movement' && active?.type === 'farm') {
          const [fields, seasons] = await Promise.all([
            safely(api.listV1('fields', { farm_id: active.id })),
            safely(api.listV1('crop-seasons', { farm_id: active.id })),
          ])
          if (!cancelled) setRecordActionReferences({ fields, seasons })
        }
      } catch (error) {
        if (!cancelled) setRecordActionError(error.message)
      } finally {
        if (!cancelled) setRecordActionLoading(false)
      }
    }

    loadRecordActionData()
    return () => {
      cancelled = true
    }
  }, [active?.id, active?.type, recordAction])

  const rows = useMemo(() => pageState.data ?? [], [pageState.data])

  function startCreate() {
    if (!canManageActive) return
    const initial = makeInitialForm(definition)
    if (active) initial[`${active.type}_id`] = active.id
    setForm(initial)
    setEditingId(null)
    setMutationError('')
    setMutationDetails({})
    setIsFormOpen(true)
  }

  function startEdit(item) {
    const initial = makeInitialForm(definition)
    definition.fields.forEach((field) => {
      initial[field.name] = item[field.name] ?? initial[field.name]
    })
    setForm(initial)
    setEditingId(item.id)
    setMutationError('')
    setMutationDetails({})
    setIsFormOpen(true)
  }

  async function submit(event) {
    event.preventDefault()
    if (pendingId) return
    setPendingId(editingId || 'create')
    setMutationError('')
    setMutationDetails({})
    try {
      const saved = editingId
        ? await api.updateV1(resource, editingId, form)
        : await api.createV1(resource, form)
      pageState.setData((current) =>
        editingId
          ? current.map((item) =>
              String(item.id) === String(editingId) ? { ...item, ...saved } : item,
            )
          : [saved, ...current],
      )
      if (['communities', 'farms'].includes(resource)) await reloadContexts()
      setToast(`${definition.item[0].toUpperCase()}${definition.item.slice(1)} saved.`)
      setIsFormOpen(false)
      setEditingId(null)
      setForm(makeInitialForm(definition))
    } catch (error) {
      setMutationError(error.message)
      setMutationDetails(error.details || {})
    } finally {
      setPendingId(null)
    }
  }

  function updateFormField(name, value) {
    setForm((current) => ({
      ...current,
      [name]: value,
      ...(name === 'field_id' ? { field_zone_id: '' } : {}),
      ...(name === 'farm_id' ? { field_id: '' } : {}),
      ...(name === 'crop_id' ? { crop_variety_id: '' } : {}),
    }))
    setMutationDetails((current) => ({ ...current, [name]: undefined }))
  }

  async function remove(item) {
    if (pendingId || !window.confirm(`Delete ${displayName(item, definition)}?`)) return
    setPendingId(item.id)
    setMutationError('')
    setMutationDetails({})
    try {
      await api.deleteV1(resource, item.id)
      pageState.setData((current) =>
        current.filter((entry) => String(entry.id) !== String(item.id)),
      )
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
    setMutationDetails({})
    try {
      const updated = await api.transitionV1(resource, item.id, action)
      pageState.setData((current) =>
        current.map((entry) =>
          String(entry.id) === String(item.id) ? { ...entry, ...updated } : entry,
        ),
      )
      const actionLabels = {
        approve: 'approved',
        reject: 'rejected',
        cancel: 'cancelled',
        complete: 'completed',
      }
      setToast(
        `${definition.item[0].toUpperCase()}${definition.item.slice(1)} ${actionLabels[action] || action}.`,
      )
    } catch (error) {
      setMutationError(error.message)
    } finally {
      setPendingId(null)
    }
  }

  function openRecordAction(item, type) {
    if (!canManageItem(item)) return
    setRecordAction({ item, type })
    setRecordActionForm(type === 'rotation' ? {} : makeRecordActionForm(type))
    setRecordActionError('')
    setRecordActionDetails({})
    setRecordActionReferences({})
    setRotationWarnings([])
  }

  function closeRecordAction() {
    if (!recordActionPending) setRecordAction(null)
  }

  function updateRecordActionField(name, value) {
    setRecordActionForm((current) => ({ ...current, [name]: value }))
    setRecordActionDetails((current) => ({ ...current, [name]: undefined }))
  }

  async function submitRecordAction(event) {
    event.preventDefault()
    if (!recordAction || recordActionPending || recordAction.type === 'rotation') return

    setRecordActionPending(true)
    setRecordActionError('')
    setRecordActionDetails({})
    const payload = compactPayload(recordActionForm)

    try {
      let saved
      if (recordAction.type === 'variety') {
        saved = await api.postV1Path(`crops/${recordAction.item.id}/varieties`, payload)
      } else if (recordAction.type === 'condition') {
        saved = await api.postV1Path(`crop-seasons/${recordAction.item.id}/conditions`, payload)
      } else if (recordAction.type === 'harvest') {
        saved = await api.postV1Path(`crop-seasons/${recordAction.item.id}/harvests`, payload)
      } else {
        saved = await api.postV1Path('inventory-movements', {
          ...payload,
          inventory_id: recordAction.item.id,
        })
      }

      pageState.setData((current) =>
        current.map((item) => {
          if (String(item.id) !== String(recordAction.item.id)) return item
          if (recordAction.type === 'variety') {
            return { ...item, varieties: [...(item.varieties || []), saved] }
          }
          if (recordAction.type === 'condition') {
            return { ...item, conditions: [...(item.conditions || []), saved] }
          }
          if (recordAction.type === 'harvest') {
            return { ...item, harvests: [...(item.harvests || []), saved] }
          }

          return {
            ...item,
            quantity: saved?.balance_after ?? item.quantity,
            movements: [saved, ...(item.movements || [])],
          }
        }),
      )
      const messages = {
        variety: 'Variety added.',
        condition: 'Condition recorded.',
        harvest: 'Harvest recorded.',
        movement: 'Inventory movement recorded.',
      }
      setToast(messages[recordAction.type])
      setRecordAction(null)
    } catch (error) {
      setRecordActionError(error.message)
      setRecordActionDetails(error.details || {})
    } finally {
      setRecordActionPending(false)
    }
  }

  return (
    <div className="page-stack stage1-page">
      <PageHeader
        eyebrow={active ? `${active.type} workspace` : 'Yava workspace'}
        title={definition.title}
        description={definition.description}
        actions={
          contextAllowed && canManageActive && (!definition.createRequiresContext || active) ? (
            <Button onClick={startCreate}>Add {definition.item}</Button>
          ) : null
        }
      />
      <SuccessToast message={toast} onDismiss={() => setToast('')} />
      {resource === 'communities' ? (
        <CommunityAccessPanel
          onChanged={async () => {
            await reloadContexts()
            await pageState.reload()
          }}
        />
      ) : null}
      <div className="stage1-toolbar">
        <label className="stage1-search">
          <span className="sr-only">Search {definition.title}</span>
          <input
            value={query}
            onChange={(event) => setQuery(event.target.value)}
            placeholder={`Search ${definition.title.toLowerCase()}`}
          />
        </label>
        <span className="stage1-count">{rows.length} results</span>
      </div>

      {mutationError ? <ErrorState description={mutationError} /> : null}
      {pageState.loading ? (
        <LoadingState title={`Loading ${definition.title.toLowerCase()}…`} />
      ) : null}
      {pageState.error ? <ErrorState error={pageState.error} onRetry={pageState.reload} /> : null}

      {!hasRequiredContext || !contextAllowed ? (
        <section className="stage1-empty" role="status">
          <h2>Select the right workspace</h2>
          <p>
            {definition.title} requires {definition.contextTypes?.join(' or ')} context. Use the
            workspace switcher to continue.
          </p>
        </section>
      ) : null}

      {hasRequiredContext &&
      contextAllowed &&
      !pageState.loading &&
      !pageState.error &&
      rows.length === 0 ? (
        <section className="stage1-empty">
          <span className="stage1-empty-mark" aria-hidden="true">
            Y
          </span>
          <h2>Start here</h2>
          <p>{definition.empty}</p>
          {canManageActive && (!definition.createRequiresContext || active) ? (
            <Button onClick={startCreate}>Add {definition.item}</Button>
          ) : null}
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
              <Badge
                tone={
                  item.status === 'approved' || item.status === 'active' ? 'success' : 'neutral'
                }
              >
                {item.status || 'active'}
              </Badge>
            </div>
            {item.description || item.notes || item.purpose ? (
              <p>{item.description || item.notes || item.purpose}</p>
            ) : null}
            <dl className="stage1-record-meta">
              {item.area_square_metres || item.total_area_square_metres ? (
                <>
                  <dt>Area</dt>
                  <dd>{formatArea(item.area_square_metres || item.total_area_square_metres)}</dd>
                </>
              ) : null}
              {item.starts_on ? (
                <>
                  <dt>Starts</dt>
                  <dd>{formatDate(item.starts_on)}</dd>
                </>
              ) : null}
              {item.starts_at ? (
                <>
                  <dt>Time</dt>
                  <dd>{formatDateTime(item.starts_at, {}, item.timezone || active?.timezone)}</dd>
                </>
              ) : null}
              {item.quantity !== undefined ? (
                <>
                  <dt>Available</dt>
                  <dd>
                    {item.quantity} {item.unit}
                  </dd>
                </>
              ) : null}
              {item.role ? (
                <>
                  <dt>Your role</dt>
                  <dd>{item.role}</dd>
                </>
              ) : null}
              {resource === 'crops' ? (
                <>
                  <dt>Varieties</dt>
                  <dd>{item.varieties?.length || 0}</dd>
                </>
              ) : null}
              {resource === 'crop-seasons' ? (
                <>
                  <dt>Conditions</dt>
                  <dd>{item.conditions?.length || 0}</dd>
                  <dt>Harvest records</dt>
                  <dd>{item.harvests?.length || 0}</dd>
                </>
              ) : null}
              {resource === 'inventories' && item.movements?.length ? (
                <>
                  <dt>Movements</dt>
                  <dd>{item.movements.length}</dd>
                </>
              ) : null}
            </dl>
            {resource === 'inventories' ? <MovementHistory item={item} /> : null}
            <div className="stage1-card-actions">
              {resource === 'fields' ? (
                <Link className="button button-primary button-sm" to={`/fields/${item.id}/editor`}>
                  Open editor
                </Link>
              ) : null}
              {resource === 'communities' ? (
                <Link
                  className="button button-secondary button-sm"
                  to={`/communities/${item.id}/members`}
                >
                  Members
                </Link>
              ) : null}
              {resource === 'farms' ? (
                <Link
                  className="button button-secondary button-sm"
                  to={`/farms/${item.id}/members`}
                >
                  Members
                </Link>
              ) : null}
              {resource === 'reservations' && canTransition && item.status === 'pending' ? (
                <>
                  <Button
                    size="sm"
                    onClick={() => transition(item, 'approve')}
                    loading={pendingId === item.id}
                  >
                    Approve
                  </Button>
                  <Button
                    size="sm"
                    variant="secondary"
                    onClick={() => transition(item, 'reject')}
                    disabled={Boolean(pendingId)}
                  >
                    Reject
                  </Button>
                  <Button
                    size="sm"
                    variant="ghost"
                    onClick={() => transition(item, 'cancel')}
                    disabled={Boolean(pendingId)}
                  >
                    Cancel
                  </Button>
                </>
              ) : null}
              {resource === 'reservations' && canTransition && item.status === 'approved' ? (
                <>
                  <Button
                    size="sm"
                    onClick={() => transition(item, 'complete')}
                    loading={pendingId === item.id}
                  >
                    Complete
                  </Button>
                  <Button
                    size="sm"
                    variant="ghost"
                    onClick={() => transition(item, 'cancel')}
                    disabled={Boolean(pendingId)}
                  >
                    Cancel
                  </Button>
                </>
              ) : null}
              {resource === 'tasks' && canManageItem(item) && item.status !== 'completed' ? (
                <Button
                  size="sm"
                  onClick={() => transition(item, 'complete')}
                  loading={pendingId === item.id}
                >
                  Complete
                </Button>
              ) : null}
              {resource === 'crops' && canManageItem(item) ? (
                <Button
                  size="sm"
                  variant="secondary"
                  onClick={() => openRecordAction(item, 'variety')}
                >
                  Add variety
                </Button>
              ) : null}
              {resource === 'crop-seasons' && canManageItem(item) ? (
                <>
                  <Button
                    size="sm"
                    variant="secondary"
                    onClick={() => openRecordAction(item, 'rotation')}
                  >
                    View rotation warnings
                  </Button>
                  <Button
                    size="sm"
                    variant="secondary"
                    onClick={() => openRecordAction(item, 'condition')}
                  >
                    Record condition
                  </Button>
                  <Button size="sm" onClick={() => openRecordAction(item, 'harvest')}>
                    Record harvest
                  </Button>
                </>
              ) : null}
              {resource === 'inventories' && canManageItem(item) ? (
                <Button size="sm" onClick={() => openRecordAction(item, 'movement')}>
                  Record movement
                </Button>
              ) : null}
              {definition.editable !== false && canManageItem(item) ? (
                <Button size="sm" variant="ghost" onClick={() => startEdit(item)}>
                  Edit
                </Button>
              ) : null}
              {definition.deletable !== false && canManageItem(item) ? (
                <Button
                  size="sm"
                  variant="danger"
                  onClick={() => remove(item)}
                  loading={pendingId === item.id}
                >
                  Delete
                </Button>
              ) : null}
            </div>
          </article>
        ))}
      </div>

      <Dialog
        open={isFormOpen}
        onClose={() => {
          if (!pendingId) setIsFormOpen(false)
        }}
        labelledBy="stage1-form-title"
        className="stage1-modal"
      >
        <div className="stage1-modal-head">
          <h2 id="stage1-form-title">
            {editingId ? 'Edit' : 'Add'} {definition.item}
          </h2>
          <button
            type="button"
            className="stage1-close"
            aria-label="Close"
            disabled={Boolean(pendingId)}
            onClick={() => setIsFormOpen(false)}
          >
            ×
          </button>
        </div>
        <form onSubmit={submit} className="stage1-form">
          {definition.fields.map((field) => {
            if (field.scope) return null
            const fieldError = mutationDetails[field.name]?.[0] || mutationDetails[field.name]
            const errorId = fieldError ? `stage1-${field.name}-error` : undefined
            const options = field.optionsKey ? referenceOptions[field.optionsKey] || [] : null
            return (
              <label
                key={field.name}
                className={field.type === 'checkbox' ? 'stage1-checkbox' : 'field'}
              >
                <span>{field.label}</span>
                {field.type === 'textarea' ? (
                  <textarea
                    value={form[field.name]}
                    required={field.required}
                    aria-invalid={Boolean(fieldError)}
                    aria-describedby={errorId}
                    onChange={(event) => updateFormField(field.name, event.target.value)}
                  />
                ) : field.type === 'checkbox' ? (
                  <input
                    type="checkbox"
                    checked={Boolean(form[field.name])}
                    onChange={(event) => updateFormField(field.name, event.target.checked)}
                  />
                ) : field.optionsKey ? (
                  <select
                    value={form[field.name]}
                    required={field.required}
                    aria-invalid={Boolean(fieldError)}
                    aria-describedby={errorId}
                    onChange={(event) => updateFormField(field.name, event.target.value)}
                  >
                    <option value="">
                      {options.length
                        ? `Select ${field.label.toLowerCase()}`
                        : 'No options available'}
                    </option>
                    {options.map((option) => (
                      <option key={option.id} value={option.user_id || option.id}>
                        {optionLabel(option, `${field.label} ${option.id}`)}
                      </option>
                    ))}
                  </select>
                ) : field.type === 'select' ? (
                  <select
                    value={form[field.name]}
                    required={field.required}
                    aria-invalid={Boolean(fieldError)}
                    aria-describedby={errorId}
                    onChange={(event) => updateFormField(field.name, event.target.value)}
                  >
                    {field.options.map((option) => (
                      <option key={option} value={option}>
                        {option}
                      </option>
                    ))}
                  </select>
                ) : (
                  <input
                    type={field.type || 'text'}
                    value={form[field.name]}
                    required={field.required}
                    aria-invalid={Boolean(fieldError)}
                    aria-describedby={errorId}
                    onChange={(event) => updateFormField(field.name, event.target.value)}
                  />
                )}
                {fieldError ? (
                  <small className="field-error" id={errorId}>
                    {fieldError}
                  </small>
                ) : null}
              </label>
            )
          })}
          {mutationError ? (
            <p className="field-error" role="alert">
              {mutationError}
            </p>
          ) : null}
          <div className="form-actions stage1-form-actions">
            <Button type="submit" loading={Boolean(pendingId)}>
              {pendingId ? 'Saving…' : 'Save'}
            </Button>
            <Button
              variant="secondary"
              onClick={() => setIsFormOpen(false)}
              disabled={Boolean(pendingId)}
            >
              Cancel
            </Button>
          </div>
        </form>
      </Dialog>

      <Dialog
        open={Boolean(recordAction)}
        onClose={closeRecordAction}
        labelledBy="stage1-record-action-title"
        className="stage1-modal"
      >
        {recordAction ? (
          <>
            <div className="stage1-modal-head">
              <h2 id="stage1-record-action-title">
                {recordAction.type === 'rotation'
                  ? 'Rotation warnings'
                  : recordActionDefinitions[recordAction.type].title}
              </h2>
              <button
                type="button"
                className="stage1-close"
                aria-label="Close"
                disabled={recordActionPending}
                onClick={closeRecordAction}
              >
                ×
              </button>
            </div>

            {recordAction.type === 'rotation' ? (
              <div className="stage1-form">
                {recordActionLoading ? <p role="status">Loading rotation warnings…</p> : null}
                {recordActionError ? (
                  <p className="field-error" role="alert">
                    {recordActionError}
                  </p>
                ) : null}
                {!recordActionLoading && !recordActionError && rotationWarnings.length === 0 ? (
                  <p role="status">No rotation warnings for this crop season.</p>
                ) : null}
                {rotationWarnings.length ? (
                  <ul>
                    {rotationWarnings.map((warning, index) => (
                      <li key={warning.code || index}>
                        <strong>{warning.severity || 'warning'}:</strong> {warning.message}
                      </li>
                    ))}
                  </ul>
                ) : null}
                <div className="form-actions stage1-form-actions">
                  <Button variant="secondary" onClick={closeRecordAction}>
                    Close
                  </Button>
                </div>
              </div>
            ) : (
              <form onSubmit={submitRecordAction} className="stage1-form">
                {recordAction.type === 'movement' ? (
                  <MovementHistory
                    item={recordAction.item}
                    references={recordActionReferences}
                    showEmpty
                  />
                ) : null}
                {recordActionDefinitions[recordAction.type].fields.map((field) => {
                  if (field.optionsKey && active?.type !== 'farm') return null
                  const fieldError =
                    recordActionDetails[field.name]?.[0] || recordActionDetails[field.name]
                  const errorId = fieldError ? `stage1-action-${field.name}-error` : undefined
                  const options = field.optionsKey
                    ? recordActionReferences[field.optionsKey] || []
                    : null

                  return (
                    <label key={field.name} className="field">
                      <span>{field.label}</span>
                      {field.type === 'textarea' ? (
                        <textarea
                          value={recordActionForm[field.name] || ''}
                          required={field.required}
                          aria-invalid={Boolean(fieldError)}
                          aria-describedby={errorId}
                          onChange={(event) =>
                            updateRecordActionField(field.name, event.target.value)
                          }
                        />
                      ) : field.optionsKey ? (
                        <select
                          value={recordActionForm[field.name] || ''}
                          required={field.required}
                          aria-invalid={Boolean(fieldError)}
                          aria-describedby={errorId}
                          onChange={(event) =>
                            updateRecordActionField(field.name, event.target.value)
                          }
                        >
                          <option value="">
                            {recordActionLoading
                              ? 'Loading options…'
                              : options.length
                                ? `Select ${field.label.toLowerCase()}`
                                : 'No options available'}
                          </option>
                          {options.map((option) => (
                            <option key={option.id} value={option.id}>
                              {optionLabel(option, `${field.label} ${option.id}`)}
                            </option>
                          ))}
                        </select>
                      ) : field.type === 'select' ? (
                        <select
                          value={recordActionForm[field.name] || ''}
                          required={field.required}
                          aria-invalid={Boolean(fieldError)}
                          aria-describedby={errorId}
                          onChange={(event) =>
                            updateRecordActionField(field.name, event.target.value)
                          }
                        >
                          {field.options.map((option) => (
                            <option key={option || 'empty'} value={option}>
                              {option || 'Not specified'}
                            </option>
                          ))}
                        </select>
                      ) : (
                        <input
                          type={field.type || 'text'}
                          step={field.step}
                          value={recordActionForm[field.name] || ''}
                          required={field.required}
                          aria-invalid={Boolean(fieldError)}
                          aria-describedby={errorId}
                          onChange={(event) =>
                            updateRecordActionField(field.name, event.target.value)
                          }
                        />
                      )}
                      {fieldError ? (
                        <small className="field-error" id={errorId}>
                          {fieldError}
                        </small>
                      ) : null}
                    </label>
                  )
                })}
                {recordActionError ? (
                  <p className="field-error" role="alert">
                    {recordActionError}
                  </p>
                ) : null}
                <div className="form-actions stage1-form-actions">
                  <Button type="submit" loading={recordActionPending}>
                    {recordActionDefinitions[recordAction.type].submitLabel}
                  </Button>
                  <Button
                    variant="secondary"
                    onClick={closeRecordAction}
                    disabled={recordActionPending}
                  >
                    Cancel
                  </Button>
                </div>
              </form>
            )}
          </>
        ) : null}
      </Dialog>
    </div>
  )
}
