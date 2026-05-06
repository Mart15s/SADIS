import { createElement, memo } from 'react'

function ResourceCard({
  children,
  className = '',
  as: Component = 'article',
}) {
  return createElement(Component, { className: `resource-card ${className}`.trim() }, children)
}

export function ResourceCardHeader({
  title,
  subtitle = null,
  badge = null,
  children = null,
  className = '',
}) {
  return (
    <header className={`resource-card-header ${className}`.trim()}>
      <div className="resource-card-title-group">
        {title ? <h3 className="resource-card-title">{title}</h3> : null}
        {subtitle ? <span className="resource-card-subtitle">{subtitle}</span> : null}
        {children}
      </div>
      {badge ? <div className="resource-card-header-badge">{badge}</div> : null}
    </header>
  )
}

export function ResourceCardMeta({ children, className = '' }) {
  if (!children) return null

  return (
    <div className={`resource-card-meta ${className}`.trim()}>
      {children}
    </div>
  )
}

export function ResourceCardBody({ children, className = '' }) {
  if (!children) return null

  return (
    <div className={`resource-card-body ${className}`.trim()}>
      {children}
    </div>
  )
}

export function ResourceCardFooter({ children, className = '' }) {
  if (!children) return null

  return (
    <footer className={`resource-card-footer ${className}`.trim()}>
      {children}
    </footer>
  )
}

export default memo(ResourceCard)
