import { zoneVisualColors } from '../../lib/plotPlan.js'
import { plotPlanText } from '../../lib/plotPlanLt.js'
import { formatSquareMetersValue } from '../../lib/constants.js'
import Button from '../ui/Button.jsx'

const SEASONS = [
  ['', 'Visi sezonai'],
  ['spring', 'Pavasaris'],
  ['summer', 'Vasara'],
  ['autumn', 'Ruduo'],
  ['winter', 'Žiema'],
]

export default function PlotPlanControls({ options, onOptionsChange, filters, onFiltersChange, plants, zones, onReset, onSelectZone }) {
  const years = [...new Set(plants.map((plant) => String(plant.plant_date ?? '').slice(0, 4)).filter(Boolean))].sort().reverse()
  const plantNames = [...new Set(plants.map((plant) => plant.name).filter(Boolean))].sort((a, b) => a.localeCompare(b, 'lt'))

  return (
    <section className="plot-plan-controls" aria-label="Plano rodiniai ir filtrai">
      <div className="plot-plan-toggle-grid">
        {[
          ['showPlants', plotPlanText('showPlants')],
          ['showZoneNames', plotPlanText('showZoneNames')],
          ['bordersOnly', plotPlanText('bordersOnly')],
        ].map(([key, label]) => (
          <label key={key} className="plot-plan-toggle">
            <input type="checkbox" checked={options[key]} onChange={(event) => onOptionsChange({ ...options, [key]: event.target.checked })} />
            <span>{label}</span>
          </label>
        ))}
      </div>

      <div className="plot-plan-filter-grid">
        <label><span>Metai</span><select value={filters.year} onChange={(event) => onFiltersChange({ ...filters, year: event.target.value })}><option value="">Visi metai</option>{years.map((year) => <option key={year}>{year}</option>)}</select></label>
        <label><span>Sezonas</span><select value={filters.season} onChange={(event) => onFiltersChange({ ...filters, season: event.target.value })}>{SEASONS.map(([value, label]) => <option key={value} value={value}>{label}</option>)}</select></label>
        <label><span>Augalas</span><select value={filters.plant} onChange={(event) => onFiltersChange({ ...filters, plant: event.target.value })}><option value="">Visi augalai</option>{plantNames.map((name) => <option key={name}>{name}</option>)}</select></label>
        <label><span>Būklė</span><select value={filters.status} onChange={(event) => onFiltersChange({ ...filters, status: event.target.value })}><option value="">Visos būklės</option><option value="healthy">Auga įprastai</option><option value="attention">Reikia dėmesio</option><option value="critical">Kritinė būklė</option><option value="inactive">Neaktyvūs</option></select></label>
        <Button size="sm" variant="ghost" onClick={onReset}>{plotPlanText('resetFilters')}</Button>
      </div>

      <details className="plot-plan-legend" open>
        <summary>Zonų legenda <span>{zones.length}</span></summary>
        <div className="plot-plan-legend-list">
          {zones.map((zone, index) => {
            const colors = zoneVisualColors(zone, index)
            const zonePlants = plants.filter((plant) => String(plant.fk_plant_zone_id ?? plant.plant_zone_id) === String(zone.id))
            const principalPlants = zone.principal_plants?.length ? zone.principal_plants : [...new Set(zonePlants.map((plant) => plant.name))].slice(0, 3)
            return (
              <button key={zone.id} type="button" onClick={() => onSelectZone(zone)}>
                <span className="plot-plan-legend-swatch" style={{ background: colors.fill, borderColor: colors.stroke }} />
                <span><strong>{zone.name}</strong><small>{formatSquareMetersValue(zone.zone_size, 1)} · {zonePlants.length} sodinimai</small><small>{principalPlants.join(', ') || 'Augalų nėra'}{zone.archived_at ? ` · ${plotPlanText('archived')}` : ''}</small></span>
              </button>
            )
          })}
          {!zones.length ? <p>Matomų zonų nėra.</p> : null}
        </div>
      </details>
    </section>
  )
}
