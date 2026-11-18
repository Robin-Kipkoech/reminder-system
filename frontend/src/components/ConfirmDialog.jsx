import Button from './Button'
import Modal from './Modal'

export default function ConfirmDialog({
                                          open, title, children, confirmLabel = 'Confirm', danger = false, busy = false, onConfirm, onCancel,
                                      }) {
    return (
        <Modal open={open} title={title} onClose={onCancel} size="sm">
            <p className="md-confirm-text">{children}</p>
            <div className="md-actions">
                <Button className="btn-secondary" onClick={onCancel}>Cancel</Button>
                <Button className={danger ? 'btn-danger' : ''} loading={busy} onClick={onConfirm}>
                    {confirmLabel}
                </Button>
            </div>
        </Modal>
    )
}