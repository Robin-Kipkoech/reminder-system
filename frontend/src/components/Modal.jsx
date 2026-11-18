import { useEffect, useRef } from 'react'
import { X } from 'lucide-react'

export default function Modal({ open, title, onClose, children, size = 'md' }) {
    const ref = useRef(null)

    useEffect(() => {
        const dialog = ref.current
        if (!dialog) return
        if (open && !dialog.open) dialog.showModal()
        if (!open && dialog.open) dialog.close()
    }, [open])

    return (
        <dialog
            ref={ref}
            className={`md-modal md-modal-${size}`}
            onClose={onClose}
            onClick={(event) => { if (event.target === ref.current) onClose() }}
            aria-label={title}
        >
            {open && (
                <div className="md-modal-body">
                    <header className="md-modal-header">
                        <h2>{title}</h2>
                        <button type="button" className="md-icon-btn" aria-label="Close" onClick={onClose}>
                            <X size={18} />
                        </button>
                    </header>
                    {children}
                </div>
            )}
        </dialog>
    )
}