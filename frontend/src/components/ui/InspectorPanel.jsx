export default function InspectorPanel({
  title,
  description = null,
  meta = null,
  children,
  className = '',
}) {
  return (
    <aside className={`inspector-panel ${className}`.trim()} aria-label={title}>
      <header className="inspector-panel-header">
        <div className="inspector-panel-title-block">
          <h2 className="inspector-panel-title">{title}</h2>
          {description ? <p>{description}</p> : null}
        </div>
        {meta ? <div className="inspector-panel-meta">{meta}</div> : null}
      </header>
      <div className="inspector-panel-body">
        {children}
      </div>
    </aside>
  )
}

export function InspectorSection({
  title,
  description = null,
  meta = null,
  children,
  className = '',
}) {
  return (
    <section className={`inspector-section workspace-context-card ${className}`.trim()}>
      <div className="workspace-context-card-head inspector-section-head">
        <div className="page-stack">
          <h3 className="section-title">{title}</h3>
          {description ? <p className="section-copy">{description}</p> : null}
        </div>
        {meta}
      </div>
      {children}
    </section>
  )
}
