import { useEffect, useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import PageHeader from '../../components/layout/PageHeader.jsx'
import { EmptyState, ErrorState, LoadingState } from '../../components/shared/StatusView.jsx'
import Button from '../../components/ui/Button.jsx'
import { api } from '../../lib/api.js'
import { PLANT_TYPES, formatPlantType } from '../../lib/constants.js'
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
    ? `Importuota iš Perenual paieškos rezultato. Nurodytas laistymas: ${result.watering}.`
    : 'Importuota iš Perenual paieškos rezultato. Jei reikia daugiau tikslumo, bendrus priežiūros laukus užpildykite rankiniu būdu.'

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

  const label = classification.profile_label ?? classification.profile_group ?? 'Aptiktas profilis'
  const officialType = classification.official_plant_type

  if (officialType && classification.profile_group && officialType !== classification.profile_group) {
    return `${label} aptiktas. Oficialus augalo tipas susietas su "${officialType}", kad atitiktų projekto specifikaciją.`
  }

  return `${label} aptiktas pagal importuoto augalo požymius.`
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
      setPerenualSearchError('Prieš ieškodami Perenual įveskite bent 2 simbolius.')
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

  async function handleRodytiMoreResults() {
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
        setFallbackNotice('Perenual detalės laikinai ribojamos (429). Forma užpildyta vis dar pasiekiamais paieškos rezultato duomenimis. Trūkstamus priežiūros laukus užpildykite rankiniu būdu ir išsaugokite, kai duomenys paruošti.')
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
      setMethodError('Prieš išsaugodami atlikite Perenual paiešką ir pasirinkite rezultatą arba pereikite prie rankinio įrašo.')
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
          notice: isEdit ? 'Katalogo augalas sėkmingai atnaujintas.' : 'Katalogo augalas sėkmingai sukurtas.',
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
    return <LoadingState title={isEdit ? 'Įkeliamas katalogo augalo redaktorius...' : 'Įkeliama katalogo augalo forma...'} />
  }

  if (pageState.error) {
    return <ErrorState error={pageState.error} onRetry={pageState.reload} />
  }

  if (isEdit && !pageState.data) {
    return <EmptyState title="Katalogo augalas nerastas" description="Pasirinkto katalogo augalo nepavyko įkelti." />
  }

  return (
    <div className="page-stack">
      <PageHeader
        title={isEdit ? 'Redaguoti katalogo augalą' : 'Sukurti katalogo augalą'}
        description={isEdit
          ? 'Atnaujinkite pakartotinai naudojamą augalo tapatybę ir bendrą priežiūros profilį.'
          : 'Sukurkite pakartotinai naudojamą katalogo augalą rankiniu būdu arba importuokite iš Perenual prieš sodindami sklypuose ir zonose.'}
        actions={(
          <Link to={isEdit ? `/plants/catalog/${catalogPlantId}` : '/plants?view=catalog'}>
            <Button variant="secondary">Atšaukti</Button>
          </Link>
        )}
      />

      {!isEdit ? (
        <section className="panel page-stack">
          <div>
            <h3 className="section-title">Pridėjimo būdas</h3>
            <p className="section-copy">Pasirinkite, kaip pradėti katalogo įrašą. Prieš išsaugodami visus laukus dar galėsite redaguoti.</p>
          </div>

          <div className="plants-view-switch" role="tablist" aria-label="Katalogo augalo pridėjimo būdas">
            <button
              type="button"
              role="tab"
              aria-selected={entryMethod === 'manual'}
              className={`plants-view-switch-button ${entryMethod === 'manual' ? 'is-active' : ''}`.trim()}
              onClick={() => handleMethodChange('manual')}
            >
              Rankinis įrašas
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
              Rankinis įrašas visą eigą palieka sistemoje. Patys užpildykite katalogo tapatybę ir bendrinamus augalo priežiūros laukus.
            </div>
          ) : (
            <div className="page-stack">
              <form className="search-row plants-import-form" onSubmit={handlePerenualSearchSubmit}>
                <div className="field plants-search-field">
                  <label htmlFor="perenual-search">Ieškoti Perenual</label>
                  <input
                    id="perenual-search"
                    value={perenualQuery}
                    onChange={(event) => setPerenualQuery(event.target.value)}
                    placeholder="Įveskite augalo pavadinimą, tada spauskite Enter arba Ieškoti"
                  />
                </div>
                <div className="plants-import-actions">
                  <Button type="submit" disabled={perenualSearchLoading}>
                    {perenualSearchLoading ? 'Ieškoma...' : 'Ieškoti'}
                  </Button>
                </div>
              </form>

              <div className="inline-note">
                Užklausos siunčiamos tik pateikus paiešką. Vien rašymas Perenual API nekviečia.
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
                            <span className="badge badge-soft">Rūšis #{result.id}</span>
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
                            <span>{result.cycle ?? 'Ciklas nenurodytas'}</span>
                            <span>{result.watering ?? 'Laistymas nenurodytas'}</span>
                            <span>{result.sunlight?.join(', ') || 'Šviesos poreikis nenurodytas'}</span>
                          </div>
                          <div className="catalog-plant-actions">
                            <Button variant={isSelected ? 'primary' : 'secondary'}>
                              {isLoading ? 'Įkeliama...' : (isSelected ? 'Pasirinkta' : 'Naudoti šį rezultatą')}
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
                        onClick={handleRodytiMoreResults}
                        disabled={perenualSearchLoading || prefillLoadingId !== null}
                      >
                        {perenualSearchLoading ? 'Įkeliama daugiau...' : `Rodyti dar ${PERENUAL_RESULT_STEP}`}
                      </Button>
                      <span className="field-hint">
                        Viena papildoma Perenual užklausa siunčiama tik tada, kai aiškiai prašote daugiau rezultatų.
                      </span>
                    </div>
                  ) : null}
                </div>
              ) : null}

              {perenualSearchAttempted && !perenualSearchLoading && !perenualSearchError && perenualResults.length === 0 ? (
                <EmptyState
                  title="Perenual atitikmenų nerasta"
                  description="Bandykite platesnį augalo pavadinimą arba pereikite prie rankinio įrašo ir sukurkite katalogo augalą patys."
                />
              ) : null}

              {selectedSpeciesId ? (
                <div className="inline-note">
                  Pasirinkta Perenual rūšis #{selectedSpeciesId}. Toliau esanti forma dabar redaguojama ir išsaugojus išlaikys šią importo sąsają.
                </div>
              ) : null}
            </div>
          )}
        </section>
      ) : null}

      <form className="page-stack" onSubmit={handleSubmit}>
        <section className="panel page-stack">
          <div>
            <h3 className="section-title">Katalogo tapatybė</h3>
            <p className="section-copy">Pakartotinai naudojami tapatybės laukai, apibrėžiantys bendrinamą katalogo įrašą.</p>
          </div>

          {!isEdit && entryMethod === 'perenual' && selectedSpeciesId && !catalogForm.plant_type ? (
            <div className="inline-note">
              Perenual nepateikė pakankamai patikimų duomenų augalo tipui nustatyti. Prieš išsaugodami pasirinkite augalo tipą rankiniu būdu.
            </div>
          ) : null}

          {catalogForm.metadata?.classification ? (
            <div className="inline-note">
              {classificationNote(catalogForm.metadata)}
            </div>
          ) : null}

          <div className="form-grid plants-form-grid">
            <div className="field">
              <label htmlFor="catalog-plant-name">Pavadinimas</label>
              <input
                id="catalog-plant-name"
                value={catalogForm.name}
                onChange={(event) => setCatalogForm((current) => ({ ...current, name: event.target.value }))}
                required
              />
              {fieldError('name') ? <span className="field-error">{fieldError('name')}</span> : null}
            </div>

            <div className="field">
              <label htmlFor="catalog-plant-canonical">Kataloginis pavadinimas</label>
              <input
                id="catalog-plant-canonical"
                value={catalogForm.canonical_name}
                onChange={(event) => setCatalogForm((current) => ({ ...current, canonical_name: event.target.value }))}
                placeholder="Palikus tuščią sugeneruojama automatiškai"
              />
              {fieldError('canonical_name') ? <span className="field-error">{fieldError('canonical_name')}</span> : null}
            </div>

            <div className="field">
              <label htmlFor="catalog-plant-type">Augalo tipas</label>
              <select
                id="catalog-plant-type"
                value={catalogForm.plant_type}
                onChange={(event) => setCatalogForm((current) => ({ ...current, plant_type: event.target.value }))}
                required
              >
                <option value="" disabled>Pasirinkite tipą</option>
                {PLANT_TYPES.map((type) => (
                  <option key={type} value={type}>
                    {formatPlantType(type)}
                  </option>
                ))}
              </select>
              {fieldError('plant_type') ? <span className="field-error">{fieldError('plant_type')}</span> : null}
            </div>

            <div className="field">
              <label htmlFor="catalog-plant-provider">Duomenų šaltinis</label>
              <input
                id="catalog-plant-provider"
                value={catalogForm.source_provider}
                onChange={(event) => setCatalogForm((current) => ({ ...current, source_provider: event.target.value }))}
              />
            </div>

            <div className="field">
              <label htmlFor="catalog-plant-quality">Šaltinio kokybė</label>
              <input
                id="catalog-plant-quality"
                value={catalogForm.source_quality}
                onChange={(event) => setCatalogForm((current) => ({ ...current, source_quality: event.target.value }))}
              />
            </div>

            <div className="field">
              <label htmlFor="catalog-plant-scientific">Mokslinis pavadinimas</label>
              <input
                id="catalog-plant-scientific"
                value={catalogForm.source_scientific_name}
                onChange={(event) => setCatalogForm((current) => ({ ...current, source_scientific_name: event.target.value }))}
              />
            </div>

            <div className="field">
              <label htmlFor="catalog-plant-family">Šeima</label>
              <input
                id="catalog-plant-family"
                value={catalogForm.source_family}
                onChange={(event) => setCatalogForm((current) => ({ ...current, source_family: event.target.value }))}
              />
            </div>

            <div className="field">
              <label htmlFor="catalog-plant-image">Nuotraukos URL</label>
              <input
                id="catalog-plant-image"
                value={catalogForm.source_image_url}
                onChange={(event) => setCatalogForm((current) => ({ ...current, source_image_url: event.target.value }))}
              />
            </div>

            <div className="field field-span-2">
              <label htmlFor="catalog-plant-description">Katalogo aprašymas</label>
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
            <h3 className="section-title">Bendrinama augalo priežiūra</h3>
            <p className="section-copy">Šis priežiūros profilis pakartotinai naudojamas su katalogu susietiems pasodintiems augalams.</p>
          </div>

          <div className="form-grid plants-form-grid">
            <div className="field field-span-2">
              <label htmlFor="catalog-care-description">Priežiūros aprašymas</label>
              <textarea
                id="catalog-care-description"
                value={careForm.description}
                onChange={(event) => setCareForm((current) => ({ ...current, description: event.target.value }))}
              />
            </div>

            <div className="field field-span-2">
              <label htmlFor="catalog-care-conditions">Sąlygos</label>
              <textarea
                id="catalog-care-conditions"
                value={careForm.conditions}
                onChange={(event) => setCareForm((current) => ({ ...current, conditions: event.target.value }))}
              />
            </div>

            <div className="field">
              <label htmlFor="catalog-care-water">Laistymo intervalas (dienomis)</label>
              <input
                id="catalog-care-water"
                type="number"
                min="0"
                value={careForm.watering_interval_days}
                onChange={(event) => setCareForm((current) => ({ ...current, watering_interval_days: event.target.value }))}
              />
            </div>

            <div className="field">
              <label htmlFor="catalog-care-fertilize">Tręšimo intervalas (dienomis)</label>
              <input
                id="catalog-care-fertilize"
                type="number"
                min="0"
                value={careForm.fertilizing_interval_days}
                onChange={(event) => setCareForm((current) => ({ ...current, fertilizing_interval_days: event.target.value }))}
              />
            </div>

            <div className="field">
              <label htmlFor="catalog-care-pest">Kenkėjų patikros intervalas (dienomis)</label>
              <input
                id="catalog-care-pest"
                type="number"
                min="0"
                value={careForm.pest_check_interval_days}
                onChange={(event) => setCareForm((current) => ({ ...current, pest_check_interval_days: event.target.value }))}
              />
            </div>

            <div className="field">
              <label htmlFor="catalog-care-rain">Lietaus riba laistymui praleisti (mm)</label>
              <input
                id="catalog-care-rain"
                type="number"
                step="0.1"
                value={careForm.rain_skip_threshold_mm}
                onChange={(event) => setCareForm((current) => ({ ...current, rain_skip_threshold_mm: event.target.value }))}
              />
            </div>

            <div className="field">
              <label htmlFor="catalog-care-frost">Šalnos riba (°C)</label>
              <input
                id="catalog-care-frost"
                type="number"
                step="0.1"
                value={careForm.frost_temp_threshold_c}
                onChange={(event) => setCareForm((current) => ({ ...current, frost_temp_threshold_c: event.target.value }))}
              />
            </div>

            <div className="field">
              <label htmlFor="catalog-care-heat">Karščio riba papildomam laistymui (°C)</label>
              <input
                id="catalog-care-heat"
                type="number"
                step="0.1"
                value={careForm.heat_extra_water_temp_c}
                onChange={(event) => setCareForm((current) => ({ ...current, heat_extra_water_temp_c: event.target.value }))}
              />
            </div>

            <div className="field">
              <label htmlFor="catalog-care-wind">Apsauga nuo vėjo (km/h)</label>
              <input
                id="catalog-care-wind"
                type="number"
                step="0.1"
                value={careForm.wind_protection_kmh}
                onChange={(event) => setCareForm((current) => ({ ...current, wind_protection_kmh: event.target.value }))}
              />
            </div>

            <div className="field">
              <label htmlFor="catalog-care-reusable">Pakartotinai naudojamas profilis</label>
              <select
                id="catalog-care-reusable"
                value={careForm.reusable ? 'true' : 'false'}
                onChange={(event) => setCareForm((current) => ({ ...current, reusable: event.target.value === 'true' }))}
              >
                <option value="false">Ne</option>
                <option value="true">Taip</option>
              </select>
            </div>

            <div className="field">
              <label htmlFor="catalog-care-growing">Augimo trukmė (dienomis)</label>
              <input
                id="catalog-care-growing"
                type="number"
                min="0"
                value={careForm.growing_duration_days}
                onChange={(event) => setCareForm((current) => ({ ...current, growing_duration_days: event.target.value }))}
              />
            </div>

            <div className="field">
              <label htmlFor="catalog-care-germinating">Dygimo trukmė (dienomis)</label>
              <input
                id="catalog-care-germinating"
                type="number"
                min="0"
                value={careForm.germinating_duration_days}
                onChange={(event) => setCareForm((current) => ({ ...current, germinating_duration_days: event.target.value }))}
              />
            </div>

            <div className="field">
              <label htmlFor="catalog-care-flowering">Žydėjimo trukmė (dienomis)</label>
              <input
                id="catalog-care-flowering"
                type="number"
                min="0"
                value={careForm.flowering_duration_days}
                onChange={(event) => setCareForm((current) => ({ ...current, flowering_duration_days: event.target.value }))}
              />
            </div>

            <div className="field">
              <label htmlFor="catalog-care-mature">Brandos trukmė (dienomis)</label>
              <input
                id="catalog-care-mature"
                type="number"
                min="0"
                value={careForm.mature_duration_days}
                onChange={(event) => setCareForm((current) => ({ ...current, mature_duration_days: event.target.value }))}
              />
            </div>

            <div className="field">
              <label htmlFor="catalog-care-end">Derėjimo pabaigos laikotarpis (dienomis)</label>
              <input
                id="catalog-care-end"
                type="number"
                min="0"
                value={careForm.mature_duration_end_days}
                onChange={(event) => setCareForm((current) => ({ ...current, mature_duration_end_days: event.target.value }))}
              />
            </div>

            <div className="field">
              <label htmlFor="catalog-care-end-alt">Brandos pabaigos trukmė (dienomis)</label>
              <input
                id="catalog-care-end-alt"
                type="number"
                min="0"
                value={careForm.mature_end_duration_days}
                onChange={(event) => setCareForm((current) => ({ ...current, mature_end_duration_days: event.target.value }))}
              />
            </div>

            <div className="field">
              <label htmlFor="catalog-care-regenerating">Atsinaujinimo trukmė (dienomis)</label>
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
            {submitting ? 'Saugoma...' : (isEdit ? 'Išsaugoti katalogo augalą' : 'Sukurti katalogo augalą')}
          </Button>
          <Link to={isEdit ? `/plants/catalog/${catalogPlantId}` : '/plants?view=catalog'}>
            <Button variant="secondary">Atgal</Button>
          </Link>
        </div>
      </form>
    </div>
  )
}
