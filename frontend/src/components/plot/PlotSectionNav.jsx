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
}) {
  const activeSectionLabel = getSectionLabel(sectionKey, sectionLabel)

  return (
    <section className="plot-workspace-nav" aria-label="Plot workspace">
      <div className="plot-workspace-nav-head">
        <Link className="plot-workspace-back" to="/plots">Back</Link>

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
          {meta ? <div className="plot-workspace-meta">{meta}</div> : null}
        </div>

        {actions ? <div className="plot-workspace-actions">{actions}</div> : null}
      </div>

      <div className="plot-section-tabs-scroll" role="presentation">
        <nav className="plot-section-nav" aria-label="Plot sections">
          {sections
            .filter((section) => !section.ownerOnly || isOwner)
            .map((section) => (
              <NavLink
                key={section.key}
                to={section.to(plotId)}
                end={section.key === 'editor'}
                className={({ isActive }) => `plot-section-link ${isActive ? 'is-active' : ''}`.trim()}
              >
                {section.label}
              </NavLink>
            ))}
        </nav>
      </div>
    </section>
  )
}
