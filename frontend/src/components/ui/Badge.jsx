import { memo } from 'react'

function Badge({ children, tone = 'neutral', size = 'md', className = '' }) {
  return <span className={`badge badge-${tone} badge-${size} ${className}`.trim()}>{children}</span>
}

export default memo(Badge)
