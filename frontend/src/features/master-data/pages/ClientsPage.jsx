import { z } from 'zod'
import { Link } from 'react-router-dom'
import { clientsApi } from '../api/masterDataApi'
import ResourcePage from '../components/ResourcePage'

const emailPattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/

const schema = z.object({
    name: z.string().trim().min(1, 'Name is required.').max(255, 'Name is too long.'),
    contact_person: z.string().trim().max(255, 'Contact person is too long.'),
    email: z.string().trim().max(255, 'Email is too long.').refine(
        (value) => value === '' || emailPattern.test(value),
        'Enter a valid email address.',
    ),
    phone: z.string().trim().max(255, 'Phone number is too long.'),
    address: z.string().trim().max(255, 'Address is too long.'),
    notes: z.string().trim().max(2000, 'Notes are too long.'),
    is_active: z.boolean(),
})

const defaults = { name: '', contact_person: '', email: '', phone: '', address: '', notes: '', is_active: true }

const fields = [
    { name: 'name', label: 'Client name', placeholder: 'e.g. Acme Kenya Ltd' },
    { name: 'contact_person', label: 'Contact person' },
    { name: 'email', label: 'Email', type: 'email' },
    { name: 'phone', label: 'Phone', type: 'tel' },
    { name: 'address', label: 'Address' },
    { name: 'notes', label: 'Notes', type: 'textarea' },
    { name: 'is_active', label: 'Active', type: 'checkbox' },
]

const columns = [
    { key: 'name', label: 'Name', sortable: true, render: (row) => <Link to={`/clients/${row.id}`}>{row.name}</Link> },
    { key: 'contact_person', label: 'Contact person', sortable: true },
    {
        key: 'email',
        label: 'Email',
        sortable: true,
        render: (row) => (row.email ? <a href={`mailto:${row.email}`}>{row.email}</a> : '—'),
    },
    {
        key: 'phone',
        label: 'Phone',
        sortable: true,
        render: (row) => (row.phone ? <a href={`tel:${row.phone}`}>{row.phone}</a> : '—'),
    },
]

export default function ClientsPage() {
    return (
        <ResourcePage
            title="Clients"
            description="Organisations you support. Clients group tasks and items together."
            noun="Client"
            queryKey="clients"
            api={clientsApi}
            columns={columns}
            fields={fields}
            schema={schema}
            defaults={defaults}
        />
    )
}