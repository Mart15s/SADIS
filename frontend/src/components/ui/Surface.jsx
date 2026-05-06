import { createElement } from 'react'

export default function Surface({
  as = 'section',
  children,
  className = '',
  tone = 'default',
  ...props
}) {
  return createElement(as, {
    className: `surface surface-${tone} ${className}`.trim(),
    ...props,
  }, children)
}
