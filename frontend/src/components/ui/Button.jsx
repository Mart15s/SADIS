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
      aria-pressed={variant === 'toggle' ? active : undefined}
      {...props}
    >
      {loading ? <span className="button-spinner" aria-hidden="true" /> : leadingIcon}
      {children}
    </button>
  )
}
