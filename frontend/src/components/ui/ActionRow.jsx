export default function ActionRow({
  children,
  align = 'start',
  wrap = true,
  className = '',
}) {
  const classes = [
    'action-row',
    `action-row-${align}`,
    wrap ? 'action-row-wrap' : 'action-row-nowrap',
    className,
  ].filter(Boolean).join(' ')

  return (
    <div className={classes}>
      {children}
    </div>
  )
}
