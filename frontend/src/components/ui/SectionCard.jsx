import SectionHeader from './SectionHeader.jsx'
import Surface from './Surface.jsx'

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
    <Surface as="section" tone={tone} className={`section-card section-card-${tone} ${compact ? 'section-card-compact' : ''} ${className}`.trim()}>
      <SectionHeader title={title} description={description} actions={actions} className="section-card-header" />
      {children ? <div className="section-card-body">{children}</div> : null}
    </Surface>
  )
}
