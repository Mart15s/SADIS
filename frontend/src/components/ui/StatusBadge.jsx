import Badge from './Badge.jsx'

const KIND_TO_TONE = {
  status: 'success',
  ownership: 'neutral',
  connection: 'info',
  selection: 'info',
  severity: 'danger',
}

export default function StatusBadge({
  children,
  kind = 'status',
  tone,
  className = '',
  size = 'md',
}) {
  return (
    <Badge tone={tone ?? KIND_TO_TONE[kind] ?? 'neutral'} size={size} className={`status-badge status-badge-${kind} ${className}`.trim()}>
      {children}
    </Badge>
  )
}
