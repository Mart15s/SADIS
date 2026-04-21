import { memo } from 'react'

function MetricCard({ label, value, note }) {
  return (
    <div className="metric-card panel">
      <span className="metric-label">{label}</span>
      <strong className="metric-value">{value}</strong>
      {note ? <span className="metric-note">{note}</span> : null}
    </div>
  )
}

export default memo(MetricCard)
