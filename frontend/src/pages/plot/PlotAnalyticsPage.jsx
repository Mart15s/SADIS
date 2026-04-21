import { useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import PageHeader from '../../components/layout/PageHeader.jsx'
import {
  EmptyState,
  ErrorState,
  LoadingState,
  ProcessingState,
  SuccessToast,
} from '../../components/shared/StatusView.jsx'
import Badge from '../../components/ui/Badge.jsx'
import Button from '../../components/ui/Button.jsx'
import MetricCard from '../../components/ui/MetricCard.jsx'
import { api } from '../../lib/api.js'
import { safeNumber } from '../../lib/constants.js'
import { useAsyncData } from '../../lib/hooks/useAsyncData.js'

const ANALYSIS_OPTIONS = [
  {
    value: 'planning',
    label: 'Planning decisions',
    description: 'Planning history, zone-season choices, rotation issues, and plan change frequency.',
  },
  {
    value: 'plant_condition',
    label: 'Plant conditions',
    description: 'Chronological condition history, changes, critical deterioration points, and care-response trends.',
  },
  {
    value: 'harvest',
    label: 'Harvest',
    description: 'Historical harvest records, yield trends, best yielding plants, and planned-vs-actual comparison.',
  },
]

function MetricBars({ title, metrics }) {
  if (!metrics || Object.keys(metrics).length === 0) {
    return null
  }

  return (
    <section className="panel page-stack">
      <h3>{title}</h3>
      <div className="analytics-bars">
        {Object.entries(metrics).map(([label, value]) => (
          <div key={label} className="bar-card">
            <div className="list-head">
              <strong>{label}</strong>
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

function WarningList({ warnings }) {
  if (!warnings?.length) {
    return null
  }

  return (
    <section className="panel page-stack">
      <div className="list-head">
        <h3>Warnings</h3>
        <Badge tone="warning">{warnings.length}</Badge>
      </div>
      <div className="analytics-warning-stack">
        {warnings.map((warning) => (
          <div key={warning} className="analytics-warning">
            {warning}
          </div>
        ))}
      </div>
    </section>
  )
}

function NoDataSection({ title, description }) {
  return (
    <section className="panel page-stack">
      <div className="list-head">
        <h3>{title}</h3>
        <Badge tone="warning">No data</Badge>
      </div>
      <p className="muted">{description}</p>
    </section>
  )
}

function PlanningSection({ section }) {
  if (!section || section.status === 'no_data') {
    return (
      <NoDataSection
        title="Planning decisions analysis"
        description="No planning history is available for this plot yet."
      />
    )
  }

  return (
    <section className="panel page-stack">
      <div className="list-head">
        <div className="stack">
          <h3>Planning decisions analysis</h3>
          <span className="muted">Historical snapshots and rotation history are combined into one planning view.</span>
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
        <section className="panel page-stack">
          <h3>Zone-season selections</h3>
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
            <p className="muted">No snapshot data is available to reconstruct zone-season plant selections.</p>
          )}
        </section>

        <section className="panel page-stack">
          <h3>Rotation participation</h3>
          {section.rotation_history?.zone_participation_counts?.length ? (
            section.rotation_history.zone_participation_counts.map((entry) => (
              <div key={entry.zone_id} className="list-head">
                <strong>{entry.zone_name || `Zone #${entry.zone_id}`}</strong>
                <span>{entry.records_count}</span>
              </div>
            ))
          ) : (
            <p className="muted">No rotation history is available yet.</p>
          )}
        </section>
      </div>

      <section className="panel page-stack">
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
        description="No plant condition history is available for this plot yet."
      />
    )
  }

  return (
    <section className="panel page-stack">
      <div className="list-head">
        <div className="stack">
          <h3>Plant condition analysis</h3>
          <span className="muted">Condition history is ordered chronologically and linked with care-response signals.</span>
        </div>
        <Badge tone="success">Ready</Badge>
      </div>

      <section className="summary-grid">
        <MetricCard label="History entries" value={section.condition_timeline?.length ?? 0} />
        <MetricCard label="Plants with history" value={section.plants_with_history_count} />
        <MetricCard label="Critical points" value={section.critical_deterioration_count} />
        <MetricCard
          label="Improvements after care"
          value={section.care_response_trends?.improvement_after_care_count ?? 0}
          note={section.care_response_trends?.improvement_after_care_ratio === null
            ? 'No ratio'
            : `${safeNumber(section.care_response_trends.improvement_after_care_ratio * 100, 1)}% of improvements`}
        />
      </section>

      <MetricBars title="Current condition distribution" metrics={section.counts_by_condition} />

      <div className="detail-grid analytics-detail-grid">
        <section className="panel page-stack">
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
                      <td>{change.from_condition}</td>
                      <td>{change.to_condition}</td>
                      <td>{change.direction}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          ) : (
            <p className="muted">No condition changes were detected in the available history.</p>
          )}
        </section>

        <section className="panel page-stack">
          <h3>Critical deterioration points</h3>
          {section.critical_deterioration_points?.length ? (
            section.critical_deterioration_points.map((entry, index) => (
              <div key={`${entry.plant_id}-${entry.to_measured_at}-${index}`} className="analytics-warning">
                <strong>{entry.plant_name}</strong>
                <div>{entry.from_condition} to {entry.to_condition}</div>
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
        description="No harvest history is available for this plot yet."
      />
    )
  }

  return (
    <section className="panel page-stack">
      <div className="list-head">
        <div className="stack">
          <h3>Harvest analysis</h3>
          <span className="muted">Explicit harvest records are grouped by period and compared with planned harvest work.</span>
        </div>
        <Badge tone="success">Ready</Badge>
      </div>

      <section className="summary-grid">
        <MetricCard label="Harvest records" value={section.total_records} />
        <MetricCard label="Total quantity" value={safeNumber(section.total_quantity, 2)} />
        <MetricCard label="Plants with harvests" value={section.plants_with_harvest_records_count} />
        <MetricCard
          label="Actual vs planned"
          value={section.actual_vs_planned_ratio === null ? 'N/A' : `${safeNumber(section.actual_vs_planned_ratio * 100, 1)}%`}
          note={section.trend?.direction ? `Trend ${section.trend.direction}` : undefined}
        />
      </section>

      <div className="detail-grid analytics-detail-grid">
        <section className="panel page-stack">
          <h3>Best yielding plants</h3>
          {section.best_yielding_plants?.length ? (
            section.best_yielding_plants.map((plant) => (
              <div key={plant.plant_id} className="list-head">
                <strong>{plant.plant_name}</strong>
                <span>{safeNumber(plant.total_quantity, 2)}</span>
              </div>
            ))
          ) : (
            <p className="muted">No explicit harvest quantities are registered yet.</p>
          )}
        </section>

        <section className="panel page-stack">
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
            <p className="muted">No period trend could be calculated.</p>
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
  const plotState = useAsyncData(() => api.getPlot(plotId), [plotId], null)
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
      setToastMessage('Analysis generated successfully.')
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

  return (
    <div className="page-stack">
      <PageHeader
        eyebrow="Plot insights"
        title={`${plotState.data?.name ?? 'Plot'} analytics`}
        description="Generate focused insight packs for planning, plant condition, and harvest data with clearer progress, warnings, and no-data handling."
        actions={(
          <>
            <Link to={`/plots/${plotId}/history`}>
              <Button variant="secondary">Planning history</Button>
            </Link>
            <Link to={`/plots/${plotId}/harvests`}>
              <Button variant="secondary">Harvests</Button>
            </Link>
            <Link to={`/plots/${plotId}`}>
              <Button variant="secondary">Back to plot</Button>
            </Link>
          </>
        )}
      />

      <SuccessToast message={toastMessage} onDismiss={() => setToastMessage('')} />

      <form className="panel page-stack" onSubmit={handleGenerate}>
        <div className="list-head">
          <div className="stack">
            <h3>Generate analysis</h3>
            <span className="muted">The selected plot is fixed by the current route. Choose the analysis branches you want to run.</span>
          </div>
          <Button type="submit" disabled={generating || selectedAnalysisTypes.length === 0}>
            {generating ? 'Generating...' : 'Generate analysis'}
          </Button>
        </div>

        <div className="analytics-type-grid">
          {ANALYSIS_OPTIONS.map((option) => {
            const selected = selectedAnalysisTypes.includes(option.value)

            return (
              <label
                key={option.value}
                className={`analytics-type-card ${selected ? 'is-selected' : ''}`}
              >
                <input
                  type="checkbox"
                  checked={selected}
                  onChange={() => toggleType(option.value)}
                />
                <div className="stack">
                  <strong>{option.label}</strong>
                  <span className="muted">{option.description}</span>
                </div>
              </label>
            )
          })}
        </div>

        {analyticsError ? (
          <div className="analytics-warning analytics-warning-error">
            {analyticsError}
          </div>
        ) : null}

        {generating ? (
          <ProcessingState
            title="Generating analysis"
            description="The system is collecting historical records, validating available data, and preparing a polished analytics summary."
            steps={['Preparing data', 'Computing metrics', 'Finalizing report']}
          />
        ) : null}
      </form>

      {!analytics ? (
        <EmptyState
          title="No analysis generated yet"
          description="Select at least one analysis type and generate a report for this plot."
        />
      ) : (
        <>
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

          <WarningList warnings={analytics.warnings} />

          <section className="panel page-stack">
            <div className="list-head">
              <h3>Generated analysis scope</h3>
              <Badge tone="soft">{analytics.selectedAnalysisTypes.length}</Badge>
            </div>
            <div className="meta-cluster">
              {analytics.selectedAnalysisTypes.map((type) => {
                const option = ANALYSIS_OPTIONS.find((entry) => entry.value === type)
                return (
                  <Badge key={type} tone="neutral">
                    {option?.label ?? type}
                  </Badge>
                )
              })}
            </div>
          </section>

          {analytics.selectedAnalysisTypes.map((type) => renderSection(type, analytics.sections?.[type]))}
        </>
      )}
    </div>
  )
}
