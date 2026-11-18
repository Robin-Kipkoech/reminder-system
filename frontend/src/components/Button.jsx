export default function Button({
  children,
  className = '',
  loading = false,
  disabled = false,
  type = 'button',
  ...props
}) {
  return (
    <button
      className={`login-button ${className}`.trim()}
      type={type}
      disabled={disabled || loading}
      {...props}
    >
      {loading && <span className="login-spinner" aria-hidden="true" />}
      {children}
    </button>
  )
}
