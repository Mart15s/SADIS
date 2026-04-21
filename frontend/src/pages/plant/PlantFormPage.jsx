import { useEffect, useMemo, useState } from 'react'
import { Link, useNavigate, useParams, useSearchParams } from 'react-router-dom'
import PageHeader from '../../components/layout/PageHeader.jsx'
import { EmptyState, ErrorState, LoadingState } from '../../components/shared/StatusView.jsx'
import Button from '../../components/ui/Button.jsx'
import { api } from '../../lib/api.js'
import { CONDITION_TYPES, PLANT_TYPES } from '../../lib/constants.js'
import { useAsyncData } from '../../lib/hooks/useAsyncData.js'

function createEmptyPlantForm() {
  return {
    name: '',
    type: '',
    condition: CONDITION_TYPES[6],
    plant_date: new Date().toISOString().slice(0, 10),
    growing_time_days: '',
    recommended_temperature: '',
    recommended_humidity: '',
    rest_time_days: '',
    plant_size: '',
    photo_url: '',
    disease: false,
    disease_notes: '',
    fk_plot_id: '',
    fk_plant_zone_id: '',
    fk_catalog_plant_id: '',
  }
}

function plantToForm(plant) {
  return {
    name: plant.name ?? '',
    type: plant.type ?? '',
    condition: plant.condition ?? CONDITION_TYPES[6],
    plant_date: plant.plant_date ?? new Date().toISOString().slice(0, 10),
    growing_time_days: plant.growing_time_days ?? '',
    recommended_temperature: plant.recommended_temperature ?? '',
    recommended_humidity: plant.recommended_humidity ?? '',
    rest_time_days: plant.rest_time_days ?? '',
    plant_size: plant.plant_size ?? '',
    photo_url: plant.photo_url ?? '',
    disease: Boolean(plant.disease),
    disease_notes: plant.disease_notes ?? '',
    fk_plot_id: String(plant.plot?.id ?? plant.fk_plot_id ?? ''),
    fk_plant_zone_id: String(plant.plant_zone?.id ?? plant.fk_plant_zone_id ?? ''),
    fk_catalog_plant_id: String(plant.catalog_plant?.id ?? plant.catalogPlant?.id ?? plant.fk_catalog_plant_id ?? ''),
  }
}

function toNullableNumber(value) {
  return value === '' || value === null || value === undefined ? null : Number(value)
}

function carePreview(catalogPlant) {
  return catalogPlant?.plant_care ?? catalogPlant?.plantCare ?? null
}

function classificationSummary(metadata) {
  const classification = metadata?.classification

  if (!classification) {
    return ''
  }

  const label = classification.profile_label ?? classification.profile_group ?? 'Detected profile'
  const officialType = classification.official_plant_type

  if (officialType && classification.profile_group && officialType !== classification.profile_group) {
    return `${label} detected. Official plant type remains "${officialType}" to stay within the required specification.`
  }

  return `${label} detected from the catalog classification signals.`
}

export default function PlantFormPage() {
  const navigate = useNavigate()
  const [searchParams] = useSearchParams()
  const { plantId } = useParams()
  const isEdit = Boolean(plantId)
  const initialCatalogPlantId = searchParams.get('catalogPlantId')
  const [plantForm, setPlantForm] = useState(createEmptyPlantForm())
  const [zones, setZones] = useState([])
  const [zonesLoading, setZonesLoading] = useState(false)
  const [catalogSearch, setCatalogSearch] = useState('')
  const [submitError, setSubmitError] = useState('')
  const [validationErrors, setValidationErrors] = useState({})
  const [submitting, setSubmitting] = useState(false)

  const pageState = useAsyncData(
    async () => {
      const [plots, catalogPlants, plant] = await Promise.all([
        api.listPlots(),
        api.listCatalogPlants(),
        isEdit ? api.getManagedPlant(plantId) : Promise.resolve(null),
      ])

      return { plots, catalogPlants, plant }
    },
    [plantId, isEdit],
    { plots: [], catalogPlants: [], plant: null },
  )

  const selectedCatalogPlant = useMemo(() => (
    pageState.data.catalogPlants.find((entry) => String(entry.id) === String(plantForm.fk_catalog_plant_id)) ?? null
  ), [pageState.data.catalogPlants, plantForm.fk_catalog_plant_id])

  useEffect(() => {
    if (isEdit) {
      if (!pageState.data.plant) {
        return
      }

      const nextForm = plantToForm(pageState.data.plant)
      setPlantForm(nextForm)
      setCatalogSearch(pageState.data.plant.catalog_plant?.name ?? pageState.data.plant.catalogPlant?.name ?? pageState.data.plant.name ?? '')
      return
    }

    if (pageState.data.plots.length === 0) {
      return
    }

    setPlantForm((current) => ({
      ...current,
      fk_plot_id: current.fk_plot_id || String(pageState.data.plots[0].id),
      fk_catalog_plant_id: current.fk_catalog_plant_id || String(initialCatalogPlantId ?? ''),
    }))

    if (initialCatalogPlantId) {
      const catalogPlant = pageState.data.catalogPlants.find((entry) => String(entry.id) === String(initialCatalogPlantId))

      if (catalogPlant) {
        setCatalogSearch(catalogPlant.name)
        setPlantForm((current) => ({
          ...current,
          name: current.name || catalogPlant.name,
          type: current.type || catalogPlant.plant_type || '',
          fk_catalog_plant_id: String(catalogPlant.id),
        }))
      }
    }
  }, [initialCatalogPlantId, isEdit, pageState.data.catalogPlants, pageState.data.plant, pageState.data.plots])

  useEffect(() => {
    if (!plantForm.fk_plot_id) {
      setZones([])
      return
    }

    let cancelled = false
    setZonesLoading(true)

    api.listPlantZones(plantForm.fk_plot_id)
      .then((loadedZones) => {
        if (cancelled) {
          return
        }

        setZones(loadedZones)
        setPlantForm((current) => ({
          ...current,
          fk_plant_zone_id: loadedZones.some((zone) => String(zone.id) === String(current.fk_plant_zone_id))
            ? current.fk_plant_zone_id
            : String(loadedZones[0]?.id ?? ''),
        }))
      })
      .catch(() => {
        if (!cancelled) {
          setZones([])
        }
      })
      .finally(() => {
        if (!cancelled) {
          setZonesLoading(false)
        }
      })

    return () => {
      cancelled = true
    }
  }, [plantForm.fk_plot_id])

  const filteredCatalog = useMemo(() => {
    const search = catalogSearch.trim().toLowerCase()

    if (!search) {
      return pageState.data.catalogPlants.slice(0, 8)
    }

    return pageState.data.catalogPlants
      .filter((entry) => [
        entry.name,
        entry.canonical_name,
        entry.plant_type,
        entry.source_scientific_name,
        entry.source_family,
      ].some((value) => String(value ?? '').toLowerCase().includes(search)))
      .slice(0, 8)
  }, [catalogSearch, pageState.data.catalogPlants])

  function fieldError(key) {
    return validationErrors[key]?.[0] ?? ''
  }

  function handleCatalogSelect(catalogPlant) {
    setCatalogSearch(catalogPlant.name)
    setPlantForm((current) => ({
      ...current,
      name: catalogPlant.name,
      type: catalogPlant.plant_type || current.type,
      fk_catalog_plant_id: String(catalogPlant.id),
    }))
  }

  async function handleSubmit(event) {
    event.preventDefault()
    setSubmitting(true)
    setSubmitError('')
    setValidationErrors({})

    const payload = {
      name: plantForm.name.trim() || null,
      type: plantForm.type || null,
      condition: plantForm.condition,
      plant_date: plantForm.plant_date,
      growing_time_days: toNullableNumber(plantForm.growing_time_days),
      recommended_temperature: toNullableNumber(plantForm.recommended_temperature),
      recommended_humidity: toNullableNumber(plantForm.recommended_humidity),
      rest_time_days: toNullableNumber(plantForm.rest_time_days),
      plant_size: toNullableNumber(plantForm.plant_size),
      photo_url: plantForm.photo_url || null,
      disease: Boolean(plantForm.disease),
      disease_notes: plantForm.disease_notes || null,
      fk_plant_zone_id: Number(plantForm.fk_plant_zone_id),
      fk_catalog_plant_id: plantForm.fk_catalog_plant_id ? Number(plantForm.fk_catalog_plant_id) : null,
    }

    if (!isEdit) {
      payload.fk_plot_id = Number(plantForm.fk_plot_id)
    }

    try {
      const savedPlant = isEdit
        ? await api.updateManagedPlant(plantId, payload)
        : await api.createManagedPlant(payload)

      navigate(`/plants/${savedPlant.id}`, {
        state: {
          notice: isEdit ? 'Plant updated successfully.' : 'Plant created successfully.',
        },
      })
    } catch (requestError) {
      setSubmitError(requestError.message)
      setValidationErrors(requestError.details ?? {})
    } finally {
      setSubmitting(false)
    }
  }

  if (pageState.loading) {
    return <LoadingState title={isEdit ? 'Loading plant editor...' : 'Loading plant form...'} />
  }

  if (pageState.error) {
    return <ErrorState error={pageState.error} onRetry={pageState.reload} />
  }

  if (isEdit && !pageState.data.plant) {
    return <EmptyState title="Plant not found" description="The requested plant could not be loaded." />
  }

  if (!isEdit && pageState.data.plots.length === 0) {
    return (
      <EmptyState
        title="Create a plot first"
        description="Plants belong to a plot and zone, so you need at least one plot before creating a planted record."
        action={(
          <Link to="/plots">
            <Button>Open Plots</Button>
          </Link>
        )}
      />
    )
  }

  const selectedCare = carePreview(selectedCatalogPlant)

  return (
    <div className="page-stack">
      <PageHeader
        title={isEdit ? 'Edit Plant' : 'Create Plant'}
        description={isEdit
          ? 'Update the planted instance and its catalog linkage.'
          : 'Place a plant into a plot and zone, starting from the reusable plant catalog whenever possible.'}
        actions={(
          <>
            <Link to={isEdit ? `/plants/${plantId}` : '/plants'}>
              <Button variant="secondary">Cancel</Button>
            </Link>
            <Link to="/plants/catalog/new">
              <Button variant="ghost">New Catalog Plant</Button>
            </Link>
          </>
        )}
      />

      <section className="panel page-stack">
        <div>
          <h3 className="section-title">Catalog Plant</h3>
          <p className="section-copy">Choose a reusable catalog plant to prefill shared identity and care data before placing this plant into a zone.</p>
        </div>
        <div className="field">
          <label htmlFor="catalog-search">Catalog search</label>
          <input
            id="catalog-search"
            value={catalogSearch}
            onChange={(event) => {
              setCatalogSearch(event.target.value)
              setPlantForm((current) => ({
                ...current,
                fk_catalog_plant_id: '',
              }))
            }}
            placeholder="Search the plant catalog"
          />
        </div>
        {plantForm.fk_catalog_plant_id ? (
          <div className="inline-note">
            Selected catalog plant #{plantForm.fk_catalog_plant_id}. Editing shared care now happens in the catalog plant page.
          </div>
        ) : (
          <div className="inline-note">
            No catalog plant selected. You can still save a manual plant record, but new reusable plants should ideally be created in Plant Catalog first.
          </div>
        )}
        {filteredCatalog.length > 0 ? (
          <div className="card-grid">
            {filteredCatalog.map((entry) => (
              <button
                key={entry.id}
                type="button"
                className="card plants-catalog-card"
                onClick={() => handleCatalogSelect(entry)}
              >
                <div className="list-head">
                  <strong>{entry.name}</strong>
                  <span className="badge badge-soft">{entry.plant_type ?? 'unknown'}</span>
                </div>
                <div className="muted">{entry.source_scientific_name || 'No scientific name stored'}</div>
                <div className="meta-cluster">
                  <span>Water every {entry.plant_care_summary?.watering_interval_days ?? 'n/a'} days</span>
                  <span>Fertilize every {entry.plant_care_summary?.fertilizing_interval_days ?? 'n/a'} days</span>
                  <span>{entry.usage_count ?? 0} placed</span>
                </div>
              </button>
            ))}
          </div>
        ) : (
          <div className="inline-note">No catalog plants matched that search.</div>
        )}
      </section>

      <form className="page-stack" onSubmit={handleSubmit}>
        <section className="panel page-stack">
          <div>
            <h3 className="section-title">Planted Instance</h3>
            <p className="section-copy">Instance-specific details for this plot and zone placement.</p>
          </div>

          <div className="form-grid plants-form-grid">
            {!isEdit ? (
              <div className="field">
                <label htmlFor="plant-plot">Plot</label>
                <select
                  id="plant-plot"
                  value={plantForm.fk_plot_id}
                  onChange={(event) => setPlantForm((current) => ({
                    ...current,
                    fk_plot_id: event.target.value,
                    fk_plant_zone_id: '',
                  }))}
                  required
                >
                  <option value="" disabled>Select plot</option>
                  {pageState.data.plots.map((plot) => (
                    <option key={plot.id} value={plot.id}>
                      {plot.name}
                    </option>
                  ))}
                </select>
                {fieldError('fk_plot_id') ? <span className="field-error">{fieldError('fk_plot_id')}</span> : null}
              </div>
            ) : (
              <div className="field">
                <label>Plot</label>
                <div className="inline-note">
                  {pageState.data.plant?.plot?.name ?? 'Unknown plot'}
                </div>
              </div>
            )}

            <div className="field">
              <label htmlFor="plant-zone">Zone</label>
              <select
                id="plant-zone"
                value={plantForm.fk_plant_zone_id}
                onChange={(event) => setPlantForm((current) => ({ ...current, fk_plant_zone_id: event.target.value }))}
                required
                disabled={zonesLoading || zones.length === 0}
              >
                <option value="" disabled>{zonesLoading ? 'Loading zones...' : 'Select zone'}</option>
                {zones.map((zone) => (
                  <option key={zone.id} value={zone.id}>
                    {zone.name}
                  </option>
                ))}
              </select>
              {zones.length === 0 && !zonesLoading ? (
                <span className="field-hint">Create a zone in the selected plot before saving this plant.</span>
              ) : null}
              {fieldError('fk_plant_zone_id') ? <span className="field-error">{fieldError('fk_plant_zone_id')}</span> : null}
            </div>

            <div className="field">
              <label htmlFor="plant-name">Display name</label>
              <input
                id="plant-name"
                value={plantForm.name}
                onChange={(event) => setPlantForm((current) => ({ ...current, name: event.target.value }))}
              />
              {fieldError('name') ? <span className="field-error">{fieldError('name')}</span> : null}
            </div>

            <div className="field">
              <label htmlFor="plant-type">Plant type</label>
              <select
                id="plant-type"
                value={plantForm.type}
                onChange={(event) => setPlantForm((current) => ({ ...current, type: event.target.value }))}
              >
                <option value="">Select type</option>
                {PLANT_TYPES.map((type) => (
                  <option key={type} value={type}>
                    {type}
                  </option>
                ))}
              </select>
              {fieldError('type') ? <span className="field-error">{fieldError('type')}</span> : null}
            </div>

            <div className="field">
              <label htmlFor="plant-condition">Condition</label>
              <select
                id="plant-condition"
                value={plantForm.condition}
                onChange={(event) => setPlantForm((current) => ({ ...current, condition: event.target.value }))}
                required
              >
                {CONDITION_TYPES.map((condition) => (
                  <option key={condition} value={condition}>
                    {condition}
                  </option>
                ))}
              </select>
            </div>

            <div className="field">
              <label htmlFor="plant-date">Plant date</label>
              <input
                id="plant-date"
                type="date"
                value={plantForm.plant_date}
                onChange={(event) => setPlantForm((current) => ({ ...current, plant_date: event.target.value }))}
                required
              />
            </div>

            <div className="field">
              <label htmlFor="plant-growing-time">Growing time (days)</label>
              <input
                id="plant-growing-time"
                type="number"
                min="0"
                value={plantForm.growing_time_days}
                onChange={(event) => setPlantForm((current) => ({ ...current, growing_time_days: event.target.value }))}
              />
            </div>

            <div className="field">
              <label htmlFor="plant-size">Plant size</label>
              <input
                id="plant-size"
                type="number"
                min="0"
                step="0.1"
                value={plantForm.plant_size}
                onChange={(event) => setPlantForm((current) => ({ ...current, plant_size: event.target.value }))}
              />
            </div>

            <div className="field">
              <label htmlFor="plant-temp">Recommended temperature</label>
              <input
                id="plant-temp"
                type="number"
                step="0.1"
                value={plantForm.recommended_temperature}
                onChange={(event) => setPlantForm((current) => ({ ...current, recommended_temperature: event.target.value }))}
              />
            </div>

            <div className="field">
              <label htmlFor="plant-humidity">Recommended humidity</label>
              <input
                id="plant-humidity"
                type="number"
                step="0.1"
                value={plantForm.recommended_humidity}
                onChange={(event) => setPlantForm((current) => ({ ...current, recommended_humidity: event.target.value }))}
              />
            </div>

            <div className="field">
              <label htmlFor="plant-rest">Rest time (days)</label>
              <input
                id="plant-rest"
                type="number"
                min="0"
                value={plantForm.rest_time_days}
                onChange={(event) => setPlantForm((current) => ({ ...current, rest_time_days: event.target.value }))}
              />
            </div>

            <div className="field">
              <label htmlFor="plant-photo">Photo URL</label>
              <input
                id="plant-photo"
                value={plantForm.photo_url}
                onChange={(event) => setPlantForm((current) => ({ ...current, photo_url: event.target.value }))}
              />
            </div>

            <div className="field">
              <label htmlFor="plant-disease">Disease present</label>
              <select
                id="plant-disease"
                value={plantForm.disease ? 'true' : 'false'}
                onChange={(event) => setPlantForm((current) => ({ ...current, disease: event.target.value === 'true' }))}
              >
                <option value="false">No</option>
                <option value="true">Yes</option>
              </select>
            </div>

            <div className="field field-span-2">
              <label htmlFor="plant-disease-notes">Disease notes</label>
              <textarea
                id="plant-disease-notes"
                value={plantForm.disease_notes}
                onChange={(event) => setPlantForm((current) => ({ ...current, disease_notes: event.target.value }))}
              />
            </div>
          </div>
        </section>

        {selectedCatalogPlant ? (
          <section className="panel page-stack">
            <div>
              <h3 className="section-title">Shared Care Preview</h3>
              <p className="section-copy">This planted instance will reuse the shared care owned by the selected catalog plant.</p>
            </div>

            <div className="meta-cluster">
              <span>Catalog {selectedCatalogPlant.name}</span>
              <span>Canonical {selectedCatalogPlant.canonical_name}</span>
              <span>Usage {selectedCatalogPlant.usage_count ?? 0}</span>
            </div>

            {selectedCatalogPlant.metadata?.classification ? (
              <div className="inline-note">
                {classificationSummary(selectedCatalogPlant.metadata)}
              </div>
            ) : null}

            {selectedCare ? (
              <div className="form-grid plants-detail-grid">
                <div className="card">
                  <strong>Watering interval</strong>
                  <span className="muted">{selectedCare.watering_interval_days ?? 'Not set'} days</span>
                </div>
                <div className="card">
                  <strong>Fertilizing interval</strong>
                  <span className="muted">{selectedCare.fertilizing_interval_days ?? 'Not set'} days</span>
                </div>
                <div className="card">
                  <strong>Pest checks</strong>
                  <span className="muted">{selectedCare.pest_check_interval_days ?? 'Not set'} days</span>
                </div>
                <div className="card">
                  <strong>Conditions</strong>
                  <span className="muted">{selectedCare.conditions || 'Not set'}</span>
                </div>
              </div>
            ) : (
              <div className="inline-note">
                This catalog plant does not have a shared care profile linked yet.
              </div>
            )}

            <div className="row-actions">
              <Link to={`/plants/catalog/${selectedCatalogPlant.id}`}>
                <Button variant="ghost">Open Catalog Plant</Button>
              </Link>
              <Link to={`/plants/catalog/${selectedCatalogPlant.id}/edit`}>
                <Button variant="secondary">Edit Shared Care</Button>
              </Link>
            </div>
          </section>
        ) : null}

        {submitError ? <span className="field-error">{submitError}</span> : null}

        <div className="form-actions">
          <Button type="submit" disabled={submitting || zones.length === 0}>
            {submitting ? 'Saving...' : (isEdit ? 'Save Plant' : 'Create Plant')}
          </Button>
          <Link to={isEdit ? `/plants/${plantId}` : '/plants'}>
            <Button variant="secondary">Back</Button>
          </Link>
        </div>
      </form>
    </div>
  )
}
