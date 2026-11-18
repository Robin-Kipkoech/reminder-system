const TOKEN = /@\[(.*?)\]\(user:(\d+)\)/g

export function renderMentions(body) {
  const text = String(body ?? '')
  const parts = []
  let last = 0

  for (const match of text.matchAll(TOKEN)) {
    if (match.index > last) parts.push(text.slice(last, match.index))
    parts.push(<span key={match.index} className="mention">@{match[1]}</span>)
    last = match.index + match[0].length
  }

  if (last < text.length) parts.push(text.slice(last))
  return parts
}

export function withMentionTokens(text, mentions) {
  let output = text
  const sorted = [...mentions].sort((a, b) => b.name.length - a.name.length)
  for (const person of sorted) {
    output = output.split(`@${person.name}`).join(`@[${person.name}](user:${person.id})`)
  }
  return output
}
