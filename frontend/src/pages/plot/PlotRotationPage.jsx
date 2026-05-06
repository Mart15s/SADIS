import { useState } from 'react'
import { useParams } from 'react-router-dom'
import PlotSectionNav from '../../components/plot/PlotSectionNav.jsx'
import {
  EmptyState,
  ErrorState,
  LoadingState,
  SuccessToast,
} from '../../components/shared/StatusView.jsx'
import Button from '../../components/ui/Button.jsx'
import EmptyStatePanel from '../../components/ui/EmptyStatePanel.jsx'
import StatusBadge from '../../components/ui/StatusBadge.jsx'
import { api } from '../../lib/api.js'
import { formatDate } from '../../lib/constants.js'
import { useAsyncData } from '../../lib/hooks/useAsyncData.js'

function draftSummary(plan) {
  const summary = plan?.summary ?? {}
  return {
    plantCount: summary.plant_count ?? 0,
    assignedCount: summary.assigned_plant_count ?? 0,
    unresolvedCount: summary.unresolved_plant_count ?? 0,
    blockedCount: summary.blocked_plant_count ?? summary.unresolved_plant_count ?? 0,
  }
}

export default function PlotRotationPage() {
  const { plotId } = useParams()
  const [planningDate, setPlanningDate] = useState(new Date().toISOString().slice(0, 10))
  const [draft, setDraft] = useState(null)
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)
  const [success, setSuccess] = useState('')

  const pageState = useAsyncData(
    async () => {
      const plots = await api.listPlots()
      const accessRole = plots.find((entry) => String(entry.id) === String(plotId))?.access_role ?? null
      const [plot, plants, zones, history] = await Promise.all([
        api.getPlot(plotId),
        api.listPlants(plotId),
        api.listPlantZones(plotId),
        api.listRotations(plotId),
      ])

      return {
        plot,
        plants,
        zones,
        history,
        accessRole,
      }
    },
    [plotId],
    {
      plot: null,
      plants: [],
      zones: [],
      history: [],
      accessRole: null,
    },
  )

  const canEdit = ['owner', 'editor'].includes(pageState.data.accessRole)
  const isOwner = pageState.data.accessRole === 'owner'
  const planStatus = draft?.plan?.status ?? null
  const summary = draftSummary(draft?.plan)

  async function handleGenerate(event) {
    event.preventDefault()
    setBusy(true)
    setError('')

    try {
      const response = await api.createRotationPlan(plotId, {
        planning_date: planningDate,
      })
      setDraft(response.draft)
      setSuccess('Rotation draft generated.')
    } catch (requestError) {
      setError(requestError.message)
    } finally {
      setBusy(false)
    }
  }

  async function handleConfirm() {
    if (!draft?.id) {
      return
    }

    setBusy(true)
    setError('')

    try {
      await api.confirmRotationPlan(plotId, draft.id)
      await pageState.reload()
      setDraft(null)
      setSuccess('Rotation plan confirmed.')
    } catch (requestError) {
      setError(requestError.message)
    } finally {
      setBusy(false)
    }
  }

  async function handleReject() {
    if (!draft?.id) {
      return
    }

    setBusy(true)
    setError('')

    try {
      await api.rejectRotationPlan(plotId, draft.id)
      setDraft(null)
      setSuccess('Rotation draft dismissed.')
    } catch (requestError) {
      setError(requestError.message)
    } finally {
      setBusy(false)
    }
  }

  if (pageState.loading) {
    return <LoadingState title="Loading rotation planning..." />
  }

  if (pageState.error) {
    return <ErrorState error={pageState.error} onRetry={pageState.reload} />
  }

  if (!pageState.data.plot) {
    return <EmptyState title="Plot not found" description="The requested plot could not be loaded." />
  }

  return (
    <div className="page-stack">
      <PlotSectionNav
        plotId={plotId}
        plotName={pageState.data.plot.name}
        sectionKey="rotation"
        isOwner={isOwner}
        description="Keep crop rotation planning in its own workspace so the editor stays focused on live geometry and planting."
        meta={(
          <>
            <StatusBadge kind="selection" tone="neutral">{pageState.data.zones.length} zones</StatusBadge>
            <StatusBadge kind="selection" tone="neutral">{pageState.data.plants.length} plants</StatusBadge>
          </>
        )}
      />
      <SuccessToast message={success} onDismiss={() => setSuccess('')} />

      <div className="page-stack">
        {!canEdit ? (
          <EmptyState
            title="Read-only rotation access"
            description="You can review saved rotation decisions here, but generating or confirming a plan requires owner or editor access."
          />
        ) : (
          <section className="panel page-stack">
            <div className="plot-page-section-head">
              <div>
                <h2 className="section-title">Generate draft</h2>
                <p className="section-copy">Create one proposed rotation plan, review unresolved plants, then confirm only when the result is usable.</p>
              </div>
            </div>

            <form className="plot-rotation-toolbar" onSubmit={handleGenerate}>
              <div className="field">
                <label htmlFor="rotation-planning-date">Planning date</label>
                <input
                  id="rotation-planning-date"
                  type="date"
                  value={planningDate}
                  onChange={(event) => setPlanningDate(event.target.value)}
                  required
                />
              </div>
              <div className="form-actions">
                <Button type="submit" loading={busy}>
                  {busy ? 'Generating draft' : 'Generate rotation draft'}
                </Button>
              </div>
            </form>
            {error ? <span className="field-error">{error}</span> : null}
          </section>
        )}

        {pageState.data.plants.length === 0 || pageState.data.zones.length === 0 ? (
          <EmptyStatePanel
            title="Rotation planning needs zones and plants"
            description="Add at least one zone and one planted crop in the editor before generating a rotation draft."
            tone="subtle"
          />
        ) : null}

        {draft ? (
          <section className="panel page-stack">
            <div className="plot-page-section-head">
              <div>
                <h2 className="section-title">Draft plan</h2>
                <p className="section-copy">Review the summary first, then inspect only the plants that still need attention.</p>
              </div>
              <StatusBadge kind="status" tone={planStatus === 'ready' ? 'success' : 'warning'}>
                {planStatus === 'ready' ? 'Ready to confirm' : 'Needs adjustment'}
              </StatusBadge>
            </div>

            <div className="plot-rotation-summary">
              <div className="plot-rotation-stat">
                <span className="plot-rotation-stat-label">Plants</span>
                <strong className="plot-rotation-stat-value">{summary.plantCount}</strong>
              </div>
              <div className="plot-rotation-stat">
                <span className="plot-rotation-stat-label">Assigned</span>
                <strong className="plot-rotation-stat-value">{summary.assignedCount}</strong>
              </div>
              <div className="plot-rotation-stat">
                <span className="plot-rotation-stat-label">Unresolved</span>
                <strong className="plot-rotation-stat-value">{summary.unresolvedCount}</strong>
              </div>
              <div className="plot-rotation-stat">
                <span className="plot-rotation-stat-label">Blocked</span>
                <strong className="plot-rotation-stat-value">{summary.blockedCount}</strong>
              </div>
            </div>

            {summary.assignedCount === 0 ? (
              <span className="field-error">No valid automatic rotation could be generated for the selected date.</span>
            ) : summary.unresolvedCount > 0 ? (
              <p className="section-copy">This draft cannot be confirmed until every unresolved plant is reviewed.</p>
            ) : null}

            <div className="plot-rotation-draft-list">
              {(draft.plan?.plants ?? []).map((entry) => {
                const targetZone = entry.selected_target_zone
                const alternatives = entry.alternatives ?? []
                const fallbackSolutions = entry.fallback_solutions ?? []
                const positiveReasons = (targetZone?.positive_reasons ?? targetZone?.passed_reasons ?? []).filter(Boolean)
                const softWarnings = (targetZone?.soft_warnings ?? []).filter(Boolean)
                const blockedCandidates = (entry.candidate_zones ?? [])
                  .filter((candidate) => !candidate.is_eligible)
                  .slice(0, 4)

                return (
                  <article key={entry.plant?.id} className="plot-rotation-draft-item">
                    <div className="plot-rotation-draft-head">
                      <div>
                        <strong>{entry.plant?.name}</strong>
                        <p className="muted">
                          {entry.current_zone?.name ? `Current zone: ${entry.current_zone.name}` : 'No current zone'}
                        </p>
                      </div>
                      <StatusBadge kind="selection" tone={targetZone ? 'success' : 'warning'}>
                        {targetZone ? `Target: ${targetZone.zone_name}` : 'Needs manual review'}
                      </StatusBadge>
                    </div>

                    {targetZone ? (
                      <p className="plot-rotation-draft-summary">
                        Move to <strong>{targetZone.zone_name}</strong>.
                      </p>
                    ) : (
                      <p className="plot-rotation-draft-summary">
                        No clean placement was selected automatically.
                      </p>
                    )}

                    {(positiveReasons.length > 0 || softWarnings.length > 0 || alternatives.length > 0 || fallbackSolutions.length > 0 || blockedCandidates.length > 0) ? (
                      <details className="task-card-details">
                        <summary>Why this recommendation</summary>
                        <div className="task-card-detail-stack">
                          {targetZone && positiveReasons.length > 0 ? (
                            <div className="task-card-detail-block">
                              <strong>Positive reasons</strong>
                              <div className="task-card-resource-list">
                                {positiveReasons.slice(0, 3).map((reason) => (
                                  <div key={`${entry.plant?.id}-${reason}`} className="task-card-resource-row">
                                    <span>{reason}</span>
                                  </div>
                                ))}
                              </div>
                            </div>
                          ) : null}

                          {targetZone && softWarnings.length > 0 ? (
                            <div className="task-card-detail-block">
                              <strong>Soft warnings</strong>
                              <div className="task-card-resource-list">
                                {softWarnings.slice(0, 3).map((warning) => (
                                  <div key={`${entry.plant?.id}-${warning}`} className="task-card-resource-row">
                                    <span>{warning}</span>
                                  </div>
                                ))}
                              </div>
                            </div>
                          ) : null}

                          {alternatives.length > 0 ? (
                            <div className="task-card-detail-block">
                              <strong>Alternative zones</strong>
                              <div className="task-card-resource-list">
                                {alternatives.slice(0, 3).map((alternative) => (
                                  <div key={`${entry.plant?.id}-${alternative.zone_id}`} className="task-card-resource-row">
                                    <span>{alternative.zone_name}</span>
                                    <span>Score {alternative.score}</span>
                                  </div>
                                ))}
                              </div>
                            </div>
                          ) : null}

                          {!targetZone && blockedCandidates.length > 0 ? (
                            <div className="task-card-detail-block">
                              <strong>Blocked candidate zones</strong>
                              <div className="task-card-resource-list">
                                {blockedCandidates.map((candidate) => (
                                  <div key={`${entry.plant?.id}-${candidate.zone_id}`} className="task-card-resource-row">
                                    <span>{candidate.zone_name}</span>
                                    <span>{(candidate.hard_blocking_reasons ?? candidate.blocking_reasons ?? []).slice(0, 2).join('; ')}</span>
                                  </div>
                                ))}
                              </div>
                            </div>
                          ) : null}

                          {fallbackSolutions.length > 0 ? (
                            <div className="task-card-detail-block">
                              <strong>Fallback suggestions</strong>
                              <div className="task-card-resource-list">
                                {fallbackSolutions.map((solution) => (
                                  <div key={`${entry.plant?.id}-${solution}`} className="task-card-resource-row">
                                    <span>{solution}</span>
                                  </div>
                                ))}
                              </div>
                            </div>
                          ) : null}
                        </div>
                      </details>
                    ) : null}
                  </article>
                )
              })}
            </div>

            {canEdit ? (
              <div className="form-actions">
                <Button onClick={handleConfirm} disabled={planStatus !== 'ready' || summary.assignedCount === 0} loading={busy}>
                  Confirm rotation plan
                </Button>
                <Button variant="ghost" onClick={handleReject} disabled={busy}>Discard draft</Button>
              </div>
            ) : null}
          </section>
        ) : (
          <EmptyStatePanel
            title="No active draft"
            description="Generate a draft when you want the system to evaluate zone changes for the current plot."
            tone="subtle"
          />
        )}

        <section className="panel page-stack">
          <div className="plot-page-section-head">
            <div>
              <h2 className="section-title">Saved rotation history</h2>
              <p className="section-copy">Confirmed rotation moves stay visible here as a quiet record of planting decisions over time.</p>
            </div>
            <StatusBadge kind="selection" tone="neutral">{pageState.data.history.length} entries</StatusBadge>
          </div>

          {pageState.data.history.length === 0 ? (
            <EmptyStatePanel
              title="No rotation history yet"
              description="Confirm a draft rotation plan to start building the long-term crop rotation record for this plot."
              tone="subtle"
            />
          ) : (
            <div className="plot-rotation-history-list">
              {pageState.data.history.map((entry) => (
                <article key={entry.id} className="plot-rotation-history-item">
                  <div>
                    <strong>{entry.plant?.name ?? 'Plant removed'}</strong>
                    <p className="muted">{entry.plant_zone?.name ?? 'Unknown zone'}</p>
                  </div>
                  <span className="plot-rotation-history-date">
                    {formatDate(entry.from_date)}
                    {entry.to_date ? ` to ${formatDate(entry.to_date)}` : ''}
                  </span>
                </article>
              ))}
            </div>
          )}
        </section>
      </div>
    </div>
  )
}
