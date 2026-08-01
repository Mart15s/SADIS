import React, { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { EmptyState } from '../shared/StatusView.jsx'
import EmptyStatePanel from '../ui/EmptyStatePanel.jsx'
import Button from '../ui/Button.jsx'
import { KeyValueGrid, StatRow } from '../ui/DefinitionList.jsx'
import { DialogBody, DialogFooter, DialogHeader, Drawer } from '../ui/Dialog.jsx'
import { api } from '../../lib/api.js'
import {
  CONDITION_TYPES,
  formatDayCount,
  formatPlantCondition,
  formatPlantType,
  formatSoilType,
  formatSquareMetersValue,
} from '../../lib/constants.js'
import { useDebouncedValue } from '../../lib/hooks/useDebouncedValue.js'

function createInitialForm(selectedZone) {
  return {
    name: '',
    type: '',
    condition: CONDITION_TYPES[6],
    plant_date: new Date().toISOString().slice(0, 10),
    disease: false,
    disease_notes: '',
    variety: '',
    quantity: '',
    occupied_area: '',
    season: '',
    notes: '',
    fk_plant_zone_id: String(selectedZone?.id ?? ''),
    fk_catalog_plant_id: '',
  }
}

function carePreview(catalogPlant) {
  return catalogPlant?.plant_care ?? catalogPlant?.plantCare ?? null
}

export default function PlotPlantingDrawer({
  selectedZone,
  canEdit,
  busy,
  onCreatePlant,
}) {
  const [isOpen, setIsOpen] = useState(false)
  const [catalogSearch, setCatalogSearch] = useState('')
  const [catalogResults, setCatalogResults] = useState([])
  const [catalogLoading, setCatalogLoading] = useState(false)
  const [catalogError, setCatalogError] = useState('')
  const [submitError, setSubmitError] = useState('')
  const [form, setForm] = useState(() => createInitialForm(selectedZone))
  const [selectedCatalogPlant, setSelectedCatalogPlant] = useState(null)
  const debouncedSearch = useDebouncedValue(catalogSearch)

  useEffect(() => {
    setForm((current) => ({
      ...current,
      fk_plant_zone_id: String(selectedZone?.id ?? ''),
    }))
  }, [selectedZone])

  useEffect(() => {
    if (!isOpen) {
      return undefined
    }

    let cancelled = false
    setCatalogLoading(true)
    setCatalogError('')

    api.listCatalogPlants(debouncedSearch.trim() ? { q: debouncedSearch.trim() } : {})
      .then((results) => {
        if (!cancelled) {
          setCatalogResults(results.slice(0, 12))
        }
      })
      .catch((requestError) => {
        if (!cancelled) {
          setCatalogResults([])
          setCatalogError(requestError.message)
        }
      })
      .finally(() => {
        if (!cancelled) {
          setCatalogLoading(false)
        }
      })

    return () => {
      cancelled = true
    }
  }, [debouncedSearch, isOpen])

  function resetDrawerState(nextZone = selectedZone) {
    setSelectedCatalogPlant(null)
    setCatalogSearch('')
    setCatalogResults([])
    setCatalogError('')
    setSubmitError('')
    setForm(createInitialForm(nextZone))
  }

  function openDrawer() {
    if (!selectedZone || !canEdit) {
      return
    }

    resetDrawerState(selectedZone)
    setIsOpen(true)
  }

  function closeDrawer() {
    setIsOpen(false)
    resetDrawerState(selectedZone)
  }

  function handleCatalogSelect(catalogPlant) {
    setSelectedCatalogPlant(catalogPlant)
    setCatalogSearch(catalogPlant.name)
    setCatalogResults([])
    setSubmitError('')
    setForm((current) => ({
      ...current,
      name: catalogPlant.name,
      type: catalogPlant.plant_type ?? current.type,
      fk_catalog_plant_id: String(catalogPlant.id),
    }))
  }

  async function handleSubmit(event) {
    event.preventDefault()

    if (!selectedZone || !selectedCatalogPlant) {
      return
    }

    setSubmitError('')

    try {
      await onCreatePlant({
        name: form.name.trim() || selectedCatalogPlant.name,
        type: form.type || selectedCatalogPlant.plant_type || null,
        condition: form.condition,
        plant_date: form.plant_date,
        disease: Boolean(form.disease),
        disease_notes: form.disease_notes.trim() || null,
        variety: form.variety.trim() || null,
        quantity: form.quantity === '' ? null : Number(form.quantity),
        occupied_area: form.occupied_area === '' ? null : Number(form.occupied_area),
        season: form.season || null,
        notes: form.notes.trim() || null,
        fk_catalog_plant_id: Number(form.fk_catalog_plant_id),
        fk_plant_zone_id: selectedZone.id,
        perenual_species_id: carePreview(selectedCatalogPlant)?.source_perenual_species_id ?? null,
      })

      closeDrawer()
    } catch (requestError) {
      setSubmitError(requestError.message)
    }
  }

  const selectedCare = carePreview(selectedCatalogPlant)

  return (
    <>
      <section className="inspector-section workspace-context-card plant-placement-card">
        <div className="list-head">
          <div className="page-stack">
            <h3 className="section-title">Augalo sodinimas</h3>
            <p className="section-copy">
              Pasirinkite zoną, katalogo augalą ir įdėkite jį su jau susieta bendrine priežiūra.
            </p>
          </div>
          <Button
            onClick={openDrawer}
            disabled={!canEdit || !selectedZone}
            data-testid="open-plant-drawer"
          >
            {selectedZone ? 'Pridėti augalą į juodraštį' : 'Reikia pasirinkti zoną'}
          </Button>
        </div>

        {selectedZone ? (
          <div className="meta-cluster">
            <StatRow label="Aktyvi zona" value={selectedZone.name} />
            <StatRow label="Plotas" value={formatSquareMetersValue(selectedZone.zone_size, 2)} />
            <StatRow label="Dirvožemis" value={formatSoilType(selectedZone.soil_type)} />
          </div>
        ) : (
          <EmptyStatePanel
            title="Pirmiausia pasirinkite zoną"
            description="Pasirinkite zoną plane, kad galėtumėte į ją įdėti augalą."
            tone="subtle"
          />
        )}
      </section>

      <Drawer
        open={isOpen}
        onClose={closeDrawer}
        labelledBy="plant-placement-title"
        describedBy="plant-placement-subtitle"
        size="lg"
        className="plant-flow-panel"
      >
        <DialogHeader
          title={`Pridėti augalą į ${selectedZone?.name ?? 'pasirinktą zoną'}`}
          subtitle="Pasirinkite daugkartinį katalogo augalą ir įdėkite jį į pasirinktą zoną. Pakeitimas liks redaktoriaus juodraštyje iki pagrindinio išsaugojimo."
          titleId="plant-placement-title"
          subtitleId="plant-placement-subtitle"
          onClose={closeDrawer}
          closeLabel="Uždaryti augalo langą"
        />
        <DialogBody className="plant-flow-body page-stack">

            <div className="inline-note">
              Zonos kontekstas užrakintas: <strong>{selectedZone?.name ?? 'pasirinkta zona'}</strong>.
            </div>

            <section className="page-stack">
              <div className="field">
                <label htmlFor="drawer-catalog-search">Rasti katalogo augalą</label>
                <input
                  id="drawer-catalog-search"
                  value={catalogSearch}
                  onChange={(event) => setCatalogSearch(event.target.value)}
                  placeholder="Ieškoti pagal pavadinimą, mokslinį pavadinimą arba šeimą"
                />
              </div>

              <div className="row-actions">
                <Link to="/plants/catalog/new">
                  <Button variant="secondary">Naujas katalogo augalas</Button>
                </Link>
              </div>

              {catalogLoading ? <span className="muted">Įkeliami katalogo augalai...</span> : null}
              {catalogError ? <span className="field-error">{catalogError}</span> : null}

              {catalogResults.length > 0 ? (
                <div className="plant-flow-catalog-grid">
                  {catalogResults.map((catalogPlant) => {
                    const isSelected = String(selectedCatalogPlant?.id ?? '') === String(catalogPlant.id)
                    const preview = carePreview(catalogPlant)

                    return (
                      <button
                        key={catalogPlant.id}
                        type="button"
                        className={`catalog-plant-card ${isSelected ? 'catalog-plant-card--selected' : ''}`.trim()}
                        onClick={() => handleCatalogSelect(catalogPlant)}
                        data-testid={`catalog-option-${catalogPlant.id}`}
                      >
                        <div className="catalog-plant-identity">
                          <p className="catalog-plant-name">{catalogPlant.name}</p>
                          <span className="catalog-plant-canonical">{catalogPlant.source_scientific_name || catalogPlant.canonical_name}</span>
                        </div>

                        <div className="catalog-plant-meta">
                          <span className="badge badge-soft">{formatPlantType(catalogPlant.plant_type) || 'Nežinomas tipas'}</span>
                          {catalogPlant.source_family ? <span className="badge badge-neutral">{catalogPlant.source_family}</span> : null}
                        </div>

                        <div className="meta-cluster">
                          <StatRow label="Laistyti kas" value={formatDayCount(preview?.watering_interval_days)} />
                          <StatRow label="Tręšti kas" value={formatDayCount(preview?.fertilizing_interval_days)} />
                          <StatRow label="Pasodinta" value={catalogPlant.usage_count ?? 0} />
                        </div>
                      </button>
                    )
                  })}
                </div>
              ) : (
                !catalogLoading ? (
                  <div className="inline-note">
                    Pagal paiešką katalogo augalų nerasta. Jei reikia, pirmiausia sukurkite daugkartinį katalogo įrašą.
                  </div>
                ) : null
              )}
            </section>

            {selectedCatalogPlant ? (
              <form id="plant-placement-form" className="page-stack" onSubmit={handleSubmit}>
                <section className="panel page-stack plant-flow-summary">
                  <div className="list-head">
                    <div className="page-stack">
                      <h4 className="section-title">Pasirinktas katalogo augalas</h4>
                      <p className="section-copy">
                        {selectedCatalogPlant.name}
                        {selectedCatalogPlant.source_scientific_name ? ` | ${selectedCatalogPlant.source_scientific_name}` : ''}
                      </p>
                    </div>
                    <div className="meta-cluster">
                      <span className="badge badge-soft">{formatPlantType(selectedCatalogPlant.plant_type) || 'Nežinomas tipas'}</span>
                    </div>
                  </div>

                  <div className="form-grid plant-flow-instance-grid">
                    <div className="field">
                      <label htmlFor="placement-name">Rodomas pavadinimas</label>
                      <input
                        id="placement-name"
                        value={form.name}
                        onChange={(event) => setForm((current) => ({ ...current, name: event.target.value }))}
                        required
                      />
                    </div>

                    <div className="field">
                      <label htmlFor="placement-date">Sodinimo data</label>
                      <input
                        id="placement-date"
                        type="date"
                        value={form.plant_date}
                        onChange={(event) => setForm((current) => ({ ...current, plant_date: event.target.value }))}
                        required
                      />
                    </div>

                    <div className="field">
                      <label htmlFor="placement-condition">Būklė</label>
                      <select
                        id="placement-condition"
                        value={form.condition}
                        onChange={(event) => setForm((current) => ({ ...current, condition: event.target.value }))}
                      >
                        {CONDITION_TYPES.map((condition) => (
                          <option key={condition} value={condition}>
                            {formatPlantCondition(condition)}
                          </option>
                        ))}
                      </select>
                    </div>

                    <div className="field">
                      <label htmlFor="placement-variety">Veislė</label>
                      <input id="placement-variety" value={form.variety} onChange={(event) => setForm((current) => ({ ...current, variety: event.target.value }))} placeholder="Pasirinktinai" />
                    </div>

                    <div className="field">
                      <label htmlFor="placement-quantity">Kiekis</label>
                      <input id="placement-quantity" type="number" min="0" step="0.01" value={form.quantity} onChange={(event) => setForm((current) => ({ ...current, quantity: event.target.value }))} placeholder="vnt." />
                    </div>

                    <div className="field">
                      <label htmlFor="placement-area">Užimamas plotas</label>
                      <input id="placement-area" type="number" min="0" step="0.01" value={form.occupied_area} onChange={(event) => setForm((current) => ({ ...current, occupied_area: event.target.value }))} placeholder="m²" />
                    </div>

                    <div className="field">
                      <label htmlFor="placement-season">Sezonas</label>
                      <select id="placement-season" value={form.season} onChange={(event) => setForm((current) => ({ ...current, season: event.target.value }))}>
                        <option value="">Pagal sodinimo datą</option><option value="spring">Pavasaris</option><option value="summer">Vasara</option><option value="autumn">Ruduo</option><option value="winter">Žiema</option>
                      </select>
                    </div>

                    <div className="field">
                      <label htmlFor="placement-disease">Yra liga</label>
                      <select
                        id="placement-disease"
                        value={form.disease ? 'true' : 'false'}
                        onChange={(event) => setForm((current) => ({ ...current, disease: event.target.value === 'true' }))}
                      >
                        <option value="false">Ne</option>
                        <option value="true">Taip</option>
                      </select>
                    </div>

                    <div className="field field-span-2">
                      <label htmlFor="placement-disease-notes">Ligos pastabos</label>
                      <textarea
                        id="placement-disease-notes"
                        value={form.disease_notes}
                        onChange={(event) => setForm((current) => ({ ...current, disease_notes: event.target.value }))}
                        placeholder="Pasirinktinės pastabos apie šį pasodintą augalą"
                      />
                    </div>

                    <div className="field field-span-2">
                      <label htmlFor="placement-notes">Sodinimo pastabos</label>
                      <textarea id="placement-notes" value={form.notes} onChange={(event) => setForm((current) => ({ ...current, notes: event.target.value }))} placeholder="Pasirinktinės pastabos apie sodinimą" />
                    </div>
                  </div>
                </section>

                <section className="panel page-stack">
                  <div className="list-head">
                    <div className="page-stack">
                      <h4 className="section-title">Bendrinamos priežiūros peržiūra</h4>
                      <p className="section-copy">
                        Šis pasodintas augalas naudos kataloge susietą bendrinamą priežiūrą. Norėdami ją keisti, redaguokite katalogo augalą.
                      </p>
                    </div>
                  </div>

                  {selectedCare ? (
                    <KeyValueGrid
                      className="plants-detail-grid"
                      items={[
                        { label: 'Laistymo intervalas', value: formatDayCount(selectedCare.watering_interval_days) },
                        { label: 'Tręšimo intervalas', value: formatDayCount(selectedCare.fertilizing_interval_days) },
                        { label: 'Kenkėjų patikros', value: formatDayCount(selectedCare.pest_check_interval_days) },
                        { label: 'Sąlygos', value: selectedCare.conditions || 'Nenurodyta' },
                      ]}
                    />
                  ) : (
                    <div className="inline-note">
                      Šis katalogo augalas dar neturi bendrinamo priežiūros profilio. Atidarykite katalogo augalą ir pridėkite jį prieš sodinimą.
                    </div>
                  )}

                  <div className="row-actions">
                    <Link to={`/plants/catalog/${selectedCatalogPlant.id}`}>
                      <Button variant="ghost">Atidaryti katalogo augalą</Button>
                    </Link>
                    <Link to={`/plants/catalog/${selectedCatalogPlant.id}/edit`}>
                      <Button variant="secondary">Redaguoti bendrinamą priežiūrą</Button>
                    </Link>
                  </div>
                </section>

                {submitError ? <span className="field-error">{submitError}</span> : null}

              </form>
            ) : (
              <div className="panel page-stack">
                <EmptyState
                  title="Pasirinkite katalogo augalą"
                  description="Ieškokite kataloge ir pasirinkite daugkartinį augalą, kurį norite įdėti į dabartinę zoną."
                />
              </div>
            )}
        </DialogBody>
        <DialogFooter>
          {selectedCatalogPlant ? (
            <>
              <Button type="submit" form="plant-placement-form" disabled={busy}>
                {busy ? 'Pridedama į juodraštį...' : 'Pridėti augalą į juodraštį'}
              </Button>
              <Button type="button" variant="secondary" onClick={closeDrawer} disabled={busy}>
                Atšaukti
              </Button>
            </>
          ) : (
            <Button type="button" variant="secondary" onClick={closeDrawer}>
              Uždaryti
            </Button>
          )}
        </DialogFooter>
      </Drawer>
    </>
  )
}
