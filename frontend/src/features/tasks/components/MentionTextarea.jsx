import { useMemo, useRef, useState } from 'react'

export default function MentionTextarea({ value, onChange, mentions, onMentionsChange, users, ...props }) {
  const ref = useRef(null)
  const [query, setQuery] = useState(null)
  const [active, setActive] = useState(0)

  const matches = useMemo(() => {
    if (!query) return []
    const search = query.text.toLowerCase()
    return users.filter((user) => user.name.toLowerCase().includes(search)).slice(0, 6)
  }, [query, users])
  const open = query !== null && matches.length > 0

  function detect(text, caret) {
    const found = /(^|\s)@([^\s@]{0,30})$/.exec(text.slice(0, caret))
    if (found) {
      setQuery({ start: caret - found[2].length - 1, text: found[2] })
      setActive(0)
    } else {
      setQuery(null)
    }
  }

  function handleChange(event) {
    onChange(event.target.value)
    detect(event.target.value, event.target.selectionStart)
  }

  function pick(user) {
    const caret = ref.current.selectionStart
    const insert = `@${user.name} `
    onChange(value.slice(0, query.start) + insert + value.slice(caret))
    if (!mentions.some((mention) => mention.id === user.id)) {
      onMentionsChange([...mentions, { id: user.id, name: user.name }])
    }
    const position = query.start + insert.length
    setQuery(null)
    requestAnimationFrame(() => {
      ref.current?.focus()
      ref.current?.setSelectionRange(position, position)
    })
  }

  function handleKeyDown(event) {
    if (!open) return
    if (event.key === 'ArrowDown') {
      event.preventDefault()
      setActive((index) => (index + 1) % matches.length)
    } else if (event.key === 'ArrowUp') {
      event.preventDefault()
      setActive((index) => (index - 1 + matches.length) % matches.length)
    } else if (event.key === 'Enter' || event.key === 'Tab') {
      event.preventDefault()
      pick(matches[active])
    } else if (event.key === 'Escape') {
      event.preventDefault()
      event.stopPropagation()
      setQuery(null)
    }
  }

  return (
    <div className="mention-wrap">
      <div className="field-control">
        <textarea
          ref={ref}
          className="field-input"
          value={value}
          onChange={handleChange}
          onKeyDown={handleKeyDown}
          onClick={(event) => detect(event.target.value, event.target.selectionStart)}
          onBlur={() => setTimeout(() => setQuery(null), 120)}
          role="combobox"
          aria-autocomplete="list"
          aria-expanded={open}
          aria-controls="mention-list"
          aria-activedescendant={open ? `mention-option-${matches[active]?.id}` : undefined}
          {...props}
        />
      </div>
      {open && (
        <ul className="mention-list" id="mention-list" role="listbox" aria-label="Mention a person">
          {matches.map((user, index) => (
            <li
              key={user.id}
              id={`mention-option-${user.id}`}
              role="option"
              aria-selected={index === active}
              className={index === active ? 'active' : ''}
              onMouseDown={(event) => { event.preventDefault(); pick(user) }}
              onMouseEnter={() => setActive(index)}
            >
              {user.name} <small>{user.role}</small>
            </li>
          ))}
        </ul>
      )}
    </div>
  )
}
