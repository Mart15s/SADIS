import { useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import PlotSectionNav from '../../components/plot/PlotSectionNav.jsx'
import {
  ErrorState,
  LoadingState,
  ProcessingState,
  SuccessToast,
} from '../../components/shared/StatusView.jsx'
import Badge from '../../components/ui/Badge.jsx'
import Button from '../../components/ui/Button.jsx'
import { StatRow } from '../../components/ui/DefinitionList.jsx'
import EmptyStatePanel from '../../components/ui/EmptyStatePanel.jsx'
import MetricCard from '../../components/ui/MetricCard.jsx'
import SectionCard from '../../components/ui/SectionCard.jsx'
import StatusBadge from '../../components/ui/StatusBadge.jsx'
import { api } from '../../lib/api.js'
import {
  formatPlantCondition,
  safeNumber,
} from '../../lib/constants.js'
import { useAsyncData } from '../../lib/hooks/useAsyncData.js'

const ANALYTICS_HEADER_ROLE_LABELS = {
  admin: 'Administratorius',
  editor: 'Redaktorius',
  owner: 'Savininkas',
  viewer: 'Žiūrovas',
}

const ANALYSIS_OPTIONS = [
  {
    value: 'planning',
    label: 'Planavimo sprendimai',
    description: 'Planavimo istorija, zonų sezoniniai pasirinkimai, rotacijos problemos ir plano keitimo dažnis.',
  },
  {
    value: 'plant_condition',
    label: 'Augalų būklės',
    description: 'Chronologinė būklės istorija, pokyčiai, kritiniai pablogėjimo taškai ir priežiūros reakcijų tendencijos.',
  },
  {
    value: 'harvest',
    label: 'Derlius',
    description: 'Derliaus istorija, kiekio tendencijos, derlingiausi augalai ir plano bei faktinių rezultatų palyginimas.',
  },
]

function MetricBars({ title, metrics }) {
  if (!metrics || Object.keys(metrics).length === 0) {
    return null
  }

  return (
    <section className="panel analytics-result-subsection page-stack">
      <h3>{title}</h3>
      <div className="analytics-bars">
        {Object.entries(metrics).map(([label, value]) => (
          <div key={label} className="bar-card">
            <div className="list-head">
              <strong>{formatAnalyticsLabel(label)}</strong>
              <span>{safeNumber(value, 0)}</span>
            </div>
            <div className="bar-track">
              <div className="bar-fill" style={{ width: `${Math.min(100, Number(value) * 10 || 0)}%` }} />
            </div>
          </div>
        ))}
      </div>
    </section>
  )
}

function formatAnalyticsLabel(value) {
  const labels = {
    improved: 'Pagerėjo',
    worsened: 'Pablogėjo',
    unchanged: 'Nepasikeitė',
    stable: 'Stabili',
    critical: 'Kritinė',
    warning: 'Įspėjimas',
    deteriorated: 'Pablogėjo',
  }

  return labels[value] ?? formatPlantCondition(value)
}

function formatAnalyticsWarning(value) {
  const translations = {
    'Rotation history records are missing, so rotation violations cannot be evaluated.': 'Trūksta rotacijos istorijos įrašų, todėl rotacijos pažeidimų įvertinti negalima.',
    'No plant condition history is available for the selected plot.': 'Pasirinktam sklypui nėra augalų būklės istorijos duomenų.',
    'No harvest history is available for the selected plot.': 'Pasirinktam sklypui nėra derliaus istorijos duomenų.',
  }

  return translations[value] ?? value
}

function renderAnalyticsHeaderMeta(accessRole, plotName) {
  const roleLabel = ANALYTICS_HEADER_ROLE_LABELS[String(accessRole ?? '').toLowerCase()] ?? ''
  const activePlotName = typeof plotName === 'string' ? plotName.trim() : ''

  if (!roleLabel && !activePlotName) {
    return null
  }

  return (
    <>
      {roleLabel ? (
        <StatusBadge kind="status" tone="neutral" className="analytics-header-role-badge">
          {roleLabel}
        </StatusBadge>
      ) : null}
      {activePlotName ? (
        <StatusBadge kind="selection" tone="neutral" className="analytics-header-plot-badge">
          {activePlotName}
        </StatusBadge>
      ) : null}
    </>
  )
}

function WarningList({ warnings }) {
  if (!warnings?.length) {
    return null
  }

  return (
    <SectionCard
      title="Įspėjimai"
      description="Šios analitikos dalys sugeneruotos sėkmingai, bet kai kurie sklypo įrašai dar nebaigti."
      className="analytics-warning-card"
      actions={<Badge tone="warning">{warnings.length}</Badge>}
    >
      <div className="analytics-warning-stack">
        {warnings.map((warning) => (
          <div key={warning} className="analytics-warning">
            {formatAnalyticsWarning(warning)}
          </div>
        ))}
      </div>
    </SectionCard>
  )
}

function NoDataSection({ title, description }) {
  return (
    <SectionCard
      title={title}
      description={description}
      className="analytics-result-card analytics-no-data-card"
      actions={<Badge tone="warning">Duomenų nėra</Badge>}
    />
  )
}

function PlanningSection({ section }) {
  if (!section || section.status === 'no_data') {
    return (
      <NoDataSection
        title="Planavimo sprendimų analizė"
        description="Šiam sklypui dar nėra planavimo istorijos."
      />
    )
  }

  return (
    <section className="panel analytics-result-card page-stack">
      <div className="list-head">
        <div className="stack">
          <h3>Planavimo sprendimų analizė</h3>
          <span className="muted">Istorinės plano versijos ir rotacijos istorija sujungiamos į vieną planavimo vaizdą.</span>
        </div>
        <Badge tone="success">Paruošta</Badge>
      </div>

      <section className="summary-grid">
        <MetricCard label="Versijos" value={section.total_versions} />
        <MetricCard label="Pakeitimų įvykiai" value={section.change_events_count} />
        <MetricCard label="Rotacijos pažeidimai" value={section.rotation_violation_count} />
        <MetricCard label="Pakeitimai per mėnesį" value={safeNumber(section.plan_change_frequency?.changes_per_month, 2)} />
      </section>

      <div className="detail-grid analytics-detail-grid">
        <section className="panel analytics-result-subsection page-stack">
          <h3>Zonų sezono pasirinkimai</h3>
          {section.zone_season_selections?.length ? (
            <div className="table-wrap">
              <table>
                <thead>
                  <tr>
                    <th>Sezonas</th>
                    <th>Zona</th>
                    <th>Augalai</th>
                    <th>Versijos</th>
                  </tr>
                </thead>
                <tbody>
                  {section.zone_season_selections.map((entry) => (
                    <tr key={`${entry.season}-${entry.zone_id}`}>
                      <td>{entry.season}</td>
                      <td>{entry.zone_name || `Zona #${entry.zone_id}`}</td>
                      <td>{entry.plant_names?.join(', ') || 'Nėra'}</td>
                      <td>{entry.version_count}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          ) : (
            <p className="muted">Nėra momentinių kopijų duomenų zonų sezoniniams augalų pasirinkimams atkurti.</p>
          )}
        </section>

        <section className="panel analytics-result-subsection page-stack">
          <h3>Dalyvavimas rotacijoje</h3>
          {section.rotation_history?.zone_participation_counts?.length ? (
            section.rotation_history.zone_participation_counts.map((entry) => (
              <StatRow
                key={entry.zone_id}
                label={entry.zone_name || `Zona #${entry.zone_id}`}
                value={entry.records_count}
              />
            ))
          ) : (
            <p className="muted">Rotacijos istorijos dar nėra.</p>
          )}
        </section>
      </div>

      <section className="panel analytics-result-subsection page-stack">
        <h3>Aptikti rotacijos pažeidimai</h3>
        {section.rotation_violations?.length ? (
          section.rotation_violations.map((violation, index) => (
            <div key={`${violation.zone_id}-${violation.current_from_date}-${index}`} className="analytics-warning">
              <strong>{violation.zone_name || `Zona #${violation.zone_id}`}</strong>
              <div>{violation.reasons?.join(' ')}</div>
            </div>
          ))
        ) : (
          <p className="muted">Turimoje istorijoje rotacijos pažeidimų neaptikta.</p>
        )}
      </section>
    </section>
  )
}

function PlantConditionSection({ section }) {
  if (!section || section.status === 'no_data') {
    return (
      <NoDataSection
        title="Augalų būklės analizė"
        description="Šiam sklypui dar nėra augalų būklės istorijos."
      />
    )
  }

  return (
    <section className="panel analytics-result-card page-stack">
      <div className="list-head">
        <div className="stack">
          <h3>Augalų būklės analizė</h3>
          <span className="muted">Būklės istorija pateikiama chronologiškai ir siejama su priežiūros reakcijos požymiais.</span>
        </div>
        <Badge tone="success">Paruošta</Badge>
      </div>

      <section className="summary-grid">
        <MetricCard label="Istorijos įrašai" value={section.condition_timeline?.length ?? 0} />
        <MetricCard label="Augalai su istorija" value={section.plants_with_history_count} />
        <MetricCard label="Kritiniai taškai" value={section.critical_deterioration_count} />
        <MetricCard
          label="Pagerėjimai po priežiūros"
          value={section.care_response_trends?.improvement_after_care_count ?? 0}
          note={section.care_response_trends?.improvement_after_care_ratio === null
            ? 'Santykis neskaičiuotas'
            : `${safeNumber(section.care_response_trends.improvement_after_care_ratio * 100, 1)}% pagerėjimų`}
        />
      </section>

      <MetricBars title="Dabartinės būklės pasiskirstymas" metrics={section.counts_by_condition} />

      <div className="detail-grid analytics-detail-grid">
        <section className="panel analytics-result-subsection page-stack">
          <h3>Būklės pokyčiai</h3>
          {section.condition_changes?.length ? (
            <div className="table-wrap">
              <table>
                <thead>
                  <tr>
                    <th>Augalas</th>
                    <th>Iš</th>
                    <th>Į</th>
                    <th>Kryptis</th>
                  </tr>
                </thead>
                <tbody>
                  {section.condition_changes.map((change, index) => (
                    <tr key={`${change.plant_id}-${change.to_measured_at}-${index}`}>
                      <td>{change.plant_name}</td>
                      <td>{formatPlantCondition(change.from_condition)}</td>
                      <td>{formatPlantCondition(change.to_condition)}</td>
                      <td>{formatAnalyticsLabel(change.direction)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          ) : (
            <p className="muted">Turimoje istorijoje būklės pokyčių neaptikta.</p>
          )}
        </section>

        <section className="panel analytics-result-subsection page-stack">
          <h3>Kritiniai pablogėjimo taškai</h3>
          {section.critical_deterioration_points?.length ? (
            section.critical_deterioration_points.map((entry, index) => (
              <div key={`${entry.plant_id}-${entry.to_measured_at}-${index}`} className="analytics-warning">
                <strong>{entry.plant_name}</strong>
                <div>{formatPlantCondition(entry.from_condition)} → {formatPlantCondition(entry.to_condition)}</div>
              </div>
            ))
          ) : (
            <p className="muted">Kritinių pablogėjimo taškų nerasta.</p>
          )}
        </section>
      </div>
    </section>
  )
}

function HarvestSection({ section }) {
  if (!section || section.status === 'no_data') {
    return (
      <NoDataSection
        title="Derliaus analizė"
        description="Šiam sklypui derliaus istorijos dar nėra."
      />
    )
  }

  return (
    <section className="panel analytics-result-card page-stack">
      <div className="list-head">
        <div className="stack">
          <h3>Derliaus analizė</h3>
          <span className="muted">Derliaus įrašai grupuojami pagal laikotarpius ir lyginami su suplanuotais derliaus darbais.</span>
        </div>
        <Badge tone="success">Paruošta</Badge>
      </div>

      <section className="summary-grid">
        <MetricCard label="Derliaus įrašai" value={section.total_records} />
        <MetricCard label="Bendras kiekis" value={safeNumber(section.total_quantity, 2)} />
        <MetricCard label="Augalai su derliumi" value={section.plants_with_harvest_records_count} />
        <MetricCard
          label="Faktas ir planas"
          value={section.actual_vs_planned_ratio === null ? 'Nėra' : `${safeNumber(section.actual_vs_planned_ratio * 100, 1)}%`}
          note={section.trend?.direction ? `Tendencija: ${formatAnalyticsLabel(section.trend.direction)}` : undefined}
        />
      </section>

      <div className="detail-grid analytics-detail-grid">
        <section className="panel analytics-result-subsection page-stack">
          <h3>Derlingiausi augalai</h3>
          {section.best_yielding_plants?.length ? (
            section.best_yielding_plants.map((plant) => (
              <StatRow
                key={plant.plant_id}
                label={plant.plant_name}
                value={safeNumber(plant.total_quantity, 2)}
              />
            ))
          ) : (
            <p className="muted">Aiškių derliaus kiekių dar neužregistruota.</p>
          )}
        </section>

        <section className="panel analytics-result-subsection page-stack">
          <h3>Derliaus tendencija pagal laikotarpį</h3>
          {section.records_by_period?.length ? (
            <div className="table-wrap">
              <table>
                <thead>
                  <tr>
                    <th>Laikotarpis</th>
                    <th>Bendras kiekis</th>
                  </tr>
                </thead>
                <tbody>
                  {section.records_by_period.map((entry) => (
                    <tr key={entry.period}>
                      <td>{entry.period}</td>
                      <td>{safeNumber(entry.total_quantity, 2)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          ) : (
            <p className="muted">Laikotarpio tendencijos apskaičiuoti nepavyko.</p>
          )}
        </section>
      </div>
    </section>
  )
}

function renderSection(type, section) {
  if (type === 'planning') {
    return <PlanningSection key={type} section={section} />
  }

  if (type === 'plant_condition') {
    return <PlantConditionSection key={type} section={section} />
  }

  if (type === 'harvest') {
    return <HarvestSection key={type} section={section} />
  }

  return null
}

export default function PlotAnalyticsPage() {
  const { plotId } = useParams()
  const plotState = useAsyncData(
    async () => {
      const plots = await api.listPlots()
      const accessRole = plots.find((entry) => String(entry.id) === String(plotId))?.access_role ?? null
      const plot = await api.getPlot(plotId)
      return { plot, accessRole }
    },
    [plotId],
    { plot: null, accessRole: null },
  )
  const [selectedAnalysisTypes, setSelectedAnalysisTypes] = useState([])
  const [analytics, setAnalytics] = useState(null)
  const [analyticsError, setAnalyticsError] = useState('')
  const [generating, setGenerating] = useState(false)
  const [toastMessage, setToastMessage] = useState('')

  function toggleType(type) {
    setSelectedAnalysisTypes((current) => (
      current.includes(type)
        ? current.filter((entry) => entry !== type)
        : [...current, type]
    ))
  }

  async function handleGenerate(event) {
    event.preventDefault()
    setGenerating(true)
    setAnalyticsError('')

    try {
      const orderedTypes = ANALYSIS_OPTIONS
        .map((option) => option.value)
        .filter((type) => selectedAnalysisTypes.includes(type))

      const generated = await api.generatePlotAnalytics(plotId, {
        analysisTypes: orderedTypes,
      })

      setAnalytics(generated)
      setToastMessage('Analitika sugeneruota sėkmingai.')
    } catch (requestError) {
      setAnalyticsError(requestError.message)
    } finally {
      setGenerating(false)
    }
  }

  if (plotState.loading) {
    return <LoadingState title="Įkeliama analitikos darbo sritis..." />
  }

  if (plotState.error) {
    return <ErrorState error={plotState.error} onRetry={plotState.reload} />
  }

  const summary = analytics?.summary ?? null
  const selectedOptions = ANALYSIS_OPTIONS.filter((option) => selectedAnalysisTypes.includes(option.value))

  return (
    <div className="page-stack analytics-page">
      <PlotSectionNav
        plotId={plotId}
        plotName={plotState.data?.plot?.name ?? 'Sklypas'}
        sectionKey="analytics"
        isOwner={plotState.data?.accessRole === 'owner'}
        description="Generuokite planavimo istorijos, augalų būklės ir derliaus įžvalgas neišeidami iš sklypo darbo srities."
        meta={renderAnalyticsHeaderMeta(plotState.data?.accessRole, plotState.data?.plot?.name)}
        actions={(
          <>
            <Link to={`/plots/${plotId}/history`}>
              <Button variant="secondary">Planavimo istorija</Button>
            </Link>
            <Link to={`/plots/${plotId}/harvests`}>
              <Button variant="secondary">Derliaus įrašai</Button>
            </Link>
          </>
        )}
      />

      <SuccessToast message={toastMessage} onDismiss={() => setToastMessage('')} />

      <form onSubmit={handleGenerate}>
        <SectionCard
          title="Generuoti analitiką"
          description="Pasirinkite analitikos rinkinius, kuriuos norite paleisti šiam sklypui. Kiekviena kryptis apima atskirą daržo darbo proceso dalį."
          className="analytics-generator-card"
          actions={(
            <StatusBadge kind="selection" tone={selectedAnalysisTypes.length > 0 ? 'soft' : 'neutral'}>
              Pasirinkta {selectedAnalysisTypes.length}/3
            </StatusBadge>
          )}
        >
          <div className="analytics-generator-layout">
            <div className="analytics-option-grid">
              {ANALYSIS_OPTIONS.map((option) => {
                const selected = selectedAnalysisTypes.includes(option.value)

                return (
                  <label
                    key={option.value}
                    className={`analytics-option-card ${selected ? 'is-selected' : ''}`.trim()}
                  >
                    <input
                      className="analytics-option-input"
                      type="checkbox"
                      checked={selected}
                      onChange={() => toggleType(option.value)}
                    />
                    <div className="analytics-option-card-head">
                      <div className="analytics-option-copy">
                        <strong className="analytics-option-title">{option.label}</strong>
                        <span className="analytics-option-description">{option.description}</span>
                      </div>
                      <span className={`analytics-option-indicator ${selected ? 'is-selected' : ''}`.trim()}>
                        {selected ? 'Pasirinkta' : 'Pasirinkti'}
                      </span>
                    </div>
                  </label>
                )
              })}
            </div>

            <aside className="analytics-generator-sidebar">
              <div className="analytics-generator-sidebar-copy">
                <span className="workspace-section-eyebrow">Analitikos paleidimas</span>
                <h2 className="workspace-overview-title">Sukurkite aiškią ataskaitą vietoje neapdorotų duomenų sąrašo.</h2>
                <p className="section-copy">
                  Sklypas jau parinktas šiame maršrute, todėl čia tereikia nuspręsti, kuriuos analitikos rinkinius įtraukti.
                </p>
              </div>

              <div className="analytics-selection-summary">
                <span className="analytics-selection-label">Pasirinktos kryptys</span>
                {selectedOptions.length > 0 ? (
                  <div className="analytics-selection-chips">
                    {selectedOptions.map((option) => (
                      <Badge key={option.value} tone="soft">{option.label}</Badge>
                    ))}
                  </div>
                ) : (
                  <p className="muted">Pasirinkite bent vieną kryptį, kad galėtumėte generuoti analitiką.</p>
                )}
              </div>

              <Button
                type="submit"
                fullWidth
                disabled={generating || selectedAnalysisTypes.length === 0}
                className="analytics-generate-button"
              >
                {generating ? 'Generuojama analitika...' : 'Generuoti analitiką'}
              </Button>

              <p className="analytics-generator-note">
                Planavimo, būklės ir derliaus analitika gali būti generuojama kartu arba atskirai.
              </p>
            </aside>
          </div>

          {analyticsError ? (
            <div className="analytics-warning analytics-warning-error">
              {analyticsError}
            </div>
          ) : null}

          {generating ? (
            <ProcessingState
              title="Generuojama analitika"
              description="Sistema renka istorinius įrašus, tikrina turimus duomenis ir rengia analitikos santrauką."
              steps={['Ruošiami duomenys', 'Skaičiuojami rodikliai', 'Baigiama ataskaita']}
              compact
            />
          ) : null}
        </SectionCard>
      </form>

      {!analytics ? (
        <EmptyStatePanel
          title="Analitika dar nesugeneruota"
          description="Pasirinkite vieną ar kelis analitikos rinkinius ir paleiskite generavimą, kad ši darbo sritis būtų užpildyta."
          className="analytics-empty-state"
          tone="subtle"
        />
      ) : (
        <>
          <SectionCard
            title="Sugeneruota analizė"
            description="Šis rezultatas remiasi šiuo metu pasirinktu sklypu ir naujausiais backend analitikos duomenimis."
            className="analytics-summary-shell"
            actions={<Badge tone="soft">{analytics.selectedAnalysisTypes.length} skyriai</Badge>}
          >
            <section className="summary-grid">
              <MetricCard label="Zonos" value={summary?.total_zones} />
              <MetricCard label="Augalai" value={summary?.total_plants} />
              <MetricCard label="Skyriai su duomenimis" value={summary?.sections_with_data_count} />
              <MetricCard
                label="Yra veiksmingų duomenų"
                value={summary?.has_actionable_data ? 'Taip' : 'Ne'}
                note={`${summary?.sections_without_data_count ?? 0} be duomenų`}
              />
            </section>

            <div className="analytics-selection-chips">
              {analytics.selectedAnalysisTypes.map((type) => {
                const option = ANALYSIS_OPTIONS.find((entry) => entry.value === type)
                return (
                  <Badge key={type} tone="neutral">
                    {option?.label ?? type}
                  </Badge>
                )
              })}
            </div>
          </SectionCard>

          <WarningList warnings={analytics.warnings} />

          {analytics.selectedAnalysisTypes.map((type) => renderSection(type, analytics.sections?.[type]))}
        </>
      )}
    </div>
  )
}
