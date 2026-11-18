import { useMemo, useState } from 'react'

export default function MultiPick({ label, options, value, onChange, searchable = false, error, hint }) {
    const [query, setQuery] = useState('')
    const visible = useMemo(() => {
        const q = query.trim().toLowerCase()
        return q ? options.filter((o) => o.label.toLowerCase().includes(q)) : options
    }, [options, query])

    function toggle(id) {
        onChange(value.includes(id) ? value.filter((v) => v !== id) : [...value, id])
    }

    return (
        <fieldset className="tk-multi">
            <legend className="field-label">{label}</legend>
            {hint && <p className="tk-hint">{hint}</p>}
            <div className={`tk-multi-box${error ? ' has-error' : ''}`}>
                {searchable && (
                    <input
                        type="search"
                        className="tk-multi-search"
                        placeholder="Search…"
                        aria-label={`Search ${label}`}
                        value={query}
                        onChange={(event) => setQuery(event.target.value)}
                    />
                )}
                <div className="tk-multi-list">
                    {visible.length === 0 && <p className="tk-hint">Nothing to show.</p>}
                    {visible.map((option) => (
                        <label key={option.id} className="tk-multi-item">
                            <input type="checkbox" checked={value.includes(option.id)} onChange={() => toggle(option.id)} />
                            <span>{option.label}</span>
                            {option.note && <small>{option.note}</small>}
                        </label>
                    ))}
                </div>
            </div>
            {error && <p className="field-error">{error}</p>}
        </fieldset>
    )
}