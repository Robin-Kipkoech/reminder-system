import { useEffect, useState } from 'react'
import { Search } from 'lucide-react'

export default function SearchBox({ value, onSearch }) {
  const [text, setText] = useState(value)

  useEffect(() => {
    if (text.trim() === value) return undefined
    const timer = window.setTimeout(() => onSearch(text.trim()), 300)
    return () => window.clearTimeout(timer)
  }, [text, value, onSearch])

  return (
    <label className="search-field">
      <Search size={17} aria-hidden="true" />
      <span className="sr-only">Search users by name, email or phone</span>
      <input
        type="search"
        placeholder="Search users…"
        value={text}
        onChange={(event) => setText(event.target.value)}
      />
      {text && <button type="button" aria-label="Clear search" onClick={() => setText('')}>×</button>}
    </label>
  )
}
