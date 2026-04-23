export default function FormSection({
  title,
  description,
  actions = null,
  children,
  className = '',
}) {
  return (
    <section className={`section-card form-section ${className}`.trim()}>
      {(title || description || actions) ? (
        <header className="section-card-header">
          <div className="section-card-copy">
            {title ? <h2 className="section-card-title">{title}</h2> : null}
            {description ? <p className="section-card-description">{description}</p> : null}
          </div>
          {actions ? <div className="section-card-actions">{actions}</div> : null}
        </header>
      ) : null}
      <div className="section-card-body">
        {children}
      </div>
    </section>
  )
}
