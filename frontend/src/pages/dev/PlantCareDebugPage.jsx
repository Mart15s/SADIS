import { useRef, useState } from 'react'
import PageHeader from '../../components/layout/PageHeader.jsx'
import { EmptyState } from '../../components/shared/StatusView.jsx'
import Button from '../../components/ui/Button.jsx'
import { formatDateTime, safeNumber } from '../../lib/constants.js'
import { api } from '../../lib/api.js'

const CLIENT_CACHE_MS = 10 * 60 * 1000
const REQUEST_COOLDOWN_MS = 1200
const RATE_LIMIT_BLOCK_MS = 60 * 1000

function readCache(cacheRef, key) {
  const entry = cacheRef.current.get(key)

  if (!entry) {
    return null
  }

  if (Date.now() > entry.expiresAt) {
    cacheRef.current.delete(key)
    return null
  }

  return entry.value
}

function writeCache(cacheRef, key, value, ttl = CLIENT_CACHE_MS) {
  cacheRef.current.set(key, {
    value,
    expiresAt: Date.now() + ttl,
  })
}

function normalizeKey(value) {
  return value.trim().toLowerCase()
}

function extractPayload(error) {
  return error?.original?.response?.data ?? null
}

function extractRetryAfterSeconds(error) {
  const payload = extractPayload(error)
  const retryAfter = payload?.retry_after

  return Number.isFinite(Number(retryAfter)) ? Number(retryAfter) : null
}

function formatValue(value) {
  if (value === null || value === undefined || value === '') {
    return 'Not available'
  }

  if (Array.isArray(value)) {
    return value.length ? value.join(', ') : 'Not available'
  }

  if (typeof value === 'boolean') {
    return value ? 'true' : 'false'
  }

  if (typeof value === 'object') {
    return JSON.stringify(value)
  }

  return String(value)
}

function statusTone(status) {
  if (status === 'direct' || status === 'direct_api') {
    return 'success'
  }

  if (status === 'derived' || status === 'reused_local') {
    return 'warning'
  }

  return 'danger'
}

function formatResolutionAction(action) {
  const labels = {
    create_new_profile: 'Create new local profile',
    reused_existing_profile: 'Reuse existing local profile',
    reused_existing_species_match: 'Reuse existing species match',
    reused_existing_local_match: 'Reuse existing canonical match',
    reused_linked_profile: 'Reuse already linked profile',
    updated_existing_species_match: 'Update existing species match',
    updated_existing_local_match: 'Update existing canonical match',
  }

  return labels[action] ?? action ?? 'Unknown'
}

function AlertStack({ alerts }) {
  if (!alerts.length) {
    return null
  }

  return (
    <div className="dev-alert-stack">
      {alerts.map((alert) => (
        <div key={alert.id} className={`dev-alert dev-alert-${alert.tone}`}>
          {alert.message}
        </div>
      ))}
    </div>
  )
}

function JsonDetails({ title, data }) {
  return (
    <details className="dev-raw-details">
      <summary>{title}</summary>
      <pre className="dev-json">{JSON.stringify(data ?? null, null, 2)}</pre>
    </details>
  )
}

function RequestStatus({ label, source, loading, blockedUntil }) {
  const remainingSeconds = blockedUntil && blockedUntil > Date.now()
    ? Math.ceil((blockedUntil - Date.now()) / 1000)
    : 0

  return (
    <div className="dev-status-pill">
      <strong>{label}</strong>
      <span>{loading ? 'Loading...' : source ?? 'Idle'}</span>
      {remainingSeconds > 0 ? <span>Locked for {remainingSeconds}s</span> : null}
    </div>
  )
}

export default function PlantCareDebugPage() {
  const searchCacheRef = useRef(new Map())
  const speciesCacheRef = useRef(new Map())
  const weatherCacheRef = useRef(new Map())

  const [searchQuery, setSearchQuery] = useState('')
  const [searchLoading, setSearchLoading] = useState(false)
  const [searchResult, setSearchResult] = useState(null)
  const [selectedSpeciesId, setSelectedSpeciesId] = useState(null)
  const [searchBlockedUntil, setSearchBlockedUntil] = useState(0)

  const [speciesLoading, setSpeciesLoading] = useState(false)
  const [speciesResult, setSpeciesResult] = useState(null)
  const [speciesBlockedUntil, setSpeciesBlockedUntil] = useState(0)

  const [weatherCity, setWeatherCity] = useState('Vilnius')
  const [weatherLoading, setWeatherLoading] = useState(false)
  const [weatherResult, setWeatherResult] = useState(null)
  const [weatherBlockedUntil, setWeatherBlockedUntil] = useState(0)

  const [alerts, setAlerts] = useState([])

  function pushAlerts(nextAlerts) {
    setAlerts(nextAlerts.map((alert, index) => ({
      id: `${Date.now()}-${index}-${alert.tone}`,
      ...alert,
    })))
  }

  const selectedSearchResult = searchResult?.results?.find(
    (result) => String(result.id) === String(selectedSpeciesId),
  ) ?? null

  const selectedRawSearchItem = searchResult?.raw_response?.find(
    (result) => String(result?.id) === String(selectedSpeciesId),
  ) ?? null

  async function handleSearch(event) {
    event?.preventDefault()

    const query = searchQuery.trim()

    if (query.length < 2) {
      pushAlerts([{ tone: 'error', message: '❌ Perenual search failed: enter at least 2 characters.' }])
      return
    }

    if (Date.now() < searchBlockedUntil) {
      pushAlerts([{ tone: 'warning', message: '⚠️ Search is temporarily locked to prevent repeated requests.' }])
      return
    }

    const cacheKey = normalizeKey(query)
    const cached = readCache(searchCacheRef, cacheKey)

    if (cached) {
      setSearchResult({
        ...cached,
        request: {
          ...(cached.request ?? {}),
          source: 'client_cache',
        },
      })
      pushAlerts([{ tone: 'info', message: 'ℹ️ Loaded from cache.' }])
      return
    }

    setSearchLoading(true)
    setSearchBlockedUntil(Date.now() + REQUEST_COOLDOWN_MS)

    try {
      const response = await api.debugSearchPlants(query)
      writeCache(searchCacheRef, cacheKey, response)
      setSearchResult(response)
      setSelectedSpeciesId(response.results?.[0]?.id ?? null)
      setSpeciesResult(null)

      pushAlerts([{
        tone: response.request?.source === 'cache' ? 'info' : 'success',
        message: response.request?.source === 'cache'
          ? 'ℹ️ Loaded from cache.'
          : 'Loaded from live API.',
      }])
    } catch (error) {
      const payload = extractPayload(error)
      const retryAfter = extractRetryAfterSeconds(error)

      if (error.status === 429) {
        setSearchBlockedUntil(Date.now() + ((retryAfter ?? RATE_LIMIT_BLOCK_MS / 1000) * 1000))
      }

      pushAlerts([{
        tone: 'error',
        message: `❌ Perenual search failed: ${payload?.message ?? error.message}`,
      }, ...(error.status === 429 ? [{
        tone: 'warning',
        message: `⚠️ Rate limited. Retry-After: ${retryAfter ?? 'not provided'} seconds. Further automatic retries are blocked to save credits.`,
      }] : [])])
    } finally {
      setSearchLoading(false)
    }
  }

  async function handleLoadSpecies() {
    if (!selectedSpeciesId) {
      pushAlerts([{ tone: 'warning', message: '⚠️ Select a species before loading details.' }])
      return
    }

    if (Date.now() < speciesBlockedUntil) {
      pushAlerts([{ tone: 'warning', message: '⚠️ Species loading is temporarily locked to reduce repeated requests.' }])
      return
    }

    const cacheKey = String(selectedSpeciesId)
    const cached = readCache(speciesCacheRef, cacheKey)

    if (cached) {
      setSpeciesResult({
        ...cached,
        details: {
          ...(cached.details ?? {}),
          request: {
            ...(cached.details?.request ?? {}),
            source: 'client_cache',
          },
        },
        care_guides: {
          ...(cached.care_guides ?? {}),
          request: {
            ...(cached.care_guides?.request ?? {}),
            source: 'client_cache',
          },
        },
      })
      pushAlerts([{ tone: 'info', message: 'ℹ️ Loaded from cache.' }])
      return
    }

    setSpeciesLoading(true)
    setSpeciesBlockedUntil(Date.now() + REQUEST_COOLDOWN_MS)

    try {
      const response = await api.debugLoadPlantCareSpecies(selectedSpeciesId)

      writeCache(speciesCacheRef, cacheKey, response)
      setSpeciesResult(response)

      const warningAlerts = (response.normalization?.warnings ?? []).map((message) => ({
        tone: 'warning',
        message: `⚠️ ${message}`,
      }))

      pushAlerts([
        {
          tone: response.details?.request?.source === 'cache' || response.care_guides?.request?.source === 'cache'
            ? 'info'
            : 'success',
          message: response.details?.request?.source === 'cache' || response.care_guides?.request?.source === 'cache'
            ? 'ℹ️ Loaded from cache.'
            : 'Selected species details loaded from live API.',
        },
        ...warningAlerts,
      ])
    } catch (error) {
      const payload = extractPayload(error)
      const retryAfter = extractRetryAfterSeconds(error)

      if (error.status === 429) {
        setSpeciesBlockedUntil(Date.now() + ((retryAfter ?? RATE_LIMIT_BLOCK_MS / 1000) * 1000))
      }

      pushAlerts([{
        tone: 'error',
        message: `❌ Perenual details failed: ${payload?.message ?? error.message}`,
      }, ...(error.status === 429 ? [{
        tone: 'warning',
        message: `⚠️ Rate limited. Retry-After: ${retryAfter ?? 'not provided'} seconds. Further requests are temporarily blocked to save credits.`,
      }] : [])])
    } finally {
      setSpeciesLoading(false)
    }
  }

  async function handleWeather(event) {
    event?.preventDefault()

    const city = weatherCity.trim()

    if (city.length < 2) {
      pushAlerts([{ tone: 'error', message: '❌ Meteo.lt failed: enter at least 2 characters for a place.' }])
      return
    }

    if (Date.now() < weatherBlockedUntil) {
      pushAlerts([{ tone: 'warning', message: '⚠️ Weather requests are temporarily locked to reduce repeated calls.' }])
      return
    }

    const cacheKey = normalizeKey(city)
    const cached = readCache(weatherCacheRef, cacheKey)

    if (cached) {
      setWeatherResult({
        ...cached,
        request: {
          place: { ...(cached.request?.place ?? {}), source: 'client_cache' },
          forecast: { ...(cached.request?.forecast ?? {}), source: 'client_cache' },
        },
      })
      pushAlerts([{ tone: 'info', message: 'ℹ️ Loaded from cache.' }])
      return
    }

    setWeatherLoading(true)
    setWeatherBlockedUntil(Date.now() + REQUEST_COOLDOWN_MS)

    try {
      const response = await api.debugCheckWeather(city)
      writeCache(weatherCacheRef, cacheKey, response, 5 * 60 * 1000)
      setWeatherResult(response)
      pushAlerts([{
        tone: response.source === 'stored_weather_forecasts' ? 'warning' : 'success',
        message: response.source === 'stored_weather_forecasts'
          ? '⚠️ Meteo.lt unavailable. Showing stored weather_forecasts fallback.'
          : response.request?.forecast?.source === 'cache'
            ? 'ℹ️ Loaded from cache.'
            : 'Loaded from live Meteo.lt.',
      }])
    } catch (error) {
      const payload = extractPayload(error)
      const retryAfter = extractRetryAfterSeconds(error)

      if (error.status === 429) {
        setWeatherBlockedUntil(Date.now() + ((retryAfter ?? RATE_LIMIT_BLOCK_MS / 1000) * 1000))
      }

      pushAlerts([{
        tone: 'error',
        message: `❌ Meteo.lt failed: ${payload?.message ?? error.message}`,
      }, ...(error.status === 429 ? [{
        tone: 'warning',
        message: `⚠️ Rate limited. Retry-After: ${retryAfter ?? 'not provided'} seconds. Further requests are temporarily blocked.`,
      }] : [])])
    } finally {
      setWeatherLoading(false)
    }
  }

  return (
    <div className="page-stack dev-page">
      <div className="dev-warning-banner">⚠️ TEST PAGE - DELETE BEFORE PRODUCTION</div>
      <PageHeader
        title="Plant Care Debug Test"
        description="Temporary development-only page for verifying Perenual catalog loading, plant_care normalization traceability, Meteo.lt weather fetching, and cache/rate-limit behavior."
      />

      <AlertStack alerts={alerts} />

      <section className="card dev-section">
        <div className="page-stack">
          <div className="page-header">
            <div className="page-header-copy">
              <h2 className="section-title">1. Plant Search</h2>
              <p className="section-copy">Search only runs on button click or Enter. Results are cached client-side and backend-side to save Perenual credits.</p>
            </div>
            <RequestStatus
              label="Search Status"
              source={searchResult?.request?.source}
              loading={searchLoading}
              blockedUntil={searchBlockedUntil}
            />
          </div>

          <form className="dev-form-grid" onSubmit={handleSearch}>
            <div className="field">
              <label htmlFor="dev-search-query">Plant name</label>
              <input
                id="dev-search-query"
                value={searchQuery}
                onChange={(event) => setSearchQuery(event.target.value)}
                placeholder="Search Perenual species"
              />
            </div>
            <div className="dev-inline-actions">
              <Button type="submit" disabled={searchLoading}>Search</Button>
            </div>
          </form>

          {searchResult?.results?.length ? (
            <div className="dev-results-list">
              {searchResult.results.map((result) => {
                const selected = String(selectedSpeciesId) === String(result.id)

                return (
                  <button
                    key={result.id}
                    type="button"
                  className={`dev-result-card ${selected ? 'is-selected' : ''}`.trim()}
                  onClick={() => {
                    setSelectedSpeciesId(result.id)
                    setSpeciesResult(null)
                  }}
                >
                    <div className="dev-result-head">
                      <strong>{result.name}</strong>
                      <span className="badge badge-soft">species #{result.id}</span>
                    </div>
                    <div className="dev-result-grid">
                      <span><strong>Scientific:</strong> {formatValue(result.scientific_name)}</span>
                      <span><strong>Family:</strong> {formatValue(result.family)}</span>
                      <span><strong>Cycle:</strong> {formatValue(result.cycle)}</span>
                      <span><strong>Watering:</strong> {formatValue(result.watering)}</span>
                      <span><strong>Sunlight:</strong> {formatValue(result.sunlight)}</span>
                      <span><strong>Match score:</strong> {formatValue(result.match_score)}</span>
                    </div>
                  </button>
                )
              })}
            </div>
          ) : searchResult ? (
            <EmptyState title="No catalog matches" description="No normalized Perenual candidates were returned for the current query." />
          ) : null}

          {searchResult ? (
            <JsonDetails title="Search Response" data={searchResult.raw_response} />
          ) : null}
        </div>
      </section>

      <section className="card dev-section">
        <div className="page-stack">
          <div className="page-header">
            <div className="page-header-copy">
              <h2 className="section-title">2. Plant Care Mapping</h2>
              <p className="section-copy">This section follows the selected search result and shows the real backend normalization preview plus the real local `plant_care` reuse/create decision.</p>
            </div>
            <RequestStatus
              label="Species Status"
              source={speciesResult?.details?.request?.source}
              loading={speciesLoading}
              blockedUntil={speciesBlockedUntil}
            />
          </div>

          {selectedSearchResult ? (
            <div className="page-stack">
              <div className="dev-meta-grid">
                <div className="dev-meta-card">
                  <strong>Selected species id</strong>
                  <span>{formatValue(selectedSearchResult.id)}</span>
                </div>
                <div className="dev-meta-card">
                  <strong>Selected common name</strong>
                  <span>{formatValue(selectedSearchResult.name)}</span>
                </div>
                <div className="dev-meta-card">
                  <strong>Selected scientific name</strong>
                  <span>{formatValue(selectedSearchResult.scientific_name)}</span>
                </div>
                <div className="dev-meta-card">
                  <strong>Selected family</strong>
                  <span>{formatValue(selectedSearchResult.family)}</span>
                </div>
              </div>

              <div className="dev-inline-actions">
                <Button onClick={handleLoadSpecies} disabled={speciesLoading || !selectedSpeciesId}>
                  Load real backend mapping
                </Button>
              </div>
            </div>
          ) : (
            <EmptyState title="No selected species" description="Search for a plant and choose one result to inspect its real backend mapping flow." />
          )}

          {speciesResult ? (
            <div className="page-stack">
              <div className="dev-meta-grid">
                <div className="dev-meta-card">
                  <strong>Resolution</strong>
                  <span>{formatValue(formatResolutionAction(speciesResult.normalization?.local_resolution?.action))}</span>
                </div>
                <div className="dev-meta-card">
                  <strong>Would reuse local</strong>
                  <span>{formatValue(speciesResult.normalization?.local_resolution?.would_reuse)}</span>
                </div>
                <div className="dev-meta-card">
                  <strong>Would create local</strong>
                  <span>{formatValue(speciesResult.normalization?.local_resolution?.would_create)}</span>
                </div>
                <div className="dev-meta-card">
                  <strong>Matched existing by</strong>
                  <span>{formatValue(speciesResult.normalization?.local_resolution?.matched_existing_by)}</span>
                </div>
                <div className="dev-meta-card">
                  <strong>Existing local profile id</strong>
                  <span>{formatValue(speciesResult.normalization?.local_resolution?.existing_profile_id)}</span>
                </div>
                <div className="dev-meta-card">
                  <strong>Final source quality</strong>
                  <span>{formatValue(speciesResult.normalization?.local_resolution?.final_profile?.source_quality)}</span>
                </div>
                <div className="dev-meta-card">
                  <strong>Final provider</strong>
                  <span>{formatValue(speciesResult.normalization?.local_resolution?.final_profile?.source_provider)}</span>
                </div>
                <div className="dev-meta-card">
                  <strong>Available care guide types</strong>
                  <span>{formatValue(speciesResult.care_guides?.available_types)}</span>
                </div>
              </div>

              <div className="table-wrap">
                <table>
                  <thead>
                    <tr>
                      <th>plant_care field</th>
                      <th>final value</th>
                      <th>source kind</th>
                      <th>source detail</th>
                    </tr>
                  </thead>
                  <tbody>
                    {(speciesResult.normalization?.mapping ?? []).map((row) => (
                      <tr key={row.field} className={`dev-mapping-row status-${row.source_kind}`}>
                        <td><strong>{row.field}</strong></td>
                        <td>{formatValue(row.value)}</td>
                        <td>
                          <span className={`badge badge-${statusTone(row.source_kind)}`}>
                            {row.source_kind}
                          </span>
                        </td>
                        <td>{row.source_detail}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>

              {(speciesResult.normalization?.notes ?? []).map((note) => (
                <div key={note} className="inline-note">{note}</div>
              ))}

              <JsonDetails title="Selected Search Result JSON" data={selectedRawSearchItem ?? selectedSearchResult} />
              <JsonDetails title="Species Details JSON" data={speciesResult.details?.raw_response} />
              <JsonDetails title="Care Guide Response JSON" data={speciesResult.care_guides?.raw_response} />
              <JsonDetails title="Normalized Candidate JSON" data={speciesResult.normalization?.normalized_candidate} />
              <JsonDetails title="Final plant_care Row JSON" data={speciesResult.normalization?.local_resolution?.final_profile} />
              <JsonDetails title="Existing Reused plant_care JSON" data={speciesResult.normalization?.local_resolution?.existing_profile} />
              <JsonDetails title="Normalization Trace JSON" data={speciesResult.normalization?.trace} />
              <JsonDetails title="Backend Mapping Payload JSON" data={speciesResult.backend_debug_payload} />
            </div>
          ) : (
            <EmptyState title="No species loaded" description="Search for a plant, pick one result, and load the real backend mapping inspector." />
          )}
        </div>
      </section>

      <section className="card dev-section">
        <div className="page-stack">
          <div className="page-header">
            <div className="page-header-copy">
              <h2 className="section-title">3. Meteo.lt Weather Test</h2>
              <p className="section-copy">This uses the backend Meteo.lt integration and shows whether the response came from live Meteo.lt or stored weather_forecasts fallback rows.</p>
            </div>
            <RequestStatus
              label="Weather Status"
              source={weatherResult?.request?.forecast?.source ?? weatherResult?.source}
              loading={weatherLoading}
              blockedUntil={weatherBlockedUntil}
            />
          </div>

          <form className="dev-form-grid" onSubmit={handleWeather}>
            <div className="field">
              <label htmlFor="dev-weather-city">City or place</label>
              <input
                id="dev-weather-city"
                value={weatherCity}
                onChange={(event) => setWeatherCity(event.target.value)}
                placeholder="Vilnius"
              />
            </div>
            <div className="dev-inline-actions">
              <Button type="submit" disabled={weatherLoading}>Check Weather</Button>
            </div>
          </form>

          {weatherResult ? (
            <div className="page-stack">
              <div className="dev-meta-grid">
                <div className="dev-meta-card">
                  <strong>Resolved place</strong>
                  <span>{formatValue(weatherResult.resolved_place?.name)}</span>
                </div>
                <div className="dev-meta-card">
                  <strong>Place code</strong>
                  <span>{formatValue(weatherResult.resolved_place?.code)}</span>
                </div>
                <div className="dev-meta-card">
                  <strong>Forecast source</strong>
                  <span>{formatValue(weatherResult.source)}</span>
                </div>
                <div className="dev-meta-card">
                  <strong>Place lookup source</strong>
                  <span>{formatValue(weatherResult.request?.place?.source)}</span>
                </div>
              </div>

              {weatherResult.normalized?.current ? (
                <div className="dev-weather-grid">
                  <div className="dev-weather-card">
                    <strong>Forecast time</strong>
                    <span>{formatDateTime(weatherResult.normalized.current.forecast_time_utc)}</span>
                  </div>
                  <div className="dev-weather-card">
                    <strong>Temperature</strong>
                    <span>{safeNumber(weatherResult.normalized.current.temperature, 1)} C</span>
                  </div>
                  <div className="dev-weather-card">
                    <strong>Precipitation</strong>
                    <span>{safeNumber(weatherResult.normalized.current.precipitation_mm, 1)} mm</span>
                  </div>
                  <div className="dev-weather-card">
                    <strong>Humidity</strong>
                    <span>{safeNumber(weatherResult.normalized.current.humidity, 0)}%</span>
                  </div>
                  <div className="dev-weather-card">
                    <strong>Wind speed</strong>
                    <span>{safeNumber(weatherResult.normalized.current.wind_kmh, 1)} km/h</span>
                  </div>
                </div>
              ) : (
                <EmptyState title="No normalized weather data" description="The debug endpoint did not return a current normalized forecast row." />
              )}

              {weatherResult.normalized?.daily?.length ? (
                <div className="table-wrap">
                  <table>
                    <thead>
                      <tr>
                        <th>Date</th>
                        <th>Temp</th>
                        <th>Rain</th>
                        <th>Humidity</th>
                        <th>Wind</th>
                        <th>Source</th>
                      </tr>
                    </thead>
                    <tbody>
                      {weatherResult.normalized.daily.map((row) => (
                        <tr key={`${row.date}-${row.forecast_time_utc}`}>
                          <td>{formatValue(row.date)}</td>
                          <td>{safeNumber(row.temperature, 1)} C</td>
                          <td>{safeNumber(row.precipitation_mm, 1)} mm</td>
                          <td>{safeNumber(row.humidity, 0)}%</td>
                          <td>{safeNumber(row.wind_kmh, 1)} km/h</td>
                          <td>{formatValue(row.source)}</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              ) : null}

              <JsonDetails title="Place Lookup Response" data={weatherResult.raw_place_lookup} />
              <JsonDetails title="Forecast Response" data={weatherResult.raw_forecast} />
              <JsonDetails title="Normalized Weather Output" data={weatherResult.normalized} />
            </div>
          ) : (
            <EmptyState title="No weather checked yet" description="Enter a city or Meteo.lt place name and run the debug fetch." />
          )}
        </div>
      </section>
    </div>
  )
}
