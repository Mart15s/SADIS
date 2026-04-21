import { startTransition, useEffect, useMemo, useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import PageHeader from '../../components/layout/PageHeader.jsx'
import { EmptyState, ErrorState, LoadingState } from '../../components/shared/StatusView.jsx'
import Button from '../../components/ui/Button.jsx'
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
  minimum_quantity: '0',
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
    minimum_quantity: formatQuantityInput(existingItem?.minimum_quantity ?? 0, type) || '0',
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
  }, [applyResourceSuggestion, hasAppliedRequestPrefill, inventoryMatchesByResource, inventoryRequestContext, inventoryState.loading])

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
          minimum_quantity: item.minimum_quantity ?? 0,
          type: item.type,
          unit: item.unit ?? 'unit',
        })
      })
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
        minimum_quantity: Number(form.minimum_quantity || 0),
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
      setForm(emptyForm)
    } catch (requestError) {
      setError(requestError.message)
    } finally {
      setSubmitting(false)
    }
  }

  const unitOptions = form.type === 'tool' ? TOOL_UNITS : MATERIAL_UNITS
  const quantityStep = form.type === 'tool' ? '1' : '0.01'

  if (inventoryState.loading) {
    return <LoadingState title="Loading inventory..." />
  }

  if (inventoryState.error) {
    return <ErrorState error={inventoryState.error} onRetry={inventoryState.reload} />
  }

  return (
    <div className="page-stack">
      <PageHeader
        title="Inventory"
        description="Track consumable materials and reusable tools with explicit units, stock thresholds, and calendar-ready inventory data."
      />

      {inventoryRequestContext ? (
        <section className="panel page-stack">
          <h3 style={{ margin: 0 }}>Missing resources for task</h3>
          <div className="inline-note">
            {inventoryRequestContext.taskName
              ? `You came here from task "${inventoryRequestContext.taskName}". Replenish the missing inventory, then return and complete the task.`
              : 'You came here from a task with missing inventory. Replenish the missing items, then return and complete the task.'}
          </div>
          {inventoryRequestContext.returnTo ? (
            <div className="row-actions">
              <Link to={inventoryRequestContext.returnTo}>
                <Button variant="secondary">{inventoryRequestContext.returnLabel}</Button>
              </Link>
            </div>
          ) : null}
          {inventoryRequestContext.missing.length > 0 ? (
            <div className="page-stack" style={{ gap: '0.35rem' }}>
              {inventoryRequestContext.missing.map((resource, index) => {
                const resourceKey = buildResourceKey(resource)
                const matchingItem = inventoryMatchesByResource.get(resourceKey)
                const isSelected = activeResourceKey === resourceKey

                return (
                  <div
                    key={`${resource.id ?? index}-${resource.name}`}
                    className="panel"
                    style={{
                      padding: '0.85rem 1rem',
                      borderColor: isSelected ? 'var(--accent)' : 'var(--border)',
                    }}
                  >
                    <div className="page-stack" style={{ gap: '0.5rem' }}>
                      <strong>{resource.name}</strong>
                      <span className="muted">
                        Need {safeNumber(resource.required_quantity, resource.type === 'tool' ? 0 : 2)}, have {safeNumber(resource.available_quantity ?? 0, resource.type === 'tool' ? 0 : 2)}, missing {safeNumber(resource.shortage_quantity ?? 0, resource.type === 'tool' ? 0 : 2)} {formatInventoryUnit(resource.unit)}
                      </span>
                      <div className="inline-note" style={{ fontSize: '0.85rem' }}>
                        {matchingItem
                          ? `Existing stock found. The form is prepared to update "${matchingItem.name}" to ${formatQuantityInput(Number(matchingItem.quantity) + Number(resource.shortage_quantity ?? 0), resource.type)} ${formatInventoryUnit(resource.unit)} total.`
                          : 'No matching inventory entry was found. The form is prepared to create this item with the missing quantity.'}
                      </div>
                      <div className="row-actions">
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
                      </div>
                    </div>
                  </div>
                )
              })}
            </div>
          ) : null}
        </section>
      ) : null}

      <div className="detail-grid">
        <section className="panel table-stack">
          {inventoryState.data.length === 0 ? (
            <EmptyState
              title="Inventory is empty"
              description="Add your first tool or material to start linking garden work with stock levels."
            />
          ) : (
            <div className="table-wrap">
              <table>
                <thead>
                  <tr>
                    <th>Name</th>
                    <th>Type</th>
                    <th>Unit</th>
                    <th>Quantity</th>
                    <th>Min. stock</th>
                    <th>Status</th>
                    <th />
                  </tr>
                </thead>
                <tbody>
                  {inventoryState.data.map((item) => (
                    <tr key={item.id}>
                      <td>{item.name}</td>
                      <td>{item.type}</td>
                      <td>{formatInventoryUnit(item.unit)}</td>
                      <td>{safeNumber(item.quantity, item.type === 'tool' ? 0 : 2)}</td>
                      <td>{safeNumber(item.minimum_quantity, item.type === 'tool' ? 0 : 2)}</td>
                      <td>{item.is_below_minimum ? 'Needs replenishment' : 'Available'}</td>
                      <td>
                        <div className="row-actions">
                          <Button variant="ghost" onClick={() => handleEdit(item.id)}>
                            Edit
                          </Button>
                          <Button variant="danger" onClick={() => handleDelete(item.id)} disabled={submitting}>
                            Delete
                          </Button>
                        </div>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </section>

        <form className="panel input-grid" onSubmit={handleSubmit}>
          <h3>{editingId ? 'Edit inventory item' : 'Add inventory item'}</h3>
          {inventoryRequestContext && activeResourceKey ? (
            <div className="inline-note">
              {editingId
                ? 'The form is preloaded for replenishment. Adjust the total stock after purchase, save it, then return to the task.'
                : 'The form is preloaded with the missing resource so you can add it without retyping details.'}
            </div>
          ) : null}
          <div className="field">
            <label htmlFor="item-name">Name</label>
            <input id="item-name" name="name" value={form.name} onChange={handleChange} required />
          </div>
          <div className="field">
            <label htmlFor="item-type">Type</label>
            <select id="item-type" name="type" value={form.type} onChange={handleChange}>
              {INVENTORY_TYPES.map((type) => (
                <option key={type} value={type}>
                  {type}
                </option>
              ))}
            </select>
          </div>
          <div className="field">
            <label htmlFor="item-unit">Unit</label>
            <select
              id="item-unit"
              name="unit"
              value={form.unit}
              onChange={handleChange}
              disabled={form.type === 'tool'}
            >
              {unitOptions.map((unit) => (
                <option key={unit} value={unit}>
                  {formatInventoryUnit(unit)}
                </option>
              ))}
            </select>
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
          <div className="field">
            <label htmlFor="item-minimum-quantity">Minimum stock</label>
            <input
              id="item-minimum-quantity"
              name="minimum_quantity"
              type="number"
              min="0"
              step={quantityStep}
              value={form.minimum_quantity}
              onChange={handleChange}
            />
          </div>

          <div className="inline-note" style={{ fontSize: '0.85rem' }}>
            {form.type === 'tool'
              ? 'Reusable tools are only checked for availability and are never deducted after task completion.'
              : 'Consumable materials are automatically deducted from inventory when linked care tasks are completed.'}
          </div>

          {successMessage ? <span style={{ color: 'var(--success)' }}>{successMessage}</span> : null}
          {error ? <span className="field-error">{error}</span> : null}

          <div className="form-actions">
            <Button type="submit" disabled={submitting}>
              {submitting ? 'Saving...' : editingId ? 'Save item' : 'Add item'}
            </Button>
            {editingId ? (
              <Button
                variant="secondary"
                onClick={() => {
                  setEditingId(null)
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
          </div>
        </form>
      </div>
    </div>
  )
}
