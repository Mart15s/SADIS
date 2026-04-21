import { useEffect, useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import PageHeader from '../../components/layout/PageHeader.jsx'
import { EmptyState, ErrorState, LoadingState } from '../../components/shared/StatusView.jsx'
import Button from '../../components/ui/Button.jsx'
import { api } from '../../lib/api.js'
import { CONDITION_TYPES, PLANT_TYPES, formatDate } from '../../lib/constants.js'
import { useAsyncData } from '../../lib/hooks/useAsyncData.js'

function parseBooleanSelectValue(value) {
  return value === 'true'
}

const initialConditionForm = {
  measured_at: new Date().toISOString().slice(0, 10),
  notes: '',
  photo_url: '',
  condition: CONDITION_TYPES[6],
  disease: '',
}

export default function PlantDetailPage() {
  const navigate = useNavigate()
  const { plotId, plantId } = useParams()
  const [plantForm, setPlantForm] = useState(null)
  const [conditionForm, setConditionForm] = useState(initialConditionForm)
  const [error, setError] = useState('')
  const [submitting, setSubmitting] = useState(false)

  const pageState = useAsyncData(
    async () => {
      const plots = await api.listPlots()
      const accessRole = plots.find((entry) => String(entry.id) === String(plotId))?.access_role ?? null
      const [plant, conditions, rotations, zones] = await Promise.all([
        api.getPlant(plotId, plantId),
        api.listPlantConditions(plotId, plantId),
        api.listRotations(plotId),
        api.listPlantZones(plotId),
      ])

      return {
        plant,
        conditions,
        rotations: rotations.filter((rotation) => String(rotation.fk_plant_id) === String(plantId)),
        zones,
        accessRole,
      }
    },
    [plotId, plantId],
    {
      plant: null,
      conditions: [],
      rotations: [],
      zones: [],
      accessRole: null,
    },
  )

  useEffect(() => {
    if (pageState.data.plant) {
      setPlantForm({
        name: pageState.data.plant.name ?? '',
        plant_date: pageState.data.plant.plant_date ?? '',
        type: pageState.data.plant.type ?? '',
        condition: pageState.data.plant.condition ?? CONDITION_TYPES[6],
        disease: Boolean(pageState.data.plant.disease),
        growing_time_days: pageState.data.plant.growing_time_days ?? '',
        recommended_temperature: pageState.data.plant.recommended_temperature ?? '',
        recommended_humidity: pageState.data.plant.recommended_humidity ?? '',
        rest_time_days: pageState.data.plant.rest_time_days ?? '',
        plant_size: pageState.data.plant.plant_size ?? '',
        fk_plant_zone_id: pageState.data.plant.fk_plant_zone_id ?? '',
      })
    }
  }, [pageState.data.plant])

  const canEdit = ['owner', 'editor'].includes(pageState.data.accessRole)

  async function handlePlantSave(event) {
    event.preventDefault()
    setSubmitting(true)
    setError('')

    try {
      const updated = await api.updatePlant(plotId, plantId, {
        ...plantForm,
        fk_plant_zone_id: Number(plantForm.fk_plant_zone_id),
        growing_time_days: plantForm.growing_time_days ? Number(plantForm.growing_time_days) : null,
        recommended_temperature: plantForm.recommended_temperature ? Number(plantForm.recommended_temperature) : null,
        recommended_humidity: plantForm.recommended_humidity ? Number(plantForm.recommended_humidity) : null,
        rest_time_days: plantForm.rest_time_days ? Number(plantForm.rest_time_days) : null,
        plant_size: plantForm.plant_size ? Number(plantForm.plant_size) : null,
        disease: Boolean(plantForm.disease),
      })
      pageState.setData((current) => ({
        ...current,
        plant: updated,
      }))
    } catch (requestError) {
      setError(requestError.message)
    } finally {
      setSubmitting(false)
    }
  }

  async function handleConditionSubmit(event) {
    event.preventDefault()
    setSubmitting(true)
    setError('')

    try {
      const created = await api.createPlantCondition(plotId, plantId, {
        ...conditionForm,
        notes: conditionForm.notes || null,
        photo_url: conditionForm.photo_url || null,
        disease: conditionForm.disease || null,
      })
      pageState.setData((current) => ({
        ...current,
        conditions: [created, ...current.conditions],
        plant: {
          ...current.plant,
          condition: conditionForm.condition,
          disease: conditionForm.disease || current.plant.disease,
        },
      }))
      setConditionForm(initialConditionForm)
    } catch (requestError) {
      setError(requestError.message)
    } finally {
      setSubmitting(false)
    }
  }

  async function handleDelete() {
    setSubmitting(true)
    setError('')

    try {
      await api.deletePlant(plotId, plantId)
      navigate(`/plots/${plotId}`)
    } catch (requestError) {
      setError(requestError.message)
      setSubmitting(false)
    }
  }

  if (pageState.loading || !plantForm) {
    return <LoadingState title="Loading plant detail..." />
  }

  if (pageState.error) {
    return <ErrorState error={pageState.error} onRetry={pageState.reload} />
  }

  if (!pageState.data.plant) {
    return <EmptyState title="Plant not found" description="The requested plant could not be loaded." />
  }

  const linkedZone = pageState.data.plant.plantZone ?? pageState.data.plant.plant_zone ?? null
  const linkedCare = pageState.data.plant.plantCare ?? pageState.data.plant.plant_care ?? null
  const sharedCare = pageState.data.plant.sharedPlantCare ?? pageState.data.plant.shared_plant_care ?? linkedCare
  const linkedCatalogPlant = pageState.data.plant.catalogPlant ?? pageState.data.plant.catalog_plant ?? null
  const hasLinkedCare = Boolean(pageState.data.plant.fk_plant_care_id || linkedCare)

  return (
    <div className="page-stack">
      <PageHeader
        title={pageState.data.plant.name}
        description={`Type ${pageState.data.plant.type} | condition ${pageState.data.plant.condition} | zone ${linkedZone?.name ?? 'Unknown'} | catalog ${linkedCatalogPlant?.name ?? 'none'}`}
        actions={(
          <Link to={`/plots/${plotId}`}>
            <Button variant="secondary">Back to plot</Button>
          </Link>
        )}
      />

      <div className="detail-grid">
        <section className="panel page-stack">
          <h3>Plant details</h3>
          <div className="meta-cluster">
            <span>Planted {formatDate(pageState.data.plant.plant_date)}</span>
            <span>Disease {pageState.data.plant.disease ? 'Yes' : 'No'}</span>
            <span>Care profile {pageState.data.plant.fk_plant_care_id ?? 'Not linked'}</span>
            <span>Catalog {linkedCatalogPlant?.name ?? 'Not linked'}</span>
            <span>
              Care source {linkedCare?.source_quality ?? 'missing'}
            </span>
          </div>

          {linkedCatalogPlant ? (
            <div className="inline-note">
              This planted instance is linked to reusable catalog plant {linkedCatalogPlant.name}. Shared care edits happen on the catalog plant.
            </div>
          ) : null}

          {linkedCare ? (
            <div className="panel page-stack">
              <h3>Effective care profile</h3>
              <div className="meta-cluster">
                <span>{linkedCare.plant_name}</span>
                <span>Water every {linkedCare.watering_interval_days ?? 'n/a'} days</span>
                <span>Fertilize every {linkedCare.fertilizing_interval_days ?? 'n/a'} days</span>
                <span>Provider {linkedCare.source_provider ?? 'local'}</span>
                <span>Quality {linkedCare.source_quality ?? 'unknown'}</span>
                <span>Canonical {linkedCare.canonical_name ?? 'n/a'}</span>
                <span>Scientific {linkedCare.source_scientific_name ?? 'n/a'}</span>
                <span>Family {linkedCare.source_family ?? 'n/a'}</span>
                <span>Species ID {linkedCare.source_perenual_species_id ?? 'n/a'}</span>
              </div>

              {sharedCare ? (
                <div className="inline-note">
                  This plant uses the shared catalog care profile #{pageState.data.plant.fk_plant_care_id}. To change watering, fertilizing, or thresholds, edit the catalog plant.
                </div>
              ) : null}
              {linkedCatalogPlant ? (
                <div className="row-actions">
                  <Link to={`/plants/catalog/${linkedCatalogPlant.id}`}>
                    <Button variant="ghost">Open Catalog Plant</Button>
                  </Link>
                  <Link to={`/plants/catalog/${linkedCatalogPlant.id}/edit`}>
                    <Button variant="secondary">Edit Shared Care</Button>
                  </Link>
                </div>
              ) : null}
            </div>
          ) : !hasLinkedCare ? (
            <div className="inline-note">
              No linked care profile is present on this plant record. The backend can still generate defaults when needed.
            </div>
          ) : (
            <div className="inline-note">
              A care profile ID is linked, but its metadata is not available in this response yet.
            </div>
          )}

          {canEdit ? (
            <form className="input-grid" onSubmit={handlePlantSave}>
              <div className="field">
                <label htmlFor="plant-edit-name">Name</label>
                <input
                  id="plant-edit-name"
                  value={plantForm.name}
                  onChange={(event) => setPlantForm((current) => ({ ...current, name: event.target.value }))}
                  required
                />
              </div>
              <div className="field">
                <label htmlFor="plant-edit-zone">Zone</label>
                <select
                  id="plant-edit-zone"
                  value={plantForm.fk_plant_zone_id}
                  onChange={(event) => setPlantForm((current) => ({ ...current, fk_plant_zone_id: event.target.value }))}
                >
                  {pageState.data.zones.map((zone) => (
                    <option key={zone.id} value={zone.id}>
                      {zone.name}
                    </option>
                  ))}
                </select>
              </div>
              <div className="field">
                <label>Catalog plant</label>
                <div className="inline-note">
                  {linkedCatalogPlant ? (
                    <Link to={`/plants/catalog/${linkedCatalogPlant.id}`}>
                      Open {linkedCatalogPlant.name}
                    </Link>
                  ) : 'No catalog plant linked'}
                </div>
              </div>
              <div className="field">
                <label htmlFor="plant-edit-type">Type</label>
                <select
                  id="plant-edit-type"
                  value={plantForm.type}
                  onChange={(event) => setPlantForm((current) => ({ ...current, type: event.target.value }))}
                  required
                >
                  <option value="" disabled>Select type</option>
                  {PLANT_TYPES.map((type) => (
                    <option key={type} value={type}>
                      {type}
                    </option>
                  ))}
                </select>
              </div>
              <div className="field">
                <label htmlFor="plant-edit-condition">Condition</label>
                <select
                  id="plant-edit-condition"
                  value={plantForm.condition}
                  onChange={(event) => setPlantForm((current) => ({ ...current, condition: event.target.value }))}
                >
                  {CONDITION_TYPES.map((condition) => (
                    <option key={condition} value={condition}>
                      {condition}
                    </option>
                  ))}
                </select>
              </div>
              <div className="field">
                <label htmlFor="plant-edit-disease">Disease present</label>
                <select
                  id="plant-edit-disease"
                  value={plantForm.disease ? 'true' : 'false'}
                  onChange={(event) => setPlantForm((current) => ({ ...current, disease: parseBooleanSelectValue(event.target.value) }))}
                >
                  <option value="false">No</option>
                  <option value="true">Yes</option>
                </select>
              </div>
              {error ? <span className="field-error">{error}</span> : null}
              <div className="form-actions">
                <Button type="submit" disabled={submitting}>
                  {submitting ? 'Saving...' : 'Save plant'}
                </Button>
                <Button variant="danger" onClick={handleDelete} disabled={submitting}>
                  Delete plant
                </Button>
              </div>
            </form>
          ) : null}
        </section>

        <aside className="page-stack">
          <section className="panel page-stack">
            <h3>Condition history</h3>
            {pageState.data.conditions.length === 0 ? (
              <EmptyState title="No condition history" description="No condition logs exist for this plant yet." />
            ) : (
              <div className="table-wrap">
                <table>
                  <thead>
                    <tr>
                      <th>Date</th>
                      <th>Condition</th>
                      <th>Notes</th>
                    </tr>
                  </thead>
                  <tbody>
                    {pageState.data.conditions.map((entry) => (
                      <tr key={entry.id}>
                        <td>{formatDate(entry.measured_at)}</td>
                        <td>{entry.condition}</td>
                        <td>{entry.notes || 'No notes'}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}

            {canEdit ? (
              <form className="input-grid" onSubmit={handleConditionSubmit}>
                <div className="field">
                  <label htmlFor="condition-date">Measured at</label>
                  <input
                    id="condition-date"
                    type="date"
                    value={conditionForm.measured_at}
                    onChange={(event) => setConditionForm((current) => ({ ...current, measured_at: event.target.value }))}
                    required
                  />
                </div>
                <div className="field">
                  <label htmlFor="condition-type">Condition</label>
                  <select
                    id="condition-type"
                    value={conditionForm.condition}
                    onChange={(event) => setConditionForm((current) => ({ ...current, condition: event.target.value }))}
                  >
                    {CONDITION_TYPES.map((condition) => (
                      <option key={condition} value={condition}>
                        {condition}
                      </option>
                    ))}
                  </select>
                </div>
                <div className="field">
                  <label htmlFor="condition-notes">Notes</label>
                  <textarea
                    id="condition-notes"
                    value={conditionForm.notes}
                    onChange={(event) => setConditionForm((current) => ({ ...current, notes: event.target.value }))}
                  />
                </div>
                <Button type="submit" disabled={submitting}>
                  {submitting ? 'Saving...' : 'Log condition'}
                </Button>
              </form>
            ) : null}
          </section>

          <section className="panel page-stack">
            <h3>Rotation history</h3>
            {pageState.data.rotations.length === 0 ? (
              <EmptyState title="No rotations" description="This plant has not appeared in recorded rotation history yet." />
            ) : (
              <div className="table-wrap">
                <table>
                  <thead>
                    <tr>
                      <th>From</th>
                      <th>To</th>
                      <th>Zone</th>
                    </tr>
                  </thead>
                  <tbody>
                    {pageState.data.rotations.map((rotation) => (
                      <tr key={rotation.id}>
                        <td>{formatDate(rotation.from_date)}</td>
                        <td>{formatDate(rotation.to_date)}</td>
                        <td>{rotation.fk_plant_zone_id}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </section>
        </aside>
      </div>
    </div>
  )
}
