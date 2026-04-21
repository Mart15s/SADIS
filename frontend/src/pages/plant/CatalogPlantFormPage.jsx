import { useEffect, useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import PageHeader from '../../components/layout/PageHeader.jsx'
import { EmptyState, ErrorState, LoadingState } from '../../components/shared/StatusView.jsx'
import Button from '../../components/ui/Button.jsx'
import { api } from '../../lib/api.js'
import { PLANT_TYPES } from '../../lib/constants.js'
import { useAsyncData } from '../../lib/hooks/useAsyncData.js'

const PERENUAL_RESULT_STEP = 3
const PERENUAL_RESULT_MAX = 9

function createEmptyCatalogForm() {
  return {
    name: '',
    canonical_name: '',
    plant_type: '',
    description: '',
    source_provider: 'local',
    source_quality: 'partial',
    source_scientific_name: '',
    source_family: '',
    source_image_url: '',
    metadata: null,
  }
}

function createEmptyCareForm() {
  return {
    description: '',
    conditions: '',
    watering_interval_days: '',
    fertilizing_interval_days: '',
    pest_check_interval_days: '',
    rain_skip_threshold_mm: '',
    frost_temp_threshold_c: '',
    heat_extra_water_temp_c: '',
    wind_protection_kmh: '',
    reusable: false,
    growing_duration_days: '',
    germinating_duration_days: '',
    flowering_duration_days: '',
    mature_duration_days: '',
    mature_duration_end_days: '',
    mature_end_duration_days: '',
    regenerating_duration_days: '',
  }
}

function catalogPlantToForm(catalogPlant) {
  return {
    name: catalogPlant.name ?? '',
    canonical_name: catalogPlant.canonical_name ?? '',
    plant_type: catalogPlant.plant_type ?? '',
    description: catalogPlant.description ?? '',
    source_provider: catalogPlant.source_provider ?? 'local',
    source_quality: catalogPlant.source_quality ?? 'partial',
    source_scientific_name: catalogPlant.source_scientific_name ?? '',
    source_family: catalogPlant.source_family ?? '',
    source_image_url: catalogPlant.source_image_url ?? '',
    metadata: catalogPlant.metadata ?? null,
  }
}

function careToForm(care) {
  if (!care) {
    return createEmptyCareForm()
  }

  return {
    description: care.description ?? '',
    conditions: care.conditions ?? '',
    watering_interval_days: care.watering_interval_days ?? '',
    fertilizing_interval_days: care.fertilizing_interval_days ?? '',
    pest_check_interval_days: care.pest_check_interval_days ?? '',
    rain_skip_threshold_mm: care.rain_skip_threshold_mm ?? '',
    frost_temp_threshold_c: care.frost_temp_threshold_c ?? '',
    heat_extra_water_temp_c: care.heat_extra_water_temp_c ?? '',
    wind_protection_kmh: care.wind_protection_kmh ?? '',
    reusable: Boolean(care.reusable),
    growing_duration_days: care.growing_duration_days ?? '',
    germinating_duration_days: care.germinating_duration_days ?? '',
    flowering_duration_days: care.flowering_duration_days ?? '',
    mature_duration_days: care.mature_duration_days ?? '',
    mature_duration_end_days: care.mature_duration_end_days ?? '',
    mature_end_duration_days: care.mature_end_duration_days ?? '',
    regenerating_duration_days: care.regenerating_duration_days ?? '',
  }
}

function toNullableNumber(value) {
  return value === '' || value === null || value === undefined ? null : Number(value)
}

function canonicalizeName(value) {
  return String(value ?? '')
    .trim()
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, ' ')
    .trim()
    .replace(/\s+/g, ' ')
}

function buildFallbackDraftFromSearchResult(result) {
  const conditions = Array.isArray(result.sunlight) && result.sunlight.length > 0
    ? result.sunlight.join(', ')
    : ''
  const description = result.watering
    ? `Imported from a Perenual search result. Reported watering: ${result.watering}.`
    : 'Imported from a Perenual search result. Complete the shared care fields manually if more detail is needed.'

  return {
    species_id: result.id,
    catalog: {
      name: result.name ?? '',
      canonical_name: canonicalizeName(result.name ?? ''),
      plant_type: '',
      description: '',
      source_provider: 'perenual',
      source_quality: 'partial',
      source_scientific_name: result.scientific_name ?? '',
      source_family: '',
      source_image_url: result.image ?? '',
      metadata: null,
    },
    plant_care: {
      ...createEmptyCareForm(),
      description,
      conditions,
    },
  }
}

function classificationNote(metadata) {
  const classification = metadata?.classification

  if (!classification) {
    return ''
  }

  const label = classification.profile_label ?? classification.profile_group ?? 'Detected profile'
  const officialType = classification.official_plant_type

  if (officialType && classification.profile_group && officialType !== classification.profile_group) {
    return `${label} detected. Official plant type is mapped to "${officialType}" to stay aligned with the project specification.`
  }

  return `${label} detected from the imported plant signals.`
}

export default function CatalogPlantFormPage() {
  const navigate = useNavigate()
  const { catalogPlantId } = useParams()
  const isEdit = Boolean(catalogPlantId)
  const [entryMethod, setEntryMethod] = useState('manual')
  const [catalogForm, setCatalogForm] = useState(createEmptyCatalogForm())
  const [careForm, setCareForm] = useState(createEmptyCareForm())
  const [submitError, setSubmitError] = useState('')
  const [validationErrors, setValidationErrors] = useState({})
  const [submitting, setSubmitting] = useState(false)
  const [perenualQuery, setPerenualQuery] = useState('')
  const [perenualResults, setPerenualResults] = useState([])
  const [perenualSearchError, setPerenualSearchError] = useState('')
  const [perenualSearchLoading, setPerenualSearchLoading] = useState(false)
  const [perenualSearchAttempted, setPerenualSearchAttempted] = useState(false)
  const [perenualRequestedLimit, setPerenualRequestedLimit] = useState(PERENUAL_RESULT_STEP)
  const [perenualHasMore, setPerenualHasMore] = useState(false)
  const [selectedSpeciesId, setSelectedSpeciesId] = useState(null)
  const [selectedResultId, setSelectedResultId] = useState(null)
  const [prefillLoadingId, setPrefillLoadingId] = useState(null)
  const [methodError, setMethodError] = useState('')
  const [fallbackNotice, setFallbackNotice] = useState('')

  const pageState = useAsyncData(
    async () => {
      if (!isEdit) {
        return null
      }

      return api.getCatalogPlant(catalogPlantId)
    },
    [catalogPlantId, isEdit],
    null,
  )

  useEffect(() => {
    if (!pageState.data) {
      return
    }

    setCatalogForm(catalogPlantToForm(pageState.data))
    setCareForm(careToForm(pageState.data.plant_care ?? pageState.data.plantCare))
  }, [pageState.data])

  function fieldError(key) {
    return validationErrors[key]?.[0] ?? ''
  }

  function handleMethodChange(nextMethod) {
    setEntryMethod(nextMethod)
    setMethodError('')
    setFallbackNotice('')
  }

  async function runPerenualSearch(limit, options = {}) {
    const { preserveSelection = false } = options
    const query = perenualQuery.trim()
    setPerenualSearchError('')
    setMethodError('')
    setFallbackNotice('')

    if (query.length < 2) {
      setPerenualResults([])
      setPerenualHasMore(false)
      setPerenualRequestedLimit(PERENUAL_RESULT_STEP)
      setPerenualSearchError('Enter at least 2 characters before searching Perenual.')
      return
    }

    if (!preserveSelection) {
      setSelectedSpeciesId(null)
      setSelectedResultId(null)
    }

    setPerenualSearchLoading(true)

    try {
      const response = await api.searchPerenualPlants(query, { limit })
      const results = Array.isArray(response?.data) ? response.data.slice(0, PERENUAL_RESULT_MAX) : []
      const nextLimit = Number(response?.meta?.next_limit ?? 0)

      setPerenualResults(results)
      setPerenualRequestedLimit(Number(response?.meta?.limit ?? limit))
      setPerenualHasMore(Boolean(response?.meta?.has_more) && nextLimit > 0)
    } catch (requestError) {
      setPerenualResults([])
      setPerenualHasMore(false)
      setPerenualSearchError(requestError.message)
    } finally {
      setPerenualSearchLoading(false)
    }
  }

  async function handlePerenualSearchSubmit(event) {
    event.preventDefault()
    setPerenualSearchAttempted(true)

    await runPerenualSearch(PERENUAL_RESULT_STEP)
  }

  async function handleShowMoreResults() {
    const nextLimit = Math.min(perenualRequestedLimit + PERENUAL_RESULT_STEP, PERENUAL_RESULT_MAX)

    if (nextLimit <= perenualRequestedLimit) {
      setPerenualHasMore(false)
      return
    }

    await runPerenualSearch(nextLimit, { preserveSelection: true })
  }

  async function handlePerenualSelect(result) {
    setSelectedResultId(result.id)
    setPrefillLoadingId(result.id)
    setMethodError('')
    setSubmitError('')
    setValidationErrors({})
    setFallbackNotice('')

    try {
      const draft = await api.previewPerenualCatalogPlant(result.id)
      setSelectedSpeciesId(draft.species_id ?? result.id)
      setCatalogForm((current) => ({
        ...current,
        ...draft.catalog,
      }))
      setCareForm(careToForm(draft.plant_care))
      setPerenualQuery(result.name)
    } catch (requestError) {
      if (requestError.status === 429) {
        const fallbackDraft = buildFallbackDraftFromSearchResult(result)
        setSelectedSpeciesId(fallbackDraft.species_id ?? result.id)
        setCatalogForm((current) => ({
          ...current,
          ...fallbackDraft.catalog,
        }))
        setCareForm(careToForm(fallbackDraft.plant_care))
        setPerenualQuery(result.name)
        setFallbackNotice('Perenual details are temporarily rate-limited (429). The form was filled with the search-result data that is still available. Complete any missing care fields manually and save when ready.')
      } else {
        setMethodError(requestError.message)
      }
    } finally {
      setPrefillLoadingId(null)
    }
  }

  async function handleSubmit(event) {
    event.preventDefault()

    if (!isEdit && entryMethod === 'perenual' && !selectedSpeciesId) {
      setMethodError('Search Perenual and select a result before saving, or switch to Manual Entry.')
      return
    }

    setSubmitting(true)
    setSubmitError('')
    setValidationErrors({})
    setMethodError('')
    setFallbackNotice('')

    const payload = {
      name: catalogForm.name.trim(),
      canonical_name: catalogForm.canonical_name.trim() || null,
      plant_type: catalogForm.plant_type,
      description: catalogForm.description || null,
      source_provider: catalogForm.source_provider || null,
      source_quality: catalogForm.source_quality || null,
      source_scientific_name: catalogForm.source_scientific_name || null,
      source_family: catalogForm.source_family || null,
      source_image_url: catalogForm.source_image_url || null,
      metadata: catalogForm.metadata ?? null,
      perenual_species_id: entryMethod === 'perenual' && selectedSpeciesId ? Number(selectedSpeciesId) : null,
      plant_care: {
        description: careForm.description || null,
        conditions: careForm.conditions || null,
        watering_interval_days: toNullableNumber(careForm.watering_interval_days),
        fertilizing_interval_days: toNullableNumber(careForm.fertilizing_interval_days),
        pest_check_interval_days: toNullableNumber(careForm.pest_check_interval_days),
        rain_skip_threshold_mm: toNullableNumber(careForm.rain_skip_threshold_mm),
        frost_temp_threshold_c: toNullableNumber(careForm.frost_temp_threshold_c),
        heat_extra_water_temp_c: toNullableNumber(careForm.heat_extra_water_temp_c),
        wind_protection_kmh: toNullableNumber(careForm.wind_protection_kmh),
        reusable: Boolean(careForm.reusable),
        growing_duration_days: toNullableNumber(careForm.growing_duration_days),
        germinating_duration_days: toNullableNumber(careForm.germinating_duration_days),
        flowering_duration_days: toNullableNumber(careForm.flowering_duration_days),
        mature_duration_days: toNullableNumber(careForm.mature_duration_days),
        mature_duration_end_days: toNullableNumber(careForm.mature_duration_end_days),
        mature_end_duration_days: toNullableNumber(careForm.mature_end_duration_days),
        regenerating_duration_days: toNullableNumber(careForm.regenerating_duration_days),
      },
    }

    try {
      const savedCatalogPlant = isEdit
        ? await api.updateCatalogPlant(catalogPlantId, payload)
        : await api.createCatalogPlant(payload)

      navigate(`/plants/catalog/${savedCatalogPlant.id}`, {
        state: {
          notice: isEdit ? 'Catalog plant updated successfully.' : 'Catalog plant created successfully.',
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
    return <LoadingState title={isEdit ? 'Loading catalog plant editor...' : 'Loading catalog plant form...'} />
  }

  if (pageState.error) {
    return <ErrorState error={pageState.error} onRetry={pageState.reload} />
  }

  if (isEdit && !pageState.data) {
    return <EmptyState title="Catalog plant not found" description="The requested catalog plant could not be loaded." />
  }

  return (
    <div className="page-stack">
      <PageHeader
        title={isEdit ? 'Edit Catalog Plant' : 'Create Catalog Plant'}
        description={isEdit
          ? 'Update the reusable plant identity and its shared care profile together.'
          : 'Create a reusable catalog plant manually or import it from Perenual before placing it into plots and zones.'}
        actions={(
          <Link to={isEdit ? `/plants/catalog/${catalogPlantId}` : '/plants?view=catalog'}>
            <Button variant="secondary">Cancel</Button>
          </Link>
        )}
      />

      {!isEdit ? (
        <section className="panel page-stack">
          <div>
            <h3 className="section-title">Add Method</h3>
            <p className="section-copy">Choose how you want to start the catalog entry. You can still edit every field before saving.</p>
          </div>

          <div className="plants-view-switch" role="tablist" aria-label="Catalog plant add method">
            <button
              type="button"
              role="tab"
              aria-selected={entryMethod === 'manual'}
              className={`plants-view-switch-button ${entryMethod === 'manual' ? 'is-active' : ''}`.trim()}
              onClick={() => handleMethodChange('manual')}
            >
              Manual Entry
            </button>
            <button
              type="button"
              role="tab"
              aria-selected={entryMethod === 'perenual'}
              className={`plants-view-switch-button ${entryMethod === 'perenual' ? 'is-active' : ''}`.trim()}
              onClick={() => handleMethodChange('perenual')}
            >
              Perenual API
            </button>
          </div>

          {entryMethod === 'manual' ? (
            <div className="inline-note">
              Manual Entry keeps the flow completely local. Fill in the catalog identity and all shared plant care fields yourself.
            </div>
          ) : (
            <div className="page-stack">
              <form className="search-row plants-import-form" onSubmit={handlePerenualSearchSubmit}>
                <div className="field plants-search-field">
                  <label htmlFor="perenual-search">Search Perenual</label>
                  <input
                    id="perenual-search"
                    value={perenualQuery}
                    onChange={(event) => setPerenualQuery(event.target.value)}
                    placeholder="Enter a plant name, then press Enter or click Search"
                  />
                </div>
                <div className="plants-import-actions">
                  <Button type="submit" disabled={perenualSearchLoading}>
                    {perenualSearchLoading ? 'Searching...' : 'Search'}
                  </Button>
                </div>
              </form>

              <div className="inline-note">
                Requests are only sent after you submit the search. Typing alone does not call the Perenual API.
              </div>

              {perenualSearchError ? <span className="field-error">{perenualSearchError}</span> : null}
              {methodError ? <span className="field-error">{methodError}</span> : null}
              {fallbackNotice ? <div className="inline-note">{fallbackNotice}</div> : null}

              {perenualResults.length > 0 ? (
                <div className="page-stack">
                  <div className="card-grid">
                    {perenualResults.map((result) => {
                      const isSelected = selectedResultId === result.id
                      const isLoading = prefillLoadingId === result.id

                      return (
                        <button
                          key={result.id}
                          type="button"
                          className={`card plants-catalog-card ${isSelected ? 'is-selected' : ''}`.trim()}
                          onClick={() => handlePerenualSelect(result)}
                          disabled={prefillLoadingId !== null}
                        >
                          <div className="list-head">
                            <strong>{result.name}</strong>
                            <span className="badge badge-soft">Species #{result.id}</span>
                          </div>
                          {result.scientific_name ? <div className="muted">{result.scientific_name}</div> : null}
                          {result.image ? (
                            <img
                              src={result.image}
                              alt={result.name}
                              className="catalog-result-image"
                            />
                          ) : null}
                          <div className="meta-cluster">
                            <span>{result.cycle ?? 'Cycle not specified'}</span>
                            <span>{result.watering ?? 'Watering not specified'}</span>
                            <span>{result.sunlight?.join(', ') || 'Sunlight not specified'}</span>
                          </div>
                          <div className="catalog-plant-actions">
                            <Button variant={isSelected ? 'primary' : 'secondary'}>
                              {isLoading ? 'Loading...' : (isSelected ? 'Selected' : 'Use This Result')}
                            </Button>
                          </div>
                        </button>
                      )
                    })}
                  </div>

                  {perenualHasMore ? (
                    <div className="plants-import-more">
                      <Button
                        type="button"
                        variant="secondary"
                        onClick={handleShowMoreResults}
                        disabled={perenualSearchLoading || prefillLoadingId !== null}
                      >
                        {perenualSearchLoading ? 'Loading more...' : `Show ${PERENUAL_RESULT_STEP} more`}
                      </Button>
                      <span className="field-hint">
                        Sends one extra Perenual request only when you explicitly ask for more results.
                      </span>
                    </div>
                  ) : null}
                </div>
              ) : null}

              {perenualSearchAttempted && !perenualSearchLoading && !perenualSearchError && perenualResults.length === 0 ? (
                <EmptyState
                  title="No Perenual matches found"
                  description="Try a broader plant name, or switch to Manual Entry and create the catalog plant yourself."
                />
              ) : null}

              {selectedSpeciesId ? (
                <div className="inline-note">
                  Perenual species #{selectedSpeciesId} selected. The form below is now editable and will keep this import link when you save.
                </div>
              ) : null}
            </div>
          )}
        </section>
      ) : null}

      <form className="page-stack" onSubmit={handleSubmit}>
        <section className="panel page-stack">
          <div>
            <h3 className="section-title">Catalog Identity</h3>
            <p className="section-copy">Reusable identity fields that define the shared catalog entry.</p>
          </div>

          {!isEdit && entryMethod === 'perenual' && selectedSpeciesId && !catalogForm.plant_type ? (
            <div className="inline-note">
              Perenual did not provide enough reliable evidence to classify this plant type confidently. Choose the plant type manually before saving.
            </div>
          ) : null}

          {catalogForm.metadata?.classification ? (
            <div className="inline-note">
              {classificationNote(catalogForm.metadata)}
            </div>
          ) : null}

          <div className="form-grid plants-form-grid">
            <div className="field">
              <label htmlFor="catalog-plant-name">Name</label>
              <input
                id="catalog-plant-name"
                value={catalogForm.name}
                onChange={(event) => setCatalogForm((current) => ({ ...current, name: event.target.value }))}
                required
              />
              {fieldError('name') ? <span className="field-error">{fieldError('name')}</span> : null}
            </div>

            <div className="field">
              <label htmlFor="catalog-plant-canonical">Canonical name</label>
              <input
                id="catalog-plant-canonical"
                value={catalogForm.canonical_name}
                onChange={(event) => setCatalogForm((current) => ({ ...current, canonical_name: event.target.value }))}
                placeholder="Auto-generated when left blank"
              />
              {fieldError('canonical_name') ? <span className="field-error">{fieldError('canonical_name')}</span> : null}
            </div>

            <div className="field">
              <label htmlFor="catalog-plant-type">Plant type</label>
              <select
                id="catalog-plant-type"
                value={catalogForm.plant_type}
                onChange={(event) => setCatalogForm((current) => ({ ...current, plant_type: event.target.value }))}
                required
              >
                <option value="" disabled>Select type</option>
                {PLANT_TYPES.map((type) => (
                  <option key={type} value={type}>
                    {type}
                  </option>
                ))}
              </select>
              {fieldError('plant_type') ? <span className="field-error">{fieldError('plant_type')}</span> : null}
            </div>

            <div className="field">
              <label htmlFor="catalog-plant-provider">Source provider</label>
              <input
                id="catalog-plant-provider"
                value={catalogForm.source_provider}
                onChange={(event) => setCatalogForm((current) => ({ ...current, source_provider: event.target.value }))}
              />
            </div>

            <div className="field">
              <label htmlFor="catalog-plant-quality">Source quality</label>
              <input
                id="catalog-plant-quality"
                value={catalogForm.source_quality}
                onChange={(event) => setCatalogForm((current) => ({ ...current, source_quality: event.target.value }))}
              />
            </div>

            <div className="field">
              <label htmlFor="catalog-plant-scientific">Scientific name</label>
              <input
                id="catalog-plant-scientific"
                value={catalogForm.source_scientific_name}
                onChange={(event) => setCatalogForm((current) => ({ ...current, source_scientific_name: event.target.value }))}
              />
            </div>

            <div className="field">
              <label htmlFor="catalog-plant-family">Family</label>
              <input
                id="catalog-plant-family"
                value={catalogForm.source_family}
                onChange={(event) => setCatalogForm((current) => ({ ...current, source_family: event.target.value }))}
              />
            </div>

            <div className="field">
              <label htmlFor="catalog-plant-image">Image URL</label>
              <input
                id="catalog-plant-image"
                value={catalogForm.source_image_url}
                onChange={(event) => setCatalogForm((current) => ({ ...current, source_image_url: event.target.value }))}
              />
            </div>

            <div className="field field-span-2">
              <label htmlFor="catalog-plant-description">Catalog description</label>
              <textarea
                id="catalog-plant-description"
                value={catalogForm.description}
                onChange={(event) => setCatalogForm((current) => ({ ...current, description: event.target.value }))}
              />
            </div>
          </div>
        </section>

        <section className="panel page-stack">
          <div>
            <h3 className="section-title">Shared Plant Care</h3>
            <p className="section-copy">This care profile is reused by catalog-linked planted instances.</p>
          </div>

          <div className="form-grid plants-form-grid">
            <div className="field field-span-2">
              <label htmlFor="catalog-care-description">Care description</label>
              <textarea
                id="catalog-care-description"
                value={careForm.description}
                onChange={(event) => setCareForm((current) => ({ ...current, description: event.target.value }))}
              />
            </div>

            <div className="field field-span-2">
              <label htmlFor="catalog-care-conditions">Conditions</label>
              <textarea
                id="catalog-care-conditions"
                value={careForm.conditions}
                onChange={(event) => setCareForm((current) => ({ ...current, conditions: event.target.value }))}
              />
            </div>

            <div className="field">
              <label htmlFor="catalog-care-water">Watering interval (days)</label>
              <input
                id="catalog-care-water"
                type="number"
                min="0"
                value={careForm.watering_interval_days}
                onChange={(event) => setCareForm((current) => ({ ...current, watering_interval_days: event.target.value }))}
              />
            </div>

            <div className="field">
              <label htmlFor="catalog-care-fertilize">Fertilizing interval (days)</label>
              <input
                id="catalog-care-fertilize"
                type="number"
                min="0"
                value={careForm.fertilizing_interval_days}
                onChange={(event) => setCareForm((current) => ({ ...current, fertilizing_interval_days: event.target.value }))}
              />
            </div>

            <div className="field">
              <label htmlFor="catalog-care-pest">Pest check interval (days)</label>
              <input
                id="catalog-care-pest"
                type="number"
                min="0"
                value={careForm.pest_check_interval_days}
                onChange={(event) => setCareForm((current) => ({ ...current, pest_check_interval_days: event.target.value }))}
              />
            </div>

            <div className="field">
              <label htmlFor="catalog-care-rain">Rain skip threshold (mm)</label>
              <input
                id="catalog-care-rain"
                type="number"
                step="0.1"
                value={careForm.rain_skip_threshold_mm}
                onChange={(event) => setCareForm((current) => ({ ...current, rain_skip_threshold_mm: event.target.value }))}
              />
            </div>

            <div className="field">
              <label htmlFor="catalog-care-frost">Frost threshold (C)</label>
              <input
                id="catalog-care-frost"
                type="number"
                step="0.1"
                value={careForm.frost_temp_threshold_c}
                onChange={(event) => setCareForm((current) => ({ ...current, frost_temp_threshold_c: event.target.value }))}
              />
            </div>

            <div className="field">
              <label htmlFor="catalog-care-heat">Heat extra-water threshold (C)</label>
              <input
                id="catalog-care-heat"
                type="number"
                step="0.1"
                value={careForm.heat_extra_water_temp_c}
                onChange={(event) => setCareForm((current) => ({ ...current, heat_extra_water_temp_c: event.target.value }))}
              />
            </div>

            <div className="field">
              <label htmlFor="catalog-care-wind">Wind protection (km/h)</label>
              <input
                id="catalog-care-wind"
                type="number"
                step="0.1"
                value={careForm.wind_protection_kmh}
                onChange={(event) => setCareForm((current) => ({ ...current, wind_protection_kmh: event.target.value }))}
              />
            </div>

            <div className="field">
              <label htmlFor="catalog-care-reusable">Reusable profile</label>
              <select
                id="catalog-care-reusable"
                value={careForm.reusable ? 'true' : 'false'}
                onChange={(event) => setCareForm((current) => ({ ...current, reusable: event.target.value === 'true' }))}
              >
                <option value="false">No</option>
                <option value="true">Yes</option>
              </select>
            </div>

            <div className="field">
              <label htmlFor="catalog-care-growing">Growing duration (days)</label>
              <input
                id="catalog-care-growing"
                type="number"
                min="0"
                value={careForm.growing_duration_days}
                onChange={(event) => setCareForm((current) => ({ ...current, growing_duration_days: event.target.value }))}
              />
            </div>

            <div className="field">
              <label htmlFor="catalog-care-germinating">Germinating duration (days)</label>
              <input
                id="catalog-care-germinating"
                type="number"
                min="0"
                value={careForm.germinating_duration_days}
                onChange={(event) => setCareForm((current) => ({ ...current, germinating_duration_days: event.target.value }))}
              />
            </div>

            <div className="field">
              <label htmlFor="catalog-care-flowering">Flowering duration (days)</label>
              <input
                id="catalog-care-flowering"
                type="number"
                min="0"
                value={careForm.flowering_duration_days}
                onChange={(event) => setCareForm((current) => ({ ...current, flowering_duration_days: event.target.value }))}
              />
            </div>

            <div className="field">
              <label htmlFor="catalog-care-mature">Mature duration (days)</label>
              <input
                id="catalog-care-mature"
                type="number"
                min="0"
                value={careForm.mature_duration_days}
                onChange={(event) => setCareForm((current) => ({ ...current, mature_duration_days: event.target.value }))}
              />
            </div>

            <div className="field">
              <label htmlFor="catalog-care-end">Productive end window (days)</label>
              <input
                id="catalog-care-end"
                type="number"
                min="0"
                value={careForm.mature_duration_end_days}
                onChange={(event) => setCareForm((current) => ({ ...current, mature_duration_end_days: event.target.value }))}
              />
            </div>

            <div className="field">
              <label htmlFor="catalog-care-end-alt">Mature end duration (days)</label>
              <input
                id="catalog-care-end-alt"
                type="number"
                min="0"
                value={careForm.mature_end_duration_days}
                onChange={(event) => setCareForm((current) => ({ ...current, mature_end_duration_days: event.target.value }))}
              />
            </div>

            <div className="field">
              <label htmlFor="catalog-care-regenerating">Regenerating duration (days)</label>
              <input
                id="catalog-care-regenerating"
                type="number"
                min="0"
                value={careForm.regenerating_duration_days}
                onChange={(event) => setCareForm((current) => ({ ...current, regenerating_duration_days: event.target.value }))}
              />
            </div>
          </div>
        </section>

        {submitError ? <span className="field-error">{submitError}</span> : null}

        <div className="form-actions">
          <Button type="submit" disabled={submitting}>
            {submitting ? 'Saving...' : (isEdit ? 'Save Catalog Plant' : 'Create Catalog Plant')}
          </Button>
          <Link to={isEdit ? `/plants/catalog/${catalogPlantId}` : '/plants?view=catalog'}>
            <Button variant="secondary">Back</Button>
          </Link>
        </div>
      </form>
    </div>
  )
}
