export default function SectionHeader({
  title,
  description,
  actions = null,
  className = '',
}) {
  if (!title && !description && !actions) {
    return null
  }

  return (
    <header className={`section-header ${className}`.trim()}>
      <div className="section-header-copy">
        {title ? <h2 className="section-header-title">{title}</h2> : null}
        {description ? <p className="section-card-description">{description}</p> : null}
      </div>
      {actions ? <div className="section-header-actions">{actions}</div> : null}
    </header>
  )
}
