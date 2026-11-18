import { useState } from 'react'
import { useQueryClient } from '@tanstack/react-query'
import { Plus } from 'lucide-react'
import { useAuth } from '../../../auth/useAuth'
import Button from '../../../components/Button'
import Modal from '../../../components/Modal'
import { nairobiGreeting, nairobiToday } from '../../../lib/time'
import RenewDialog from '../../items/components/RenewDialog'
import TaskForm from '../../tasks/componenets/TaskForm'
import {
    ActivityPanel, ItemsExpiringPanel, MyTasksPanel, RankedPanel, StatCards, UpcomingPanel, WorkloadPanel,
} from '../components/DashboardPanels'
import { useDashboard } from '../hooks/useDashboard'

export default function DashboardPage() {
    const { user } = useAuth()
    const queryClient = useQueryClient()
    const { data, isLoading, isError, refetch } = useDashboard()
    const [creating, setCreating] = useState(false)
    const [renewItem, setRenewItem] = useState(null)

    const firstName = user?.name?.trim()?.split(/\s+/)[0] ?? ''
    const refresh = () => queryClient.invalidateQueries({ queryKey: ['dashboard'] })
    const isManager = data?.items_expiring != null

    return (
        <div className="md-page">
            <header className="md-page-header">
                <div>
                    <h1>{nairobiGreeting()}, {firstName}</h1>
                    <p>{nairobiToday()}</p>
                </div>
                <Button onClick={() => setCreating(true)}><Plus size={18} /> New task</Button>
            </header>

            {isError ? (
                <div className="md-state">
                    <p>We couldn’t load your dashboard.</p>
                    <Button className="btn-secondary" onClick={() => refetch()}>Try again</Button>
                </div>
            ) : isLoading || !data ? (
                <div className="md-skeletons" role="status" aria-label="Loading">
                    {Array.from({ length: 6 }, (_, index) => (
                        <div key={index} className="md-skeleton" style={{ height: 90 }} />
                    ))}
                </div>
            ) : (
                <>
                    <StatCards stats={data.stats ?? {}} showItems={isManager} />
                    <div className="db-grid">
                        <MyTasksPanel tasks={data.my_tasks ?? []} />
                        <UpcomingPanel entries={data.upcoming ?? []} />
                        {isManager && (
                            <>
                                <ItemsExpiringPanel buckets={data.items_expiring} onRenew={setRenewItem} />
                                <WorkloadPanel rows={data.workload ?? []} />
                                <RankedPanel
                                    title="Open work by client"
                                    rows={data.by_client ?? []}
                                    labelOf={(row) => row.client?.name}
                                />
                                <RankedPanel
                                    title="Open work by department"
                                    rows={data.by_department ?? []}
                                    labelOf={(row) => row.department?.name}
                                />
                                <ActivityPanel entries={data.recent_activity ?? []} />
                            </>
                        )}
                    </div>
                </>
            )}

            <Modal open={creating} title="New task" size="lg" onClose={() => setCreating(false)}>
                {creating && <TaskForm task={null} onSaved={refresh} onClose={() => setCreating(false)} />}
            </Modal>
            <RenewDialog item={renewItem} onClose={() => { setRenewItem(null); refresh() }} />
        </div>
    )
}
