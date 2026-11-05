import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import InputError from '@/Components/InputError';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { ArrowLeft, BellRing, Plus, X } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

type Organization = { id: string; name: string; currency: string };
type Policy = {
    id: string;
    organizationId: string;
    organizationName: string;
    currency: string;
    name: string;
    gracePeriodDays: number;
    calculationMethod: string;
    amount: string;
    recurrenceIntervalDays: number | null;
    maximumOccurrences: number;
    maximumFeeAmount: string | null;
    appliesTo: string[];
    isActive: boolean;
};
type FormData = {
    organization_id: string;
    name: string;
    grace_period_days: string;
    calculation_method: string;
    amount: string;
    recurrence_interval_days: string;
    maximum_occurrences: string;
    maximum_fee_amount: string;
    applies_to: string[];
    is_active: boolean;
};
type Props = { organizations: Organization[]; policies: Policy[] };

const emptyForm = (organizationId: string): FormData => ({
    organization_id: organizationId,
    name: '',
    grace_period_days: '5',
    calculation_method: 'fixed',
    amount: '1000.00',
    recurrence_interval_days: '',
    maximum_occurrences: '1',
    maximum_fee_amount: '',
    applies_to: ['rent'],
    is_active: true,
});

const formatMoney = (amount: string, currency: string) => new Intl.NumberFormat(undefined, {
    style: 'currency',
    currency,
    maximumFractionDigits: 2,
}).format(Number(amount));

export default function Index({ organizations, policies }: Props) {
    const [isOpen, setIsOpen] = useState(false);
    const [editingId, setEditingId] = useState<string | null>(null);
    const form = useForm<FormData>(emptyForm(organizations[0]?.id ?? ''));
    const selectedOrganization = organizations.find((organization) => organization.id === form.data.organization_id);

    const openCreate = () => {
        form.clearErrors();
        form.setData(emptyForm(organizations[0]?.id ?? ''));
        setEditingId(null);
        setIsOpen(true);
    };

    const openEdit = (policy: Policy) => {
        form.clearErrors();
        form.setData({
            organization_id: policy.organizationId,
            name: policy.name,
            grace_period_days: String(policy.gracePeriodDays),
            calculation_method: policy.calculationMethod,
            amount: policy.amount,
            recurrence_interval_days: policy.recurrenceIntervalDays === null ? '' : String(policy.recurrenceIntervalDays),
            maximum_occurrences: String(policy.maximumOccurrences),
            maximum_fee_amount: policy.maximumFeeAmount ?? '',
            applies_to: policy.appliesTo,
            is_active: policy.isActive,
        });
        setEditingId(policy.id);
        setIsOpen(true);
    };

    const closeForm = () => {
        setIsOpen(false);
        setEditingId(null);
        form.clearErrors();
    };

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        const options = { onSuccess: closeForm };
        if (editingId) {
            form.put(route('late-payment-policies.update', editingId), options);
        } else {
            form.post(route('late-payment-policies.store'), options);
        }
    };

    const toggleActive = (policy: Policy) => {
        router.put(route('late-payment-policies.update', policy.id), {
            organization_id: policy.organizationId,
            name: policy.name,
            grace_period_days: policy.gracePeriodDays,
            calculation_method: policy.calculationMethod,
            amount: policy.amount,
            recurrence_interval_days: policy.recurrenceIntervalDays,
            maximum_occurrences: policy.maximumOccurrences,
            maximum_fee_amount: policy.maximumFeeAmount,
            applies_to: policy.appliesTo,
            is_active: !policy.isActive,
        }, { preserveScroll: true });
    };

    return (
        <AuthenticatedLayout>
            <Head title="Late-payment policies" />
            <div className="space-y-8">
                <div className="flex flex-col gap-5 border-b border-[#dfe5df] pb-6 sm:flex-row sm:items-end sm:justify-between">
                    <div>
                        <Link href={route('charges.index')} className="mb-3 inline-flex items-center gap-1.5 text-xs font-semibold text-[#547067] hover:text-[#17352d]"><ArrowLeft size={14} /> Charge policies</Link>
                        <p className="eyebrow">Company configuration</p>
                        <h1 className="page-title">Late-payment policies</h1>
                        <p className="mt-2 max-w-2xl text-sm leading-6 text-[#64756e]">Set a grace period, fee basis, recurrence, and cap per organization. Assessments are based on unpaid rent or service-charge principal and never compound on earlier late fees.</p>
                    </div>
                    <button type="button" onClick={openCreate} disabled={organizations.length === 0} className="inline-flex h-11 items-center justify-center gap-2 rounded-lg bg-[#17352d] px-4 text-sm font-semibold text-white hover:bg-[#244b40] disabled:opacity-50"><Plus size={17} /> New late-payment policy</button>
                </div>

                <p className="border-l-2 border-[#8ba344] bg-white px-5 py-4 text-sm leading-6 text-[#53675e]">Fees are assessed only after the configured grace period and remain limited by the policy cap and outstanding principal. Confirm contract terms and applicable local requirements before enabling a policy. Assessment does not send SMS or email.</p>

                {isOpen && <form onSubmit={submit} className="space-y-5 border border-[#dfe5df] bg-white p-5 sm:p-7">
                    <div className="flex items-start justify-between gap-4">
                        <div><p className="eyebrow">{editingId ? 'Edit policy' : 'New policy'}</p><h2 className="mt-1 font-display text-xl text-[#18342c]">Late-fee rules</h2></div>
                        <button type="button" onClick={closeForm} aria-label="Close form" className="grid size-9 place-items-center rounded-lg text-[#6d7d76] hover:bg-[#f1f4ef]"><X size={18} /></button>
                    </div>
                    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        <div><label htmlFor="late-policy-organization" className="field-label">Organization</label><select id="late-policy-organization" disabled={Boolean(editingId)} value={form.data.organization_id} onChange={(event) => form.setData('organization_id', event.target.value)} className="field-input">{organizations.map((organization) => <option key={organization.id} value={organization.id}>{organization.name}</option>)}</select><InputError message={form.errors.organization_id} className="mt-2" /></div>
                        <Field label="Policy name" id="late-policy-name" value={form.data.name} error={form.errors.name} onChange={(value) => form.setData('name', value)} />
                        <NumberField label="Grace period (days)" id="late-policy-grace" min="0" max="365" value={form.data.grace_period_days} error={form.errors.grace_period_days} onChange={(value) => form.setData('grace_period_days', value)} />
                        <div><label htmlFor="late-policy-method" className="field-label">Fee calculation</label><select id="late-policy-method" value={form.data.calculation_method} onChange={(event) => form.setData('calculation_method', event.target.value)} className="field-input"><option value="fixed">Fixed amount</option><option value="percentage">Percentage of overdue principal</option></select><InputError message={form.errors.calculation_method} className="mt-2" /></div>
                        <NumberField label={form.data.calculation_method === 'percentage' ? 'Rate (%)' : `Fee amount (${selectedOrganization?.currency ?? 'NGN'})`} id="late-policy-amount" min="0.01" max={form.data.calculation_method === 'percentage' ? '100' : undefined} step="0.01" value={form.data.amount} error={form.errors.amount} onChange={(value) => form.setData('amount', value)} />
                        <NumberField label="Repeat interval (days; blank for one-time)" id="late-policy-interval" min="1" max="365" value={form.data.recurrence_interval_days} error={form.errors.recurrence_interval_days} onChange={(value) => form.setData((current) => ({ ...current, recurrence_interval_days: value, maximum_occurrences: value ? (current.maximum_occurrences === '1' ? '3' : current.maximum_occurrences) : '1' }))} />
                        <NumberField label="Maximum fee occurrences" id="late-policy-occurrences" min="1" max="100" value={form.data.maximum_occurrences} error={form.errors.maximum_occurrences} onChange={(value) => form.setData('maximum_occurrences', value)} />
                        <NumberField label={`Total fee cap (${selectedOrganization?.currency ?? 'NGN'}; blank for no cap)`} id="late-policy-cap" min="0.01" step="0.01" value={form.data.maximum_fee_amount} error={form.errors.maximum_fee_amount} onChange={(value) => form.setData('maximum_fee_amount', value)} />
                    </div>
                    <fieldset className="space-y-2">
                        <legend className="field-label">Apply only to</legend>
                        <div className="flex flex-wrap gap-4">
                            {([['rent', 'Rent'], ['service_charge', 'Service charges']] as const).map(([value, label]) => (
                                <label key={value} className="inline-flex items-center gap-2 text-sm text-[#4c6259]">
                                    <input type="checkbox" checked={form.data.applies_to.includes(value)} onChange={(event) => form.setData('applies_to', event.target.checked ? [...form.data.applies_to, value] : form.data.applies_to.filter((item) => item !== value))} />
                                    {label}
                                </label>
                            ))}
                        </div>
                        <InputError message={form.errors.applies_to} />
                    </fieldset>
                    <div className="flex justify-end"><button type="submit" disabled={form.processing || !form.data.organization_id} className="inline-flex h-10 items-center gap-2 rounded-lg bg-[#17352d] px-4 text-sm font-semibold text-white disabled:opacity-50"><BellRing size={15} />{form.processing ? 'Saving…' : editingId ? 'Save policy' : 'Create policy'}</button></div>
                </form>}

                <section className="space-y-4">
                    {policies.map((policy) => (
                        <article key={policy.id} className="flex flex-col gap-4 border border-[#dfe5df] bg-white p-5 sm:flex-row sm:items-start sm:justify-between">
                            <div>
                                <div className="flex flex-wrap items-center gap-2"><h2 className="font-semibold text-[#233c33]">{policy.name}</h2><span className={`rounded-full px-2.5 py-1 text-xs font-medium ${policy.isActive ? 'bg-[#edf4eb] text-[#315447]' : 'bg-[#e9edf0] text-[#465860]'}`}>{policy.isActive ? 'Active' : 'Inactive'}</span></div>
                                <p className="mt-1 text-xs text-[#7b8983]">{policy.organizationName}</p>
                                <p className="mt-3 text-sm text-[#4c6259]">{policy.gracePeriodDays} grace days · {policy.calculationMethod === 'fixed' ? formatMoney(policy.amount, policy.currency) : `${policy.amount}% of overdue principal`} per assessment</p>
                                <p className="mt-1 text-sm text-[#64756e]">{policy.recurrenceIntervalDays === null ? 'One-time' : `Repeats every ${policy.recurrenceIntervalDays} days`} · maximum {policy.maximumOccurrences} occurrence(s){policy.maximumFeeAmount ? ` · capped at ${formatMoney(policy.maximumFeeAmount, policy.currency)}` : ''}</p>
                                <p className="mt-1 text-xs capitalize text-[#7b8983]">Applies to {policy.appliesTo.map((item) => item.replaceAll('_', ' ')).join(', ')}</p>
                            </div>
                            <div className="flex shrink-0 gap-2">
                                <button type="button" onClick={() => openEdit(policy)} className="inline-flex h-9 items-center rounded-lg border border-[#d9e1d9] px-3 text-xs font-semibold text-[#315447] hover:bg-[#f1f4ef]">Edit</button>
                                <button type="button" onClick={() => toggleActive(policy)} className="inline-flex h-9 items-center rounded-lg border border-[#d9e1d9] px-3 text-xs font-semibold text-[#315447] hover:bg-[#f1f4ef]">{policy.isActive ? 'Deactivate' : 'Activate'}</button>
                            </div>
                        </article>
                    ))}
                    {policies.length === 0 && <p className="border border-dashed border-[#cbd6cc] bg-white px-5 py-10 text-center text-sm text-[#718078]">No late-payment policies are configured. Late fees will not be assessed until a policy is added and activated.</p>}
                </section>
            </div>
        </AuthenticatedLayout>
    );
}

function Field({ label, id, value, error, onChange }: { label: string; id: string; value: string; error?: string; onChange: (value: string) => void }) {
    return <div><label htmlFor={id} className="field-label">{label}</label><input id={id} required maxLength={120} value={value} onChange={(event) => onChange(event.target.value)} className="field-input" /><InputError message={error} className="mt-2" /></div>;
}

function NumberField({ label, id, min, max, step = '1', value, error, onChange }: { label: string; id: string; min?: string; max?: string; step?: string; value: string; error?: string; onChange: (value: string) => void }) {
    return <div><label htmlFor={id} className="field-label">{label}</label><input id={id} type="number" min={min} max={max} step={step} value={value} onChange={(event) => onChange(event.target.value)} className="field-input" /><InputError message={error} className="mt-2" /></div>;
}
