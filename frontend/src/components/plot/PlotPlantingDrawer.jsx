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
            <h3 className="section-title">Plant placement</h3>
            <p className="section-copy">
              Pick a zone, choose a catalog plant, and place it with the shared care already linked in the catalog.
            </p>
          </div>
          <Button
            onClick={openDrawer}
            disabled={!canEdit || !selectedZone}
            data-testid="open-plant-drawer"
          >
            {selectedZone ? 'Add plant to draft' : 'Zone required'}
          </Button>
        </div>

        {selectedZone ? (
          <div className="meta-cluster">
            <StatRow label="Active zone" value={selectedZone.name} />
            <StatRow label="Area" value={formatSquareMetersValue(selectedZone.zone_size, 2)} />
            <StatRow label="Soil" value={selectedZone.soil_type} />
          </div>
        ) : (
          <EmptyStatePanel
            title="Select a zone first"
            description="Choose a zone on the canvas to unlock planting. The rest of the workspace stays visible so the next step is clear without looking like a clickable CTA."
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
          title={`Add plant to ${selectedZone?.name ?? 'selected zone'}`}
          subtitle="Choose a reusable catalog plant and place it into the selected zone. This stays in the editor draft until the main plot save is committed."
          titleId="plant-placement-title"
          subtitleId="plant-placement-subtitle"
          onClose={closeDrawer}
          closeLabel="Close plant drawer"
        />
        <DialogBody className="plant-flow-body page-stack">

            <div className="inline-note">
              Zone context is locked to <strong>{selectedZone?.name ?? 'selected zone'}</strong>.
            </div>

            <section className="page-stack">
              <div className="field">
                <label htmlFor="drawer-catalog-search">Find catalog plant</label>
                <input
                  id="drawer-catalog-search"
                  value={catalogSearch}
                  onChange={(event) => setCatalogSearch(event.target.value)}
                  placeholder="Search by name, scientific name, or family"
                />
              </div>

              <div className="row-actions">
                <Link to="/plants/catalog/new">
                  <Button variant="secondary">New catalog plant</Button>
                </Link>
              </div>

              {catalogLoading ? <span className="muted">Loading catalog plants...</span> : null}
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
                          <span className="badge badge-soft">{catalogPlant.plant_type || 'unknown type'}</span>
                          {catalogPlant.source_family ? <span className="badge badge-neutral">{catalogPlant.source_family}</span> : null}
                        </div>

                        <div className="meta-cluster">
                          <StatRow label="Water every" value={formatDayCount(preview?.watering_interval_days)} />
                          <StatRow label="Feed every" value={formatDayCount(preview?.fertilizing_interval_days)} />
                          <StatRow label="Placed" value={catalogPlant.usage_count ?? 0} />
                        </div>
                      </button>
                    )
                  })}
                </div>
              ) : (
                !catalogLoading ? (
                  <div className="inline-note">
                    No catalog plants matched that search yet. You can create a reusable catalog entry first if needed.
                  </div>
                ) : null
              )}
            </section>

            {selectedCatalogPlant ? (
              <form id="plant-placement-form" className="page-stack" onSubmit={handleSubmit}>
                <section className="panel page-stack plant-flow-summary">
                  <div className="list-head">
                    <div className="page-stack">
                      <h4 className="section-title">Selected catalog plant</h4>
                      <p className="section-copy">
                        {selectedCatalogPlant.name}
                        {selectedCatalogPlant.source_scientific_name ? ` | ${selectedCatalogPlant.source_scientific_name}` : ''}
                      </p>
                    </div>
                    <div className="meta-cluster">
                      <span className="badge badge-soft">{selectedCatalogPlant.plant_type || 'unknown type'}</span>
                    </div>
                  </div>

                  <div className="form-grid plant-flow-instance-grid">
                    <div className="field">
                      <label htmlFor="placement-name">Display name</label>
                      <input
                        id="placement-name"
                        value={form.name}
                        onChange={(event) => setForm((current) => ({ ...current, name: event.target.value }))}
                        required
                      />
                    </div>

                    <div className="field">
                      <label htmlFor="placement-date">Plant date</label>
                      <input
                        id="placement-date"
                        type="date"
                        value={form.plant_date}
                        onChange={(event) => setForm((current) => ({ ...current, plant_date: event.target.value }))}
                        required
                      />
                    </div>

                    <div className="field">
                      <label htmlFor="placement-condition">Condition</label>
                      <select
                        id="placement-condition"
                        value={form.condition}
                        onChange={(event) => setForm((current) => ({ ...current, condition: event.target.value }))}
                      >
                        {CONDITION_TYPES.map((condition) => (
                          <option key={condition} value={condition}>
                            {condition}
                          </option>
                        ))}
                      </select>
                    </div>

                    <div className="field">
                      <label htmlFor="placement-disease">Disease present</label>
                      <select
                        id="placement-disease"
                        value={form.disease ? 'true' : 'false'}
                        onChange={(event) => setForm((current) => ({ ...current, disease: event.target.value === 'true' }))}
                      >
                        <option value="false">No</option>
                        <option value="true">Yes</option>
                      </select>
                    </div>

                    <div className="field field-span-2">
                      <label htmlFor="placement-disease-notes">Disease notes</label>
                      <textarea
                        id="placement-disease-notes"
                        value={form.disease_notes}
                        onChange={(event) => setForm((current) => ({ ...current, disease_notes: event.target.value }))}
                        placeholder="Optional notes specific to this planted instance"
                      />
                    </div>
                  </div>
                </section>

                <section className="panel page-stack">
                  <div className="list-head">
                    <div className="page-stack">
                      <h4 className="section-title">Shared plant care preview</h4>
                      <p className="section-copy">
                        This planted instance will use the catalog plant&apos;s shared care. To change care, edit the catalog plant.
                      </p>
                    </div>
                  </div>

                  {selectedCare ? (
                    <KeyValueGrid
                      className="plants-detail-grid"
                      items={[
                        { label: 'Watering interval', value: formatDayCount(selectedCare.watering_interval_days) },
                        { label: 'Fertilizing interval', value: formatDayCount(selectedCare.fertilizing_interval_days) },
                        { label: 'Pest checks', value: formatDayCount(selectedCare.pest_check_interval_days) },
                        { label: 'Conditions', value: selectedCare.conditions || 'Not set' },
                      ]}
                    />
                  ) : (
                    <div className="inline-note">
                      This catalog plant does not have a shared care profile yet. Open the catalog plant to add it before planting.
                    </div>
                  )}

                  <div className="row-actions">
                    <Link to={`/plants/catalog/${selectedCatalogPlant.id}`}>
                      <Button variant="ghost">Open catalog plant</Button>
                    </Link>
                    <Link to={`/plants/catalog/${selectedCatalogPlant.id}/edit`}>
                      <Button variant="secondary">Edit shared care</Button>
                    </Link>
                  </div>
                </section>

                {submitError ? <span className="field-error">{submitError}</span> : null}

              </form>
            ) : (
              <div className="panel page-stack">
                <EmptyState
                  title="Choose a catalog plant"
                  description="Search the catalog above and select a reusable plant to place it into the current zone."
                />
              </div>
            )}
        </DialogBody>
        <DialogFooter>
          {selectedCatalogPlant ? (
            <>
              <Button type="submit" form="plant-placement-form" disabled={busy}>
                {busy ? 'Adding to draft...' : 'Add plant to draft'}
              </Button>
              <Button type="button" variant="secondary" onClick={closeDrawer} disabled={busy}>
                Cancel
              </Button>
            </>
          ) : (
            <Button type="button" variant="secondary" onClick={closeDrawer}>
              Close
            </Button>
          )}
        </DialogFooter>
      </Drawer>
    </>
  )
}
