import { useState } from 'react'
import { Link } from 'react-router-dom'
import { Check, Pencil, RotateCcw, Trash2 } from 'lucide-react'
import Button from '../../../components/Button'
import { useToast } from '../../../components/Toast'
import { errorMessage } from '../../../lib/api'
import {
    useCommentMutations, useComments, useTask, useTaskMutations, useUserOptions,
} from '../hooks/useTasks'
import MentionTextarea from './MentionTextarea'
import { renderMentions, withMentionTokens } from './mentionUtils'
import { dueLabel, formatDate, offsetLabel, recurrenceLabel, STATUS_LABELS } from '../utils'

function formatTimestamp(value) {
    if (!value) return ''
    return new Date(value).toLocaleString('en-GB', {
        day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit',
    })
}

function Comments({ taskId }) {
    const toast = useToast()
    const { data: comments = [], isLoading, isError, refetch } = useComments(taskId)
    const { add, remove } = useCommentMutations(taskId)
    const users = useUserOptions().data ?? []
    const [body, setBody] = useState('')
    const [mentions, setMentions] = useState([])

    async function submit(event) {
        event.preventDefault()
        const text = body.trim()
        if (!text) return
        try {
            await add.mutateAsync(withMentionTokens(text, mentions))
            setBody('')
            setMentions([])
        } catch (error) {
            toast.error(errorMessage(error))
        }
    }

    return (
        <section className="tk-comments" aria-label="Comments">
            <h3>Comments</h3>
            {isError && (
                <div className="md-state">
                    <p>We couldn’t load comments.</p>
                    <Button className="btn-secondary" onClick={() => refetch()}>Try again</Button>
                </div>
            )}
            {isLoading && <p className="tk-hint">Loading…</p>}
            {!isLoading && !isError && comments.length === 0 && <p className="tk-hint">No comments yet.</p>}
            {!isError && comments.length > 0 && <ul>
                {comments.map((comment) => (
                    <li key={comment.id}>
                        <div className="tk-comment-head">
                            <strong>{comment.author?.name}</strong>
                            <time>{new Date(comment.created_at).toLocaleString('en-GB', { dateStyle: 'medium', timeStyle: 'short' })}</time>
                            {comment.can_delete && (
                                <button type="button" className="md-icon-btn md-icon-danger" aria-label="Delete comment"
                                    onClick={() => remove.mutate(comment.id, { onError: (error) => toast.error(errorMessage(error)) })}>
                                    <Trash2 size={15} />
                                </button>
                            )}
                        </div>
                        <p>{renderMentions(comment.body)}</p>
                    </li>
                ))}
            </ul>}
            <form className="tk-comment-form" onSubmit={submit}>
                <MentionTextarea
                    rows={2}
                    placeholder="Write a comment… type @ to mention someone"
                    aria-label="Write a comment"
                    maxLength={1900}
                    value={body}
                    onChange={setBody}
                    mentions={mentions}
                    onMentionsChange={setMentions}
                    users={users}
                />
                <Button type="submit" loading={add.isPending} disabled={!body.trim()}>Post</Button>
            </form>
        </section>
    )
}

export default function TaskDetail({ id, onEdit, onDelete }) {
    const toast = useToast()
    const { data: task, isLoading, isError, refetch } = useTask(id)
    const { complete, setStatus } = useTaskMutations()

    async function changeStatus() {
        try {
            if (task.status === 'done') {
                await setStatus.mutateAsync({ id, status: 'open' })
                toast.success('Task reopened.')
            } else {
                await complete.mutateAsync(id)
                toast.success('Task completed.')
            }
        } catch (error) {
            toast.error(errorMessage(error))
        }
    }

    if (isLoading) return <div className="md-state" role="status">Loading task…</div>
    if (isError || !task) {
        return (
            <div className="md-state">
                <p>We couldn’t load this task.</p>
                <Button className="btn-secondary" onClick={() => refetch()}>Try again</Button>
            </div>
        )
    }

    const busy = complete.isPending || setStatus.isPending
    const overdue = task.is_overdue && task.status !== 'done'

    return (
        <div className="tk-detail">
            <div className="tk-detail-top">
                <span className={`md-badge tk-status-${task.status}`}>{STATUS_LABELS[task.status] ?? task.status}</span>
                <span className={overdue ? 'is-overdue' : ''}>{dueLabel(task)}</span>
            </div>

            {task.description && <p className="tk-description">{task.description}</p>}

            <dl className="tk-meta">
                <div><dt>Client</dt><dd>{task.client?.id ? <Link to={`/clients/${task.client.id}`}>{task.client.name}</Link> : '—'}</dd></div>
                <div><dt>Department</dt><dd>{task.department?.name ?? '—'}</dd></div>
                <div><dt>Due date</dt><dd>{formatDate(task.due_date)}</dd></div>
                <div><dt>Visibility</dt><dd>{task.visibility === 'involved_only' ? 'People involved only' : 'Everyone'}</dd></div>
                <div><dt>Responsible</dt><dd>{task.assignees?.map((assignee) => assignee.name).join(', ') || '—'}</dd></div>
                <div><dt>Created by</dt><dd>{task.creator?.name ?? '—'}</dd></div>
                <div><dt>Reminders</dt><dd>{task.reminder_periods?.map(offsetLabel).join(', ') || 'None'}</dd></div>
                <div><dt>Recurrence</dt><dd>{recurrenceLabel(task)}</dd></div>
                {task.completed_by && (
                    <div><dt>Completed by</dt><dd>{task.completed_by.name} · {formatTimestamp(task.completed_at)}</dd></div>
                )}
            </dl>

            <div className="tk-actions">
                {task.can_edit && (
                    <>
                        <Button onClick={changeStatus} loading={busy}>
                            {task.status === 'done' ? <RotateCcw size={17} /> : <Check size={17} />}
                            {task.status === 'done' ? 'Reopen task' : 'Mark complete'}
                        </Button>
                        <Button className="btn-secondary" onClick={() => onEdit(task)}>
                            <Pencil size={17} /> Edit
                        </Button>
                    </>
                )}
                {task.can_delete && (
                    <Button className="btn-danger" onClick={() => onDelete(task)}>
                        <Trash2 size={17} /> Delete
                    </Button>
                )}
            </div>

            <Comments taskId={id} />
        </div>
    )
}
