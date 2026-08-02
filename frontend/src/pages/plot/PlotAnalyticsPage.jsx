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
  admin: 'Administrator',
  editor: 'Editor',
  owner: 'Owner',
  viewer: 'Viewer',
}

const ANALYSIS_OPTIONS = [
  {
    value: 'planning',
    label: 'Planning decisions',
    description: 'Planning history, seasonal zone choices, rotation issues, and plan change frequency.',
  },
  {
    value: 'plant_condition',
    label: 'Plant conditions',
    description: 'A chronological condition history, changes, critical deterioration points, and care response trends.',
  },
  {
    value: 'harvest',
    label: 'Harvest',
    description: 'Harvest history, quantity trends, best-yielding plants, and planned-versus-actual results.',
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
    improved: 'Improved',
    worsened: 'Worsened',
    unchanged: 'Unchanged',
    stable: 'Stable',
    critical: 'Critical',
    warning: 'Warning',
    deteriorated: 'Deteriorated',
  }

  return labels[value] ?? formatPlantCondition(value)
}

function formatAnalyticsWarning(value) {
  const translations = {
    'Rotation history records are missing, so rotation violations cannot be evaluated.': 'Rotation history records are missing, so rotation violations cannot be evaluated.',
    'No plant condition history is available for the selected plot.': 'No plant condition history is available for the selected plot.',
    'No harvest history is available for the selected plot.': 'No harvest history is available for the selected plot.',
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
      title="Warnings"
      description="These analytics sections were generated successfully, but some plot records are incomplete."
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
      actions={<Badge tone="warning">No data</Badge>}
    />
  )
}

function PlanningSection({ section }) {
  if (!section || section.status === 'no_data') {
    return (
      <NoDataSection
        title="Planning decision analysis"
        description="This plot does not have any planning history yet."
      />
    )
  }

  return (
    <section className="panel analytics-result-card page-stack">
      <div className="list-head">
        <div className="stack">
          <h3>Planning decision analysis</h3>
          <span className="muted">Historical plan versions and rotation history are combined in one planning view.</span>
        </div>
        <Badge tone="success">Ready</Badge>
      </div>

      <section className="summary-grid">
        <MetricCard label="Versions" value={section.total_versions} />
        <MetricCard label="Change events" value={section.change_events_count} />
        <MetricCard label="Rotation violations" value={section.rotation_violation_count} />
        <MetricCard label="Changes per month" value={safeNumber(section.plan_change_frequency?.changes_per_month, 2)} />
      </section>

      <div className="detail-grid analytics-detail-grid">
        <section className="panel analytics-result-subsection page-stack">
          <h3>Seasonal zone choices</h3>
          {section.zone_season_selections?.length ? (
            <div className="table-wrap">
              <table>
                <thead>
                  <tr>
                    <th>Season</th>
                    <th>Zone</th>
                    <th>Plants</th>
                    <th>Versions</th>
                  </tr>
                </thead>
                <tbody>
                  {section.zone_season_selections.map((entry) => (
                    <tr key={`${entry.season}-${entry.zone_id}`}>
                      <td>{entry.season}</td>
                      <td>{entry.zone_name || `Zone #${entry.zone_id}`}</td>
                      <td>{entry.plant_names?.join(', ') || 'None'}</td>
                      <td>{entry.version_count}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          ) : (
            <p className="muted">No snapshot data is available to reconstruct seasonal plant choices by zone.</p>
          )}
        </section>

        <section className="panel analytics-result-subsection page-stack">
          <h3>Rotation participation</h3>
          {section.rotation_history?.zone_participation_counts?.length ? (
            section.rotation_history.zone_participation_counts.map((entry) => (
              <StatRow
                key={entry.zone_id}
                label={entry.zone_name || `Zone #${entry.zone_id}`}
                value={entry.records_count}
              />
            ))
          ) : (
            <p className="muted">No rotation history is available yet.</p>
          )}
        </section>
      </div>

      <section className="panel analytics-result-subsection page-stack">
        <h3>Detected rotation violations</h3>
        {section.rotation_violations?.length ? (
          section.rotation_violations.map((violation, index) => (
            <div key={`${violation.zone_id}-${violation.current_from_date}-${index}`} className="analytics-warning">
              <strong>{violation.zone_name || `Zone #${violation.zone_id}`}</strong>
              <div>{violation.reasons?.join(' ')}</div>
            </div>
          ))
        ) : (
          <p className="muted">No rotation violations were detected in the available history.</p>
        )}
      </section>
    </section>
  )
}

function PlantConditionSection({ section }) {
  if (!section || section.status === 'no_data') {
    return (
      <NoDataSection
        title="Plant condition analysis"
        description="This plot does not have any plant condition history yet."
      />
    )
  }

  return (
    <section className="panel analytics-result-card page-stack">
      <div className="list-head">
        <div className="stack">
          <h3>Plant condition analysis</h3>
          <span className="muted">Condition history is presented chronologically and linked to signs of care response.</span>
        </div>
        <Badge tone="success">Ready</Badge>
      </div>

      <section className="summary-grid">
        <MetricCard label="History records" value={section.condition_timeline?.length ?? 0} />
        <MetricCard label="Plants with history" value={section.plants_with_history_count} />
        <MetricCard label="Critical points" value={section.critical_deterioration_count} />
        <MetricCard
          label="Improvements after care"
          value={section.care_response_trends?.improvement_after_care_count ?? 0}
          note={section.care_response_trends?.improvement_after_care_ratio === null
            ? 'Ratio not calculated'
            : `${safeNumber(section.care_response_trends.improvement_after_care_ratio * 100, 1)}% improved`}
        />
      </section>

      <MetricBars title="Current condition distribution" metrics={section.counts_by_condition} />

      <div className="detail-grid analytics-detail-grid">
        <section className="panel analytics-result-subsection page-stack">
          <h3>Condition changes</h3>
          {section.condition_changes?.length ? (
            <div className="table-wrap">
              <table>
                <thead>
                  <tr>
                    <th>Plant</th>
                    <th>From</th>
                    <th>To</th>
                    <th>Direction</th>
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
            <p className="muted">No condition changes were detected in the available history.</p>
          )}
        </section>

        <section className="panel analytics-result-subsection page-stack">
          <h3>Critical deterioration points</h3>
          {section.critical_deterioration_points?.length ? (
            section.critical_deterioration_points.map((entry, index) => (
              <div key={`${entry.plant_id}-${entry.to_measured_at}-${index}`} className="analytics-warning">
                <strong>{entry.plant_name}</strong>
                <div>{formatPlantCondition(entry.from_condition)} → {formatPlantCondition(entry.to_condition)}</div>
              </div>
            ))
          ) : (
            <p className="muted">No critical deterioration points were found.</p>
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
        title="Harvest analysis"
        description="This plot does not have any harvest history yet."
      />
    )
  }

  return (
    <section className="panel analytics-result-card page-stack">
      <div className="list-head">
        <div className="stack">
          <h3>Harvest analysis</h3>
          <span className="muted">Harvest records are grouped by period and compared with planned harvest tasks.</span>
        </div>
        <Badge tone="success">Ready</Badge>
      </div>

      <section className="summary-grid">
        <MetricCard label="Harvest records" value={section.total_records} />
        <MetricCard label="Total quantity" value={safeNumber(section.total_quantity, 2)} />
        <MetricCard label="Plants with harvests" value={section.plants_with_harvest_records_count} />
        <MetricCard
          label="Actual vs. planned"
          value={section.actual_vs_planned_ratio === null ? 'None' : `${safeNumber(section.actual_vs_planned_ratio * 100, 1)}%`}
          note={section.trend?.direction ? `Trend: ${formatAnalyticsLabel(section.trend.direction)}` : undefined}
        />
      </section>

      <div className="detail-grid analytics-detail-grid">
        <section className="panel analytics-result-subsection page-stack">
          <h3>Best-yielding plants</h3>
          {section.best_yielding_plants?.length ? (
            section.best_yielding_plants.map((plant) => (
              <StatRow
                key={plant.plant_id}
                label={plant.plant_name}
                value={safeNumber(plant.total_quantity, 2)}
              />
            ))
          ) : (
            <p className="muted">No clear harvest quantities have been recorded yet.</p>
          )}
        </section>

        <section className="panel analytics-result-subsection page-stack">
          <h3>Harvest trend by period</h3>
          {section.records_by_period?.length ? (
            <div className="table-wrap">
              <table>
                <thead>
                  <tr>
                    <th>Period</th>
                    <th>Total quantity</th>
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
            <p className="muted">The trend for this period could not be calculated.</p>
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
      setToastMessage('Analytics generated successfully.')
    } catch (requestError) {
      setAnalyticsError(requestError.message)
    } finally {
      setGenerating(false)
    }
  }

  if (plotState.loading) {
    return <LoadingState title="Loading analytics workspace..." />
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
        plotName={plotState.data?.plot?.name ?? 'Plot'}
        sectionKey="analytics"
        isOwner={plotState.data?.accessRole === 'owner'}
        description="Generate planning history, plant condition, and harvest insights without leaving the plot workspace."
        meta={renderAnalyticsHeaderMeta(plotState.data?.accessRole, plotState.data?.plot?.name)}
        actions={(
          <>
            <Link to={`/plots/${plotId}/history`}>
              <Button variant="secondary">Planning history</Button>
            </Link>
            <Link to={`/plots/${plotId}/harvests`}>
              <Button variant="secondary">Harvest records</Button>
            </Link>
          </>
        )}
      />

      <SuccessToast message={toastMessage} onDismiss={() => setToastMessage('')} />

      <form onSubmit={handleGenerate}>
        <SectionCard
          title="Generate analytics"
          description="Choose the analytics sets to run for this plot. Each one covers a distinct part of the growing workflow."
          className="analytics-generator-card"
          actions={(
            <StatusBadge kind="selection" tone={selectedAnalysisTypes.length > 0 ? 'soft' : 'neutral'}>
              Selected {selectedAnalysisTypes.length}/3
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
                        {selected ? 'Selected' : 'Select'}
                      </span>
                    </div>
                  </label>
                )
              })}
            </div>

            <aside className="analytics-generator-sidebar">
              <div className="analytics-generator-sidebar-copy">
                <span className="workspace-section-eyebrow">Analytics run</span>
                <h2 className="workspace-overview-title">Create a clear report instead of a raw data list.</h2>
                <p className="section-copy">
                  The plot is already selected for this route, so you only need to choose which analytics sets to include.
                </p>
              </div>

              <div className="analytics-selection-summary">
                <span className="analytics-selection-label">Selected sets</span>
                {selectedOptions.length > 0 ? (
                  <div className="analytics-selection-chips">
                    {selectedOptions.map((option) => (
                      <Badge key={option.value} tone="soft">{option.label}</Badge>
                    ))}
                  </div>
                ) : (
                  <p className="muted">Select at least one set to generate analytics.</p>
                )}
              </div>

              <Button
                type="submit"
                fullWidth
                disabled={generating || selectedAnalysisTypes.length === 0}
                className="analytics-generate-button"
              >
                {generating ? 'Generating analytics...' : 'Generate analytics'}
              </Button>

              <p className="analytics-generator-note">
                Planning, condition, and harvest analytics can be generated together or separately.
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
              title="Generating analytics"
              description="The system is collecting historical records, checking available data, and preparing an analytics summary."
              steps={['Preparing data', 'Calculating metrics', 'Finalizing report']}
              compact
            />
          ) : null}
        </SectionCard>
      </form>

      {!analytics ? (
        <EmptyStatePanel
          title="Analytics have not been generated yet"
          description="Select one or more analytics sets and run generation to populate this workspace."
          className="analytics-empty-state"
          tone="subtle"
        />
      ) : (
        <>
          <SectionCard
            title="Generated analysis"
            description="This result is based on the currently selected plot and the latest analytics data from the server."
            className="analytics-summary-shell"
            actions={<Badge tone="soft">{analytics.selectedAnalysisTypes.length} sections</Badge>}
          >
            <section className="summary-grid">
              <MetricCard label="Zones" value={summary?.total_zones} />
              <MetricCard label="Plants" value={summary?.total_plants} />
              <MetricCard label="Sections with data" value={summary?.sections_with_data_count} />
              <MetricCard
                label="Has actionable data"
                value={summary?.has_actionable_data ? 'Yes' : 'No'}
                note={`${summary?.sections_without_data_count ?? 0} without data`}
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
