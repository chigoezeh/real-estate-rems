import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import InputError from '@/Components/InputError';
import { Head, router, useForm } from '@inertiajs/react';
import { ClipboardCheck, Plus, Wrench } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

type Property = { id: string; projectName: string; label: string };
type WorkOrder = {
    id: string;
    vendorName: string | null;
    status: string;
    estimatedCost: string | null;
    actualCost: string | null;
    scheduledAt: string | null;
    completedAt: string | null;
    completionNotes: string | null;
};
type MaintenanceRequest = {
    id: string;
    reference: string;
    title: string;
    description: string;
    category: string;
    priority: string;
    status: string;
    reportedAt: string | null;
    property: string;
    tenantName: string | null;
    canManage: boolean;
    workOrders: WorkOrder[];
};
type Props = { requests: MaintenanceRequest[]; properties: Property[]; canManage: boolean };
type RequestForm = { property_unit_id: string; category: string; priority: string; title: string; description: string };
type WorkOrderForm = { vendor_name: string; estimated_cost: string; scheduled_at: string };

export default function Index({ requests, properties, canManage }: Props) {
    const [selectedRequest, setSelectedRequest] = useState<string | null>(null);
    const requestForm = useForm<RequestForm>({
        property_unit_id: properties[0]?.id ?? '',
        category: 'other',
        priority: 'normal',
        title: '',
        description: '',
    });
    const workOrderForm = useForm<WorkOrderForm>({ vendor_name: '', estimated_cost: '', scheduled_at: '' });

    const submitRequest: FormEventHandler = (event) => {
        event.preventDefault();
        requestForm.post(route('maintenance.requests.store'), { onSuccess: () => requestForm.reset('title', 'description') });
    };

    const submitWorkOrder: FormEventHandler = (event) => {
        event.preventDefault();
        if (!selectedRequest) return;
        workOrderForm.post(route('maintenance.requests.work-orders.store', selectedRequest), {
            onSuccess: () => {
                workOrderForm.reset();
                setSelectedRequest(null);
            },
        });
    };

    const complete = (workOrder: WorkOrder) => {
        const actualCost = window.prompt('Enter the actual cost (optional):', workOrder.estimatedCost ?? '');
        if (actualCost === null) return;
        const completionNotes = window.prompt('Completion notes (optional):') ?? '';
        router.patch(route('maintenance.work-orders.complete', workOrder.id), {
            actual_cost: actualCost,
            completion_notes: completionNotes,
        }, { preserveScroll: true });
    };

    return (
        <AuthenticatedLayout>
            <Head title="Maintenance" />
            <div className="space-y-8">
                <div className="border-b border-[#dfe5df] pb-6"><p className="eyebrow">Property operations</p><h1 className="page-title">Maintenance</h1><p className="mt-2 max-w-2xl text-sm leading-6 text-[#64756e]">Track property issues and manage approved repair work orders.</p></div>

                {canManage && properties.length > 0 && <form onSubmit={submitRequest} className="grid gap-4 border border-[#dfe5df] bg-white p-5 sm:grid-cols-2 lg:grid-cols-4 sm:p-7">
                    <div className="lg:col-span-2"><label htmlFor="maintenance-property" className="field-label">Property</label><select id="maintenance-property" required value={requestForm.data.property_unit_id} onChange={(event) => requestForm.setData('property_unit_id', event.target.value)} className="field-input">{properties.map((property) => <option key={property.id} value={property.id}>{property.projectName} · {property.label}</option>)}</select><InputError message={requestForm.errors.property_unit_id} className="mt-2" /></div>
                    <div><label htmlFor="maintenance-category" className="field-label">Category</label><select id="maintenance-category" value={requestForm.data.category} onChange={(event) => requestForm.setData('category', event.target.value)} className="field-input"><option value="plumbing">Plumbing</option><option value="electrical">Electrical</option><option value="structural">Structural</option><option value="appliance">Appliance</option><option value="security">Security</option><option value="cleaning">Cleaning</option><option value="other">Other</option></select><InputError message={requestForm.errors.category} className="mt-2" /></div>
                    <div><label htmlFor="maintenance-priority" className="field-label">Priority</label><select id="maintenance-priority" value={requestForm.data.priority} onChange={(event) => requestForm.setData('priority', event.target.value)} className="field-input"><option value="low">Low</option><option value="normal">Normal</option><option value="high">High</option><option value="urgent">Urgent</option></select><InputError message={requestForm.errors.priority} className="mt-2" /></div>
                    <div><label htmlFor="maintenance-title" className="field-label">Issue title</label><input id="maintenance-title" required value={requestForm.data.title} onChange={(event) => requestForm.setData('title', event.target.value)} className="field-input" /><InputError message={requestForm.errors.title} className="mt-2" /></div>
                    <div className="sm:col-span-2 lg:col-span-3"><label htmlFor="maintenance-description" className="field-label">Description</label><textarea id="maintenance-description" required rows={2} value={requestForm.data.description} onChange={(event) => requestForm.setData('description', event.target.value)} className="field-input min-h-20" /><InputError message={requestForm.errors.description} className="mt-2" /></div>
                    <div className="flex items-end"><button type="submit" disabled={requestForm.processing || !requestForm.data.property_unit_id} className="inline-flex h-11 items-center gap-2 rounded-lg bg-[#17352d] px-4 text-sm font-semibold text-white hover:bg-[#244b40] disabled:opacity-50"><Plus size={16} />{requestForm.processing ? 'Saving…' : 'Record request'}</button></div>
                </form>}
                {canManage && properties.length === 0 && <p className="border-l-2 border-[#8ba344] bg-white px-5 py-4 text-sm text-[#53675e]">Add a project and property unit before recording maintenance requests.</p>}

                {selectedRequest && <form onSubmit={submitWorkOrder} className="grid gap-4 border border-[#dfe5df] bg-[#f8faf6] p-5 sm:grid-cols-2 lg:grid-cols-4">
                    <div className="sm:col-span-2 lg:col-span-4"><h2 className="font-display text-lg text-[#18342c]">Create work order</h2></div>
                    <div><label htmlFor="work-order-vendor" className="field-label">Vendor / contractor</label><input id="work-order-vendor" value={workOrderForm.data.vendor_name} onChange={(event) => workOrderForm.setData('vendor_name', event.target.value)} className="field-input" /><InputError message={workOrderForm.errors.vendor_name} className="mt-2" /></div>
                    <div><label htmlFor="work-order-estimate" className="field-label">Estimated cost</label><input id="work-order-estimate" type="number" min="0" step="0.01" value={workOrderForm.data.estimated_cost} onChange={(event) => workOrderForm.setData('estimated_cost', event.target.value)} className="field-input" /><InputError message={workOrderForm.errors.estimated_cost} className="mt-2" /></div>
                    <div><label htmlFor="work-order-schedule" className="field-label">Scheduled date</label><input id="work-order-schedule" type="datetime-local" value={workOrderForm.data.scheduled_at} onChange={(event) => workOrderForm.setData('scheduled_at', event.target.value)} className="field-input" /><InputError message={workOrderForm.errors.scheduled_at} className="mt-2" /></div>
                    <div className="flex items-end gap-2"><button type="submit" disabled={workOrderForm.processing} className="inline-flex h-10 items-center gap-2 rounded-lg bg-[#17352d] px-4 text-sm font-semibold text-white disabled:opacity-50">{workOrderForm.processing ? 'Saving…' : 'Submit for approval'}</button><button type="button" onClick={() => setSelectedRequest(null)} className="h-10 rounded-lg border border-[#d9e1d9] px-3 text-sm text-[#53675e]">Cancel</button></div>
                </form>}

                <div className="space-y-4">
                    {requests.map((maintenanceRequest) => <article key={maintenanceRequest.id} className="border border-[#dfe5df] bg-white">
                        <div className="flex flex-col gap-4 border-b border-[#edf0ec] p-5 sm:flex-row sm:items-start sm:justify-between sm:p-6">
                            <div className="space-y-2"><div className="flex flex-wrap items-center gap-2"><span className="text-xs font-semibold tracking-wide text-[#718078]">{maintenanceRequest.reference}</span><Status value={maintenanceRequest.priority} /><Status value={maintenanceRequest.status} /></div><h2 className="font-display text-xl text-[#18342c]">{maintenanceRequest.title}</h2><p className="text-sm text-[#53675e]">{maintenanceRequest.property}{maintenanceRequest.tenantName ? ` · Tenant: ${maintenanceRequest.tenantName}` : ''}</p><p className="max-w-3xl text-sm leading-6 text-[#718078]">{maintenanceRequest.description}</p></div>
                            {canManage && maintenanceRequest.canManage && maintenanceRequest.status !== 'resolved' && <button type="button" onClick={() => setSelectedRequest(maintenanceRequest.id)} className="inline-flex h-9 shrink-0 items-center gap-2 rounded-lg border border-[#d9e1d9] px-3 text-xs font-semibold text-[#315447] hover:bg-[#f8faf6]"><Wrench size={14} /> Create work order</button>}
                        </div>
                        <div className="space-y-3 p-5 sm:p-6">
                            {maintenanceRequest.workOrders.map((workOrder) => <div key={workOrder.id} className="flex flex-col gap-3 border-l-2 border-[#d9e1d9] pl-4 sm:flex-row sm:items-center sm:justify-between">
                                <div><p className="text-sm font-semibold text-[#29483e]">{workOrder.vendorName ?? 'Unassigned vendor'} <span className="ml-2 text-xs font-normal capitalize text-[#718078]">{workOrder.status.replaceAll('_', ' ')}</span></p><p className="mt-1 text-xs text-[#718078]">Estimate: {workOrder.estimatedCost ?? 'Not set'}{workOrder.actualCost ? ` · Actual: ${workOrder.actualCost}` : ''}{workOrder.scheduledAt ? ` · Scheduled: ${workOrder.scheduledAt}` : ''}</p>{workOrder.completionNotes && <p className="mt-1 text-xs text-[#718078]">{workOrder.completionNotes}</p>}</div>
                                {canManage && maintenanceRequest.canManage && <div className="flex gap-2">{workOrder.status === 'pending_approval' && <button type="button" onClick={() => router.patch(route('maintenance.work-orders.approve', workOrder.id), {}, { preserveScroll: true })} className="h-9 rounded-lg bg-[#17352d] px-3 text-xs font-semibold text-white">Approve</button>}{workOrder.status === 'approved' && <button type="button" onClick={() => complete(workOrder)} className="inline-flex items-center gap-1.5 rounded-lg bg-[#edf4eb] px-3 text-xs font-semibold text-[#315447] h-9"><ClipboardCheck size={14} /> Complete</button>}</div>}
                            </div>)}
                            {maintenanceRequest.workOrders.length === 0 && <p className="text-xs text-[#87938d]">No work orders yet.</p>}
                        </div>
                    </article>)}
                    {requests.length === 0 && <div className="border border-dashed border-[#d9e1d9] py-12 text-center text-sm text-[#718078]">No maintenance requests have been recorded.</div>}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}

function Status({ value }: { value: string }) {
    const tone = ['urgent', 'high'].includes(value) ? 'bg-[#f8e9e5] text-[#8b4739]' : value === 'resolved' || value === 'completed' || value === 'approved' ? 'bg-[#edf4eb] text-[#315447]' : 'bg-[#f8f2dc] text-[#756128]';
    return <span className={`rounded-full px-2.5 py-1 text-[11px] font-medium capitalize ${tone}`}>{value.replaceAll('_', ' ')}</span>;
}
