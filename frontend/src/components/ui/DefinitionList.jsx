function hasRenderableValue(value) {
  return value !== null && value !== undefined && value !== ''
}

export function DefinitionItem({
  label,
  value,
  children,
  className = '',
}) {
  const renderedValue = hasRenderableValue(children) ? children : value

  return (
    <div className={`definition-item ${className}`.trim()}>
      <dt className="definition-item-label">{label}</dt>
      <dd className="definition-item-value">
        {hasRenderableValue(renderedValue) ? renderedValue : 'Not set'}
      </dd>
    </div>
  )
}

export function DefinitionList({ items = [], children, className = '' }) {
  return (
    <dl className={`definition-list ${className}`.trim()}>
      {children ?? items.map((item) => (
        <DefinitionItem
          key={item.key ?? item.label}
          label={item.label}
          value={item.value}
          className={item.className ?? ''}
        />
      ))}
    </dl>
  )
}

export function KeyValueGrid({ items = [], children, className = '' }) {
  return (
    <dl className={`key-value-grid ${className}`.trim()}>
      {children ?? items.map((item) => (
        <DefinitionItem
          key={item.key ?? item.label}
          label={item.label}
          value={item.value}
          className={item.className ?? ''}
        />
      ))}
    </dl>
  )
}

export function StatRow({ label, value, children, className = '' }) {
  const renderedValue = hasRenderableValue(children) ? children : value

  return (
    <div className={`stat-row ${className}`.trim()}>
      <span className="stat-row-label">{label}</span>
      <strong className="stat-row-value">
        {hasRenderableValue(renderedValue) ? renderedValue : 'Not set'}
      </strong>
    </div>
  )
}
