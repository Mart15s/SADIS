export default function EmptyStatePanel({
  title,
  description,
  action = null,
  className = '',
  tone = 'default',
  children = null,
}) {
  return (
    <section className={`empty-state-panel empty-state-panel-${tone} ${className}`.trim()}>
      {children}
      <div className="empty-state-panel-copy">
        <strong>{title}</strong>
        {description ? <p>{description}</p> : null}
      </div>
      {action ? <div className="empty-state-panel-action">{action}</div> : null}
    </section>
  )
}
