export default function StatCard({
  label,
  value,
  note = null,
  accent = 'default',
  className = '',
}) {
  return (
    <article className={`stat-card stat-card-${accent} ${className}`.trim()}>
      <span className="stat-card-label">{label}</span>
      <strong className="stat-card-value">{value}</strong>
      {note ? <span className="stat-card-note">{note}</span> : null}
    </article>
  )
}
