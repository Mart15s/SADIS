export default function SectionCard({
  title,
  description,
  actions = null,
  children,
  className = '',
  tone = 'default',
  compact = false,
}) {
  return (
    <section className={`section-card section-card-${tone} ${compact ? 'section-card-compact' : ''} ${className}`.trim()}>
      {(title || description || actions) ? (
        <header className="section-card-header">
          <div className="section-card-copy">
            {title ? <h2 className="section-card-title">{title}</h2> : null}
            {description ? <p className="section-card-description">{description}</p> : null}
          </div>
          {actions ? <div className="section-card-actions">{actions}</div> : null}
        </header>
      ) : null}
      {children ? <div className="section-card-body">{children}</div> : null}
    </section>
  )
}
