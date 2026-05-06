import { startTransition, useEffect, useMemo, useRef, useState } from 'react'
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

  function applyResourceSuggestion(resource) {
    const matchingItem = inventoryMatchesByResource.get(buildResourceKey(resource))

    startTransition(() => {
      setActiveResourceKey(buildResourceKey(resource))
      setEditingId(matchingItem?.id ?? null)
      setForm(buildSuggestedForm(resource, matchingItem))
    })
  }

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
  }, [hasAppliedRequestPrefill, inventoryMatchesByResource, inventoryRequestContext, inventoryState.loading])

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
    setSubmitting(true)
    setError('')
    setSuccessMessage('')

    try {
      await api.deleteInventoryItem(itemId)
      inventoryState.setData((current) => current.filter((item) => item.id !== itemId))
      if (editingId === itemId) {
        setEditingId(null)
        setActiveResourceKey(null)
        setForm(emptyForm)
      }
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
        inventoryState.setData((current) => current.map((item) => (
          item.id === updated.id ? updated : item
        )))
        setSuccessMessage(`Updated "${updated.name}" inventory.`)
      } else {
        const created = await api.createInventoryItem(payload)
        inventoryState.setData((current) => [created, ...current])
        setSuccessMessage(`Added "${created.name}" to inventory.`)
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
        <Button variant="danger" size="sm" onClick={() => handleDelete(item.id)} disabled={submitting}>
          Delete
        </Button>
      </div>
    )
  }

  function renderInventoryCard(item) {
    return (
      <ResourceCard>
        <ResourceCardHeader
          title={item.name}
          subtitle={formatInventoryUnit(item.unit)}
          badge={<Badge tone={item.is_available ? 'success' : 'warning'}>{item.is_available ? 'In stock' : 'Out of stock'}</Badge>}
        />
        <ResourceCardMeta>
          <Badge tone="neutral">{item.type}</Badge>
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
              <dd>{item.is_available ? 'In stock' : 'Out of stock'}</dd>
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
    { key: 'name', label: 'Name', render: (item) => item.name },
    { key: 'type', label: 'Type', render: (item) => item.type },
    { key: 'unit', label: 'Unit', render: (item) => formatInventoryUnit(item.unit) },
    { key: 'quantity', label: 'Quantity', render: (item) => safeNumber(item.quantity, item.type === 'tool' ? 0 : 2) },
    { key: 'status', label: 'Status', render: (item) => item.is_available ? 'In stock' : 'Out of stock' },
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
        eyebrow="Stock and supplies"
        title="Inventory"
        description="Track materials and tools as operational inputs for calendar tasks, shortages, and replenishment."
        meta={<StatusBadge kind="ownership">{inventoryState.data.length} items tracked</StatusBadge>}
      />

      <section className="inventory-yard-strip" aria-label="Inventory summary">
        <MeasurementBadge label="Materials" value={materialCount} tone="earth" />
        <MeasurementBadge label="Tools" value={toolCount} tone="field" />
        <MeasurementBadge label="Shortage flags" value={unavailableCount} tone={unavailableCount > 0 ? 'amber' : 'leaf'} />
      </section>

      {inventoryRequestContext ? (
        <SectionCard title="Missing resources for task" description="Restock directly from calendar shortages, then return to the originating task context.">
          <div className="inline-note">
            {inventoryRequestContext.taskName
              ? `You came here from task "${inventoryRequestContext.taskName}". Replenish the missing inventory, then return and complete the task.`
              : 'You came here from a task with missing inventory. Replenish the missing items, then return and complete the task.'}
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
                      <strong>{resource.name}</strong>
                      <div className="resource-summary-row">
                        <StatRow
                          label="Need"
                          value={`${safeNumber(resource.required_quantity, resource.type === 'tool' ? 0 : 2)} ${formatInventoryUnit(resource.unit)}`}
                        />
                        <StatRow
                          label="Have"
                          value={`${safeNumber(resource.available_quantity ?? 0, resource.type === 'tool' ? 0 : 2)} ${formatInventoryUnit(resource.unit)}`}
                        />
                        <StatRow
                          label="Missing"
                          className={Number(resource.shortage_quantity ?? 0) > 0 ? 'stat-row-danger' : ''}
                          value={`${safeNumber(resource.shortage_quantity ?? 0, resource.type === 'tool' ? 0 : 2)} ${formatInventoryUnit(resource.unit)}`}
                        />
                      </div>
                      <div className="inline-note inline-note-compact">
                        Item type will be assigned automatically as <strong>{resource.type}</strong> from the task resource.
                      </div>
                      <div className="inline-note inline-note-compact">
                        {matchingItem
                          ? `Existing stock found. The form is prepared to update "${matchingItem.name}" to ${formatQuantityInput(Number(matchingItem.quantity) + Number(resource.shortage_quantity ?? 0), resource.type)} ${formatInventoryUnit(resource.unit)} total.`
                          : 'No matching inventory entry was found. The form is prepared to create this item with the missing quantity.'}
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
                          {matchingItem ? 'Prepare restock form' : 'Prepare add form'}
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
          title="Tracked items"
          description="The table shrinks to its content when the inventory is small, so the list feels intentional instead of half-empty."
        >
          {inventoryState.data.length === 0 ? (
            <EmptyState
              title="Inventory is empty"
              description="Add your first tool or material to start linking garden work with stock levels."
            />
          ) : (
            <ResponsiveTable
              columns={inventoryColumns}
              items={inventoryState.data}
              getKey={(item) => item.id}
              renderCard={renderInventoryCard}
              tableLabel="Inventory items table"
              cardListLabel="Inventory items list"
            />
          )}
        </SectionCard>

        <form
          ref={inventoryFormRef}
          className={editingId ? 'inventory-editor-form is-editing' : 'inventory-editor-form'}
          onSubmit={handleSubmit}
        >
          <FormSection
            title={editingId ? 'Edit inventory item' : 'Add inventory item'}
            description="Use the compact form on the right to add or restock items without letting the editor dominate the page."
          >
            {inventoryRequestContext && activeResourceKey ? (
              <div className="inline-note">
                {editingId
                  ? 'The form is preloaded for replenishment. Adjust the total stock after purchase, save it, then return to the task.'
                  : 'The form is preloaded with the missing resource so you can add it without retyping details.'}
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
                      {type}
                    </option>
                  ))}
                </select>
                {typeLockedByTask ? (
                  <span className="field-hint">Type is locked to the task resource.</span>
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
                  <span className="field-hint">Unit is locked to the task resource.</span>
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
                ? 'Reusable tools are only checked for availability and are never deducted after task completion.'
                : 'Consumable materials are automatically deducted from inventory when linked care tasks are completed.'}
            </div>

            {successMessage ? <span className="form-success">{successMessage}</span> : null}
            {error ? <span className="field-error">{error}</span> : null}

            <ActionRow>
              <Button type="submit" disabled={submitting}>
                {submitting ? 'Saving...' : editingId ? 'Save item' : 'Add item'}
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
                  Cancel edit
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
