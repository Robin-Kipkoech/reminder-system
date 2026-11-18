const SIZES = { sm: 30, md: 40, lg: 56 }

export default function Avatar({ user, size = 'md', className = '' }) {
    const name = user?.name?.trim() || '?'
    const initials = name.split(/\s+/).slice(0, 2).map((part) => part[0]?.toUpperCase()).join('') || '?'
    const seed = Number(user?.id) || name.length
    const px = SIZES[size] ?? SIZES.md
    return (
        <span
            className={`avatar ${className}`.trim()}
            style={{
                width: px,
                height: px,
                fontSize: Math.round(px * 0.4),
                background: `hsl(${(seed * 47) % 360} 50% 40%)`,
            }}
            aria-hidden="true"
            title={name}
        >
            {initials}
        </span>
    )
}
