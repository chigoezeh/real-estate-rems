import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import InputError from '@/Components/InputError';
import { Head, router, useForm } from '@inertiajs/react';
import { CalendarDays, Check, Home, Plus, Users, X } from 'lucide-react';
import { FormEventHandler, useMemo, useState } from 'react';

type Client = { id: string; organizationId: string; name: string; phone: string };
type ChargePolicy = {
    id: string;
    name: string;
    category: string;
    calculationMethod: string;
    amount: string;
    isRecurring: boolean;
};
type Property = {
    id: string;
    organizationId: string;
    projectName: string;
    currency: string;
    label: string;
    unitType: string;
    areaSqm: string | null;
    chargePolicies: ChargePolicy[];
};
type Notice = {
    id: string;
    type: string;
    noticeOn: string;
    proposedVacateOn: string;
    reason: string;
    status: string;
    handedOverOn: string | null;
    handoverNotes: string | null;
};
type DepositDeduction = { category: string; description: string; amount: string; evidence_reference: string | null; rent_schedule_id: string | null };
type UnpaidRentSchedule = { id: string; period: string; amountDue: string; pendingAmount: string };
type DepositReconciliation = {
    id: string;
    status: string;
    depositReceivedAmount: string;
    deductionTotal: string;
    refundDueAmount: string;
    refundedAt: string | null;
    refundReference: string | null;
    refundNotes: string | null;
    deductions: DepositDeduction[];
};
type Tenancy = {
    id: string;
    number: string;
    status: string;
    tenantName: string;
    tenantPhone: string;
    property: string;
    startsOn: string;
    endsOn: string | null;
    frequency: string;
    rentAmount: string;
    securityDeposit: string;
    serviceCharge: string;
    additionalChargesTotal: string;
    additionalCharges: Array<{ name: string; total_amount: string }>;
    depositReceivedAmount: string;
    depositCarriedForwardAmount: string;
    notices: Notice[];
    unpaidRentSchedules: UnpaidRentSchedule[];
    depositReconciliation: DepositReconciliation | null;
    currency: string;
    renewalOfNumber: string | null;
    renewedByNumber: string | null;
    renewal: { clientId: string; startsOn: string; endsOn: string; property: Property } | null;
    canManage: boolean;
};
type FormData = {
    client_id: string;
    property_unit_id: string;
    renewal_of_tenancy_id: string;
    starts_on: string;
    ends_on: string;
    rent_frequency: string;
    rent_amount: string;
    security_deposit: string;
    service_charge: string;
    charge_quotes: Record<string, string>;
};
type NoticeFormData = { notice_type: string; notice_on: string; proposed_vacate_on: string; reason: string };
type HandoverFormData = { handed_over_on: string; handover_notes: string };
type ReconciliationFormData = { deductions: DepositDeduction[] };
type RefundFormData = { refund_reference: string; refund_notes: string };
type Props = { tenancies: Tenancy[]; clients: Client[]; properties: Property[]; canManage: boolean };

const emptyForm = (): FormData => ({
    client_id: '',
    property_unit_id: '',
    renewal_of_tenancy_id: '',
    starts_on: '',
    ends_on: '',
    rent_frequency: 'monthly',
    rent_amount: '',
    security_deposit: '0',
    service_charge: '0',
    charge_quotes: {},
});
const emptyDeduction = (): DepositDeduction => ({
    category: 'damage',
    description: '',
    amount: '',
    evidence_reference: '',
    rent_schedule_id: '',
});
const normalizedDeductions = (deductions: DepositDeduction[]) => deductions.map((deduction) => ({
    category: deduction.category,
    description: deduction.description,
    amount: deduction.amount,
    evidence_reference: deduction.evidence_reference ?? '',
    rent_schedule_id: deduction.rent_schedule_id ?? '',
}));

const formatMoney = (amount: string, currency: string) => new Intl.NumberFormat(undefined, {
    style: 'currency',
    currency,
    maximumFractionDigits: 2,
}).format(Number(amount));
const localDateString = () => {
    const date = new Date();
    date.setMinutes(date.getMinutes() - date.getTimezoneOffset());
    return date.toISOString().slice(0, 10);
};

export default function Index({ tenancies, clients, properties, canManage }: Props) {
    const [isAdding, setIsAdding] = useState(false);
    const [renewalSource, setRenewalSource] = useState<Tenancy | null>(null);
    const [selectedTenancyId, setSelectedTenancyId] = useState<string | null>(null);
    const form = useForm<FormData>(emptyForm());
    const noticeForm = useForm<NoticeFormData>({ notice_type: 'tenant', notice_on: '', proposed_vacate_on: '', reason: '' });
    const handoverForm = useForm<HandoverFormData>({ handed_over_on: '', handover_notes: '' });
    const reconciliationForm = useForm<ReconciliationFormData>({ deductions: [emptyDeduction()] });
    const refundForm = useForm<RefundFormData>({ refund_reference: '', refund_notes: '' });
    const selectedTenancy = tenancies.find((tenancy) => tenancy.id === selectedTenancyId) ?? null;
    const servedNotice = selectedTenancy?.notices.find((notice) => notice.status === 'served') ?? null;
    const completedNotice = selectedTenancy?.notices.find((notice) => notice.status === 'completed') ?? null;
    const hasUnsavedReconciliation = selectedTenancy?.depositReconciliation?.status === 'draft'
        && JSON.stringify(normalizedDeductions(reconciliationForm.data.deductions))
            !== JSON.stringify(normalizedDeductions(selectedTenancy.depositReconciliation.deductions));
    const selectedProperty = properties.find((property) => property.id === form.data.property_unit_id)
        ?? renewalSource?.renewal?.property;
    const activeChargePolicies = selectedProperty?.chargePolicies ?? [];
    const availableClients = useMemo(
        () => clients.filter((client) => client.organizationId === selectedProperty?.organizationId),
        [clients, selectedProperty],
    );
    const draftCount = tenancies.filter((tenancy) => tenancy.status === 'draft').length;
    const activeCount = tenancies.filter((tenancy) => tenancy.status === 'active').length;

    const openForm = () => {
        form.clearErrors();
        setRenewalSource(null);
        form.setData({
            ...emptyForm(),
            property_unit_id: properties[0]?.id ?? '',
            client_id: clients.find((client) => client.organizationId === properties[0]?.organizationId)?.id ?? '',
        });
        setIsAdding(true);
    };

    const openRenewal = (tenancy: Tenancy) => {
        if (!tenancy.renewal) return;
        form.clearErrors();
        setRenewalSource(tenancy);
        form.setData({
            ...emptyForm(),
            client_id: tenancy.renewal.clientId,
            property_unit_id: tenancy.renewal.property.id,
            renewal_of_tenancy_id: tenancy.id,
            starts_on: tenancy.renewal.startsOn,
            ends_on: tenancy.renewal.endsOn,
            rent_frequency: tenancy.frequency,
            rent_amount: tenancy.rentAmount,
            security_deposit: tenancy.securityDeposit,
            service_charge: tenancy.serviceCharge,
        });
        setIsAdding(true);
    };

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        form.post(route('tenancies.store'), {
            onSuccess: () => {
                setIsAdding(false);
                setRenewalSource(null);
            },
        });
    };

    const openCloseout = (tenancy: Tenancy) => {
        const today = localDateString();
        const proposedVacateOn = tenancy.endsOn && tenancy.endsOn >= today ? tenancy.endsOn : today;
        setSelectedTenancyId(tenancy.id);
        noticeForm.clearErrors();
        noticeForm.setData({ notice_type: 'tenant', notice_on: today, proposed_vacate_on: proposedVacateOn, reason: '' });
        handoverForm.clearErrors();
        handoverForm.setData({
            handed_over_on: proposedVacateOn <= today ? proposedVacateOn : today,
            handover_notes: '',
        });
        reconciliationForm.clearErrors();
        const deductions = tenancy.depositReconciliation?.deductions.length
            ? tenancy.depositReconciliation.deductions
            : [];
        reconciliationForm.setData({
            deductions,
        });
        refundForm.clearErrors();
        refundForm.setData({ refund_reference: '', refund_notes: '' });
    };

    const submitNotice: FormEventHandler = (event) => {
        event.preventDefault();
        if (!selectedTenancy) return;
        noticeForm.post(route('tenancies.notices.store', selectedTenancy.id), { preserveScroll: true });
    };

    const submitHandover: FormEventHandler = (event) => {
        event.preventDefault();
        if (!servedNotice) return;
        handoverForm.patch(route('tenancy-notices.handover', servedNotice.id), { preserveScroll: true });
    };

    const submitReconciliation: FormEventHandler = (event) => {
        event.preventDefault();
        if (!selectedTenancy) return;
        const reconciliation = selectedTenancy.depositReconciliation;
        const url = reconciliation
            ? route('tenancies.deposit-reconciliation.update', reconciliation.id)
            : route('tenancies.deposit-reconciliation.store', selectedTenancy.id);
        reconciliationForm.transform((data) => ({
            ...data,
            deductions: data.deductions.filter((deduction) => deduction.description || deduction.amount),
        }));
        if (reconciliation) {
            reconciliationForm.put(url, { preserveScroll: true });
        } else {
            reconciliationForm.post(url, { preserveScroll: true });
        }
    };

    const submitRefund: FormEventHandler = (event) => {
        event.preventDefault();
        const reconciliation = selectedTenancy?.depositReconciliation;
        if (!reconciliation) return;
        refundForm.patch(route('tenancies.deposit-reconciliation.refund', reconciliation.id), { preserveScroll: true });
    };

    return (
        <AuthenticatedLayout>
            <Head title="Tenancies" />
            <div className="space-y-8">
                <div className="flex flex-col gap-5 border-b border-[#dfe5df] pb-6 sm:flex-row sm:items-end sm:justify-between">
                    <div><p className="eyebrow">Leasing operations</p><h1 className="page-title">Tenancies</h1><p className="mt-2 max-w-2xl text-sm leading-6 text-[#64756e]">Set up tenancy terms, activate agreements, and generate rent schedules.</p></div>
                    {canManage && <button type="button" onClick={openForm} disabled={properties.length === 0 || clients.length === 0} className="inline-flex h-11 items-center justify-center gap-2 rounded-lg bg-[#17352d] px-4 text-sm font-semibold text-white hover:bg-[#244b40] disabled:cursor-not-allowed disabled:opacity-50"><Plus size={17} /> New tenancy</button>}
                </div>

                <div className="grid gap-6 border-b border-[#dfe5df] pb-6 sm:grid-cols-3">
                    <Summary label="Total tenancies" value={tenancies.length} icon={Home} />
                    <Summary label="Active" value={activeCount} icon={Users} />
                    <Summary label="Drafts to activate" value={draftCount} icon={CalendarDays} />
                </div>

                {canManage && properties.length === 0 && <p className="border-l-2 border-[#8ba344] bg-white px-5 py-4 text-sm text-[#53675e]">List an available property for rent from Inventory before creating a tenancy.</p>}

                {isAdding && (
                    <form onSubmit={submit} className="space-y-5 border border-[#dfe5df] bg-white p-5 sm:p-7">
                        <div className="flex items-start justify-between gap-4"><div><p className="eyebrow">{renewalSource ? 'Renewal draft' : 'New agreement'}</p><h2 className="mt-1 font-display text-xl text-[#18342c]">{renewalSource ? `Renew ${renewalSource.number}` : 'Tenancy details'}</h2></div><button type="button" onClick={() => { setIsAdding(false); setRenewalSource(null); }} aria-label="Close form" className="grid size-9 place-items-center rounded-lg text-[#6d7d76] hover:bg-[#f1f4ef]"><X size={18} /></button></div>
                        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                            {renewalSource ? (
                                <div><p className="field-label">Property</p><p className="mt-2 text-sm text-[#29483e]">{renewalSource.property}</p></div>
                            ) : <Field label="Property" id="tenancy-property">
                                <select id="tenancy-property" required value={form.data.property_unit_id} onChange={(event) => {
                                    const property = properties.find((item) => item.id === event.target.value);
                                    form.setData((current) => ({
                                        ...current,
                                        property_unit_id: event.target.value,
                                        client_id: clients.find((client) => client.organizationId === property?.organizationId)?.id ?? '',
                                        charge_quotes: {},
                                    }));
                                }} className="field-input">
                                    <option value="">Select a rentable property</option>
                                    {properties.map((property) => <option key={property.id} value={property.id}>{property.projectName} · {property.label} ({property.unitType})</option>)}
                                </select>
                                <InputError message={form.errors.property_unit_id} className="mt-2" />
                            </Field>}
                            {renewalSource ? (
                                <div><p className="field-label">Tenant</p><p className="mt-2 text-sm text-[#29483e]">{renewalSource.tenantName} · {renewalSource.tenantPhone}</p></div>
                            ) : <Field label="Verified tenant" id="tenancy-client">
                                <select id="tenancy-client" required value={form.data.client_id} onChange={(event) => form.setData('client_id', event.target.value)} className="field-input">
                                    <option value="">Select a tenant</option>
                                    {availableClients.map((client) => <option key={client.id} value={client.id}>{client.name} · {client.phone}</option>)}
                                </select>
                                <InputError message={form.errors.client_id} className="mt-2" />
                            </Field>}
                            <Field label="Rent frequency" id="tenancy-frequency">
                                <select id="tenancy-frequency" value={form.data.rent_frequency} onChange={(event) => form.setData('rent_frequency', event.target.value)} className="field-input">
                                    <option value="monthly">Monthly</option><option value="quarterly">Quarterly</option><option value="semi_annual">Semi-annual</option><option value="annual">Annual</option>
                                </select>
                                <InputError message={form.errors.rent_frequency} className="mt-2" />
                            </Field>
                            <InputField label="Start date" id="tenancy-start" type="date" value={form.data.starts_on} error={form.errors.starts_on} onChange={(value) => form.setData('starts_on', value)} />
                            <InputField label="End date" id="tenancy-end" type="date" value={form.data.ends_on} error={form.errors.ends_on} onChange={(value) => form.setData('ends_on', value)} />
                            <InputField label="Rent per period" id="tenancy-rent" type="number" min="0.01" step="0.01" value={form.data.rent_amount} error={form.errors.rent_amount} onChange={(value) => form.setData('rent_amount', value)} />
                        </div>
                        <fieldset className="space-y-4 border-t border-[#e7ece6] pt-5">
                            <legend className="field-label">{activeChargePolicies.length > 0 ? 'Active company charge policies' : 'Manually entered charges'}</legend>
                            {activeChargePolicies.length > 0 ? (
                                <div className="space-y-3">
                                    {activeChargePolicies.map((policy) => {
                                        const methodDescription = policy.calculationMethod === 'fixed'
                                            ? `Fixed amount ${formatMoney(policy.amount, selectedProperty?.currency ?? 'NGN')}`
                                            : policy.calculationMethod === 'percentage'
                                                ? `${policy.amount}% of rent per period`
                                                : policy.calculationMethod === 'per_area'
                                                    ? `${formatMoney(policy.amount, selectedProperty?.currency ?? 'NGN')} per m²${selectedProperty?.areaSqm ? ` · ${selectedProperty.areaSqm} m²` : ''}`
                                                    : 'Enter a contract-specific quote';
                                        return (
                                            <div key={policy.id} className="grid gap-3 rounded-lg border border-[#e7ece6] p-4 sm:grid-cols-[1fr_auto] sm:items-center">
                                                <div><p className="text-sm font-medium text-[#29483e]">{policy.name}</p><p className="mt-1 text-xs text-[#73827d]">{methodDescription}{policy.isRecurring ? ' · repeats each rent period' : ' · charged in the first rent period'}</p></div>
                                                {policy.calculationMethod === 'manual_quote' && <div><label htmlFor={`tenancy-charge-quote-${policy.id}`} className="field-label">Quote ({selectedProperty?.currency ?? 'NGN'})</label><input id={`tenancy-charge-quote-${policy.id}`} type="number" min="0" step="0.01" required value={form.data.charge_quotes[policy.id] ?? ''} onChange={(event) => form.setData('charge_quotes', { ...form.data.charge_quotes, [policy.id]: event.target.value })} className="field-input sm:w-48" /></div>}
                                            </div>
                                        );
                                    })}
                                </div>
                            ) : (
                                <>
                                    <p className="text-sm text-[#64756e]">No active rental policies are configured for this organization. Enter the one-time deposit and recurring service charge for this agreement.</p>
                                    <div className="grid gap-4 sm:grid-cols-2">
                                        <InputField label="Security deposit" id="tenancy-deposit" type="number" min="0" step="0.01" value={form.data.security_deposit} error={form.errors.security_deposit} onChange={(value) => form.setData('security_deposit', value)} />
                                        <InputField label="Service charge per period" id="tenancy-service-charge" type="number" min="0" step="0.01" value={form.data.service_charge} error={form.errors.service_charge} onChange={(value) => form.setData('service_charge', value)} />
                                    </div>
                                </>
                            )}
                            {Object.entries(form.errors).filter(([key]) => key.startsWith('charge_quotes.')).map(([key, message]) => <InputError key={key} message={message} />)}
                            {form.errors.charge_quotes && <InputError message={form.errors.charge_quotes} />}
                        </fieldset>
                        <p className="text-xs leading-5 text-[#76857e]">{renewalSource ? 'The renewal is a separate agreement draft linked to the current tenancy. Existing terms and rent charges remain unchanged; activation is available during the new term.' : 'The agreement is saved as a draft. Activating it checks for overlapping tenancies and creates the scheduled rent charges.'}</p>
                        <div className="flex justify-end"><button type="submit" disabled={form.processing || !selectedProperty || availableClients.length === 0} className="inline-flex h-10 items-center gap-2 rounded-lg bg-[#17352d] px-4 text-sm font-semibold text-white hover:bg-[#244b40] disabled:opacity-50">{form.processing ? 'Saving…' : renewalSource ? 'Save renewal draft' : 'Save draft'}</button></div>
                    </form>
                )}

                {selectedTenancy && (
                    <section className="space-y-6 border border-[#dfe5df] bg-white p-5 sm:p-7">
                        <div className="flex items-start justify-between gap-4">
                            <div>
                                <p className="eyebrow">Tenancy closeout</p>
                                <h2 className="mt-1 font-display text-xl text-[#18342c]">{selectedTenancy.number}</h2>
                                <p className="mt-2 text-sm text-[#64756e]">{selectedTenancy.tenantName} · {selectedTenancy.property}</p>
                            </div>
                            <button type="button" onClick={() => setSelectedTenancyId(null)} aria-label="Close tenancy closeout" className="grid size-9 place-items-center rounded-lg text-[#6d7d76] hover:bg-[#f1f4ef]"><X size={18} /></button>
                        </div>

                        <div className="grid gap-6 lg:grid-cols-2">
                            <div className="space-y-4">
                                <div>
                                    <h3 className="font-semibold text-[#29483e]">Notice and handover</h3>
                                    <p className="mt-1 text-xs leading-5 text-[#76857e]">Record the agreed notice dates. Confirm the tenancy agreement and applicable state requirements; REMS does not prescribe a statutory notice period.</p>
                                </div>
                                {selectedTenancy.notices.length > 0 && (
                                    <div className="space-y-2">
                                        {selectedTenancy.notices.map((notice) => (
                                            <div key={notice.id} className="rounded-lg border border-[#e7ece6] p-3 text-sm">
                                                <div className="flex flex-wrap items-center justify-between gap-2">
                                                    <p className="font-medium capitalize text-[#29483e]">{notice.type.replaceAll('_', ' ')} notice · {notice.status}</p>
                                                    <p className="text-xs text-[#73827d]">{notice.noticeOn} → {notice.proposedVacateOn}</p>
                                                </div>
                                                <p className="mt-2 text-[#64756e]">{notice.reason}</p>
                                                {notice.handedOverOn && <p className="mt-2 text-xs text-[#64756e]">Handed over {notice.handedOverOn}{notice.handoverNotes ? ` · ${notice.handoverNotes}` : ''}</p>}
                                            </div>
                                        ))}
                                    </div>
                                )}
                                {selectedTenancy.canManage && selectedTenancy.status === 'active' && !servedNotice && (
                                    <form onSubmit={submitNotice} className="space-y-3 rounded-lg bg-[#f8faf6] p-4">
                                        <p className="text-sm font-medium text-[#29483e]">Record a notice to end the tenancy</p>
                                        <div className="grid gap-3 sm:grid-cols-3">
                                            <Field label="Notice from" id="notice-type">
                                                <select id="notice-type" required value={noticeForm.data.notice_type} onChange={(event) => noticeForm.setData('notice_type', event.target.value)} className="field-input">
                                                    <option value="tenant">Tenant</option><option value="landlord">Landlord</option><option value="mutual_agreement">Mutual agreement</option>
                                                </select>
                                            </Field>
                                            <InputField label="Notice date" id="notice-date" type="date" value={noticeForm.data.notice_on} error={noticeForm.errors.notice_on} onChange={(value) => noticeForm.setData('notice_on', value)} />
                                            <InputField label="Proposed vacate date" id="notice-vacate" type="date" value={noticeForm.data.proposed_vacate_on} error={noticeForm.errors.proposed_vacate_on} onChange={(value) => noticeForm.setData('proposed_vacate_on', value)} />
                                        </div>
                                        <div><label htmlFor="notice-reason" className="field-label">Reason</label><textarea id="notice-reason" required maxLength={5000} value={noticeForm.data.reason} onChange={(event) => noticeForm.setData('reason', event.target.value)} className="field-input min-h-20" /><InputError message={noticeForm.errors.reason} className="mt-2" /></div>
                                        <InputError message={noticeForm.errors.notice_type} />
                                        <button type="submit" disabled={noticeForm.processing} className="inline-flex h-9 items-center rounded-lg bg-[#17352d] px-3 text-xs font-semibold text-white disabled:opacity-50">{noticeForm.processing ? 'Saving…' : 'Record notice'}</button>
                                    </form>
                                )}
                                {selectedTenancy.canManage && servedNotice && (
                                    <div className="space-y-3 rounded-lg bg-[#f8faf6] p-4">
                                        <div className="flex flex-wrap items-center justify-between gap-3">
                                            <p className="text-sm font-medium text-[#29483e]">Complete property handover</p>
                                            <button type="button" onClick={() => router.patch(route('tenancy-notices.withdraw', servedNotice.id), {}, { preserveScroll: true })} className="text-xs font-semibold text-[#8a493b] hover:underline">Withdraw notice</button>
                                        </div>
                                        <form onSubmit={submitHandover} className="space-y-3">
                                            <InputField label="Actual handover date" id="handover-date" type="date" value={handoverForm.data.handed_over_on} error={handoverForm.errors.handed_over_on} onChange={(value) => handoverForm.setData('handed_over_on', value)} />
                                            <div><label htmlFor="handover-notes" className="field-label">Inspection and key-return notes</label><textarea id="handover-notes" maxLength={5000} value={handoverForm.data.handover_notes} onChange={(event) => handoverForm.setData('handover_notes', event.target.value)} className="field-input min-h-20" /><InputError message={handoverForm.errors.handover_notes} className="mt-2" /></div>
                                            <p className="text-xs leading-5 text-[#76857e]">{servedNotice.type === 'mutual_agreement' ? 'A mutually agreed early handover can be recorded.' : `Handover is available on or after ${servedNotice.proposedVacateOn}.`}</p>
                                            <button type="submit" disabled={handoverForm.processing || (servedNotice.type !== 'mutual_agreement' && servedNotice.proposedVacateOn > localDateString())} className="inline-flex h-9 items-center rounded-lg bg-[#17352d] px-3 text-xs font-semibold text-white disabled:opacity-50">{handoverForm.processing ? 'Saving…' : 'Complete handover'}</button>
                                        </form>
                                        <InputError message={handoverForm.errors.handed_over_on} />
                                    </div>
                                )}
                                {selectedTenancy.status === 'completed' && !completedNotice && <p className="rounded-lg bg-[#f8faf6] p-4 text-sm text-[#64756e]">{selectedTenancy.renewedByNumber ? `This tenancy was superseded by ${selectedTenancy.renewedByNumber}; its security deposit is carried forward and will be reconciled at the renewed tenancy’s handover.` : 'This tenancy was completed without a recorded handover notice. Deposit reconciliation is available after handover has been recorded.'}</p>}
                            </div>

                            <div className="space-y-4">
                                <div>
                                    <h3 className="font-semibold text-[#29483e]">Security deposit reconciliation</h3>
                                    <p className="mt-1 text-xs leading-5 text-[#76857e]">Only approved receipts allocated to security-deposit charges count as funds held. Record each deduction with a reason and evidence reference where available.</p>
                                </div>
                                <p className="rounded-lg bg-[#f8faf6] px-4 py-3 text-sm text-[#29483e]">Deposit held (approved receipts{Number(selectedTenancy.depositCarriedForwardAmount) > 0 ? ` plus ${formatMoney(selectedTenancy.depositCarriedForwardAmount, selectedTenancy.currency)} carried forward` : ''}): <strong>{formatMoney(selectedTenancy.depositReceivedAmount, selectedTenancy.currency)}</strong></p>
                                {selectedTenancy.status === 'completed' && completedNotice && selectedTenancy.canManage && (
                                    <>
                                        {selectedTenancy.depositReconciliation?.status === 'draft' || !selectedTenancy.depositReconciliation ? (
                                            <form onSubmit={submitReconciliation} className="space-y-4">
                                                <div className="space-y-3">
                                                    {reconciliationForm.data.deductions.map((deduction, index) => (
                                                        <div key={index} className="grid gap-3 rounded-lg border border-[#e7ece6] p-3 sm:grid-cols-2">
                                                            <Field label="Deduction type" id={`deduction-category-${index}`}>
                                                                <select id={`deduction-category-${index}`} required value={deduction.category} onChange={(event) => reconciliationForm.setData('deductions', reconciliationForm.data.deductions.map((item, itemIndex) => itemIndex === index ? { ...item, category: event.target.value, rent_schedule_id: event.target.value === 'unpaid_rent' ? item.rent_schedule_id : '' } : item))} className="field-input">
                                                                    <option value="unpaid_rent">Unpaid rent</option><option value="damage">Damage</option><option value="utilities">Utilities</option><option value="other">Other</option>
                                                                </select>
                                                            </Field>
                                                            {deduction.category === 'unpaid_rent' && <Field label="Rent charge to settle" id={`deduction-rent-schedule-${index}`}>
                                                                <select id={`deduction-rent-schedule-${index}`} required value={deduction.rent_schedule_id ?? ''} onChange={(event) => reconciliationForm.setData('deductions', reconciliationForm.data.deductions.map((item, itemIndex) => itemIndex === index ? { ...item, rent_schedule_id: event.target.value } : item))} className="field-input">
                                                                    <option value="">Select an outstanding rent charge</option>
                                                                    {selectedTenancy.unpaidRentSchedules.map((schedule) => <option key={schedule.id} value={schedule.id}>{schedule.period} · balance {formatMoney(schedule.amountDue, selectedTenancy.currency)}</option>)}
                                                                </select>
                                                                <InputError message={reconciliationForm.errors[`deductions.${index}.rent_schedule_id`]} className="mt-2" />
                                                            </Field>}
                                                            <div><label htmlFor={`deduction-amount-${index}`} className="field-label">Amount</label><input id={`deduction-amount-${index}`} required type="number" min="0.01" step="0.01" value={deduction.amount} onChange={(event) => reconciliationForm.setData('deductions', reconciliationForm.data.deductions.map((item, itemIndex) => itemIndex === index ? { ...item, amount: event.target.value } : item))} className="field-input" /><InputError message={reconciliationForm.errors[`deductions.${index}.amount`]} className="mt-2" /></div>
                                                            <div><label htmlFor={`deduction-description-${index}`} className="field-label">Reason</label><input id={`deduction-description-${index}`} required maxLength={180} value={deduction.description} onChange={(event) => reconciliationForm.setData('deductions', reconciliationForm.data.deductions.map((item, itemIndex) => itemIndex === index ? { ...item, description: event.target.value } : item))} className="field-input" /><InputError message={reconciliationForm.errors[`deductions.${index}.description`]} className="mt-2" /></div>
                                                            <div className="flex items-end gap-2"><div className="flex-1"><label htmlFor={`deduction-evidence-${index}`} className="field-label">Evidence reference (optional)</label><input id={`deduction-evidence-${index}`} maxLength={200} value={deduction.evidence_reference ?? ''} onChange={(event) => reconciliationForm.setData('deductions', reconciliationForm.data.deductions.map((item, itemIndex) => itemIndex === index ? { ...item, evidence_reference: event.target.value } : item))} className="field-input" /></div><button type="button" onClick={() => reconciliationForm.setData('deductions', reconciliationForm.data.deductions.filter((_, itemIndex) => itemIndex !== index))} aria-label={`Remove deduction ${index + 1}`} className="grid size-10 place-items-center rounded-lg text-[#8a493b] hover:bg-[#f9eeeb]"><X size={16} /></button></div>
                                                        </div>
                                                    ))}
                                                </div>
                                                <InputError message={reconciliationForm.errors.deductions} />
                                                <div className="flex flex-wrap gap-2">
                                                    <button type="button" onClick={() => reconciliationForm.setData('deductions', [...reconciliationForm.data.deductions, emptyDeduction()])} className="inline-flex h-9 items-center gap-1.5 rounded-lg border border-[#d9e1d9] px-3 text-xs font-semibold text-[#315447] hover:bg-[#f1f4ef]"><Plus size={14} /> Add deduction</button>
                                                    <button type="submit" disabled={reconciliationForm.processing} className="inline-flex h-9 items-center rounded-lg bg-[#17352d] px-3 text-xs font-semibold text-white disabled:opacity-50">{reconciliationForm.processing ? 'Saving…' : selectedTenancy.depositReconciliation ? 'Update draft' : 'Save draft'}</button>
                                                    {selectedTenancy.depositReconciliation && <button type="button" disabled={reconciliationForm.processing || hasUnsavedReconciliation} onClick={() => router.patch(route('tenancies.deposit-reconciliation.finalize', selectedTenancy.depositReconciliation?.id), {}, { preserveScroll: true })} className="inline-flex h-9 items-center rounded-lg border border-[#d9e1d9] px-3 text-xs font-semibold text-[#315447] hover:bg-[#f1f4ef] disabled:opacity-50">Finalize reconciliation</button>}
                                                </div>
                                            </form>
                                        ) : (
                                            <div className="space-y-3 rounded-lg border border-[#e7ece6] p-4">
                                                <p className="text-sm font-medium capitalize text-[#29483e]">Reconciliation {selectedTenancy.depositReconciliation.status}</p>
                                                <div className="grid gap-2 text-sm text-[#64756e] sm:grid-cols-3">
                                                    <p>Held: {formatMoney(selectedTenancy.depositReconciliation.depositReceivedAmount, selectedTenancy.currency)}</p>
                                                    <p>Deductions: {formatMoney(selectedTenancy.depositReconciliation.deductionTotal, selectedTenancy.currency)}</p>
                                                    <p>Refund due: {formatMoney(selectedTenancy.depositReconciliation.refundDueAmount, selectedTenancy.currency)}</p>
                                                </div>
                                                {selectedTenancy.depositReconciliation.deductions.map((deduction, index) => <p key={`${deduction.category}-${index}`} className="text-xs text-[#64756e]">{deduction.category.replaceAll('_', ' ')} · {deduction.description} · {formatMoney(deduction.amount, selectedTenancy.currency)}{deduction.evidence_reference ? ` · Ref: ${deduction.evidence_reference}` : ''}</p>)}
                                                {selectedTenancy.depositReconciliation.status === 'finalized' && Number(selectedTenancy.depositReconciliation.refundDueAmount) > 0 && (
                                                    <form onSubmit={submitRefund} className="space-y-3 border-t border-[#e7ece6] pt-3">
                                                        <p className="text-sm font-medium text-[#29483e]">Record completed refund</p>
                                                        <div><label htmlFor="refund-reference" className="field-label">Payment reference</label><input id="refund-reference" required maxLength={120} value={refundForm.data.refund_reference} onChange={(event) => refundForm.setData('refund_reference', event.target.value)} className="field-input" /><InputError message={refundForm.errors.refund_reference} className="mt-2" /></div>
                                                        <div><label htmlFor="refund-notes" className="field-label">Notes (optional)</label><textarea id="refund-notes" maxLength={2000} value={refundForm.data.refund_notes} onChange={(event) => refundForm.setData('refund_notes', event.target.value)} className="field-input min-h-16" /></div>
                                                        <button type="submit" disabled={refundForm.processing} className="inline-flex h-9 items-center rounded-lg bg-[#17352d] px-3 text-xs font-semibold text-white disabled:opacity-50">{refundForm.processing ? 'Saving…' : 'Record refund paid'}</button>
                                                    </form>
                                                )}
                                                {selectedTenancy.depositReconciliation.status === 'finalized' && Number(selectedTenancy.depositReconciliation.refundDueAmount) === 0 && <p className="text-xs text-[#64756e]">No refund is due after the recorded deductions.</p>}
                                                {selectedTenancy.depositReconciliation.status === 'refunded' && <p className="text-xs text-[#64756e]">Refund recorded{selectedTenancy.depositReconciliation.refundReference ? ` · Reference ${selectedTenancy.depositReconciliation.refundReference}` : ''}{selectedTenancy.depositReconciliation.refundedAt ? ` · ${new Date(selectedTenancy.depositReconciliation.refundedAt).toLocaleDateString()}` : ''}</p>}
                                            </div>
                                        )}
                                    </>
                                )}
                            </div>
                        </div>
                    </section>
                )}

                <div className="overflow-x-auto border border-[#dfe5df] bg-white">
                    <table className="w-full min-w-[900px] text-left">
                        <thead className="bg-[#f8faf6] text-[11px] font-semibold uppercase tracking-[0.12em] text-[#77857f]"><tr><th className="px-5 py-3">Tenancy</th><th className="px-5 py-3">Tenant</th><th className="px-5 py-3">Property</th><th className="px-5 py-3">Term</th><th className="px-5 py-3 text-right">Rent</th><th className="px-5 py-3">Status</th><th className="px-5 py-3" /></tr></thead>
                        <tbody className="divide-y divide-[#edf0ec]">
                            {tenancies.map((tenancy) => <tr key={tenancy.id}>
                                <td className="px-5 py-4"><p className="text-sm font-semibold text-[#233c33]">{tenancy.number}</p><p className="mt-1 text-xs capitalize text-[#7b8983]">{tenancy.frequency.replaceAll('_', ' ')}</p>{tenancy.renewalOfNumber && <p className="mt-1 text-xs text-[#7b8983]">Renewal of {tenancy.renewalOfNumber}</p>}</td>
                                <td className="px-5 py-4"><p className="text-sm font-medium text-[#29483e]">{tenancy.tenantName}</p><p className="mt-1 text-xs text-[#7b8983]">{tenancy.tenantPhone}</p></td>
                                <td className="px-5 py-4 text-sm text-[#4c6259]">{tenancy.property}</td>
                                <td className="px-5 py-4 text-sm text-[#4c6259]">{tenancy.startsOn} – {tenancy.endsOn ?? 'Open ended'}</td>
                                <td className="px-5 py-4 text-right text-sm font-medium text-[#29483e]">{formatMoney(tenancy.rentAmount, tenancy.currency)}{tenancy.additionalCharges.length > 0 && <p className="mt-1 text-xs font-normal text-[#7b8983]" title={tenancy.additionalCharges.map((charge) => charge.name).join(', ')}>Charges {formatMoney(tenancy.additionalChargesTotal, tenancy.currency)} · {tenancy.additionalCharges.map((charge) => charge.name).join(', ')}</p>}</td>
                                <td className="px-5 py-4"><span className={`rounded-full px-2.5 py-1 text-xs font-medium ${tenancy.status === 'active' ? 'bg-[#edf4eb] text-[#315447]' : tenancy.status === 'completed' ? 'bg-[#e9edf0] text-[#465860]' : 'bg-[#f8f2dc] text-[#756128]'}`}>{tenancy.status.replaceAll('_', ' ')}</span></td>
                                <td className="px-5 py-4 text-right"><div className="flex justify-end gap-2">{canManage && tenancy.canManage && tenancy.status === 'draft' && <button type="button" onClick={() => router.patch(route('tenancies.activate', tenancy.id), {}, { preserveScroll: true })} className="inline-flex h-9 items-center gap-1.5 rounded-lg bg-[#17352d] px-3 text-xs font-semibold text-white hover:bg-[#244b40]"><Check size={14} /> Activate</button>}{canManage && tenancy.canManage && tenancy.renewal && <button type="button" onClick={() => openRenewal(tenancy)} className="inline-flex h-9 items-center gap-1.5 rounded-lg border border-[#d9e1d9] px-3 text-xs font-semibold text-[#315447] hover:bg-[#f1f4ef]"><CalendarDays size={14} /> Draft renewal</button>}{canManage && tenancy.canManage && ['active', 'completed'].includes(tenancy.status) && <button type="button" onClick={() => selectedTenancyId === tenancy.id ? setSelectedTenancyId(null) : openCloseout(tenancy)} className="inline-flex h-9 items-center rounded-lg border border-[#d9e1d9] px-3 text-xs font-semibold text-[#315447] hover:bg-[#f1f4ef]">{selectedTenancyId === tenancy.id ? 'Closeout open' : 'Manage closeout'}</button>}</div></td>
                            </tr>)}
                            {tenancies.length === 0 && <tr><td colSpan={7} className="px-5 py-12 text-center text-sm text-[#718078]">No tenancies have been created yet.</td></tr>}
                        </tbody>
                    </table>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}

function Summary({ label, value, icon: Icon }: { label: string; value: number; icon: typeof Home }) {
    return <div className="flex items-center gap-3 border-t-2 border-[#d9e1d9] bg-white px-4 py-4 sm:px-5"><span className="grid size-10 place-items-center rounded-lg bg-[#e7eee1] text-[#315447]"><Icon size={18} /></span><div><p className="text-xs text-[#73827d]">{label}</p><p className="mt-1 text-lg font-semibold text-[#233c33]">{value}</p></div></div>;
}

function Field({ label, id, children }: { label: string; id: string; children: React.ReactNode }) {
    return <div><label htmlFor={id} className="field-label">{label}</label>{children}</div>;
}

function InputField({ label, id, type = 'text', min, step, value, error, onChange }: { label: string; id: string; type?: string; min?: string; step?: string; value: string; error?: string; onChange: (value: string) => void }) {
    return <div><label htmlFor={id} className="field-label">{label}</label><input id={id} type={type} required={id !== 'tenancy-deposit' && id !== 'tenancy-service-charge'} min={min} step={step} value={value} onChange={(event) => onChange(event.target.value)} className="field-input" /><InputError message={error} className="mt-2" /></div>;
}
