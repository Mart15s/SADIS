export default function PageHeader({
  title,
  description,
  eyebrow,
  meta,
  actions,
  className = '',
}) {
  return (
    <header className={`page-header page-header-premium ${className}`.trim()}>
      <div className="page-header-copy">
        {eyebrow ? <span className="page-header-eyebrow">{eyebrow}</span> : null}
        <h1>{title}</h1>
        {description ? <p>{description}</p> : null}
        {meta ? <div className="page-header-meta">{meta}</div> : null}
      </div>
      {actions ? <div className="header-actions">{actions}</div> : null}
    </header>
  )
}
