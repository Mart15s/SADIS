import { memo } from 'react'
import Surface from './Surface.jsx'

function Card({ children, className = '', tone = 'default', as = 'article' }) {
  return (
    <Surface as={as} tone={tone} className={`card card-${tone} ${className}`.trim()}>
      {children}
    </Surface>
  )
}

export default memo(Card)
