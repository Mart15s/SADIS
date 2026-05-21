import { Link, useLocation, useParams } from 'react-router-dom'
import PageHeader from '../../components/layout/PageHeader.jsx'
import { EmptyState, ErrorState, LoadingState } from '../../components/shared/StatusView.jsx'
import Button from '../../components/ui/Button.jsx'
import EmptyStatePanel from '../../components/ui/EmptyStatePanel.jsx'
import { DefinitionList, KeyValueGrid } from '../../components/ui/DefinitionList.jsx'
import StatusBadge from '../../components/ui/StatusBadge.jsx'
import { api } from '../../lib/api.js'
import {
  formatDayCount,
  formatDisplayValue,
  formatNumberWithUnit,
  formatPlantType,
  formatTemperatureC,
} from '../../lib/constants.js'
import { useAsyncData } from '../../lib/hooks/useAsyncData.js'

function CareMetric({ label, value }) {
  return (
    <KeyValueGrid className="catalog-care-metric" items={[{ label, value }]} />
  )
}

export default function CatalogPlantDetailPage() {
  const location = useLocation()
  const { catalogPlantId } = useParams()

  const pageState = useAsyncData(
    () => api.getCatalogPlant(catalogPlantId),
    [catalogPlantId],
    null,
  )

  if (pageState.loading) return <LoadingState title="Įkeliamas katalogo augalas..." />
  if (pageState.error) return <ErrorState error={pageState.error} onRetry={pageState.reload} />
  if (!pageState.data) return <EmptyState title="Katalogo augalas nerastas" description="Pasirinkto katalogo augalo nepavyko įkelti." />

  const catalogPlant = pageState.data
  const care = catalogPlant.plantCare ?? catalogPlant.plant_care ?? null
  const notice = location.state?.notice

  return (
    <div className="page-stack">
      <PageHeader
        eyebrow="Augalų katalogas"
        title={catalogPlant.name}
        description={`${formatPlantType(catalogPlant.plant_type ?? '')} / ${catalogPlant.usage_count ?? 0} pasodinti įrašai`}
        meta={(
          <>
            {catalogPlant.plant_type ? <StatusBadge kind="selection" tone="neutral">{formatPlantType(catalogPlant.plant_type)}</StatusBadge> : null}
            <StatusBadge kind="selection" tone={catalogPlant.usage_count > 0 ? 'success' : 'warning'}>
              {catalogPlant.usage_count > 0 ? `${catalogPlant.usage_count} aktyvūs naudojimai` : 'Dar nepasodinta'}
            </StatusBadge>
          </>
        )}
        actions={(
          <>
            <Link to="/plants?view=catalog">
              <Button variant="secondary">Atgal</Button>
            </Link>
            <Link to={`/plants/catalog/${catalogPlant.id}/edit`}>
              <Button variant="ghost">Redaguoti</Button>
            </Link>
            <Link to={`/plants/new?catalogPlantId=${catalogPlant.id}`}>
              <Button>Sodinti zonoje</Button>
            </Link>
          </>
        )}
      />

      {notice ? <div className="inline-note">{notice}</div> : null}

      <div className="catalog-plant-layout">
        <section className="panel page-stack catalog-plant-identity-panel">
          <div className="plot-page-section-head">
            <div>
              <h2 className="section-title">Trumpai</h2>
              <p className="section-copy">Pakartotinai naudojama tapatybė ir bendra priežiūra pateikiamos kompaktiškai, kad katalogo įrašą būtų lengva įvertinti.</p>
            </div>
          </div>

          {catalogPlant.description ? (
            <p className="catalog-plant-description">{catalogPlant.description}</p>
          ) : (
            <EmptyStatePanel
              title="Aprašymo dar nėra"
              description="Redagavimo formoje pridėkite trumpą aprašą, kad katalogo augalą būtų lengviau atpažinti sklypuose."
              tone="subtle"
            />
          )}

          <DefinitionList
            items={[
              { label: 'Kataloginis pavadinimas', value: formatDisplayValue(catalogPlant.canonical_name) },
              { label: 'Mokslinis pavadinimas', value: formatDisplayValue(catalogPlant.source_scientific_name) },
              { label: 'Šeima', value: formatDisplayValue(catalogPlant.source_family) },
              { label: 'Duomenų šaltinis', value: formatDisplayValue(catalogPlant.source_provider) },
              { label: 'Šaltinio kokybė', value: formatDisplayValue(catalogPlant.source_quality) },
            ]}
          />
        </section>

        <section className="panel page-stack catalog-plant-care-panel">
          <div className="plot-page-section-head">
            <div>
              <h2 className="section-title">Bendrinama priežiūra</h2>
              <p className="section-copy">Su katalogu susieta priežiūra naudojama skirtinguose pasodinimuose, todėl svarbiausi intervalai rodomi pirmiausia.</p>
            </div>
            {care?.reusable ? <StatusBadge kind="status" tone="success">Pakartotinai naudojama priežiūra</StatusBadge> : null}
          </div>

          {care ? (
            <>
              <div className="catalog-care-grid">
                <CareMetric label="Laistymas" value={formatDayCount(care.watering_interval_days)} />
                <CareMetric label="Tręšimas" value={formatDayCount(care.fertilizing_interval_days)} />
                <CareMetric label="Kenkėjų patikra" value={formatDayCount(care.pest_check_interval_days)} />
                <CareMetric label="Lietaus riba" value={formatNumberWithUnit(care.rain_skip_threshold_mm, 'mm', 1)} />
                <CareMetric label="Šalnos riba" value={formatTemperatureC(care.frost_temp_threshold_c)} />
                <CareMetric label="Karščio riba" value={formatTemperatureC(care.heat_extra_water_temp_c)} />
                <CareMetric label="Apsauga nuo vėjo" value={formatNumberWithUnit(care.wind_protection_kmh, 'km/h', 1)} />
                <CareMetric label="Augimo trukmė" value={formatDayCount(care.growing_duration_days)} />
              </div>

              <details className="catalog-detail-disclosure" open>
                <summary>Augimo rekomendacijos</summary>
                <DefinitionList
                  items={[
                    { label: 'Sąlygos', value: formatDisplayValue(care.conditions) },
                    { label: 'Aprašymas', value: formatDisplayValue(care.description) },
                  ]}
                />
              </details>

              <details className="catalog-detail-disclosure">
                <summary>Augimo etapų trukmės</summary>
                <DefinitionList
                  items={[
                    { label: 'Dygimas', value: formatDayCount(care.germinating_duration_days) },
                    { label: 'Žydėjimas', value: formatDayCount(care.flowering_duration_days) },
                    { label: 'Brandos pradžia', value: formatDayCount(care.mature_duration_days) },
                    { label: 'Brandos pabaiga', value: formatDayCount(care.mature_end_duration_days ?? care.mature_duration_end_days) },
                    { label: 'Atsinaujinimas', value: formatDayCount(care.regenerating_duration_days) },
                  ]}
                />
              </details>
            </>
          ) : (
            <EmptyStatePanel
              title="Bendrinama priežiūra nesusieta"
              description="Katalogo augalo formoje susiekite priežiūros profilį, kad kalendorius ir priežiūros taisyklės galėtų naudoti šį įrašą."
              tone="subtle"
            />
          )}
        </section>
      </div>
    </div>
  )
}
