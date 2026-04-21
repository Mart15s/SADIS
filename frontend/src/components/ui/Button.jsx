export default function Button({
  children,
  className = '',
  variant = 'primary',
  size = 'md',
  loading = false,
  leadingIcon = null,
  type = 'button',
  disabled = false,
  ...props
}) {
  return (
    <button
      type={type}
      className={`button button-${variant} button-${size} ${loading ? 'is-loading' : ''} ${className}`.trim()}
      disabled={disabled || loading}
      {...props}
    >
      {loading ? <span className="button-spinner" aria-hidden="true" /> : leadingIcon}
      {children}
    </button>
  )
}
