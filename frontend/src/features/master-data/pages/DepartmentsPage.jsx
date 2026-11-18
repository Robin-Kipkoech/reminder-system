import { z } from 'zod'
import { departmentsApi } from '../api/masterDataApi'
import ResourcePage from '../components/ResourcePage'

const schema = z.object({
    name: z.string().trim().min(1, 'Name is required.').max(255, 'Name is too long.'),
    description: z.string().trim().max(1000),
    is_active: z.boolean(),
})

const defaults = { name: '', description: '', is_active: true }

const fields = [
    { name: 'name', label: 'Department name' },
    { name: 'description', label: 'Description', type: 'textarea' },
    { name: 'is_active', label: 'Active', type: 'checkbox' },
]

const columns = [
    { key: 'name', label: 'Name', sortable: true },
    { key: 'description', label: 'Description' },
]

export default function DepartmentsPage() {
    return (
        <ResourcePage
            title="Departments"
            description="Areas of work such as Development or IT Support. Nobody is tied to a department."
            noun="Department"
            queryKey="departments"
            api={departmentsApi}
            columns={columns}
            fields={fields}
            schema={schema}
            defaults={defaults}
        />
    )
}