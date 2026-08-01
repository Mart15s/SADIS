export default function Button({
  children,
  className = '',
  variant = 'primary',
  size = 'md',
  loading = false,
  leadingIcon = null,
  type = 'button',
  disabled = false,
  active = false,
  fullWidth = false,
  ...props
}) {
  const accessibleName = props['aria-label'] ?? (typeof children === 'string' ? children : undefined)
  return (
    <button
      type={type}
      className={[
        'button',
        `button-${variant}`,
        `button-${size}`,
        loading ? 'is-loading' : '',
        active ? 'is-active' : '',
        fullWidth ? 'button-block' : '',
        className,
      ].filter(Boolean).join(' ')}
      disabled={disabled || loading}
      aria-label={accessibleName}
      aria-busy={loading || undefined}
      aria-pressed={variant === 'toggle' ? active : undefined}
      {...props}
    >
      {loading ? <span className="button-spinner" aria-hidden="true" /> : leadingIcon}
      {children}
    </button>
  )
}
