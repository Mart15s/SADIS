import { memo } from 'react'
import StatCard from './StatCard.jsx'

function MetricCard(props) {
  return <StatCard {...props} />
}

export default memo(MetricCard)
