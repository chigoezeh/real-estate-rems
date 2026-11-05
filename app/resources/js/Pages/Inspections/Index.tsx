import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import InputError from '@/Components/InputError';
import { Head, useForm } from '@inertiajs/react';
import { CalendarDays, Check, Clock3, MapPin, Pencil, Plus, X } from 'lucide-react';
import { FormEventHandler, useMemo, useState } from 'react';

type Inspection = {
    id: string;
    organizationId: string;
    clientName: string;
    clientPhone: string;
    projectName: string;
    assignedToUserId: string | null;
    assignedToName: string | null;
    scheduledAt: string;
    status: string;
    notes: string | null;
    createdByName: string | null;
};
type Client = { id: string; organizationId: string; fullName: string; phone: string };
type Project = { id: string; organizationId: string; name: string };
type Assignee = { id: string; organizationId: string; name: string };
type BookingForm = { client_id: string; project_id: string; scheduled_at: string; assigned_to_user_id: string; notes: string };
type UpdateForm = { assigned_to_user_id: string; status: 'scheduled' | 'confirmed' | 'completed' | 'cancelled' };
type Props = { inspections: Inspection[]; clients: Client[]; projects: Project[]; assignees: Assignee[]; canManage: boolean };

const localDateTime = (date: Date): string => {
    const offset = date.getTimezoneOffset() * 60000;
    return new Date(date.getTime() - offset).toISOString().slice(0, 16);
};
const statusClass = (status: string): string => status === 'completed'
    ? 'bg-[#e5eef0] text-[#41636b]'
    : status === 'cancelled'
        ? 'bg-[#fae9e5] text-[#9a4332]'
        : status === 'confirmed'
            ? 'bg-[#edf4eb] text-[#315447]'
            : 'bg-[#f7f1e4] text-[#8a672c]';

export default function Index({ inspections, clients, projects, assignees, canManage }: Props) {
    const [bookingOpen, setBookingOpen] = useState(false);
    const [editing, setEditing] = useState<Inspection | null>(null);
    const initialProject = projects[0];
    const bookingForm = useForm<BookingForm>({
        client_id: clients.find((client) => client.organizationId === initialProject?.organizationId)?.id ?? '',
        project_id: initialProject?.id ?? '',
        scheduled_at: localDateTime(new Date(Date.now() + 86400000)),
        assigned_to_user_id: '',
        notes: '',
    });
    const updateForm = useForm<UpdateForm>({ assigned_to_user_id: '', status: 'scheduled' });
    const selectedProject = projects.find((project) => project.id === bookingForm.data.project_id);
    const organizationClients = useMemo(() => clients.filter((client) => client.organizationId === selectedProject?.organizationId), [clients, selectedProject]);
    const organizationAssignees = useMemo(() => assignees.filter((assignee) => assignee.organizationId === selectedProject?.organizationId), [assignees, selectedProject]);
    const upcomingCount = inspections.filter((inspection) => new Date(inspection.scheduledAt) >= new Date() && inspection.status !== 'cancelled').length;
    const scheduledCount = inspections.filter((inspection) => ['scheduled', 'confirmed'].includes(inspection.status)).length;

    const closeBooking = () => { setBookingOpen(false); bookingForm.reset(); bookingForm.clearErrors(); };
    const submitBooking: FormEventHandler = (event) => {
        event.preventDefault();
        bookingForm.post(route('inspections.store'), { onSuccess: closeBooking });
    };
    const openEdit = (inspection: Inspection) => {
        setEditing(inspection);
        updateForm.setData({ assigned_to_user_id: inspection.assignedToUserId ?? '', status: inspection.status as UpdateForm['status'] });
        updateForm.clearErrors();
    };
    const submitUpdate: FormEventHandler = (event) => {
        event.preventDefault();
        if (!editing) return;
        updateForm.patch(route('inspections.update', editing.id), { onSuccess: () => setEditing(null) });
    };

    return (
        <AuthenticatedLayout>
            <Head title="Site inspections" />
            <div className="space-y-8">
                <div className="flex flex-col gap-5 border-b border-[#dfe5df] pb-6 sm:flex-row sm:items-end sm:justify-between">
                    <div><p className="eyebrow">Field operations</p><h1 className="page-title">Site inspections</h1><p className="mt-2 max-w-2xl text-sm leading-6 text-[#64756e]">Coordinate client visits across projects and assign an active site officer.</p></div>
                    {canManage && <button type="button" onClick={() => setBookingOpen(true)} disabled={projects.length === 0 || clients.length === 0} className="inline-flex h-11 items-center justify-center gap-2 rounded-lg bg-[#17352d] px-4 text-sm font-semibold text-white transition hover:bg-[#244b40] disabled:cursor-not-allowed disabled:opacity-50"><Plus size={17} /> Schedule visit</button>}
                </div>

                <div className="grid gap-6 border-b border-[#dfe5df] pb-6 sm:grid-cols-3">
                    <div className="flex items-center gap-3"><span className="grid size-10 place-items-center rounded-lg bg-[#e7eee1] text-[#315447]"><CalendarDays size={18} /></span><div><p className="text-xs text-[#73827d]">Bookings</p><p className="mt-1 text-lg font-semibold text-[#233c33]">{inspections.length}</p></div></div>
                    <div className="flex items-center gap-3"><span className="grid size-10 place-items-center rounded-lg bg-[#f7f1e4] text-[#8a672c]"><Clock3 size={18} /></span><div><p className="text-xs text-[#73827d]">Upcoming visits</p><p className="mt-1 text-lg font-semibold text-[#233c33]">{upcomingCount}</p></div></div>
                    <div className="flex items-center gap-3"><span className="grid size-10 place-items-center rounded-lg bg-[#e5eef0] text-[#41636b]"><MapPin size={18} /></span><div><p className="text-xs text-[#73827d]">Open bookings</p><p className="mt-1 text-lg font-semibold text-[#233c33]">{scheduledCount}</p></div></div>
                </div>

                {canManage && (projects.length === 0 || clients.length === 0) && <div className="border-l-2 border-[#8ba344] bg-white px-5 py-4 text-sm text-[#53675e]">Add an active project and a client record before scheduling a site visit.</div>}

                <div className="overflow-x-auto rounded-lg border border-[#dfe5df] bg-white">
                    <table className="w-full min-w-[900px] text-left">
                        <thead className="bg-[#f8faf6] text-[11px] font-semibold uppercase tracking-[0.12em] text-[#77857f]"><tr><th className="px-5 py-3 sm:px-7">Client</th><th className="px-5 py-3">Project</th><th className="px-5 py-3">Visit time</th><th className="px-5 py-3">Assigned officer</th><th className="px-5 py-3">Status</th><th className="px-5 py-3 text-right sm:px-7">Action</th></tr></thead>
                        <tbody className="divide-y divide-[#edf0ec]">
                            {inspections.map((inspection) => <tr key={inspection.id} className="hover:bg-[#fbfcfa]">
                                <td className="px-5 py-4 sm:px-7"><p className="text-sm font-semibold text-[#233c33]">{inspection.clientName}</p><p className="mt-1 text-xs text-[#7b8983]">{inspection.clientPhone}</p></td>
                                <td className="px-5 py-4 text-sm text-[#4c6259]">{inspection.projectName}</td>
                                <td className="px-5 py-4"><p className="text-sm text-[#4c6259]">{new Date(inspection.scheduledAt).toLocaleDateString()}</p><p className="mt-1 text-xs text-[#7b8983]">{new Date(inspection.scheduledAt).toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' })}</p></td>
                                <td className="px-5 py-4 text-sm text-[#4c6259]">{inspection.assignedToName ?? 'Unassigned'}</td>
                                <td className="px-5 py-4"><span className={`inline-flex rounded-full px-2.5 py-1 text-xs font-medium capitalize ${statusClass(inspection.status)}`}>{inspection.status}</span></td>
                                <td className="px-5 py-4 text-right sm:px-7">{canManage && <button type="button" onClick={() => openEdit(inspection)} title="Edit booking" aria-label={`Edit booking for ${inspection.clientName}`} className="grid size-9 place-items-center rounded-lg text-[#64756e] hover:bg-[#eff3ed] hover:text-[#17352d]"><Pencil size={16} /></button>}</td>
                            </tr>)}
                        </tbody>
                    </table>
                    {inspections.length === 0 && <div className="border-t border-[#edf0ec] px-5 py-12 text-center"><CalendarDays size={22} className="mx-auto text-[#8a9791]" /><p className="mt-3 text-sm font-medium text-[#29483e]">No site visits have been scheduled.</p></div>}
                </div>
            </div>

            {bookingOpen && <div className="fixed inset-0 z-50 flex items-center justify-center bg-[#10251f]/45 px-4 py-6" role="presentation" onMouseDown={(event) => event.target === event.currentTarget && closeBooking()}>
                <section role="dialog" aria-modal="true" aria-labelledby="inspection-dialog-title" className="max-h-full w-full max-w-2xl overflow-y-auto rounded-xl bg-white p-6 shadow-2xl sm:p-8">
                    <div className="flex items-start justify-between gap-5"><div><p className="eyebrow">Field operations</p><h2 id="inspection-dialog-title" className="mt-2 font-display text-2xl text-[#18342c]">Schedule site visit</h2></div><button type="button" onClick={closeBooking} aria-label="Close dialog" className="grid size-9 place-items-center rounded-lg text-[#6d7d76] hover:bg-[#f1f4ef]"><X size={18} /></button></div>
                    <form onSubmit={submitBooking} className="mt-7 space-y-5">
                        <div><label htmlFor="project_id" className="field-label">Project</label><select id="project_id" value={bookingForm.data.project_id} onChange={(event) => { const projectId = event.target.value; const project = projects.find((item) => item.id === projectId); bookingForm.setData((current) => ({ ...current, project_id: projectId, client_id: clients.find((client) => client.organizationId === project?.organizationId)?.id ?? '', assigned_to_user_id: '' })); }} className="field-input">{projects.map((project) => <option key={project.id} value={project.id}>{project.name}</option>)}</select><InputError message={bookingForm.errors.project_id} className="mt-2" /></div>
                        <div className="grid gap-4 sm:grid-cols-2"><div><label htmlFor="client_id" className="field-label">Client</label><select id="client_id" value={bookingForm.data.client_id} onChange={(event) => bookingForm.setData('client_id', event.target.value)} className="field-input"><option value="">Select a client</option>{organizationClients.map((client) => <option key={client.id} value={client.id}>{client.fullName} · {client.phone}</option>)}</select><InputError message={bookingForm.errors.client_id} className="mt-2" /></div><div><label htmlFor="assigned_to_user_id" className="field-label">Site officer</label><select id="assigned_to_user_id" value={bookingForm.data.assigned_to_user_id} onChange={(event) => bookingForm.setData('assigned_to_user_id', event.target.value)} className="field-input"><option value="">Assign later</option>{organizationAssignees.map((assignee) => <option key={assignee.id} value={assignee.id}>{assignee.name}</option>)}</select><InputError message={bookingForm.errors.assigned_to_user_id} className="mt-2" /></div></div>
                        <div><label htmlFor="scheduled_at" className="field-label">Visit date and time</label><input id="scheduled_at" type="datetime-local" min={localDateTime(new Date())} value={bookingForm.data.scheduled_at} onChange={(event) => bookingForm.setData('scheduled_at', event.target.value)} className="field-input" /><InputError message={bookingForm.errors.scheduled_at} className="mt-2" /></div>
                        <div><label htmlFor="notes" className="field-label">Notes</label><textarea id="notes" rows={3} value={bookingForm.data.notes} onChange={(event) => bookingForm.setData('notes', event.target.value)} className="field-input" /><InputError message={bookingForm.errors.notes} className="mt-2" /></div>
                        <div className="flex justify-end gap-3 border-t border-[#e7ece6] pt-5"><button type="button" onClick={closeBooking} className="h-10 rounded-lg px-4 text-sm font-medium text-[#5f7169] hover:bg-[#f3f5ef]">Cancel</button><button type="submit" disabled={bookingForm.processing || !bookingForm.data.client_id || !bookingForm.data.project_id} className="h-10 rounded-lg bg-[#17352d] px-4 text-sm font-semibold text-white hover:bg-[#244b40] disabled:opacity-60">{bookingForm.processing ? 'Saving…' : 'Schedule visit'}</button></div>
                    </form>
                </section>
            </div>}

            {editing && <div className="fixed inset-0 z-50 flex items-center justify-center bg-[#10251f]/45 px-4 py-6" role="presentation" onMouseDown={(event) => event.target === event.currentTarget && setEditing(null)}>
                <section role="dialog" aria-modal="true" aria-labelledby="edit-inspection-title" className="w-full max-w-lg rounded-xl bg-white p-6 shadow-2xl sm:p-8">
                    <div className="flex items-start justify-between gap-5"><div><p className="eyebrow">Booking update</p><h2 id="edit-inspection-title" className="mt-2 font-display text-2xl text-[#18342c]">{editing.clientName}</h2></div><button type="button" onClick={() => setEditing(null)} aria-label="Close dialog" className="grid size-9 place-items-center rounded-lg text-[#6d7d76] hover:bg-[#f1f4ef]"><X size={18} /></button></div>
                    <form onSubmit={submitUpdate} className="mt-7 space-y-5">
                        <div><label htmlFor="edit_assignee" className="field-label">Site officer</label><select id="edit_assignee" value={updateForm.data.assigned_to_user_id} onChange={(event) => updateForm.setData('assigned_to_user_id', event.target.value)} className="field-input"><option value="">Unassigned</option>{assignees.filter((assignee) => assignee.organizationId === editing.organizationId).map((assignee) => <option key={assignee.id} value={assignee.id}>{assignee.name}</option>)}</select><InputError message={updateForm.errors.assigned_to_user_id} className="mt-2" /></div>
                        <div><label htmlFor="status" className="field-label">Status</label><select id="status" value={updateForm.data.status} onChange={(event) => updateForm.setData('status', event.target.value as UpdateForm['status'])} className="field-input"><option value="scheduled">Scheduled</option><option value="confirmed">Confirmed</option><option value="completed">Completed</option><option value="cancelled">Cancelled</option></select><InputError message={updateForm.errors.status} className="mt-2" /></div>
                        <div className="flex justify-end gap-3 border-t border-[#e7ece6] pt-5"><button type="button" onClick={() => setEditing(null)} className="h-10 rounded-lg px-4 text-sm font-medium text-[#5f7169] hover:bg-[#f3f5ef]">Cancel</button><button type="submit" disabled={updateForm.processing} className="inline-flex h-10 items-center gap-2 rounded-lg bg-[#17352d] px-4 text-sm font-semibold text-white hover:bg-[#244b40] disabled:opacity-60"><Check size={16} /> Save changes</button></div>
                    </form>
                </section>
            </div>}
        </AuthenticatedLayout>
    );
}
