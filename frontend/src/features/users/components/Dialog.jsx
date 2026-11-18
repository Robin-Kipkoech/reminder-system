import { useEffect, useRef } from 'react'
import { X } from 'lucide-react'

export default function Dialog({ open, title, onClose, children, size = 'regular' }) {
  const dialogRef = useRef(null)
  const titleId = `dialog-${title.toLowerCase().replace(/[^a-z0-9]+/g, '-')}`

  useEffect(() => {
    const dialog = dialogRef.current
    if (!dialog) return
    if (open && !dialog.open) dialog.showModal()
    if (!open && dialog.open) dialog.close()
  }, [open])

  return (
    <dialog
      ref={dialogRef}
      className={`dialog dialog-${size}`}
      aria-labelledby={titleId}
      onClose={onClose}
      onClick={(event) => {
        if (event.target === dialogRef.current) onClose()
      }}
    >
      <div className="dialog-heading">
        <div>
          <p className="eyebrow">USER MANAGEMENT</p>
          <h2 id={titleId}>{title}</h2>
        </div>
        <button className="icon-button" type="button" aria-label="Close dialog" onClick={onClose}>
          <X size={19} />
        </button>
      </div>
      {children}
    </dialog>
  )
}
