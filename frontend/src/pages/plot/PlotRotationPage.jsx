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

function zoneName(zone, fallback = 'Nežinoma zona') {
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
    'Target zone is different from the current zone, which satisfies the basic rotation movement rule.': 'Tikslinė zona skiriasi nuo dabartinės, todėl atitinka pagrindinę rotacijos perkėlimo taisyklę.',
    'Target zone has enough space for this plant.': 'Tikslinėje zonoje pakanka vietos šiam augalui.',
    'Target zone does not contain the same plant conflict.': 'Tikslinėje zonoje nėra konflikto su tuo pačiu augalu.',
    'Soil compatibility data is incomplete.': 'Dirvožemio suderinamumo duomenys yra nepilni.',
    'Permanent planting — excluded from annual crop rotation.': 'Daugiametis sodinimas – neįtraukiamas į metinę rotaciją.',
    'Daugiametis sodinimas — excluded from annual crop rotation.': 'Daugiametis sodinimas – neįtraukiamas į metinę rotaciją.',
    'Perennial plant — recommended to stay in its current zone.': 'Daugiametį augalą rekomenduojama palikti dabartinėje zonoje.',
  }

  const sameFamilyMatch = normalized.match(/^Same family was planted here in (\d{4})\.$/)
  if (sameFamilyMatch) {
    return `Tos pačios šeimos augalas čia sodintas ${sameFamilyMatch[1]} m.`
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
      setSuccess('Rotacijos juodraštis sugeneruotas.')
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
      setSuccess('Rotacijos planas patvirtintas.')
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
      setSuccess('Rotacijos juodraštis atnaujintas.')
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
      setSuccess('Rotacijos juodraštis atmestas.')
    } catch (requestError) {
      setError(requestError.message)
    } finally {
      setBusy(false)
    }
  }

  if (pageState.loading) {
    return <LoadingState title="Įkeliamas rotacijos planavimas..." />
  }

  if (pageState.error) {
    return <ErrorState error={pageState.error} onRetry={pageState.reload} />
  }

  if (!pageState.data.plot) {
    return <EmptyState title="Sklypas nerastas" description="Pasirinkto sklypo nepavyko įkelti." />
  }

  return (
    <div className="page-stack">
      <PlotSectionNav
        plotId={plotId}
        plotName={pageState.data.plot.name}
        sectionKey="rotation"
        isOwner={isOwner}
        description="Augalų rotacijos planavimas laikomas atskiroje darbo srityje, kad redaktorius išliktų skirtas geometrijai ir sodinimui."
        meta={(
          <>
            <StatusBadge kind="selection" tone="neutral">{pageState.data.zones.length} zonos</StatusBadge>
            <StatusBadge kind="selection" tone="neutral">{pageState.data.plants.length} augalai</StatusBadge>
          </>
        )}
      />
      <SuccessToast message={success} onDismiss={() => setSuccess('')} />

      <div className="page-stack">
        {!canEdit ? (
          <EmptyState
            title="Tik rotacijos peržiūra"
            description="Čia galite peržiūrėti išsaugotus rotacijos sprendimus, bet planui generuoti arba patvirtinti reikia savininko ar redaktoriaus teisės."
          />
        ) : (
          <section className="panel page-stack">
            <div className="plot-page-section-head">
              <div>
                <h2 className="section-title">Generuoti juodraštį</h2>
                <p className="section-copy">Sukurkite vieną siūlomą rotacijos planą, peržiūrėkite neišspręstus augalus ir patvirtinkite tik tada, kai rezultatas tinkamas naudoti.</p>
              </div>
            </div>

            <form className="plot-rotation-toolbar" onSubmit={handleGenerate}>
              <div className="field">
                <label htmlFor="rotation-planning-date">Planavimo data</label>
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
                  {busy ? 'Generuojamas juodraštis' : 'Generuoti rotacijos juodraštį'}
                </Button>
              </div>
            </form>
            {error ? <span className="field-error">{error}</span> : null}
          </section>
        )}

        {pageState.data.plants.length === 0 || pageState.data.zones.length === 0 ? (
          <EmptyStatePanel
            title="Rotacijos planavimui reikia zonų ir augalų"
            description="Prieš generuodami rotacijos juodraštį redaktoriuje pridėkite bent vieną zoną ir vieną pasodintą augalą."
            tone="subtle"
          />
        ) : null}

        {draft ? (
          <section className="panel page-stack">
            <div className="plot-page-section-head">
              <div>
                <h2 className="section-title">Plano juodraštis</h2>
                <p className="section-copy">Pirmiausia peržiūrėkite santrauką, tada tikrinkite tik tuos augalus, kuriems dar reikia dėmesio.</p>
              </div>
              <StatusBadge kind="status" tone={planStatus === 'ready' ? 'success' : 'warning'}>
                {planStatus === 'ready' ? 'Paruošta patvirtinti' : 'Reikia korekcijos'}
              </StatusBadge>
            </div>

            <div className="plot-rotation-summary">
              <div className="plot-rotation-stat">
                <span className="plot-rotation-stat-label">Augalai</span>
                <strong className="plot-rotation-stat-value">{summary.plantCount}</strong>
              </div>
              <div className="plot-rotation-stat">
                <span className="plot-rotation-stat-label">Vienmečiai</span>
                <strong className="plot-rotation-stat-value">{summary.annualCount}</strong>
              </div>
              <div className="plot-rotation-stat">
                <span className="plot-rotation-stat-label">Daugiamečiai</span>
                <strong className="plot-rotation-stat-value">{summary.permanentCount}</strong>
              </div>
              <div className="plot-rotation-stat">
                <span className="plot-rotation-stat-label">Priskirta</span>
                <strong className="plot-rotation-stat-value">{summary.assignedCount}</strong>
              </div>
              <div className="plot-rotation-stat">
                <span className="plot-rotation-stat-label">Rankinis įrašas</span>
                <strong className="plot-rotation-stat-value">{summary.manuallyEditedCount}</strong>
              </div>
              <div className="plot-rotation-stat">
                <span className="plot-rotation-stat-label">Neišspręsta</span>
                <strong className="plot-rotation-stat-value">{summary.unresolvedCount}</strong>
              </div>
              <div className="plot-rotation-stat">
                <span className="plot-rotation-stat-label">Blokuota</span>
                <strong className="plot-rotation-stat-value">{summary.blockedCount}</strong>
              </div>
              <div className="plot-rotation-stat">
                <span className="plot-rotation-stat-label">Pertrauka</span>
                <strong className="plot-rotation-stat-value">{summary.cooldownBlokuotaCount}</strong>
              </div>
              <div className="plot-rotation-stat">
                <span className="plot-rotation-stat-label">Lieka</span>
                <strong className="plot-rotation-stat-value">{summary.stayingCount}</strong>
              </div>
            </div>

            {summary.unresolvedCount > 0 ? (
              <span className="field-error">
                Šio juodraščio patvirtinti negalima, nes {summary.unresolvedCount} vienmečiams augalams dar reikia tinkamos tikslinės zonos.
              </span>
            ) : summary.annualCount === 0 && summary.permanentCount > 0 ? (
              <p className="section-copy">Šiam sklypui metinė rotacija nereikalinga. Daugiametis sodinimas rodomas kontekstui ir gali likti vietoje.</p>
            ) : summary.assignedCount === 0 ? (
              <span className="field-error">Pasirinktai datai nepavyko sugeneruoti tinkamos automatinės rotacijos.</span>
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
                          {entry.current_zone?.name ? `Dabartinė zona: ${entry.current_zone.name}` : 'Dabartinė zona nenurodyta'}
                        </p>
                      </div>
                      <StatusBadge kind="selection" tone={!isRotatable || ['assigned', 'manual_override', 'stays'].includes(resolutionStatus) ? 'success' : 'warning'}>
                        {!isRotatable
                          ? 'Daugiametis sodinimas'
                          : resolutionStatus === 'blocked'
                            ? 'Blokuota'
                            : resolutionStatus === 'stays'
                              ? 'Lieka'
                              : targetZone
                                ? `Tikslas: ${targetZone.zone_name}`
                                : 'Reikia tinkamos tikslinės zonos'}
                      </StatusBadge>
                    </div>

                    {!isRotatable ? (
                      <p className="plot-rotation-draft-summary">
                        {formatRotationReason(exclusionReason) || 'Daugiametį augalą rekomenduojama palikti dabartinėje zonoje.'}
                      </p>
                    ) : targetZone?.is_stay_decision ? (
                      <p className="plot-rotation-draft-summary">
                        Palikti zonoje <strong>{targetZone.zone_name}</strong>.
                      </p>
                    ) : targetZone ? (
                      <p className="plot-rotation-draft-summary">
                        Perkelti į <strong>{targetZone.zone_name}</strong>
                        {generatedTargetZone?.zone_name && generatedTargetZone.zone_name !== targetZone.zone_name
                          ? <> vietoje sugeneruoto tikslo <strong>{generatedTargetZone.zone_name}</strong>.</>
                          : '.'}
                      </p>
                    ) : (
                      <p className="plot-rotation-draft-summary">
                        Šiam vienmečiam augalui dar reikia tinkamos tikslinės zonos. Pasirinkite tikslą, pažymėkite, kad jis lieka vietoje, arba palikite sprendimą vėlesniam laikui.
                      </p>
                    )}

                    {isRotatable ? (
                      <div className="plot-rotation-editor">
                        <div className="field">
                          <label htmlFor={`rotation-decision-${entry.plant?.id}`}>Sprendimas</label>
                          <select
                            id={`rotation-decision-${entry.plant?.id}`}
                            value={selectedDecisionValue}
                            disabled={busy}
                            onChange={(event) => handleDraftItemDecision(entry, event.target.value)}
                          >
                            <option value="generated" disabled={!generatedTargetZone}>
                              {generatedTargetZone ? `Palikti sugeneruotą: ${generatedTargetZone.zone_name}` : 'Sugeneruotos tikslinės zonos nėra'}
                            </option>
                            <option value="stay">Palikti dabartinėje zonoje</option>
                            <option value="unresolved">Reikia rankinio sprendimo</option>
                            {validCandidates.map((candidate) => {
                              const blockedReason = formatRotationReason(firstBlockingReason(candidate))

                              return (
                                <option
                                  key={`${entry.plant?.id}-candidate-${candidate.zone_id}`}
                                  value={`zone:${candidate.zone_id}`}
                                  disabled={!candidate.is_eligible}
                                >
                                  {candidate.zone_name}
                                  {candidate.is_eligible ? ` - balas ${candidate.score}` : ` - blokuota${blockedReason ? `: ${blockedReason}` : ''}`}
                                </option>
                              )
                            })}
                          </select>
                        </div>
                        <div className="field">
                          <label htmlFor={`rotation-note-${entry.plant?.id}`}>Rankinė pastaba</label>
                          <input
                            id={`rotation-note-${entry.plant?.id}`}
                            defaultValue={entry.manual_note ?? ''}
                            disabled={busy}
                            maxLength={500}
                            placeholder="Pasirinktinė pakeitimo priežastis"
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
                        <summary>Kodėl siūloma ši rekomendacija</summary>
                        <div className="task-card-detail-stack">
                          {exclusionReason ? (
                            <div className="task-card-detail-block">
                              <strong>Rotacijos apimtis</strong>
                              <div className="task-card-resource-list">
                                <div className="task-card-resource-row">
                                  <span>{formatRotationReason(exclusionReason)}</span>
                                </div>
                              </div>
                            </div>
                          ) : null}

                          {targetZone && positiveReasons.length > 0 ? (
                            <div className="task-card-detail-block">
                              <strong>Teigiamos priežastys</strong>
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
                              <strong>Įspėjimai</strong>
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
                              <strong>Alternatyvios zonos</strong>
                              <div className="task-card-resource-list">
                                {alternatives.slice(0, 3).map((alternative) => (
                                  <div key={`${entry.plant?.id}-${alternative.zone_id}`} className="task-card-resource-row">
                                    <span>{alternative.zone_name}</span>
                                    <span>Balas {alternative.score}</span>
                                  </div>
                                ))}
                              </div>
                            </div>
                          ) : null}

                          {!targetZone && blockedCandidates.length > 0 ? (
                            <div className="task-card-detail-block">
                              <strong>Blokuotos kandidatės zonos</strong>
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
                              <strong>Atsarginiai pasiūlymai</strong>
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
                  Patvirtinti rotacijos planą
                </Button>
                <Button variant="ghost" onClick={handleReject} disabled={busy}>Atmesti juodraštį</Button>
              </div>
            ) : null}
          </section>
        ) : (
          <EmptyStatePanel
            title="Aktyvaus juodraščio nėra"
            description="Sugeneruokite juodraštį, kai norite įvertinti zonų pakeitimus šiame sklype."
            tone="subtle"
          />
        )}

        <section className="panel page-stack">
          <div className="plot-page-section-head">
            <div>
              <h2 className="section-title">Išsaugota rotacijos istorija</h2>
              <p className="section-copy">Patvirtinti rotacijos perkėlimai čia išlieka kaip ilgalaikis sodinimo sprendimų įrašas.</p>
            </div>
            <StatusBadge kind="selection" tone="neutral">{pageState.data.history.length} įrašai</StatusBadge>
          </div>

          {pageState.data.history.length === 0 ? (
            <EmptyStatePanel
              title="Rotacijos istorijos dar nėra"
              description="Patvirtinkite rotacijos juodraštį, kad būtų pradėta ilgalaikė šio sklypo rotacijos istorija."
              tone="subtle"
            />
          ) : (
            <div className="plot-rotation-history-list">
              {pageState.data.history.map((entry) => (
                <article key={entry.id} className="plot-rotation-history-item">
                  <div className="plot-rotation-history-main">
                    <strong>{entry.plant?.name ?? 'Augalas pašalintas'}</strong>
                    <div className="plot-rotation-history-zones">
                      <span>
                        <span className="plot-rotation-history-label">Iš zonos</span>
                        <strong>{zoneName(entry.from_zone)}</strong>
                      </span>
                      <span>
                        <span className="plot-rotation-history-label">Į zoną</span>
                        <strong>{zoneName(entry.to_zone ?? entry.plant_zone)}</strong>
                      </span>
                    </div>
                    {entry.decision_note ? <p className="muted">{entry.decision_note}</p> : null}
                  </div>
                  <span className="plot-rotation-history-date" title={entry.decision_status ?? undefined}>
                    Rotuota {formatDate(entry.from_date)}
                    {entry.to_date ? ` iki ${formatDate(entry.to_date)}` : ''}
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
