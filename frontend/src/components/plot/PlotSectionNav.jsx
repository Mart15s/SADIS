import { useEffect, useRef, useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'

const sections = [
  { key: 'editor', label: 'Planas', icon: '✎', to: (plotId) => `/plots/${plotId}` },
  { key: 'calendar', label: 'Kalendorius', icon: '◷', to: (plotId) => `/plots/${plotId}/calendar` },
  { key: 'history', label: 'Istorija', icon: '↺', to: (plotId) => `/plots/${plotId}/history` },
  { key: 'harvests', label: 'Derlius', icon: '✓', to: (plotId) => `/plots/${plotId}/harvests` },
  { key: 'analytics', label: 'Analitika', icon: '⌁', to: (plotId) => `/plots/${plotId}/analytics` },
  { key: 'sharing', label: 'Bendrinimas', icon: '↗', to: (plotId) => `/plots/${plotId}/sharing`, ownerOnly: true },
  { key: 'rotation', label: 'Rotacija', icon: '⟳', to: (plotId) => `/plots/${plotId}/rotation` },
]

function getSectionLabel(sectionKey, fallback) {
  return fallback ?? sections.find((section) => section.key === sectionKey)?.label ?? 'Planas'
}

export default function PlotSectionNav({
  plotId,
  isOwner = false,
  plotName = 'Sklypas',
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
    <section className="plot-compact-nav plot-page-header plot-section-switcher-shell" aria-label="Sklypo darbo sritis">
      <div className="plot-compact-left">
        <Link className="plot-compact-back" to="/plots" aria-label="Grįžti į sklypus">
          <span aria-hidden="true">&larr;</span>
        </Link>

        <div className="plot-compact-identity">
          <div className="plot-compact-title-block">
            <span className="plot-compact-kicker">{activeSectionLabel.toUpperCase()}</span>
            <h1 className="plot-compact-title">{plotName}</h1>
          </div>

          {meta ? (
            <div className="plot-compact-meta" aria-label="Sklypo metaduomenys">
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
          aria-label="Pasirinkti sklypo skyrių"
          onClick={() => setIsOpen((current) => !current)}
        >
          <span className="plot-section-select-icon" aria-hidden="true">{activeSection?.icon ?? '•'}</span>
          <span className="plot-section-trigger-text">Skyrius: {activeSectionLabel}</span>
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
                  <span>{section.label}</span>
                </button>
              )
            })}
          </div>
        ) : null}
      </div>

      {actions ? <div className="plot-compact-actions">{actions}</div> : null}
    </section>
  )
}
