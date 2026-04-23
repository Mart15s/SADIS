import { memo } from 'react'

function Card({ children, className = '', tone = 'default' }) {
  return (
    <article className={`card card-${tone} ${className}`.trim()}>
      {children}
    </article>
  )
}

export default memo(Card)
