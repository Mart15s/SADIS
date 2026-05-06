import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { Link, NavLink } from 'react-router-dom'

const sections = [
  { key: 'editor', label: 'Editor', to: (plotId) => `/plots/${plotId}` },
  { key: 'calendar', label: 'Calendar', to: (plotId) => `/plots/${plotId}/calendar` },
  { key: 'history', label: 'History', to: (plotId) => `/plots/${plotId}/history` },
  { key: 'harvests', label: 'Harvests', to: (plotId) => `/plots/${plotId}/harvests` },
  { key: 'analytics', label: 'Analytics', to: (plotId) => `/plots/${plotId}/analytics` },
  { key: 'sharing', label: 'Sharing', to: (plotId) => `/plots/${plotId}/sharing`, ownerOnly: true },
  { key: 'rotation', label: 'Rotation', to: (plotId) => `/plots/${plotId}/rotation` },
]

function getSectionLabel(sectionKey, fallback) {
  return fallback ?? sections.find((section) => section.key === sectionKey)?.label ?? 'Editor'
}

export default function PlotSectionNav({
  plotId,
  isOwner = false,
  plotName = 'Plot',
  sectionKey = 'editor',
  sectionLabel,
  description = '',
  meta = null,
  actions = null,
  compact = true,
}) {
  const activeSectionLabel = getSectionLabel(sectionKey, sectionLabel)
  const visibleSections = useMemo(
    () => sections.filter((section) => !section.ownerOnly || isOwner),
    [isOwner]
  )

  const tabsViewportRef = useRef(null)
  const tabsNavRef = useRef(null)

  const [canScrollLeft, setCanScrollLeft] = useState(false)
  const [canScrollRight, setCanScrollRight] = useState(false)

  const updateTabsScrollState = useCallback(() => {
    const el = tabsViewportRef.current
    if (!el) return

    const maxScrollLeft = el.scrollWidth - el.clientWidth
    const hasOverflow = maxScrollLeft > 2

    setCanScrollLeft(hasOverflow && el.scrollLeft > 2)
    setCanScrollRight(hasOverflow && el.scrollLeft < maxScrollLeft - 2)
  }, [])

  const scrollTabs = useCallback((direction) => {
    const el = tabsViewportRef.current
    if (!el) return

    const amount = Math.max(180, el.clientWidth * 0.45)

    el.scrollBy({
      left: direction === 'left' ? -amount : amount,
      behavior: 'smooth',
    })
  }, [])

  useEffect(() => {
    updateTabsScrollState()

    const el = tabsViewportRef.current
    if (!el) return undefined

    const onResize = () => updateTabsScrollState()

    window.addEventListener('resize', onResize)

    let resizeObserver = null
    if (typeof ResizeObserver !== 'undefined') {
      resizeObserver = new ResizeObserver(() => updateTabsScrollState())
      resizeObserver.observe(el)
    }

    const rafId = window.requestAnimationFrame(() => {
      updateTabsScrollState()
    })

    return () => {
      window.removeEventListener('resize', onResize)
      window.cancelAnimationFrame(rafId)
      if (resizeObserver) resizeObserver.disconnect()
    }
  }, [updateTabsScrollState, visibleSections.length, plotId, compact])

  useEffect(() => {
    const nav = tabsNavRef.current
    const viewport = tabsViewportRef.current
    if (!nav || !viewport) return

    const activeEl = nav.querySelector('.is-active')
    if (!activeEl) return

    const rafId = window.requestAnimationFrame(() => {
      activeEl.scrollIntoView({
        behavior: 'smooth',
        block: 'nearest',
        inline: 'nearest',
      })
    })

    return () => window.cancelAnimationFrame(rafId)
  }, [sectionKey, plotId, isOwner])

  const tabsShellClassName = [
    'plot-tabs-shell',
    compact ? 'is-compact' : 'is-regular',
    canScrollLeft ? 'has-left-overflow' : '',
    canScrollRight ? 'has-right-overflow' : '',
  ]
    .filter(Boolean)
    .join(' ')

  const tabs = (
    <div className={tabsShellClassName}>
      <button
        type="button"
        className={`plot-tabs-scroll-btn plot-tabs-scroll-btn-left ${canScrollLeft ? 'is-visible' : ''}`}
        onClick={() => scrollTabs('left')}
        disabled={!canScrollLeft}
        aria-label="Scroll plot sections left"
      >
        <span aria-hidden="true">‹</span>
      </button>

      <div
        ref={tabsViewportRef}
        className="plot-tabs-viewport"
        onScroll={updateTabsScrollState}
      >
        <nav
          ref={tabsNavRef}
          className={compact ? 'plot-compact-tabs' : 'plot-section-nav'}
          aria-label="Plot sections"
        >
          {visibleSections.map((section) => (
            <NavLink
              key={section.key}
              to={section.to(plotId)}
              end={section.key === 'editor'}
              className={({ isActive }) =>
                `plot-section-link ${compact ? 'plot-compact-tab' : 'plot-regular-tab'} ${isActive ? 'is-active' : ''}`.trim()
              }
            >
              {section.label}
            </NavLink>
          ))}
        </nav>
      </div>

      <button
        type="button"
        className={`plot-tabs-scroll-btn plot-tabs-scroll-btn-right ${canScrollRight ? 'is-visible' : ''}`}
        onClick={() => scrollTabs('right')}
        disabled={!canScrollRight}
        aria-label="Scroll plot sections right"
      >
        <span aria-hidden="true">›</span>
      </button>
    </div>
  )

  if (compact) {
    return (
      <section className="plot-compact-nav plot-page-header" aria-label="Plot workspace">
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

        <div className="plot-page-header-tabs">
          {tabs}
        </div>

        {actions ? <div className="plot-compact-actions">{actions}</div> : null}
      </section>
    )
  }

  return (
    <section className="plot-workspace-nav" aria-label="Plot workspace">
      <div className="plot-workspace-nav-head">
        <Link className="plot-workspace-back" to="/plots">
          Back
        </Link>

        <div className="plot-workspace-title-block">
          <nav className="plot-breadcrumb" aria-label="Breadcrumb">
            <Link to="/plots">Plots</Link>
            <span aria-hidden="true">/</span>
            <Link to={`/plots/${plotId}`}>{plotName}</Link>
            <span aria-hidden="true">/</span>
            <span aria-current="page">{activeSectionLabel}</span>
          </nav>

          <h1 className="plot-workspace-title">{plotName}</h1>
          {description ? <p className="plot-workspace-description">{description}</p> : null}
        </div>

        {meta ? <div className="plot-workspace-meta">{meta}</div> : <div className="plot-workspace-meta" />}
      </div>

      <div className="plot-section-tabs-scroll" role="presentation">
        {tabs}
      </div>

      {actions ? <div className="plot-workspace-actions">{actions}</div> : null}
    </section>
  )
}
