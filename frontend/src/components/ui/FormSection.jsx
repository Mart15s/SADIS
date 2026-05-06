import SectionHeader from './SectionHeader.jsx'
import Surface from './Surface.jsx'

export default function FormSection({
  title,
  description,
  actions = null,
  children,
  className = '',
}) {
  return (
    <Surface as="section" className={`section-card form-section ${className}`.trim()}>
      <SectionHeader title={title} description={description} actions={actions} className="section-card-header" />
      <div className="section-card-body">
        {children}
      </div>
    </Surface>
  )
}
