import { forwardRef } from 'react'

const Input = forwardRef(function Input(
    { id, label, error, trailing, className = '', ...props },
    ref,
) {
    const errorId = error ? `${id}-error` : undefined
    return (
        <div className={`field ${className}`.trim()}>
            <label className="field-label" htmlFor={id}>{label}</label>
            <div className={`field-control${error ? ' has-error' : ''}`}>
                <input
                    id={id}
                    ref={ref}
                    className="field-input"
                    aria-invalid={error ? 'true' : 'false'}
                    aria-describedby={errorId}
                    {...props}
                />
                {trailing}
            </div>
            {error && <p className="field-error" id={errorId}>{error}</p>}
        </div>
    )
})

export default Input