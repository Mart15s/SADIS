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
    annualCount: summary.annual_plant_count ?? summary.plant_count ?? 0,
    permanentCount: summary.permanent_plant_count ?? 0,
    assignedCount: summary.assigned_plant_count ?? 0,
    manuallyEditedCount: summary.manually_edited_count ?? 0,
    unresolvedCount: summary.unresolved_plant_count ?? 0,
    blockedCount: summary.blocked_plant_count ?? summary.unresolved_plant_count ?? 0,
    cooldownBlokuotaCount: summary.cooldown_blocked_count ?? 0,
    stayingCount: summary.staying_plant_count ?? 0,
  }
}

function normalizeDraft(response) {
  const draft = response?.draft
  const plan = draft?.plan

  if (!draft?.id || !plan || typeof plan !== 'object') {
    throw new Error('The rotation plan was saved, but Yava received an invalid plan response.')
  }

  return {
    ...draft,
    plan: {
      ...plan,
      summary: plan.summary && typeof plan.summary === 'object' ? plan.summary : {},
      plants: Array.isArray(plan.plants) ? plan.plants : [],
    },
  }
}

function zoneName(zone, fallback = 'Unknown zone') {
  return zone?.name || zone?.zone_name || fallback
}

function decisionValueForEntry(entry) {
  const mode = entry.decision_mode ?? (entry.selected_target_zone ? 'generated' : 'unresolved')

  if (mode === 'target') {
    return `zone:${entry.selected_target_zone?.zone_id ?? ''}`
  }

  return mode
}

function payloadForDecision(value, manualNote = '') {
  if (value.startsWith('zone:')) {
    return {
      decision: 'target',
      target_zone_id: Number(value.replace('zone:', '')),
      manual_note: manualNote || null,
    }
  }

  return {
    decision: value,
    target_zone_id: null,
    manual_note: manualNote || null,
  }
}

function firstBlockingReason(candidate) {
  return (candidate?.hard_blocking_reasons ?? candidate?.blocking_reasons ?? []).filter(Boolean)[0] ?? ''
}

function formatRotationReason(reason) {
  if (!reason) {
    return ''
  }

  const normalized = String(reason).replace('ā€”', '—')
  const translations = {
    'Target zone is different from the current zone, which satisfies the basic rotation movement rule.': 'The target zone is different from the current zone, satisfying the basic rotation movement rule.',
    'Target zone has enough space for this plant.': 'The target zone has enough space for this plant.',
    'Target zone does not contain the same plant conflict.': 'The target zone does not contain the same plant conflict.',
    'Soil compatibility data is incomplete.': 'Soil compatibility data is incomplete.',
    'Permanent planting — excluded from annual crop rotation.': 'Permanent planting is excluded from annual crop rotation.',
    'Perennial plant — recommended to stay in its current zone.': 'The perennial plant should remain in its current zone.',
  }

  const sameFamilyMatch = normalized.match(/^Same family was planted here in (\d{4})\.$/)
  if (sameFamilyMatch) {
    return `A plant from the same family was planted here in ${sameFamilyMatch[1]}.`
  }

  return translations[normalized] ?? normalized
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
      setDraft(normalizeDraft(response))
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

  async function handleDraftItemDecision(entry, value, manualNote = entry.manual_note ?? '') {
    if (!draft?.id || !entry.plant?.id) {
      return
    }

    setBusy(true)
    setError('')

    try {
      const response = await api.updateRotationDraftItem(
        plotId,
        draft.id,
        entry.plant.id,
        payloadForDecision(value, manualNote),
      )
      setDraft(normalizeDraft(response))
      setSuccess('Rotation draft updated.')
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
      setSuccess('Rotation draft discarded.')
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
    return <EmptyState title="Plot not found" description="The selected plot could not be loaded." />
  }

  return (
    <div className="page-stack">
      <PlotSectionNav
        plotId={plotId}
        plotName={pageState.data.plot.name}
        sectionKey="rotation"
        isOwner={isOwner}
        description="Crop rotation planning has its own workspace, keeping the editor focused on geometry and planting."
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
            title="View-only rotation access"
            description="You can review saved rotation decisions here, but generating or confirming a plan requires owner or editor access."
          />
        ) : (
          <section className="panel page-stack">
            <div className="plot-page-section-head">
              <div>
                <h2 className="section-title">Generate draft</h2>
                <p className="section-copy">Create a proposed rotation plan, review unresolved plants, and confirm it only when the result is ready to use.</p>
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
            title="Rotation planning requires zones and plants"
            description="Add at least one zone and one planted crop in the editor before generating a rotation draft."
            tone="subtle"
          />
        ) : null}

        {draft ? (
          <section className="panel page-stack">
            <div className="plot-page-section-head">
              <div>
                <h2 className="section-title">Plan draft</h2>
                <p className="section-copy">Review the summary first, then check only the plants that still need attention.</p>
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
                <span className="plot-rotation-stat-label">Annuals</span>
                <strong className="plot-rotation-stat-value">{summary.annualCount}</strong>
              </div>
              <div className="plot-rotation-stat">
                <span className="plot-rotation-stat-label">Perennials</span>
                <strong className="plot-rotation-stat-value">{summary.permanentCount}</strong>
              </div>
              <div className="plot-rotation-stat">
                <span className="plot-rotation-stat-label">Assigned</span>
                <strong className="plot-rotation-stat-value">{summary.assignedCount}</strong>
              </div>
              <div className="plot-rotation-stat">
                <span className="plot-rotation-stat-label">Manual entries</span>
                <strong className="plot-rotation-stat-value">{summary.manuallyEditedCount}</strong>
              </div>
              <div className="plot-rotation-stat">
                <span className="plot-rotation-stat-label">Unresolved</span>
                <strong className="plot-rotation-stat-value">{summary.unresolvedCount}</strong>
              </div>
              <div className="plot-rotation-stat">
                <span className="plot-rotation-stat-label">Blocked</span>
                <strong className="plot-rotation-stat-value">{summary.blockedCount}</strong>
              </div>
              <div className="plot-rotation-stat">
                <span className="plot-rotation-stat-label">Cooldown</span>
                <strong className="plot-rotation-stat-value">{summary.cooldownBlokuotaCount}</strong>
              </div>
              <div className="plot-rotation-stat">
                <span className="plot-rotation-stat-label">Staying</span>
                <strong className="plot-rotation-stat-value">{summary.stayingCount}</strong>
              </div>
            </div>

            {summary.unresolvedCount > 0 ? (
              <span className="field-error">
                This draft cannot be confirmed because {summary.unresolvedCount} annual plants still need a suitable target zone.
              </span>
            ) : summary.annualCount === 0 && summary.permanentCount > 0 ? (
              <p className="section-copy">This plot does not need annual rotation. Permanent plantings are shown for context and can remain in place.</p>
            ) : summary.assignedCount === 0 ? (
              <span className="field-error">A suitable automatic rotation could not be generated for the selected date.</span>
            ) : null}

            <div className="plot-rotation-draft-list">
              {(draft.plan?.plants ?? []).map((entry) => {
                const targetZone = entry.selected_target_zone
                const generatedTargetZone = entry.generated_target_zone
                const isRotatable = entry.is_rotatable ?? true
                const alternatives = entry.alternatives ?? []
                const fallbackSolutions = entry.fallback_solutions ?? []
                const positiveReasons = (targetZone?.positive_reasons ?? targetZone?.passed_reasons ?? []).filter(Boolean)
                const softWarnings = (targetZone?.soft_warnings ?? []).filter(Boolean)
                const hardBlockingReasons = (targetZone?.hard_blocking_reasons ?? targetZone?.blocking_reasons ?? []).filter(Boolean)
                const exclusionReason = entry.exclusion_reason
                const resolutionStatus = entry.resolution_status ?? (targetZone ? 'assigned' : 'unresolved')
                const blockedCandidates = (entry.candidate_zones ?? [])
                  .filter((candidate) => !candidate.is_eligible)
                  .slice(0, 4)
                const selectedDecisionValue = decisionValueForEntry(entry)
                const validCandidates = (entry.candidate_zones ?? []).filter((candidate) => candidate.zone_id)

                return (
                  <article key={entry.plant?.id} className="plot-rotation-draft-item">
                    <div className="plot-rotation-draft-head">
                      <div>
                        <strong>{entry.plant?.name}</strong>
                        <p className="muted">
                          {entry.current_zone?.name ? `Current zone: ${entry.current_zone.name}` : 'Current zone not specified'}
                        </p>
                      </div>
                      <StatusBadge kind="selection" tone={!isRotatable || ['assigned', 'manual_override', 'stays'].includes(resolutionStatus) ? 'success' : 'warning'}>
                        {!isRotatable
                          ? 'Permanent planting'
                          : resolutionStatus === 'blocked'
                            ? 'Blocked'
                            : resolutionStatus === 'stays'
                              ? 'Staying'
                              : targetZone
                                ? `Target: ${targetZone.zone_name}`
                                : 'Needs a suitable target zone'}
                      </StatusBadge>
                    </div>

                    {!isRotatable ? (
                      <p className="plot-rotation-draft-summary">
                        {formatRotationReason(exclusionReason) || 'The perennial plant should remain in its current zone.'}
                      </p>
                    ) : targetZone?.is_stay_decision ? (
                      <p className="plot-rotation-draft-summary">
                        Keep in <strong>{targetZone.zone_name}</strong>.
                      </p>
                    ) : targetZone ? (
                      <p className="plot-rotation-draft-summary">
                        Move to <strong>{targetZone.zone_name}</strong>
                        {generatedTargetZone?.zone_name && generatedTargetZone.zone_name !== targetZone.zone_name
                          ? <> instead of the generated target <strong>{generatedTargetZone.zone_name}</strong>.</>
                          : '.'}
                      </p>
                    ) : (
                      <p className="plot-rotation-draft-summary">
                        This annual plant still needs a suitable target zone. Choose a target, mark it as staying in place, or leave the decision for later.
                      </p>
                    )}

                    {isRotatable ? (
                      <div className="plot-rotation-editor">
                        <div className="field">
                          <label htmlFor={`rotation-decision-${entry.plant?.id}`}>Decision</label>
                          <select
                            id={`rotation-decision-${entry.plant?.id}`}
                            value={selectedDecisionValue}
                            disabled={busy}
                            onChange={(event) => handleDraftItemDecision(entry, event.target.value)}
                          >
                            <option value="generated" disabled={!generatedTargetZone}>
                              {generatedTargetZone ? `Keep generated target: ${generatedTargetZone.zone_name}` : 'No generated target zone'}
                            </option>
                            <option value="stay">Keep in current zone</option>
                            <option value="unresolved">Requires manual decision</option>
                            {validCandidates.map((candidate) => {
                              const blockedReason = formatRotationReason(firstBlockingReason(candidate))

                              return (
                                <option
                                  key={`${entry.plant?.id}-candidate-${candidate.zone_id}`}
                                  value={`zone:${candidate.zone_id}`}
                                  disabled={!candidate.is_eligible}
                                >
                                  {candidate.zone_name}
                                  {candidate.is_eligible ? ` - score ${candidate.score}` : ` - blocked${blockedReason ? `: ${blockedReason}` : ''}`}
                                </option>
                              )
                            })}
                          </select>
                        </div>
                        <div className="field">
                          <label htmlFor={`rotation-note-${entry.plant?.id}`}>Manual note</label>
                          <input
                            id={`rotation-note-${entry.plant?.id}`}
                            defaultValue={entry.manual_note ?? ''}
                            disabled={busy}
                            maxLength={500}
                            placeholder="Optional reason for the change"
                            onBlur={(event) => {
                              if ((entry.manual_note ?? '') !== event.target.value) {
                                handleDraftItemDecision(entry, selectedDecisionValue, event.target.value)
                              }
                            }}
                          />
                        </div>
                      </div>
                    ) : null}

                    {hardBlockingReasons.length > 0 ? (
                      <div className="plot-rotation-warning-list">
                        {hardBlockingReasons.slice(0, 3).map((reason) => (
                          <span key={`${entry.plant?.id}-blocking-${reason}`} className="field-error">{formatRotationReason(reason)}</span>
                        ))}
                      </div>
                    ) : null}

                    {(exclusionReason || positiveReasons.length > 0 || softWarnings.length > 0 || alternatives.length > 0 || fallbackSolutions.length > 0 || blockedCandidates.length > 0) ? (
                      <details className="task-card-details">
                        <summary>Why this recommendation is suggested</summary>
                        <div className="task-card-detail-stack">
                          {exclusionReason ? (
                            <div className="task-card-detail-block">
                              <strong>Rotation scope</strong>
                              <div className="task-card-resource-list">
                                <div className="task-card-resource-row">
                                  <span>{formatRotationReason(exclusionReason)}</span>
                                </div>
                              </div>
                            </div>
                          ) : null}

                          {targetZone && positiveReasons.length > 0 ? (
                            <div className="task-card-detail-block">
                              <strong>Positive reasons</strong>
                              <div className="task-card-resource-list">
                                {positiveReasons.slice(0, 3).map((reason) => (
                                  <div key={`${entry.plant?.id}-${reason}`} className="task-card-resource-row">
                                    <span>{formatRotationReason(reason)}</span>
                                  </div>
                                ))}
                              </div>
                            </div>
                          ) : null}

                          {targetZone && softWarnings.length > 0 ? (
                            <div className="task-card-detail-block">
                              <strong>Warnings</strong>
                              <div className="task-card-resource-list">
                                {softWarnings.slice(0, 3).map((warning) => (
                                  <div key={`${entry.plant?.id}-${warning}`} className="task-card-resource-row">
                                    <span>{formatRotationReason(warning)}</span>
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
                                    <span>{(candidate.hard_blocking_reasons ?? candidate.blocking_reasons ?? []).slice(0, 2).map(formatRotationReason).join('; ')}</span>
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
                                    <span>{formatRotationReason(solution)}</span>
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
                <Button onClick={handleConfirm} disabled={planStatus !== 'ready'} loading={busy}>
                  Confirm rotation plan
                </Button>
                <Button variant="ghost" onClick={handleReject} disabled={busy}>Discard draft</Button>
              </div>
            ) : null}
          </section>
        ) : (
          <EmptyStatePanel
            title="No active draft"
            description="Generate a draft when you want to evaluate zone changes for this plot."
            tone="subtle"
          />
        )}

        <section className="panel page-stack">
          <div className="plot-page-section-head">
            <div>
              <h2 className="section-title">Saved rotation history</h2>
              <p className="section-copy">Confirmed rotation moves remain here as a long-term record of planting decisions.</p>
            </div>
            <StatusBadge kind="selection" tone="neutral">{pageState.data.history.length} records</StatusBadge>
          </div>

          {pageState.data.history.length === 0 ? (
            <EmptyStatePanel
              title="No rotation history yet"
              description="Confirm a rotation draft to begin the long-term rotation history for this plot."
              tone="subtle"
            />
          ) : (
            <div className="plot-rotation-history-list">
              {pageState.data.history.map((entry) => (
                <article key={entry.id} className="plot-rotation-history-item">
                  <div className="plot-rotation-history-main">
                    <strong>{entry.plant?.name ?? 'Plant removed'}</strong>
                    <div className="plot-rotation-history-zones">
                      <span>
                        <span className="plot-rotation-history-label">From zone</span>
                        <strong>{zoneName(entry.from_zone)}</strong>
                      </span>
                      <span>
                        <span className="plot-rotation-history-label">To zone</span>
                        <strong>{zoneName(entry.to_zone ?? entry.plant_zone)}</strong>
                      </span>
                    </div>
                    {entry.decision_note ? <p className="muted">{entry.decision_note}</p> : null}
                  </div>
                  <span className="plot-rotation-history-date" title={entry.decision_status ?? undefined}>
                    Rotated {formatDate(entry.from_date)}
                    {entry.to_date ? ` through ${formatDate(entry.to_date)}` : ''}
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
