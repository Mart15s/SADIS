import { useMemo, useState } from 'react'
import { Link, useLocation, useParams } from 'react-router-dom'
import PageHeader from '../../components/layout/PageHeader.jsx'
import { EmptyState, ErrorState, LoadingState } from '../../components/shared/StatusView.jsx'
import Button from '../../components/ui/Button.jsx'
import { DefinitionList, KeyValueGrid, StatRow } from '../../components/ui/DefinitionList.jsx'
import { api } from '../../lib/api.js'
import {
  CONDITION_TYPES,
  formatDate,
  formatDateTime,
  formatDayCount,
  formatDisplayValue,
  formatNumberWithUnit,
  formatPlantCondition,
  formatPlantType,
  formatTemperatureC,
} from '../../lib/constants.js'
import { useAsyncData } from '../../lib/hooks/useAsyncData.js'

const initialConditionForm = {
  measured_at: new Date().toISOString().slice(0, 10),
  notes: '',
  photo_url: '',
  condition: CONDITION_TYPES[6],
  disease: '',
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

function rotationZoneName(zone, fallback = 'Nežinoma zona') {
  return zone?.name || zone?.zone_name || fallback
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
        label: location.state.backLabel ?? 'Atgal',
      }
    }

    if (plotId) {
      return {
        to: `/plots/${plotId}`,
        label: 'Grįžti į sklypą',
      }
    }

    return {
      to: '/plants',
      label: 'Grįžti į augalus',
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
      setNotice('Būklė įrašyta.')
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
      setNotice('Būklės peržiūra atlikta.')
      await pageState.reload()
    } catch (requestError) {
      setError(requestError.message)
    } finally {
      setSubmittingReview(false)
    }
  }

  if (pageState.loading) {
    return <LoadingState title="Įkeliama augalo informacija..." />
  }

  if (pageState.error) {
    return <ErrorState error={pageState.error} onRetry={pageState.reload} />
  }

  if (!plant) {
    return <EmptyState title="Augalas nerastas" description="Pasirinkto augalo nepavyko įkelti." />
  }

  return (
    <div className="page-stack">
      <PageHeader
        title={plant.name}
        description={`${formatPlantType(plant.plant_type)}: ${plant.plot?.name ?? 'Nežinomas sklypas'} / ${linkedZone?.name ?? 'Nežinoma zona'}`}
        actions={(
          <>
            <Link to={backTarget.to}>
              <Button variant="secondary">{backTarget.label}</Button>
            </Link>
            {resolvedPlotId && !plotId ? (
              <Link to={`/plots/${resolvedPlotId}`}>
                <Button variant="ghost">Atidaryti sklypą</Button>
              </Link>
            ) : null}
            {canEdit ? (
              <Link to={`/plants/${plant.id}/edit`}>
                <Button>Redaguoti</Button>
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
            <h3 className="section-title">Laukianti būklės peržiūra</h3>
            <p className="section-copy">Ši peržiūros užduotis atidaryta iš kalendoriaus ir atnaujins patvirtintą augalo būklę.</p>
          </div>
          <div className="meta-cluster">
            <span>Užduotis: {reviewTask.name}</span>
            <span>Siūloma: {formatPlantCondition(reviewTask.workflow_context?.review?.target_condition)}</span>
            <span>Tikimasi: {formatDate(reviewTask.workflow_context?.review?.expected_on ?? reviewTask.date)}</span>
          </div>
          <form className="input-grid" onSubmit={handleReviewSubmit}>
            <div className="field">
              <label htmlFor="review-action">Sprendimas</label>
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
                <option value="confirm">Patvirtinti siūlomą perėjimą</option>
                <option value="keep_current">Palikti dabartinę stadiją</option>
                <option value="adjust">Pakoreguoti rankiniu būdu</option>
              </select>
            </div>
            {reviewForm.action === 'adjust' ? (
              <div className="field">
                <label htmlFor="review-condition">Būklė</label>
                <select
                  id="review-condition"
                  value={reviewForm.condition}
                  onChange={(event) => setReviewForm((current) => ({ ...current, condition: event.target.value }))}
                  required
                >
                  {CONDITION_TYPES.map((condition) => (
                    <option key={condition} value={condition}>
                      {formatPlantCondition(condition)}
                    </option>
                  ))}
                </select>
              </div>
            ) : null}
            <div className="field">
              <label htmlFor="review-date">Peržiūrėta</label>
              <input
                id="review-date"
                type="date"
                value={reviewForm.measured_at}
                onChange={(event) => setReviewForm((current) => ({ ...current, measured_at: event.target.value }))}
                required
              />
            </div>
            <div className="field field-span-2">
              <label htmlFor="review-notes">Pastabos</label>
              <textarea
                id="review-notes"
                value={reviewForm.notes}
                onChange={(event) => setReviewForm((current) => ({ ...current, notes: event.target.value }))}
              />
            </div>
            <div className="form-actions">
              <Button type="submit" disabled={submittingReview}>
                {submittingReview ? 'Pateikiama peržiūra...' : 'Baigti peržiūrą'}
              </Button>
              <Button
                variant="secondary"
                onClick={() => {
                  setReviewTask(null)
                  setReviewForm(createReviewForm(null))
                }}
              >
                Uždaryti panelį
              </Button>
            </div>
          </form>
        </section>
      ) : null}

      <div className="detail-grid">
        <section className="panel page-stack">
          <div>
            <h3 className="section-title">Augalo apžvalga</h3>
            <p className="section-copy">Pagrindinė šio pasodinto augalo informacija.</p>
          </div>

          <div className="meta-cluster">
            <StatRow label="Būklė" value={formatPlantCondition(plant.condition)} />
            <StatRow label="Pasodinta" value={formatDate(plant.plant_date)} />
            <StatRow label="Sklypas" value={plant.plot?.name ?? 'Nežinoma'} />
            <StatRow label="Zona" value={linkedZone?.name ?? 'Nežinoma'} />
            <StatRow label="Katalogas" value={linkedCatalogPlant?.name ?? 'Nesusieta'} />
            <StatRow label="Liga" value={plant.disease ? 'Taip' : 'Ne'} />
          </div>

          <KeyValueGrid
            className="plants-detail-grid"
            items={[
              { label: 'Augimo laikas', value: formatDayCount(plant.growing_time_days) },
              { label: 'Rekomenduojama temperatūra', value: formatTemperatureC(plant.recommended_temperature) },
              { label: 'Rekomenduojama drėgmė', value: formatNumberWithUnit(plant.recommended_humidity, '%', 1) },
              { label: 'Poilsio laikas', value: formatDayCount(plant.rest_time_days) },
              { label: 'Augalo dydis', value: formatDisplayValue(plant.plant_size) },
              { label: 'Susietas priežiūros profilis', value: plant.fk_plant_care_id ?? linkedCare?.id ?? 'Nesusieta' },
            ]}
          />

          {plant.disease_notes ? <div className="inline-note">{plant.disease_notes}</div> : null}

          {linkedCatalogPlant ? (
            <div className="row-actions">
              <Link to={`/plants/catalog/${linkedCatalogPlant.id}`}>
                <Button variant="ghost">Atidaryti katalogo augalą</Button>
              </Link>
              <Link to={`/plants/catalog/${linkedCatalogPlant.id}/edit`}>
                <Button variant="secondary">Redaguoti bendrinamą priežiūrą</Button>
              </Link>
            </div>
          ) : null}

          <section className="panel page-stack">
            <div>
              <h3 className="section-title">Gyvavimo ciklo gairės</h3>
              <p className="section-copy">Tikėtini etapai skaičiuojami pagal susietą priežiūros profilį ir padeda planuoti peržiūros užduotis.</p>
            </div>
            {lifecycle ? (
              <>
                <div className="meta-cluster">
                  <StatRow label="Patvirtinta stadija" value={formatPlantCondition(lifecycle.current_condition)} />
                  <StatRow label="Atraminė data" value={formatDate(lifecycle.current_condition_anchor_date)} />
                  <StatRow label="Atsigavimo kelias" value={lifecycle.supports_regeneration ? 'Palaikoma' : 'Ne'} />
                </div>
                {lifecycle.next_review ? (
                  <StatRow
                    label="Kita peržiūra"
                    value={`Peržiūrėti perėjimą į ${formatPlantCondition(lifecycle.next_review.target_condition)}: ${formatDate(lifecycle.next_review.expected_on)}${lifecycle.next_review.is_overdue ? ' (vėluoja)' : ''}`}
                  />
                ) : null}
                {lifecycle.next_harvest ? (
                  <StatRow
                    label="Kitas derliaus taškas"
                    value={`Derlius tikėtinas ${formatDate(lifecycle.next_harvest.expected_on)}${lifecycle.next_harvest.is_overdue ? ' (vėluoja)' : ''}`}
                  />
                ) : null}
                <DefinitionList
                  items={Object.entries(lifecycle.scheduled_stage_starts ?? {}).map(([condition, date]) => ({
                    label: formatPlantCondition(condition),
                    value: formatDate(date),
                  }))}
                />
              </>
            ) : (
              <EmptyState title="Gyvavimo ciklo gairių nėra" description="Susiekite augalo priežiūros profilį, kad būtų skaičiuojami etapai." />
            )}
          </section>
        </section>

        <aside className="page-stack">
          <section className="panel page-stack">
            <div>
              <h3 className="section-title">Naudojamas priežiūros profilis</h3>
              <p className="section-copy">Bendrinamas augalo priežiūros įrašas, naudojamas etapams ir rekomendacijoms.</p>
            </div>

            {linkedCare ? (
              <KeyValueGrid
                className="plants-detail-grid"
                items={[
                  { label: 'Laistymo intervalas', value: formatDayCount(linkedCare.watering_interval_days) },
                  { label: 'Tręšimo intervalas', value: formatDayCount(linkedCare.fertilizing_interval_days) },
                  { label: 'Kenkėjų patikros intervalas', value: formatDayCount(linkedCare.pest_check_interval_days) },
                  { label: 'Dygimo trukmė', value: formatDayCount(linkedCare.germinating_duration_days) },
                  { label: 'Augimo trukmė', value: formatDayCount(linkedCare.growing_duration_days) },
                  { label: 'Žydėjimo trukmė', value: formatDayCount(linkedCare.flowering_duration_days) },
                  { label: 'Brandos trukmė', value: formatDayCount(linkedCare.mature_duration_days) },
                  { label: 'Atsigavimo trukmė', value: formatDayCount(linkedCare.regenerating_duration_days) },
                ]}
              />
            ) : (
              <EmptyState title="Priežiūros profilis nesusietas" description="Šis augalas šiuo metu neturi susieto priežiūros profilio." />
            )}
          </section>

          <section className="panel page-stack">
            <div>
              <h3 className="section-title">Būklės istorija</h3>
              <p className="section-copy">Patvirtinti būklės pakeitimai registruojami čia.</p>
            </div>
            {pageState.data.conditions.length === 0 ? (
              <EmptyState title="Būklės istorijos nėra" description="Šiam augalui dar nėra būklės įrašų." />
            ) : (
              <div className="table-wrap">
                <table>
                  <thead>
                    <tr>
                      <th>Data</th>
                      <th>Būklė</th>
                      <th>Pastabos</th>
                    </tr>
                  </thead>
                  <tbody>
                    {pageState.data.conditions.map((entry) => (
                      <tr key={entry.id}>
                        <td>{formatDateTime(entry.measured_at)}</td>
                        <td>{formatPlantCondition(entry.condition)}</td>
                        <td>{entry.notes || 'Pastabų nėra'}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}

            {canEdit && resolvedPlotId ? (
              <form className="input-grid" onSubmit={handleConditionSubmit}>
                <div className="field">
                  <label htmlFor="condition-date">Matavimo data</label>
                  <input
                    id="condition-date"
                    type="date"
                    value={conditionForm.measured_at}
                    onChange={(event) => setConditionForm((current) => ({ ...current, measured_at: event.target.value }))}
                    required
                  />
                </div>
                <div className="field">
                  <label htmlFor="condition-type">Būklė</label>
                  <select
                    id="condition-type"
                    value={conditionForm.condition}
                    onChange={(event) => setConditionForm((current) => ({ ...current, condition: event.target.value }))}
                  >
                    {CONDITION_TYPES.map((condition) => (
                      <option key={condition} value={condition}>
                        {formatPlantCondition(condition)}
                      </option>
                    ))}
                  </select>
                </div>
                <div className="field">
                  <label htmlFor="condition-disease">Yra liga</label>
                  <select
                    id="condition-disease"
                    value={conditionForm.disease}
                    onChange={(event) => setConditionForm((current) => ({ ...current, disease: event.target.value }))}
                  >
                    <option value="">Nustatyti pagal būklę</option>
                    <option value="false">Ne</option>
                    <option value="true">Taip</option>
                  </select>
                </div>
                <div className="field field-span-2">
                  <label htmlFor="condition-notes">Pastabos</label>
                  <textarea
                    id="condition-notes"
                    value={conditionForm.notes}
                    onChange={(event) => setConditionForm((current) => ({ ...current, notes: event.target.value }))}
                  />
                </div>
                <div className="form-actions">
                  <Button type="submit" disabled={submittingCondition}>
                    {submittingCondition ? 'Saugoma...' : 'Įrašyti būklę'}
                  </Button>
                </div>
              </form>
            ) : null}
          </section>

          <section className="panel page-stack">
            <div>
              <h3 className="section-title">Derliaus istorija</h3>
              <p className="section-copy">Čia saugomi derliaus įrašai, vėliau naudojami analitikoje.</p>
            </div>
            {resolvedPlotId ? (
              <div className="row-actions">
                <Link to={`/plots/${resolvedPlotId}/harvests?plantId=${plant.id}`}>
                  <Button variant="secondary">Atidaryti derliaus registravimą</Button>
                </Link>
              </div>
            ) : null}
            {pageState.data.harvests.length === 0 ? (
              <EmptyState title="Derliaus istorijos nėra" description="Šiam augalui derliaus įrašų dar nėra." />
            ) : (
              <div className="table-wrap">
                <table>
                  <thead>
                    <tr>
                      <th>Data</th>
                      <th>Kiekis</th>
                      <th>Užduotis</th>
                    </tr>
                  </thead>
                  <tbody>
                    {pageState.data.harvests.map((record) => (
                      <tr key={record.id}>
                        <td>{formatDate(record.harvested_on)}</td>
                        <td>{formatDisplayValue(record.quantity)}</td>
                        <td>{record.task_name || record.task_id || 'Rankinis įrašas'}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </section>

          <section className="panel page-stack">
            <div>
              <h3 className="section-title">Rotacijos istorija</h3>
              <p className="section-copy">Ankstesni rotacijos įrašai, kuriuose dalyvavo šis augalas.</p>
            </div>
            {pageState.data.rotations.length === 0 ? (
              <EmptyState title="Rotacijų nėra" description="Šis augalas dar nebuvo įtrauktas į rotacijos istoriją." />
            ) : (
              <div className="table-wrap">
                <table>
                  <thead>
                    <tr>
                      <th>Rotacijos data</th>
                      <th>Iš zonos</th>
                      <th>Į zoną</th>
                    </tr>
                  </thead>
                  <tbody>
                    {pageState.data.rotations.map((rotation) => (
                      <tr key={rotation.id}>
                        <td>{formatDate(rotation.from_date)}</td>
                        <td>{rotationZoneName(rotation.from_zone)}</td>
                        <td>{rotationZoneName(rotation.to_zone ?? rotation.plant_zone)}</td>
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
