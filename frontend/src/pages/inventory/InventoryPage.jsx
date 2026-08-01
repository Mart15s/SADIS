import { startTransition, useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { MeasurementBadge } from '../../components/garden/GardenControls.jsx'
import PageHeader from '../../components/layout/PageHeader.jsx'
import { EmptyState, ErrorState, LoadingState } from '../../components/shared/StatusView.jsx'
import ActionRow from '../../components/ui/ActionRow.jsx'
import Badge from '../../components/ui/Badge.jsx'
import Button from '../../components/ui/Button.jsx'
import { StatRow } from '../../components/ui/DefinitionList.jsx'
import FormSection from '../../components/ui/FormSection.jsx'
import ResourceCard, {
  ResourceCardBody,
  ResourceCardFooter,
  ResourceCardHeader,
  ResourceCardMeta,
} from '../../components/ui/ResourceCard.jsx'
import ResponsiveTable from '../../components/ui/ResponsiveTable.jsx'
import SectionCard from '../../components/ui/SectionCard.jsx'
import StatusBadge from '../../components/ui/StatusBadge.jsx'
import { api } from '../../lib/api.js'
import {
  INVENTORY_TYPES,
  MATERIAL_UNITS,
  TOOL_UNITS,
  formatInventoryType,
  formatInventoryUnit,
  safeNumber,
} from '../../lib/constants.js'
import { useAsyncData } from '../../lib/hooks/useAsyncData.js'

const emptyForm = {
  name: '',
  quantity: '',
  type: INVENTORY_TYPES[0],
  unit: 'unit',
}

function normalizeInventoryName(value) {
  return String(value ?? '').trim().toLowerCase()
}

function displayInventoryText(value) {
  return String(value ?? '')
}

function buildResourceKey(resource) {
  return [
    normalizeInventoryName(resource.name),
    resource.type ?? 'material',
    resource.unit ?? 'unit',
  ].join('|')
}

function formatQuantityInput(value, type) {
  const numeric = Number(value)

  if (!Number.isFinite(numeric)) {
    return ''
  }

  return type === 'tool'
    ? String(Math.max(0, Math.round(numeric)))
    : String(Number(numeric.toFixed(2)))
}

function buildSuggestedForm(resource, existingItem = null) {
  const type = resource.type === 'tool' ? 'tool' : 'material'
  const currentQuantity = Number(existingItem?.quantity ?? 0)
  const shortageQuantity = Number(resource.shortage_quantity ?? resource.required_quantity ?? 0)

  return {
    name: existingItem?.name ?? resource.name ?? '',
    quantity: formatQuantityInput(currentQuantity + shortageQuantity, type),
    type,
    unit: type === 'tool' ? 'unit' : resource.unit ?? 'unit',
  }
}

export default function InventoryPage() {
  const [searchParams] = useSearchParams()
  const inventoryState = useAsyncData(() => api.listInventory(), [], [])
  const [form, setForm] = useState(emptyForm)
  const [editingId, setEditingId] = useState(null)
  const [activeResourceKey, setActiveResourceKey] = useState(null)
  const [hasAppliedRequestPrefill, setHasAppliedRequestPrefill] = useState(false)
  const [error, setError] = useState('')
  const [successMessage, setSuccessMessage] = useState('')
  const [submitting, setSubmitting] = useState(false)
  const inventoryFormRef = useRef(null)

  const inventoryRequestContext = useMemo(() => {
    const rawMissing = searchParams.get('missing')

    if (!rawMissing) {
      return null
    }

    try {
      const missing = JSON.parse(rawMissing)

      return {
        taskId: searchParams.get('taskId'),
        taskName: searchParams.get('taskName'),
        returnTo: searchParams.get('returnTo'),
        returnLabel: searchParams.get('returnLabel') || 'Back to calendar',
        missing: Array.isArray(missing) ? missing : [],
      }
    } catch {
      return {
        taskId: searchParams.get('taskId'),
        taskName: searchParams.get('taskName'),
        returnTo: searchParams.get('returnTo'),
        returnLabel: searchParams.get('returnLabel') || 'Back to calendar',
        missing: [],
      }
    }
  }, [searchParams])

  const inventoryMatchesByResource = useMemo(() => {
    return new Map(
      inventoryState.data.map((item) => ([
        buildResourceKey({
          name: item.name,
          type: item.type,
          unit: item.unit,
        }),
        item,
      ])),
    )
  }, [inventoryState.data])

  const selectedTaskResource = useMemo(() => (
    inventoryRequestContext?.missing.find((resource) => buildResourceKey(resource) === activeResourceKey) ?? null
  ), [activeResourceKey, inventoryRequestContext])

  const typeLockedByTask = Boolean(selectedTaskResource)

  const applyResourceSuggestion = useCallback((resource) => {
    const matchingItem = inventoryMatchesByResource.get(buildResourceKey(resource))

    startTransition(() => {
      setActiveResourceKey(buildResourceKey(resource))
      setEditingId(matchingItem?.id ?? null)
      setForm(buildSuggestedForm(resource, matchingItem))
    })
  }, [inventoryMatchesByResource])

  function handleChange(event) {
    setSuccessMessage('')
    setForm((current) => ({
      ...current,
      [event.target.name]: event.target.value,
    }))
  }

  useEffect(() => {
    if (form.type === 'tool' && form.unit !== 'unit') {
      setForm((current) => ({
        ...current,
        unit: 'unit',
      }))
    }
  }, [form.type, form.unit])

  useEffect(() => {
    if (inventoryState.loading || !inventoryRequestContext?.missing.length || hasAppliedRequestPrefill) {
      return
    }

    applyResourceSuggestion(inventoryRequestContext.missing[0])
    setHasAppliedRequestPrefill(true)
  }, [applyResourceSuggestion, hasAppliedRequestPrefill, inventoryRequestContext, inventoryState.loading])

  async function handleEdit(itemId) {
    setError('')
    setSuccessMessage('')

    try {
      const item = await api.getInventoryItem(itemId)
      startTransition(() => {
        setActiveResourceKey(null)
        setEditingId(item.id)
        setForm({
          name: item.name,
          quantity: item.quantity,
          type: item.type,
          unit: item.unit ?? 'unit',
        })
      })
      window.setTimeout(() => {
        inventoryFormRef.current?.scrollIntoView({ behavior: 'smooth', block: 'start' })
        inventoryFormRef.current?.querySelector('input, select, textarea, button')?.focus()
      }, 0)
    } catch (requestError) {
      setError(requestError.message)
    }
  }

  async function handleDelete(itemId) {
    const item = inventoryState.data.find((entry) => entry.id === itemId)
    if (!window.confirm(`Delete "${displayInventoryText(item?.name) || 'this inventory item'}"? This cannot be undone.`)) {
      return
    }

    setSubmitting(true)
    setError('')
    setSuccessMessage('')

    try {
      await api.deleteInventoryItem(itemId)
      inventoryState.setData((current) => current.filter((entry) => entry.id !== itemId))
      if (editingId === itemId) {
        setEditingId(null)
        setActiveResourceKey(null)
        setForm(emptyForm)
      }
      setSuccessMessage('Inventory item deleted.')
    } catch (requestError) {
      setError(requestError.message)
    } finally {
      setSubmitting(false)
    }
  }

  async function handleSubmit(event) {
    event.preventDefault()
    setSubmitting(true)
    setError('')
    setSuccessMessage('')

    try {
      const payload = {
        ...form,
        quantity: Number(form.quantity),
      }

      if (selectedTaskResource?.id && inventoryRequestContext?.taskId) {
        payload.source_task_id = Number(inventoryRequestContext.taskId)
        payload.source_requirement_id = Number(selectedTaskResource.id)
      }

      if (editingId) {
        const updated = await api.updateInventoryItem(editingId, payload)
        if (updated?.id) {
          inventoryState.setData((current) => current.map((item) => (
            item.id === updated.id ? updated : item
          )))
          setSuccessMessage(`Inventory item "${displayInventoryText(updated.name)}" updated.`)
        } else {
          await inventoryState.reload()
          setSuccessMessage('Inventory item updated.')
        }
      } else {
        const created = await api.createInventoryItem(payload)
        if (created?.id) {
          inventoryState.setData((current) => [created, ...current])
          setSuccessMessage(`"${displayInventoryText(created.name)}" added to inventory.`)
        } else {
          await inventoryState.reload()
          setSuccessMessage('Inventory item added.')
        }
      }

      setEditingId(null)
      setActiveResourceKey(null)
      setForm(emptyForm)
    } catch (requestError) {
      setError(requestError.message)
    } finally {
      setSubmitting(false)
    }
  }

  const unitOptions = form.type === 'tool' ? TOOL_UNITS : MATERIAL_UNITS
  const quantityStep = form.type === 'tool' ? '1' : '0.01'
  const materialCount = inventoryState.data.filter((item) => item.type === 'material').length
  const toolCount = inventoryState.data.filter((item) => item.type === 'tool').length
  const unavailableCount = inventoryState.data.filter((item) => !item.is_available).length

  function renderInventoryActions(item) {
    return (
      <div className="resource-action-row">
        <Button variant="secondary" size="sm" onClick={() => handleEdit(item.id)}>
          Edit
        </Button>
        <Button
          variant="danger"
          size="sm"
          onClick={() => handleDelete(item.id)}
          disabled={submitting}
          aria-label={`Delete ${displayInventoryText(item.name)}`}
        >
          {submitting ? 'Deleting...' : 'Delete'}
        </Button>
      </div>
    )
  }

  function renderInventoryCard(item) {
    return (
      <ResourceCard>
        <ResourceCardHeader
          title={displayInventoryText(item.name)}
          subtitle={formatInventoryUnit(item.unit)}
          badge={<Badge tone={item.is_available ? 'success' : 'warning'}>{item.is_available ? 'In stock' : 'Missing'}</Badge>}
        />
        <ResourceCardMeta>
          <Badge tone="neutral">{formatInventoryType(item.type)}</Badge>
          <Badge tone="soft">{safeNumber(item.quantity, item.type === 'tool' ? 0 : 2)} {formatInventoryUnit(item.unit)}</Badge>
        </ResourceCardMeta>
        <ResourceCardBody>
          <dl className="resource-detail-grid">
            <div>
              <dt>Quantity</dt>
              <dd>{safeNumber(item.quantity, item.type === 'tool' ? 0 : 2)}</dd>
            </div>
            <div>
              <dt>Status</dt>
              <dd>{item.is_available ? 'In stock' : 'Missing'}</dd>
            </div>
          </dl>
        </ResourceCardBody>
        <ResourceCardFooter>
          {renderInventoryActions(item)}
        </ResourceCardFooter>
      </ResourceCard>
    )
  }

  const inventoryColumns = [
    { key: 'name', label: 'Name', render: (item) => displayInventoryText(item.name) },
    { key: 'type', label: 'Type', render: (item) => formatInventoryType(item.type) },
    { key: 'unit', label: 'Unit', render: (item) => formatInventoryUnit(item.unit) },
    { key: 'quantity', label: 'Quantity', render: (item) => safeNumber(item.quantity, item.type === 'tool' ? 0 : 2) },
    { key: 'status', label: 'Status', render: (item) => item.is_available ? 'In stock' : 'Missing' },
    {
      key: 'actions',
      label: '',
      cellClassName: 'table-actions-cell',
      render: (item) => renderInventoryActions(item),
    },
  ]

  if (inventoryState.loading) {
    return <LoadingState title="Loading inventory..." />
  }

  if (inventoryState.error) {
    return <ErrorState error={inventoryState.error} onRetry={inventoryState.reload} />
  }

  return (
    <div className="page-stack">
      <PageHeader
        eyebrow="Supplies and tools"
        title="Inventory"
        description="Manage materials and tools used for calendar tasks, shortages, and replenishment."
        meta={<StatusBadge kind="ownership">{inventoryState.data.length} records</StatusBadge>}
      />

      <section className="inventory-yard-strip" aria-label="Inventory summary">
        <MeasurementBadge label="Materials" value={materialCount} tone="earth" />
        <MeasurementBadge label="Tools" value={toolCount} tone="field" />
        <MeasurementBadge label="Shortages" value={unavailableCount} tone={unavailableCount > 0 ? 'amber' : 'leaf'} />
      </section>

      {inventoryRequestContext ? (
        <SectionCard title="Resources missing for task" description="Replenish inventory from calendar shortages, then return to the task.">
          <div className="inline-note">
            {inventoryRequestContext.taskName
              ? `You came here from the task "${inventoryRequestContext.taskName}". Replenish inventory, then return and complete the task.`
              : 'You came here from a task with missing inventory. Replenish the missing records, then return and complete the task.'}
          </div>
          {inventoryRequestContext.returnTo ? (
            <ActionRow>
              <Link to={inventoryRequestContext.returnTo}>
                <Button variant="secondary">{inventoryRequestContext.returnLabel}</Button>
              </Link>
            </ActionRow>
          ) : null}
          {inventoryRequestContext.missing.length > 0 ? (
            <div className="stack stack-sm">
              {inventoryRequestContext.missing.map((resource, index) => {
                const resourceKey = buildResourceKey(resource)
                const matchingItem = inventoryMatchesByResource.get(resourceKey)
                const isSelected = activeResourceKey === resourceKey

                return (
                  <div
                    key={`${resource.id ?? index}-${resource.name}`}
                    className={`inventory-request-card ${isSelected ? 'is-selected' : ''}`.trim()}
                  >
                    <div className="stack stack-sm">
                      <strong>{displayInventoryText(resource.name)}</strong>
                      <div className="resource-summary-row">
                        <StatRow
                          label="Required"
                          value={`${safeNumber(resource.required_quantity, resource.type === 'tool' ? 0 : 2)} ${formatInventoryUnit(resource.unit)}`}
                        />
                        <StatRow
                          label="Available"
                          value={`${safeNumber(resource.available_quantity ?? 0, resource.type === 'tool' ? 0 : 2)} ${formatInventoryUnit(resource.unit)}`}
                        />
                        <StatRow
                          label="Missing"
                          className={Number(resource.shortage_quantity ?? 0) > 0 ? 'stat-row-danger' : ''}
                          value={`${safeNumber(resource.shortage_quantity ?? 0, resource.type === 'tool' ? 0 : 2)} ${formatInventoryUnit(resource.unit)}`}
                        />
                      </div>
                      <div className="inline-note inline-note-compact">
                        Type is assigned automatically from the task: <strong>{formatInventoryType(resource.type)}</strong>.
                      </div>
                      <div className="inline-note inline-note-compact">
                        {matchingItem
                          ? `Existing record found. The form is ready to update "${displayInventoryText(matchingItem.name)}" to ${formatQuantityInput(Number(matchingItem.quantity) + Number(resource.shortage_quantity ?? 0), resource.type)} ${formatInventoryUnit(resource.unit)}.`
                          : 'No matching inventory record was found. The form is ready to create a record with the missing quantity.'}
                      </div>
                      <ActionRow>
                        <Button
                          variant={isSelected ? 'primary' : 'secondary'}
                          onClick={() => {
                            setError('')
                            setSuccessMessage('')
                            applyResourceSuggestion(resource)
                          }}
                        >
                          {matchingItem ? 'Prepare replenishment form' : 'Prepare add form'}
                        </Button>
                      </ActionRow>
                    </div>
                  </div>
                )
              })}
            </div>
          ) : null}
        </SectionCard>
      ) : null}

      <div className="detail-grid">
        <SectionCard
          title="Tracked records"
          description="The inventory list shows tools and materials with their quantities and units."
        >
          {inventoryState.data.length === 0 ? (
            <EmptyState
              title="Inventory is empty"
              description="Add your first tool or material so garden work can be tied to supplies."
            />
          ) : (
            <ResponsiveTable
              columns={inventoryColumns}
              items={inventoryState.data}
              getKey={(item) => item.id}
              renderCard={renderInventoryCard}
              tableLabel="Inventory records table"
              cardListLabel="Inventory records list"
            />
          )}
        </SectionCard>

        <form
          ref={inventoryFormRef}
          className={editingId ? 'inventory-editor-form is-editing' : 'inventory-editor-form'}
          onSubmit={handleSubmit}
        >
          <FormSection
            title={editingId ? 'Edit inventory record' : 'Add inventory record'}
            description="Use this form to add new records or replenish existing supplies."
          >
            {inventoryRequestContext && activeResourceKey ? (
              <div className="inline-note">
                {editingId
                  ? 'The form is ready for replenishment. Adjust the total quantity, save, and return to the task.'
                  : 'The form is prepared from the missing resource, so you do not need to retype the details.'}
              </div>
            ) : null}
            <div className="input-grid">
              <div className="field">
                <label htmlFor="item-name">Name</label>
                <input id="item-name" name="name" value={form.name} onChange={handleChange} required />
              </div>
              <div className="field">
                <label htmlFor="item-type">Type</label>
                <select
                  id="item-type"
                  name="type"
                  value={form.type}
                  onChange={handleChange}
                  disabled={typeLockedByTask}
                >
                  {INVENTORY_TYPES.map((type) => (
                    <option key={type} value={type}>
                      {formatInventoryType(type)}
                    </option>
                  ))}
                </select>
                {typeLockedByTask ? (
                  <span className="field-hint">Type is locked by the task resource.</span>
                ) : null}
              </div>
              <div className="field">
                <label htmlFor="item-unit">Unit</label>
                <select
                  id="item-unit"
                  name="unit"
                  value={form.unit}
                  onChange={handleChange}
                  disabled={form.type === 'tool' || typeLockedByTask}
                >
                  {unitOptions.map((unit) => (
                    <option key={unit} value={unit}>
                      {formatInventoryUnit(unit)}
                    </option>
                  ))}
                </select>
                {typeLockedByTask ? (
                  <span className="field-hint">Unit is locked by the task resource.</span>
                ) : null}
              </div>
              <div className="field">
                <label htmlFor="item-quantity">Quantity</label>
                <input
                  id="item-quantity"
                  name="quantity"
                  type="number"
                  min="0"
                  step={quantityStep}
                  value={form.quantity}
                  onChange={handleChange}
                  required
                />
              </div>
            </div>

            <div className="inline-note inline-note-compact">
              {form.type === 'tool'
                ? 'Reusable tools are checked only for availability and are not deducted after a task.'
                : 'Consumable materials are automatically deducted from inventory when linked tasks are marked complete.'}
            </div>

            {successMessage ? <span className="form-success">{successMessage}</span> : null}
            {error ? <span className="field-error">{error}</span> : null}

            <ActionRow>
              <Button type="submit" disabled={submitting}>
                {submitting ? 'Saving...' : editingId ? 'Save record' : 'Add record'}
              </Button>
              {editingId ? (
                <Button
                  variant="secondary"
                  onClick={() => {
                    setEditingId(null)
                    setActiveResourceKey(null)
                    setForm(emptyForm)
                    setSuccessMessage('')
                  }}
                >
                  Cancel editing
                </Button>
              ) : null}
              {inventoryRequestContext?.returnTo ? (
                <Link to={inventoryRequestContext.returnTo}>
                  <Button variant="secondary">{inventoryRequestContext.returnLabel}</Button>
                </Link>
              ) : null}
            </ActionRow>
          </FormSection>
        </form>
      </div>
    </div>
  )
}
