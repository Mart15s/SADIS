import { useMemo, useState } from 'react'
import { Link, useLocation, useParams } from 'react-router-dom'
import PageHeader from '../../components/layout/PageHeader.jsx'
import { EmptyState, ErrorState, LoadingState } from '../../components/shared/StatusView.jsx'
import Button from '../../components/ui/Button.jsx'
import { api } from '../../lib/api.js'
import { CONDITION_TYPES, formatDate, formatDateTime } from '../../lib/constants.js'
import { useAsyncData } from '../../lib/hooks/useAsyncData.js'

const initialConditionForm = {
  measured_at: new Date().toISOString().slice(0, 10),
  notes: '',
  photo_url: '',
  condition: CONDITION_TYPES[6],
  disease: '',
}

function valueOrFallback(value, suffix = '') {
  if (value === null || value === undefined || value === '') {
    return 'Not set'
  }

  return `${value}${suffix}`
}

function createReviewForm(task) {
  const review = task?.workflow_context?.review ?? {}

  return {
    action: 'confirm',
    condition: review.target_condition ?? '',
    measured_at: task?.date ?? new Date().toISOString().slice(0, 10),
    notes: '',
  }
}

export default function PlantDetailPage() {
  const location = useLocation()
  const { plotId, plantId } = useParams()
  const [conditionForm, setConditionForm] = useState(initialConditionForm)
  const [reviewTask, setReviewTask] = useState(location.state?.pendingReviewTask ?? null)
  const [reviewForm, setReviewForm] = useState(createReviewForm(location.state?.pendingReviewTask))
  const [error, setError] = useState('')
  const [notice, setNotice] = useState(location.state?.notice ?? '')
  const [submittingCondition, setSubmittingCondition] = useState(false)
  const [submittingReview, setSubmittingReview] = useState(false)

  const pageState = useAsyncData(
    async () => {
      const plots = await api.listPlots()
      const plant = plotId
        ? await api.getPlant(plotId, plantId)
        : await api.getManagedPlant(plantId)
      const resolvedPlotId = String(plotId ?? plant.plot?.id ?? plant.fk_plot_id ?? '')
      const accessRole = plots.find((entry) => String(entry.id) === resolvedPlotId)?.access_role ?? null
      const [conditions, harvests, rotations] = resolvedPlotId
        ? await Promise.all([
          api.listPlantConditions(resolvedPlotId, plantId),
          api.listHarvests(resolvedPlotId, { plant_id: plantId }),
          api.listRotations(resolvedPlotId),
        ])
        : [[], [], []]

      return {
        plant,
        conditions,
        harvests,
        rotations: rotations.filter((rotation) => String(rotation.fk_plant_id) === String(plantId)),
        accessRole,
        resolvedPlotId,
      }
    },
    [plotId, plantId],
    {
      plant: null,
      conditions: [],
      harvests: [],
      rotations: [],
      accessRole: null,
      resolvedPlotId: '',
    },
  )

  const plant = pageState.data.plant
  const resolvedPlotId = pageState.data.resolvedPlotId
  const canEdit = ['owner', 'editor'].includes(pageState.data.accessRole)
  const linkedZone = plant?.plantZone ?? plant?.plant_zone ?? null
  const linkedCare = plant?.plantCare ?? plant?.plant_care ?? null
  const linkedCatalogPlant = plant?.catalogPlant ?? plant?.catalog_plant ?? null
  const lifecycle = plant?.lifecycle ?? null

  const backTarget = useMemo(() => {
    if (location.state?.backTo) {
      return {
        to: location.state.backTo,
        label: location.state.backLabel ?? 'Back',
      }
    }

    if (plotId) {
      return {
        to: `/plots/${plotId}`,
        label: 'Back to plot',
      }
    }

    return {
      to: '/plants',
      label: 'Back to plants',
    }
  }, [location.state, plotId, resolvedPlotId])

  async function handleConditionSubmit(event) {
    event.preventDefault()
    if (!resolvedPlotId) {
      return
    }

    setSubmittingCondition(true)
    setError('')
    setNotice('')

    try {
      const created = await api.createPlantCondition(resolvedPlotId, plantId, {
        ...conditionForm,
        notes: conditionForm.notes || null,
        photo_url: conditionForm.photo_url || null,
        disease: conditionForm.disease === '' ? null : conditionForm.disease === 'true',
      })

      pageState.setData((current) => ({
        ...current,
        plant: current.plant
          ? {
            ...current.plant,
            condition: created.condition,
            lifecycle: current.plant.lifecycle
              ? {
                ...current.plant.lifecycle,
                current_condition: created.condition,
                latest_condition_entry: {
                  id: created.id,
                  measured_at: created.measured_at,
                  condition: created.condition,
                  notes: created.notes,
                },
              }
              : current.plant.lifecycle,
          }
          : current.plant,
        conditions: [created, ...current.conditions],
      }))

      setConditionForm({
        ...initialConditionForm,
        condition: created.condition ?? initialConditionForm.condition,
      })
      setNotice('Condition logged successfully.')
      await pageState.reload()
    } catch (requestError) {
      setError(requestError.message)
    } finally {
      setSubmittingCondition(false)
    }
  }

  async function handleReviewSubmit(event) {
    event.preventDefault()
    if (!reviewTask) {
      return
    }

    setSubmittingReview(true)
    setError('')
    setNotice('')

    try {
      const payload = {
        condition_review: {
          action: reviewForm.action,
          measured_at: reviewForm.measured_at,
          notes: reviewForm.notes || null,
        },
      }

      if (reviewForm.action === 'adjust') {
        payload.condition_review.condition = reviewForm.condition
      }

      const response = await api.completeTask(reviewTask.id, payload)
      const entry = response.condition_history_entry

      pageState.setData((current) => ({
        ...current,
        plant: current.plant && entry
          ? {
            ...current.plant,
            condition: entry.condition,
            lifecycle: current.plant.lifecycle
              ? {
                ...current.plant.lifecycle,
                current_condition: entry.condition,
                latest_condition_entry: {
                  id: entry.id,
                  measured_at: entry.measured_at,
                  condition: entry.condition,
                  notes: entry.notes,
                },
              }
              : current.plant.lifecycle,
          }
          : current.plant,
        conditions: entry ? [entry, ...current.conditions] : current.conditions,
      }))

      setReviewTask(null)
      setReviewForm(createReviewForm(null))
      setNotice('Lifecycle review completed successfully.')
      await pageState.reload()
    } catch (requestError) {
      setError(requestError.message)
    } finally {
      setSubmittingReview(false)
    }
  }

  if (pageState.loading) {
    return <LoadingState title="Loading plant detail..." />
  }

  if (pageState.error) {
    return <ErrorState error={pageState.error} onRetry={pageState.reload} />
  }

  if (!plant) {
    return <EmptyState title="Plant not found" description="The requested plant could not be loaded." />
  }

  return (
    <div className="page-stack">
      <PageHeader
        title={plant.name}
        description={`${plant.plant_type} in ${plant.plot?.name ?? 'Unknown plot'} / ${linkedZone?.name ?? 'Unknown zone'}`}
        actions={(
          <>
            <Link to={backTarget.to}>
              <Button variant="secondary">{backTarget.label}</Button>
            </Link>
            {resolvedPlotId && !plotId ? (
              <Link to={`/plots/${resolvedPlotId}`}>
                <Button variant="ghost">Open plot</Button>
              </Link>
            ) : null}
            {canEdit ? (
              <Link to={`/plants/${plant.id}/edit`}>
                <Button>Edit</Button>
              </Link>
            ) : null}
          </>
        )}
      />

      {notice ? <div className="inline-note">{notice}</div> : null}
      {error ? <span className="field-error">{error}</span> : null}

      {reviewTask ? (
        <section className="panel page-stack">
          <div>
            <h3 className="section-title">Pending Lifecycle Review</h3>
            <p className="section-copy">This review task was opened from the calendar and will update the plant’s confirmed condition once you submit the review.</p>
          </div>
          <div className="meta-cluster">
            <span>Task {reviewTask.name}</span>
            <span>Suggested {reviewTask.workflow_context?.review?.target_condition ?? 'Not set'}</span>
            <span>Expected {formatDate(reviewTask.workflow_context?.review?.expected_on ?? reviewTask.date)}</span>
          </div>
          <form className="input-grid" onSubmit={handleReviewSubmit}>
            <div className="field">
              <label htmlFor="review-action">Decision</label>
              <select
                id="review-action"
                value={reviewForm.action}
                onChange={(event) => setReviewForm((current) => ({
                  ...current,
                  action: event.target.value,
                  condition: event.target.value === 'confirm'
                    ? (reviewTask.workflow_context?.review?.target_condition ?? current.condition)
                    : current.condition,
                }))}
              >
                <option value="confirm">Confirm suggested transition</option>
                <option value="keep_current">Keep current stage</option>
                <option value="adjust">Adjust manually</option>
              </select>
            </div>
            {reviewForm.action === 'adjust' ? (
              <div className="field">
                <label htmlFor="review-condition">Condition</label>
                <select
                  id="review-condition"
                  value={reviewForm.condition}
                  onChange={(event) => setReviewForm((current) => ({ ...current, condition: event.target.value }))}
                  required
                >
                  {CONDITION_TYPES.map((condition) => (
                    <option key={condition} value={condition}>
                      {condition}
                    </option>
                  ))}
                </select>
              </div>
            ) : null}
            <div className="field">
              <label htmlFor="review-date">Reviewed on</label>
              <input
                id="review-date"
                type="date"
                value={reviewForm.measured_at}
                onChange={(event) => setReviewForm((current) => ({ ...current, measured_at: event.target.value }))}
                required
              />
            </div>
            <div className="field field-span-2">
              <label htmlFor="review-notes">Notes</label>
              <textarea
                id="review-notes"
                value={reviewForm.notes}
                onChange={(event) => setReviewForm((current) => ({ ...current, notes: event.target.value }))}
              />
            </div>
            <div className="form-actions">
              <Button type="submit" disabled={submittingReview}>
                {submittingReview ? 'Submitting review...' : 'Complete review'}
              </Button>
              <Button
                variant="secondary"
                onClick={() => {
                  setReviewTask(null)
                  setReviewForm(createReviewForm(null))
                }}
              >
                Dismiss panel
              </Button>
            </div>
          </form>
        </section>
      ) : null}

      <div className="detail-grid">
        <section className="panel page-stack">
          <div>
            <h3 className="section-title">Plant Overview</h3>
            <p className="section-copy">Authoritative detail view for this planted instance, regardless of whether it was opened from the plot workspace or from the plants list.</p>
          </div>

          <div className="meta-cluster">
            <span>Condition {plant.condition}</span>
            <span>Planted {formatDate(plant.plant_date)}</span>
            <span>Plot {plant.plot?.name ?? 'Unknown'}</span>
            <span>Zone {linkedZone?.name ?? 'Unknown'}</span>
            <span>Catalog {linkedCatalogPlant?.name ?? 'Not linked'}</span>
            <span>Disease {plant.disease ? 'Yes' : 'No'}</span>
          </div>

          <div className="form-grid plants-detail-grid">
            <div className="card">
              <strong>Growing time</strong>
              <span className="muted">{valueOrFallback(plant.growing_time_days, ' days')}</span>
            </div>
            <div className="card">
              <strong>Recommended temperature</strong>
              <span className="muted">{valueOrFallback(plant.recommended_temperature, ' C')}</span>
            </div>
            <div className="card">
              <strong>Recommended humidity</strong>
              <span className="muted">{valueOrFallback(plant.recommended_humidity, '%')}</span>
            </div>
            <div className="card">
              <strong>Rest time</strong>
              <span className="muted">{valueOrFallback(plant.rest_time_days, ' days')}</span>
            </div>
            <div className="card">
              <strong>Plant size</strong>
              <span className="muted">{valueOrFallback(plant.plant_size)}</span>
            </div>
            <div className="card">
              <strong>Linked care profile</strong>
              <span className="muted">{plant.fk_plant_care_id ?? linkedCare?.id ?? 'Not linked'}</span>
            </div>
          </div>

          {plant.disease_notes ? <div className="inline-note">{plant.disease_notes}</div> : null}

          {linkedCatalogPlant ? (
            <div className="row-actions">
              <Link to={`/plants/catalog/${linkedCatalogPlant.id}`}>
                <Button variant="ghost">Open catalog plant</Button>
              </Link>
              <Link to={`/plants/catalog/${linkedCatalogPlant.id}/edit`}>
                <Button variant="secondary">Edit shared care</Button>
              </Link>
            </div>
          ) : null}

          <section className="panel page-stack">
            <div>
              <h3 className="section-title">Lifecycle Guidance</h3>
              <p className="section-copy">Expected lifecycle checkpoints are derived from the linked plant care durations. They guide review tasks, but they do not silently change the confirmed plant condition.</p>
            </div>
            {lifecycle ? (
              <>
                <div className="meta-cluster">
                  <span>Confirmed stage {lifecycle.current_condition}</span>
                  <span>Anchor date {formatDate(lifecycle.current_condition_anchor_date)}</span>
                  <span>Regenerating path {lifecycle.supports_regeneration ? 'Supported' : 'No'}</span>
                </div>
                {lifecycle.next_review ? (
                  <div className="card">
                    <strong>Next review</strong>
                    <span className="muted">
                      Review transition to {lifecycle.next_review.target_condition} on {formatDate(lifecycle.next_review.expected_on)}
                      {lifecycle.next_review.is_overdue ? ' (overdue)' : ''}
                    </span>
                  </div>
                ) : null}
                {lifecycle.next_harvest ? (
                  <div className="card">
                    <strong>Next harvest checkpoint</strong>
                    <span className="muted">
                      Harvest expected on {formatDate(lifecycle.next_harvest.expected_on)}
                      {lifecycle.next_harvest.is_overdue ? ' (overdue)' : ''}
                    </span>
                  </div>
                ) : null}
                <div className="page-stack" style={{ gap: '0.35rem' }}>
                  {Object.entries(lifecycle.scheduled_stage_starts ?? {}).map(([condition, date]) => (
                    <span key={condition} className="muted">
                      {condition}: {formatDate(date)}
                    </span>
                  ))}
                </div>
              </>
            ) : (
              <EmptyState title="No lifecycle guidance" description="Link a plant care profile to derive expected stage transitions." />
            )}
          </section>
        </section>

        <aside className="page-stack">
          <section className="panel page-stack">
            <div>
              <h3 className="section-title">Effective Care Profile</h3>
              <p className="section-copy">The shared plant care record used to derive lifecycle guidance and recommendation tasks.</p>
            </div>

            {linkedCare ? (
              <div className="form-grid plants-detail-grid">
                <div className="card">
                  <strong>Watering interval</strong>
                  <span className="muted">{valueOrFallback(linkedCare.watering_interval_days, ' days')}</span>
                </div>
                <div className="card">
                  <strong>Fertilizing interval</strong>
                  <span className="muted">{valueOrFallback(linkedCare.fertilizing_interval_days, ' days')}</span>
                </div>
                <div className="card">
                  <strong>Pest check interval</strong>
                  <span className="muted">{valueOrFallback(linkedCare.pest_check_interval_days, ' days')}</span>
                </div>
                <div className="card">
                  <strong>Germinating duration</strong>
                  <span className="muted">{valueOrFallback(linkedCare.germinating_duration_days, ' days')}</span>
                </div>
                <div className="card">
                  <strong>Growing duration</strong>
                  <span className="muted">{valueOrFallback(linkedCare.growing_duration_days, ' days')}</span>
                </div>
                <div className="card">
                  <strong>Flowering duration</strong>
                  <span className="muted">{valueOrFallback(linkedCare.flowering_duration_days, ' days')}</span>
                </div>
                <div className="card">
                  <strong>Mature duration</strong>
                  <span className="muted">{valueOrFallback(linkedCare.mature_duration_days, ' days')}</span>
                </div>
                <div className="card">
                  <strong>Regenerating duration</strong>
                  <span className="muted">{valueOrFallback(linkedCare.regenerating_duration_days, ' days')}</span>
                </div>
              </div>
            ) : (
              <EmptyState title="No plant care linked" description="This plant does not currently have a linked care profile." />
            )}
          </section>

          <section className="panel page-stack">
            <div>
              <h3 className="section-title">Condition History</h3>
              <p className="section-copy">Confirmed condition updates are recorded here and remain the source of truth for the current plant condition.</p>
            </div>
            {pageState.data.conditions.length === 0 ? (
              <EmptyState title="No condition history" description="No condition logs exist for this plant yet." />
            ) : (
              <div className="table-wrap">
                <table>
                  <thead>
                    <tr>
                      <th>Measured</th>
                      <th>Condition</th>
                      <th>Notes</th>
                    </tr>
                  </thead>
                  <tbody>
                    {pageState.data.conditions.map((entry) => (
                      <tr key={entry.id}>
                        <td>{formatDateTime(entry.measured_at)}</td>
                        <td>{entry.condition}</td>
                        <td>{entry.notes || 'No notes'}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}

            {canEdit && resolvedPlotId ? (
              <form className="input-grid" onSubmit={handleConditionSubmit}>
                <div className="field">
                  <label htmlFor="condition-date">Measured at</label>
                  <input
                    id="condition-date"
                    type="date"
                    value={conditionForm.measured_at}
                    onChange={(event) => setConditionForm((current) => ({ ...current, measured_at: event.target.value }))}
                    required
                  />
                </div>
                <div className="field">
                  <label htmlFor="condition-type">Condition</label>
                  <select
                    id="condition-type"
                    value={conditionForm.condition}
                    onChange={(event) => setConditionForm((current) => ({ ...current, condition: event.target.value }))}
                  >
                    {CONDITION_TYPES.map((condition) => (
                      <option key={condition} value={condition}>
                        {condition}
                      </option>
                    ))}
                  </select>
                </div>
                <div className="field">
                  <label htmlFor="condition-disease">Disease present</label>
                  <select
                    id="condition-disease"
                    value={conditionForm.disease}
                    onChange={(event) => setConditionForm((current) => ({ ...current, disease: event.target.value }))}
                  >
                    <option value="">Infer from condition</option>
                    <option value="false">No</option>
                    <option value="true">Yes</option>
                  </select>
                </div>
                <div className="field field-span-2">
                  <label htmlFor="condition-notes">Notes</label>
                  <textarea
                    id="condition-notes"
                    value={conditionForm.notes}
                    onChange={(event) => setConditionForm((current) => ({ ...current, notes: event.target.value }))}
                  />
                </div>
                <div className="form-actions">
                  <Button type="submit" disabled={submittingCondition}>
                    {submittingCondition ? 'Saving...' : 'Log condition'}
                  </Button>
                </div>
              </form>
            ) : null}
          </section>

          <section className="panel page-stack">
            <div>
              <h3 className="section-title">Harvest History</h3>
              <p className="section-copy">Explicit harvest records persist here and feed harvest analytics later.</p>
            </div>
            {resolvedPlotId ? (
              <div className="row-actions">
                <Link to={`/plots/${resolvedPlotId}/harvests?plantId=${plant.id}`}>
                  <Button variant="secondary">Open harvest workflow</Button>
                </Link>
              </div>
            ) : null}
            {pageState.data.harvests.length === 0 ? (
              <EmptyState title="No harvest history" description="No harvest records are stored for this plant yet." />
            ) : (
              <div className="table-wrap">
                <table>
                  <thead>
                    <tr>
                      <th>Date</th>
                      <th>Quantity</th>
                      <th>Task</th>
                    </tr>
                  </thead>
                  <tbody>
                    {pageState.data.harvests.map((record) => (
                      <tr key={record.id}>
                        <td>{formatDate(record.harvested_on)}</td>
                        <td>{valueOrFallback(record.quantity)}</td>
                        <td>{record.task_name || record.task_id || 'Manual'}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </section>

          <section className="panel page-stack">
            <div>
              <h3 className="section-title">Rotation History</h3>
              <p className="section-copy">Past rotation records involving this plant instance.</p>
            </div>
            {pageState.data.rotations.length === 0 ? (
              <EmptyState title="No rotations" description="This plant has not appeared in recorded rotation history yet." />
            ) : (
              <div className="table-wrap">
                <table>
                  <thead>
                    <tr>
                      <th>From</th>
                      <th>To</th>
                      <th>Zone</th>
                    </tr>
                  </thead>
                  <tbody>
                    {pageState.data.rotations.map((rotation) => (
                      <tr key={rotation.id}>
                        <td>{formatDate(rotation.from_date)}</td>
                        <td>{formatDate(rotation.to_date)}</td>
                        <td>{rotation.fk_plant_zone_id}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </section>
        </aside>
      </div>
    </div>
  )
}
