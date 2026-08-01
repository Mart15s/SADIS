import { useEffect, useRef, useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'

const sections = [
  { key: 'editor', label: 'Plan', icon: 'P', to: (plotId) => `/plots/${plotId}` },
  { key: 'calendar', label: 'Calendar', icon: 'C', to: (plotId) => `/plots/${plotId}/calendar` },
  { key: 'history', label: 'History', icon: 'H', to: (plotId) => `/plots/${plotId}/history` },
  { key: 'harvests', label: 'Harvests', icon: 'V', to: (plotId) => `/plots/${plotId}/harvests` },
  { key: 'analytics', label: 'Analytics', icon: 'A', to: (plotId) => `/plots/${plotId}/analytics` },
  { key: 'sharing', label: 'Sharing', icon: 'S', to: (plotId) => `/plots/${plotId}/sharing`, ownerOnly: true },
  { key: 'rotation', label: 'Rotation', icon: 'R', to: (plotId) => `/plots/${plotId}/rotation` },
]

const ENGLISH_SECTION_LABELS = {
  editor: 'Plan',
  calendar: 'Calendar',
  history: 'History',
  harvests: 'Harvests',
  analytics: 'Analytics',
  sharing: 'Sharing',
  rotation: 'Rotation',
}

function getSectionLabel(sectionKey, fallback) {
  return fallback ?? ENGLISH_SECTION_LABELS[sectionKey] ?? 'Plan'
}

export default function PlotSectionNav({
  plotId,
  isOwner = false,
  plotName = 'Plot',
  sectionKey = 'editor',
  sectionLabel,
  meta = null,
  actions = null,
}) {
  const navigate = useNavigate()
  const dropdownRef = useRef(null)
  const [isOpen, setIsOpen] = useState(false)
  const visibleSections = sections.filter((section) => !section.ownerOnly || isOwner)
  const activeSectionLabel = getSectionLabel(sectionKey, sectionLabel)
  const activeSection = visibleSections.find((section) => section.key === sectionKey)

  useEffect(() => {
    if (!isOpen) return undefined

    function handlePointerDown(event) {
      if (dropdownRef.current && !dropdownRef.current.contains(event.target)) {
        setIsOpen(false)
      }
    }

    function handleKeyDown(event) {
      if (event.key === 'Escape') {
        setIsOpen(false)
      }
    }

    document.addEventListener('mousedown', handlePointerDown)
    document.addEventListener('keydown', handleKeyDown)

    return () => {
      document.removeEventListener('mousedown', handlePointerDown)
      document.removeEventListener('keydown', handleKeyDown)
    }
  }, [isOpen])

  function handleSectionSelect(section) {
    setIsOpen(false)

    if (section.key !== sectionKey) {
      navigate(section.to(plotId))
    }
  }

  return (
    <section className="plot-compact-nav plot-page-header plot-section-switcher-shell" aria-label="Plot workspace">
      <div className="plot-compact-left">
        <Link className="plot-compact-back" to="/plots" aria-label="Back to plots">
          <span aria-hidden="true">&larr;</span>
        </Link>

        <div className="plot-compact-identity">
          <div className="plot-compact-title-block">
            <span className="plot-compact-kicker">{activeSectionLabel.toUpperCase()}</span>
            <h1 className="plot-compact-title">{plotName}</h1>
          </div>

          {meta ? (
            <div className="plot-compact-meta" aria-label="Plot metadata">
              {meta}
            </div>
          ) : null}
        </div>
      </div>

      <div className="plot-section-switcher" ref={dropdownRef}>
        <button
          type="button"
          className={`plot-section-trigger ${isOpen ? 'is-open' : ''}`.trim()}
          aria-haspopup="menu"
          aria-expanded={isOpen}
          aria-label="Choose plot section"
          onClick={() => setIsOpen((current) => !current)}
        >
          <span className="plot-section-select-icon" aria-hidden="true">{activeSection?.icon ?? '*'}</span>
          <span className="plot-section-trigger-text">Section: {activeSectionLabel}</span>
          <span className="plot-section-trigger-caret" aria-hidden="true" />
        </button>

        {isOpen ? (
          <div className="plot-section-menu" role="menu">
            {visibleSections.map((section) => {
              const selected = section.key === sectionKey

              return (
                <button
                  key={section.key}
                  type="button"
                  role="menuitemradio"
                  aria-checked={selected}
                  className={`plot-section-menu-item ${selected ? 'is-active' : ''}`.trim()}
                  onClick={() => handleSectionSelect(section)}
                >
                  <span className="plot-section-menu-icon" aria-hidden="true">{section.icon}</span>
                  <span>{ENGLISH_SECTION_LABELS[section.key] ?? section.label}</span>
                </button>
              )
            })}
          </div>
        ) : null}
      </div>

      {actions ? <div className="plot-compact-actions">{actions}</div> : null}
      {actions ? (
        <details className="plot-compact-actions-menu">
          <summary>Actions</summary>
          <div className="plot-compact-actions-menu-list">
            {actions}
          </div>
        </details>
      ) : null}
    </section>
  )
}
