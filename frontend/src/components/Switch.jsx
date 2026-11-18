import { forwardRef } from 'react'

// A styled on/off switch. Works controlled (checked + onChange) or with react-hook-form's register().
const Switch = forwardRef(function Switch({ label, className = '', ...props }, ref) {
  return (
    <label className={`st-switch ${className}`.trim()}>
      <input ref={ref} type="checkbox" role="switch" aria-label={label} {...props} />
      <span className="st-track" aria-hidden="true" />
    </label>
  )
})

export default Switch