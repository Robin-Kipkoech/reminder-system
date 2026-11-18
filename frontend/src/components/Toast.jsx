import { createContext, useCallback, useContext, useMemo, useState } from 'react'
import { CheckCircle2, XCircle } from 'lucide-react'

const ToastContext = createContext(null)

export function ToastProvider({ children }) {
    const [toasts, setToasts] = useState([])

    const push = useCallback((type, message) => {
        const id = Date.now() + Math.random()
        setToasts((current) => [...current, { id, type, message }])
        setTimeout(() => setToasts((current) => current.filter((t) => t.id !== id)), 6000)
    }, [])

    const value = useMemo(
        () => ({ success: (m) => push('success', m), error: (m) => push('error', m) }),
        [push],
    )

    return (
        <ToastContext.Provider value={value}>
            {children}
            <div className="md-toasts" aria-live="polite">
                {toasts.map((t) => (
                    <div key={t.id} className={`md-toast md-toast-${t.type}`} role="status">
                        {t.type === 'success' ? <CheckCircle2 size={18} /> : <XCircle size={18} />}
                        <span>{t.message}</span>
                    </div>
                ))}
            </div>
        </ToastContext.Provider>
    )
}

export function useToast() {
    const value = useContext(ToastContext)
    if (!value) throw new Error('useToast must be used within ToastProvider')
    return value
}