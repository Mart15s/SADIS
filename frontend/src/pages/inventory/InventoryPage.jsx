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
        returnLabel: searchParams.get('returnLabel') || 'Grįžti į kalendorių',
        missing: Array.isArray(missing) ? missing : [],
      }
    } catch {
      return {
        taskId: searchParams.get('taskId'),
        taskName: searchParams.get('taskName'),
        returnTo: searchParams.get('returnTo'),
        returnLabel: searchParams.get('returnLabel') || 'Grįžti į kalendorių',
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
        setSuccessMessage(`Inventorius „${updated.name}“ atnaujintas.`)
      } else {
        const created = await api.createInventoryItem(payload)
        inventoryState.setData((current) => [created, ...current])
        setSuccessMessage(`„${created.name}“ pridėta į inventorių.`)
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
          Redaguoti
        </Button>
        <Button variant="danger" size="sm" onClick={() => handleDelete(item.id)} disabled={submitting}>
          Šalinti
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
          badge={<Badge tone={item.is_available ? 'success' : 'warning'}>{item.is_available ? 'Yra sandėlyje' : 'Trūksta'}</Badge>}
        />
        <ResourceCardMeta>
          <Badge tone="neutral">{formatInventoryType(item.type)}</Badge>
          <Badge tone="soft">{safeNumber(item.quantity, item.type === 'tool' ? 0 : 2)} {formatInventoryUnit(item.unit)}</Badge>
        </ResourceCardMeta>
        <ResourceCardBody>
          <dl className="resource-detail-grid">
            <div>
              <dt>Kiekis</dt>
              <dd>{safeNumber(item.quantity, item.type === 'tool' ? 0 : 2)}</dd>
            </div>
            <div>
              <dt>Būsena</dt>
              <dd>{item.is_available ? 'Yra sandėlyje' : 'Trūksta'}</dd>
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
    { key: 'name', label: 'Pavadinimas', render: (item) => item.name },
    { key: 'type', label: 'Tipas', render: (item) => formatInventoryType(item.type) },
    { key: 'unit', label: 'Vienetas', render: (item) => formatInventoryUnit(item.unit) },
    { key: 'quantity', label: 'Kiekis', render: (item) => safeNumber(item.quantity, item.type === 'tool' ? 0 : 2) },
    { key: 'status', label: 'Būsena', render: (item) => item.is_available ? 'Yra sandėlyje' : 'Trūksta' },
    {
      key: 'actions',
      label: '',
      cellClassName: 'table-actions-cell',
      render: (item) => renderInventoryActions(item),
    },
  ]

  if (inventoryState.loading) {
    return <LoadingState title="Įkeliamas inventorius..." />
  }

  if (inventoryState.error) {
    return <ErrorState error={inventoryState.error} onRetry={inventoryState.reload} />
  }

  return (
    <div className="page-stack">
      <PageHeader
        eyebrow="Atsargos ir priemonės"
        title="Inventorius"
        description="Valdykite medžiagas ir įrankius, kurie naudojami kalendoriaus užduotims, trūkumams ir papildymui."
        meta={<StatusBadge kind="ownership">{inventoryState.data.length} įrašų</StatusBadge>}
      />

      <section className="inventory-yard-strip" aria-label="Inventoriaus suvestinė">
        <MeasurementBadge label="Medžiagos" value={materialCount} tone="earth" />
        <MeasurementBadge label="Įrankiai" value={toolCount} tone="field" />
        <MeasurementBadge label="Trūkumai" value={unavailableCount} tone={unavailableCount > 0 ? 'amber' : 'leaf'} />
      </section>

      {inventoryRequestContext ? (
        <SectionCard title="Užduočiai trūkstami resursai" description="Papildykite atsargas pagal kalendoriaus trūkumus ir grįžkite į užduotį.">
          <div className="inline-note">
            {inventoryRequestContext.taskName
              ? `Čia atėjote iš užduoties „${inventoryRequestContext.taskName}“. Papildykite inventorių, tada grįžkite ir atlikite užduotį.`
              : 'Čia atėjote iš užduoties, kuriai trūksta inventoriaus. Papildykite trūkstamus įrašus, tada grįžkite ir atlikite užduotį.'}
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
                          label="Reikia"
                          value={`${safeNumber(resource.required_quantity, resource.type === 'tool' ? 0 : 2)} ${formatInventoryUnit(resource.unit)}`}
                        />
                        <StatRow
                          label="Turima"
                          value={`${safeNumber(resource.available_quantity ?? 0, resource.type === 'tool' ? 0 : 2)} ${formatInventoryUnit(resource.unit)}`}
                        />
                        <StatRow
                          label="Trūksta"
                          className={Number(resource.shortage_quantity ?? 0) > 0 ? 'stat-row-danger' : ''}
                          value={`${safeNumber(resource.shortage_quantity ?? 0, resource.type === 'tool' ? 0 : 2)} ${formatInventoryUnit(resource.unit)}`}
                        />
                      </div>
                      <div className="inline-note inline-note-compact">
                        Tipas automatiškai priskiriamas pagal užduotį: <strong>{formatInventoryType(resource.type)}</strong>.
                      </div>
                      <div className="inline-note inline-note-compact">
                        {matchingItem
                          ? `Rastas esamas įrašas. Forma paruošta atnaujinti „${matchingItem.name}“ iki ${formatQuantityInput(Number(matchingItem.quantity) + Number(resource.shortage_quantity ?? 0), resource.type)} ${formatInventoryUnit(resource.unit)}.`
                          : 'Atitinkančio inventoriaus įrašo nerasta. Forma paruošta sukurti įrašą su trūkstamu kiekiu.'}
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
                          {matchingItem ? 'Paruošti papildymo formą' : 'Paruošti pridėjimo formą'}
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
          title="Stebimi įrašai"
          description="Inventoriaus sąraše rodomi įrankiai ir medžiagos su jų kiekiais bei vienetais."
        >
          {inventoryState.data.length === 0 ? (
            <EmptyState
              title="Inventorius tuščias"
              description="Pridėkite pirmą įrankį arba medžiagą, kad daržo darbai būtų susieti su atsargomis."
            />
          ) : (
            <ResponsiveTable
              columns={inventoryColumns}
              items={inventoryState.data}
              getKey={(item) => item.id}
              renderCard={renderInventoryCard}
              tableLabel="Inventoriaus įrašų lentelė"
              cardListLabel="Inventoriaus įrašų sąrašas"
            />
          )}
        </SectionCard>

        <form
          ref={inventoryFormRef}
          className={editingId ? 'inventory-editor-form is-editing' : 'inventory-editor-form'}
          onSubmit={handleSubmit}
        >
          <FormSection
            title={editingId ? 'Redaguoti inventoriaus įrašą' : 'Pridėti inventoriaus įrašą'}
            description="Naudokite šią formą naujiems įrašams pridėti arba atsargoms papildyti."
          >
            {inventoryRequestContext && activeResourceKey ? (
              <div className="inline-note">
                {editingId
                  ? 'Forma paruošta papildymui. Pakoreguokite bendrą kiekį, išsaugokite ir grįžkite į užduotį.'
                  : 'Forma paruošta pagal trūkstamą resursą, todėl nereikia perrašyti duomenų.'}
              </div>
            ) : null}
            <div className="input-grid">
              <div className="field">
                <label htmlFor="item-name">Pavadinimas</label>
                <input id="item-name" name="name" value={form.name} onChange={handleChange} required />
              </div>
              <div className="field">
                <label htmlFor="item-type">Tipas</label>
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
                  <span className="field-hint">Tipas užrakintas pagal užduoties resursą.</span>
                ) : null}
              </div>
              <div className="field">
                <label htmlFor="item-unit">Vienetas</label>
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
                  <span className="field-hint">Vienetas užrakintas pagal užduoties resursą.</span>
                ) : null}
              </div>
              <div className="field">
                <label htmlFor="item-quantity">Kiekis</label>
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
                ? 'Daugkartiniai įrankiai tikrinami tik pagal prieinamumą ir po užduoties nėra nurašomi.'
                : 'Sunaudojamos medžiagos automatiškai nurašomos iš inventoriaus, kai susietos užduotys pažymimos atliktomis.'}
            </div>

            {successMessage ? <span className="form-success">{successMessage}</span> : null}
            {error ? <span className="field-error">{error}</span> : null}

            <ActionRow>
              <Button type="submit" disabled={submitting}>
                {submitting ? 'Saugoma...' : editingId ? 'Išsaugoti įrašą' : 'Pridėti įrašą'}
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
                  Atšaukti redagavimą
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
