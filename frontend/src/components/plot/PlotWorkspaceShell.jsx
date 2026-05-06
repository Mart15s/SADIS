export function WorkspaceTopBar({
  eyebrow,
  title,
  subtitle,
  meta = null,
  modes = null,
  actions = null,
  leading = null,
  className = '',
}) {
  return (
    <section className={`plot-workspace-command-bar ${className}`.trim()} aria-label={title}>
      <div className="plot-workspace-command-left">
        {leading}
        <div className="plot-workspace-command-copy">
          {eyebrow ? <span className="plot-workspace-command-eyebrow">{eyebrow}</span> : null}
          <div className="plot-workspace-command-title-row">
            <h1>{title}</h1>
            {meta ? <div className="plot-workspace-command-meta">{meta}</div> : null}
          </div>
          {subtitle ? <p>{subtitle}</p> : null}
        </div>
      </div>

      {modes ? <div className="plot-workspace-command-modes">{modes}</div> : null}
      {actions ? <div className="plot-workspace-command-actions">{actions}</div> : null}
    </section>
  )
}

export function WorkspaceStage({ children, className = '' }) {
  return (
    <section className={`plot-workspace-stage ${className}`.trim()}>
      {children}
    </section>
  )
}

export function FloatingPanel({
  children,
  position = 'left',
  className = '',
  as = 'aside',
  ...props
}) {
  const PanelTag = as

  return (
    <PanelTag
      className={`plot-floating-panel plot-floating-panel--${position} ${className}`.trim()}
      {...props}
    >
      {children}
    </PanelTag>
  )
}
