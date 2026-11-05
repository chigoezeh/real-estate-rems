import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import InputError from '@/Components/InputError';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { BadgeDollarSign, CalendarDays, FileSpreadsheet, Plus, ReceiptText, Users, X } from 'lucide-react';
import { FormEventHandler, useMemo, useState } from 'react';
import { PageProps } from '@/types';

type Client = { id: string; organizationId: string; fullName: string; phone: string };
type Unit = { id: string; label: string; status: string; effectivePrice: string; areaSqm: string | null };
type ChargePolicy = {
    id: string;
    name: string;
    category: string;
    calculationMethod: string;
    amount: string;
    isRecurring: boolean;
};
type Project = { id: string; organizationId: string; organizationName: string; currency: string; name: string; units: Unit[]; chargePolicies: ChargePolicy[] };
type Subscription = {
    id: string;
    number: string;
    clientName: string;
    clientPhone: string;
    projectName: string;
    unitCode: string;
    paymentPlanMonths: number;
    landPrice: string;
    additionalChargesTotal: string;
    charges: Array<{ name: string; total_amount: string }>;
    status: string;
    createdAt: string | null;
    allocation: {
        id: string;
        reference: string;
        status: string;
        titleReference: string | null;
        allocatedAt: string | null;
        rejectionReason: string | null;
    } | null;
    canRequestAllocation: boolean;
    allocationBlocker: string | null;
    canReviewAllocation: boolean;
};
type SubscriptionForm = {
    client_id: string;
    property_unit_id: string;
    payment_plan_months: 1 | 3 | 6 | 12 | 24;
    development_fee: string;
    deed_fee: string;
    survey_fee: string;
    charge_quotes: Record<string, string>;
};
type Props = { subscriptions: Subscription[]; clients: Client[]; projects: Project[]; canManage: boolean };

const emptyForm = (): SubscriptionForm => ({
    client_id: '',
    property_unit_id: '',
    payment_plan_months: 12,
    development_fee: '0',
    deed_fee: '0',
    survey_fee: '0',
    charge_quotes: {},
});

const formatMoney = (amount: string | number, currency: string): string => new Intl.NumberFormat('en-NG', {
    style: 'currency',
    currency,
    maximumFractionDigits: 2,
}).format(Number(amount));

export default function Index({ subscriptions, clients, projects, canManage }: Props) {
    const [isOpen, setIsOpen] = useState(false);
    const [allocationToReject, setAllocationToReject] = useState<Subscription | null>(null);
    const [selectedProjectId, setSelectedProjectId] = useState(projects[0]?.id ?? '');
    const page = usePage<PageProps<{ errors: { allocation?: string } }>>();
    const { data, setData, post, processing, errors, reset, clearErrors } = useForm<SubscriptionForm>(emptyForm());
    const {
        data: rejectionData,
        setData: setRejectionData,
        patch: submitRejection,
        processing: rejecting,
        errors: rejectionErrors,
        reset: resetRejection,
        clearErrors: clearRejectionErrors,
    } = useForm<{ rejection_reason: string }>({ rejection_reason: '' });
    const selectedProject = projects.find((project) => project.id === selectedProjectId);
    const availableUnits = selectedProject?.units.filter((unit) => unit.status === 'available') ?? [];
    const organizationClients = useMemo(() => clients.filter((client) => client.organizationId === selectedProject?.organizationId), [clients, selectedProject]);
    const pendingCount = subscriptions.filter((subscription) => subscription.status === 'active').length;
    const totalLandValue = subscriptions.reduce((sum, subscription) => sum + Number(subscription.landPrice), 0);

    const changeProject = (projectId: string) => {
        setSelectedProjectId(projectId);
        const project = projects.find((item) => item.id === projectId);
        setData((current) => ({
            ...current,
            client_id: clients.find((client) => client.organizationId === project?.organizationId)?.id ?? '',
            property_unit_id: project?.units.find((unit) => unit.status === 'available')?.id ?? '',
            charge_quotes: {},
        }));
    };

    const openForm = () => {
        clearErrors();
        const project = projects[0];
        setSelectedProjectId(project?.id ?? '');
        setData({
            ...emptyForm(),
            client_id: clients.find((client) => client.organizationId === project?.organizationId)?.id ?? '',
            property_unit_id: project?.units.find((unit) => unit.status === 'available')?.id ?? '',
        });
        setIsOpen(true);
    };

    const closeForm = () => {
        setIsOpen(false);
        reset();
        clearErrors();
    };

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        post(route('subscriptions.store'), { onSuccess: closeForm });
    };

    const requestAllocation = (subscription: Subscription) => {
        router.post(route('subscriptions.allocations.store', subscription.id), {}, { preserveScroll: true });
    };

    const approveAllocation = (subscription: Subscription) => {
        if (!subscription.allocation) return;
        router.patch(route('property-allocations.approve', subscription.allocation.id), {}, { preserveScroll: true });
    };

    const openRejectDialog = (subscription: Subscription) => {
        clearRejectionErrors();
        setRejectionData('rejection_reason', '');
        setAllocationToReject(subscription);
    };

    const closeRejectDialog = () => {
        setAllocationToReject(null);
        resetRejection();
        clearRejectionErrors();
    };

    const rejectAllocation: FormEventHandler = (event) => {
        event.preventDefault();
        if (!allocationToReject?.allocation) return;
        submitRejection(route('property-allocations.reject', allocationToReject.allocation.id), {
            preserveScroll: true,
            onSuccess: closeRejectDialog,
        });
    };

    const selectedUnit = selectedProject?.units.find((unit) => unit.id === data.property_unit_id);
    const activeChargePolicies = selectedProject?.chargePolicies ?? [];

    return (
        <AuthenticatedLayout>
            <Head title="Subscriptions" />
            <div className="space-y-8">
                <div className="flex flex-col gap-5 border-b border-[#dfe5df] pb-6 sm:flex-row sm:items-end sm:justify-between">
                    <div><p className="eyebrow">Sales & payment plans</p><h1 className="page-title">Subscriptions</h1><p className="mt-2 max-w-2xl text-sm leading-6 text-[#64756e]">Track client purchases, land installments, and company-configured charge schedules.</p></div>
                    {canManage && <button type="button" onClick={openForm} disabled={projects.length === 0 || clients.length === 0} className="inline-flex h-11 items-center justify-center gap-2 rounded-lg bg-[#17352d] px-4 text-sm font-semibold text-white transition hover:bg-[#244b40] disabled:cursor-not-allowed disabled:opacity-50"><Plus size={17} /> New subscription</button>}
                </div>

                <div className="grid gap-6 border-b border-[#dfe5df] pb-6 sm:grid-cols-3">
                    <div className="flex items-center gap-3"><span className="grid size-10 place-items-center rounded-lg bg-[#e7eee1] text-[#315447]"><ReceiptText size={18} /></span><div><p className="text-xs text-[#73827d]">Subscriptions</p><p className="mt-1 text-lg font-semibold text-[#233c33]">{subscriptions.length}</p></div></div>
                    <div className="flex items-center gap-3"><span className="grid size-10 place-items-center rounded-lg bg-[#f7f1e4] text-[#8a672c]"><CalendarDays size={18} /></span><div><p className="text-xs text-[#73827d]">Active plans</p><p className="mt-1 text-lg font-semibold text-[#233c33]">{pendingCount}</p></div></div>
                    <div className="flex items-center gap-3"><span className="grid size-10 place-items-center rounded-lg bg-[#e5eef0] text-[#41636b]"><BadgeDollarSign size={18} /></span><div><p className="text-xs text-[#73827d]">Contracted land value</p><p className="mt-1 text-lg font-semibold text-[#233c33]">{formatMoney(totalLandValue, projects[0]?.currency ?? 'NGN')}</p></div></div>
                </div>

                {page.props.errors.allocation && <p role="alert" className="rounded-lg border border-[#e7c7bd] bg-[#fff7f4] px-4 py-3 text-sm text-[#8d3e2d]">{page.props.errors.allocation}</p>}

                {canManage && (projects.length === 0 || clients.length === 0) && <div className="border-l-2 border-[#8ba344] bg-white px-5 py-4 text-sm text-[#53675e]">{clients.length === 0 ? 'Clients need verified KYC before they can subscribe.' : 'Add an active project with available units before creating a subscription.'}</div>}

                <div className="overflow-x-auto rounded-lg border border-[#dfe5df] bg-white">
                    <table className="w-full min-w-[850px] text-left">
                        <thead className="bg-[#f8faf6] text-[11px] font-semibold uppercase tracking-[0.12em] text-[#77857f]"><tr><th className="px-5 py-3 sm:px-7">Subscription</th><th className="px-5 py-3">Client</th><th className="px-5 py-3">Property</th><th className="px-5 py-3">Plan</th><th className="px-5 py-3 text-right">Land price</th><th className="px-5 py-3 text-right sm:px-7">Status & allocation</th></tr></thead>
                        <tbody className="divide-y divide-[#edf0ec]">
                            {subscriptions.map((subscription) => <tr key={subscription.id} className="hover:bg-[#fbfcfa]">
                                <td className="px-5 py-4 sm:px-7"><p className="text-sm font-semibold text-[#233c33]">{subscription.number}</p><p className="mt-1 text-xs text-[#7b8983]">{subscription.createdAt ? new Date(subscription.createdAt).toLocaleDateString() : ''}</p></td>
                                <td className="px-5 py-4"><p className="text-sm font-medium text-[#29483e]">{subscription.clientName}</p><p className="mt-1 text-xs text-[#7b8983]">{subscription.clientPhone}</p></td>
                                <td className="px-5 py-4"><p className="text-sm text-[#4c6259]">{subscription.projectName}</p><p className="mt-1 text-xs text-[#7b8983]">{subscription.unitCode}</p></td>
                                <td className="px-5 py-4 text-sm text-[#4c6259]">{subscription.paymentPlanMonths === 1 ? 'Outright' : `${subscription.paymentPlanMonths} months`}</td>
                                <td className="px-5 py-4 text-right text-sm font-medium text-[#29483e]">{formatMoney(subscription.landPrice, projects[0]?.currency ?? 'NGN')}<p className="mt-1 text-xs font-normal text-[#7b8983]">Additional charges {formatMoney(subscription.additionalChargesTotal, projects[0]?.currency ?? 'NGN')}</p>{subscription.charges.length > 0 && <p className="mt-1 max-w-56 truncate text-xs font-normal text-[#7b8983]" title={subscription.charges.map((charge) => charge.name).join(', ')}>{subscription.charges.map((charge) => charge.name).join(', ')}</p>}</td>
                                <td className="px-5 py-4 text-right sm:px-7">
                                    <span className="rounded-full bg-[#edf4eb] px-2.5 py-1 text-xs font-medium capitalize text-[#315447]">{subscription.status}</span>
                                    {subscription.allocation && <div className="mt-2 text-xs text-[#64756e]">
                                        <p>Allocation {subscription.allocation.status.replaceAll('_', ' ')} · {subscription.allocation.reference}</p>
                                        {subscription.allocation.titleReference && <p className="mt-1">Title {subscription.allocation.titleReference}</p>}
                                        {subscription.allocation.allocatedAt && <p className="mt-1">Allocated {subscription.allocation.allocatedAt}</p>}
                                        {subscription.allocation.rejectionReason && <p className="mt-1 text-[#8d3e2d]">{subscription.allocation.rejectionReason}</p>}
                                    </div>}
                                    {subscription.canRequestAllocation && <button type="button" onClick={() => requestAllocation(subscription)} className="mt-2 rounded-md border border-[#315447] px-2.5 py-1.5 text-xs font-semibold text-[#315447] hover:bg-[#edf4eb]">Request allocation</button>}
                                    {subscription.allocationBlocker && subscription.status === 'active' && <p className="mt-2 max-w-56 text-xs text-[#7b8983]">{subscription.allocationBlocker}</p>}
                                    {subscription.canReviewAllocation && subscription.allocation && <div className="mt-2 flex justify-end gap-2">
                                        <button type="button" onClick={() => approveAllocation(subscription)} className="rounded-md bg-[#315447] px-2.5 py-1.5 text-xs font-semibold text-white hover:bg-[#244b40]">Approve</button>
                                        <button type="button" onClick={() => openRejectDialog(subscription)} className="rounded-md border border-[#c98d7e] px-2.5 py-1.5 text-xs font-semibold text-[#8d3e2d] hover:bg-[#fff7f4]">Reject</button>
                                    </div>}
                                </td>
                            </tr>)}
                        </tbody>
                    </table>
                    {subscriptions.length === 0 && <div className="border-t border-[#edf0ec] px-5 py-12 text-center"><FileSpreadsheet size={22} className="mx-auto text-[#8a9791]" /><p className="mt-3 text-sm font-medium text-[#29483e]">No subscriptions have been created yet.</p></div>}
                </div>
            </div>

            {allocationToReject?.allocation && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-[#10251f]/45 px-4 py-6">
                    <section role="dialog" aria-modal="true" aria-labelledby="allocation-rejection-title" className="w-full max-w-lg rounded-xl bg-white p-6 shadow-2xl sm:p-8">
                        <div className="flex items-start justify-between gap-5">
                            <div><p className="eyebrow">Property allocation</p><h2 id="allocation-rejection-title" className="mt-2 font-display text-2xl text-[#18342c]">Reject request</h2></div>
                            <button type="button" onClick={closeRejectDialog} aria-label="Close dialog" className="grid size-9 place-items-center rounded-lg text-[#6d7d76] hover:bg-[#f1f4ef] hover:text-[#17352d]"><X size={18} /></button>
                        </div>
                        <form onSubmit={rejectAllocation} className="mt-6 space-y-4">
                            <div>
                                <label htmlFor="allocation_rejection_reason" className="field-label">Reason</label>
                                <textarea id="allocation_rejection_reason" value={rejectionData.rejection_reason} onChange={(event) => setRejectionData('rejection_reason', event.target.value)} className="field-input min-h-28" maxLength={2000} required />
                                <InputError message={rejectionErrors.rejection_reason} className="mt-2" />
                            </div>
                            <div className="flex justify-end gap-3">
                                <button type="button" onClick={closeRejectDialog} className="rounded-lg border border-[#d7dfd8] px-4 py-2 text-sm font-semibold text-[#53675e]">Cancel</button>
                                <button type="submit" disabled={rejecting} className="rounded-lg bg-[#8d3e2d] px-4 py-2 text-sm font-semibold text-white disabled:opacity-60">{rejecting ? 'Rejecting…' : 'Reject allocation'}</button>
                            </div>
                        </form>
                    </section>
                </div>
            )}

            {isOpen && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-[#10251f]/45 px-4 py-6" role="presentation" onMouseDown={(event) => event.target === event.currentTarget && closeForm()}>
                    <section role="dialog" aria-modal="true" aria-labelledby="subscription-dialog-title" className="max-h-full w-full max-w-2xl overflow-y-auto rounded-xl bg-white p-6 shadow-2xl sm:p-8">
                        <div className="flex items-start justify-between gap-5"><div><p className="eyebrow">Sales agreement</p><h2 id="subscription-dialog-title" className="mt-2 font-display text-2xl text-[#18342c]">New subscription</h2></div><button type="button" onClick={closeForm} aria-label="Close dialog" className="grid size-9 place-items-center rounded-lg text-[#6d7d76] hover:bg-[#f1f4ef] hover:text-[#17352d]"><X size={18} /></button></div>
                        <form onSubmit={submit} className="mt-7 space-y-5">
                            <div className="grid gap-4 sm:grid-cols-2">
                                <div className="sm:col-span-2"><label htmlFor="project_id" className="field-label">Project</label><select id="project_id" value={selectedProjectId} onChange={(event) => changeProject(event.target.value)} className="field-input">{projects.map((project) => <option key={project.id} value={project.id}>{project.name} · {project.organizationName}</option>)}</select></div>
                                <div className="sm:col-span-2"><label htmlFor="client_id" className="field-label">Verified client</label><select id="client_id" value={data.client_id} onChange={(event) => setData('client_id', event.target.value)} className="field-input"><option value="">Select a client</option>{organizationClients.map((client) => <option key={client.id} value={client.id}>{client.fullName} · {client.phone}</option>)}</select><InputError message={errors.client_id} className="mt-2" /></div>
                                <div className="sm:col-span-2"><label htmlFor="property_unit_id" className="field-label">Available property</label><select id="property_unit_id" value={data.property_unit_id} onChange={(event) => setData('property_unit_id', event.target.value)} className="field-input"><option value="">Select an available unit</option>{availableUnits.map((unit) => <option key={unit.id} value={unit.id}>{unit.label} · {formatMoney(unit.effectivePrice, selectedProject?.currency ?? 'NGN')}</option>)}</select><InputError message={errors.property_unit_id} className="mt-2" /></div>
                                <div><label htmlFor="payment_plan_months" className="field-label">Payment plan</label><select id="payment_plan_months" value={data.payment_plan_months} onChange={(event) => setData('payment_plan_months', Number(event.target.value) as SubscriptionForm['payment_plan_months'])} className="field-input">{[1, 3, 6, 12, 24].map((months) => <option key={months} value={months}>{months === 1 ? 'Outright' : `${months} months`}</option>)}</select><InputError message={errors.payment_plan_months} className="mt-2" /></div>
                                <div className="flex items-end rounded-lg bg-[#f8faf6] px-4 py-3"><div><p className="text-xs text-[#73827d]">Land price snapshot</p><p className="mt-1 text-sm font-semibold text-[#29483e]">{selectedUnit ? formatMoney(selectedUnit.effectivePrice, selectedProject?.currency ?? 'NGN') : 'Select a property'}</p></div></div>
                            </div>
                            <fieldset className="space-y-4 border-t border-[#e7ece6] pt-5">
                                <legend className="field-label">{activeChargePolicies.length > 0 ? 'Active company charge policies' : 'Manually entered charges'}</legend>
                                {activeChargePolicies.length > 0 ? (
                                    <div className="space-y-3">
                                        {activeChargePolicies.map((policy) => {
                                            const methodDescription = policy.calculationMethod === 'fixed'
                                                ? `${formatMoney(policy.amount, selectedProject?.currency ?? 'NGN')} fixed amount`
                                                : policy.calculationMethod === 'percentage'
                                                    ? `${policy.amount}% of the land price`
                                                    : policy.calculationMethod === 'per_area'
                                                        ? `${formatMoney(policy.amount, selectedProject?.currency ?? 'NGN')} per m²${selectedUnit?.areaSqm ? ` · ${selectedUnit.areaSqm} m²` : ''}`
                                                        : 'Enter a contract-specific quote';
                                            return (
                                                <div key={policy.id} className="grid gap-3 rounded-lg border border-[#e7ece6] p-4 sm:grid-cols-[1fr_auto] sm:items-center">
                                                    <div><p className="text-sm font-medium text-[#29483e]">{policy.name}</p><p className="mt-1 text-xs text-[#73827d]">{methodDescription}{policy.isRecurring ? ' · repeats each installment' : ' · one-time amount split across the plan'}</p></div>
                                                    {policy.calculationMethod === 'manual_quote' && <div><label htmlFor={`charge-quote-${policy.id}`} className="field-label">Quote ({selectedProject?.currency ?? 'NGN'})</label><input id={`charge-quote-${policy.id}`} type="number" min="0" step="0.01" required value={data.charge_quotes[policy.id] ?? ''} onChange={(event) => setData('charge_quotes', { ...data.charge_quotes, [policy.id]: event.target.value })} className="field-input sm:w-48" /></div>}
                                                </div>
                                            );
                                        })}
                                    </div>
                                ) : (
                                    <>
                                        <p className="text-sm text-[#64756e]">No active sale policies are configured for this organization. These amounts remain contract-specific and are split across the payment plan.</p>
                                        <div className="grid gap-4 sm:grid-cols-3">
                                            {[['development_fee', 'Development levy'], ['deed_fee', 'Deed preparation'], ['survey_fee', 'Survey plan']].map(([field, label]) => <div key={field}><label htmlFor={field} className="field-label">{label}</label><input id={field} type="number" min="0" step="0.01" value={data[field as keyof SubscriptionForm] as string} onChange={(event) => setData(field as 'development_fee' | 'deed_fee' | 'survey_fee', event.target.value)} className="field-input" /><InputError message={errors[field as keyof typeof errors]} className="mt-2" /></div>)}
                                        </div>
                                    </>
                                )}
                                {Object.entries(errors).filter(([key]) => key.startsWith('charge_quotes.')).map(([key, message]) => <InputError key={key} message={message} />)}
                                {errors.charge_quotes && <InputError message={errors.charge_quotes} />}
                            </fieldset>
                            <div className="flex justify-end gap-3 border-t border-[#e7ece6] pt-5"><button type="button" onClick={closeForm} className="h-10 rounded-lg px-4 text-sm font-medium text-[#5f7169] hover:bg-[#f3f5ef]">Cancel</button><button type="submit" disabled={processing || !data.client_id || !data.property_unit_id} className="h-10 rounded-lg bg-[#17352d] px-4 text-sm font-semibold text-white hover:bg-[#244b40] disabled:opacity-60">{processing ? 'Saving…' : 'Create subscription'}</button></div>
                        </form>
                    </section>
                </div>
            )}
        </AuthenticatedLayout>
    );
}