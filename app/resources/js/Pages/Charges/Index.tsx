import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import InputError from '@/Components/InputError';
import { Head, router, useForm } from '@inertiajs/react';
import { FileSliders, Plus, X } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

type Organization = { id: string; name: string; currency: string };
type Charge = {
    id: string;
    organizationId: string;
    organizationName: string;
    currency: string;
    name: string;
    category: string;
    classification: string;
    calculationMethod: string;
    amount: string;
    isRecurring: boolean;
    isActive: boolean;
    effectiveFrom: string | null;
    effectiveUntil: string | null;
    appliesTo: string[];
};
type FormData = {
    organization_id: string;
    name: string;
    category: string;
    classification: string;
    calculation_method: string;
    amount: string;
    is_recurring: boolean;
    is_active: boolean;
    effective_from: string;
    effective_until: string;
    applies_to: string[];
};
type Props = { organizations: Organization[]; charges: Charge[] };

const categories = [
    ['sale', 'Sale'],
    ['rental', 'Rental'],
    ['service_charge', 'Service charge'],
    ['security_deposit', 'Security deposit'],
    ['development_levy', 'Development levy'],
    ['deed_fee', 'Deed fee'],
    ['survey_fee', 'Survey fee'],
    ['title_processing', 'Title processing'],
    ['infrastructure', 'Infrastructure'],
    ['maintenance', 'Maintenance'],
    ['late_payment', 'Late payment'],
    ['other', 'Other'],
];
const applicabilityOptions = [
    ['sales', 'Sales'],
    ['rentals', 'Rentals'],
    ['maintenance', 'Maintenance'],
];

const emptyForm = (organizationId: string): FormData => ({
    organization_id: organizationId,
    name: '',
    category: 'other',
    classification: 'company_defined',
    calculation_method: 'fixed',
    amount: '0',
    is_recurring: false,
    is_active: true,
    effective_from: '',
    effective_until: '',
    applies_to: ['sales'],
});

export default function Index({ organizations, charges }: Props) {
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

    const openEdit = (charge: Charge) => {
        form.clearErrors();
        form.setData({
            organization_id: charge.organizationId,
            name: charge.name,
            category: charge.category,
            classification: charge.classification,
            calculation_method: charge.calculationMethod,
            amount: charge.amount,
            is_recurring: charge.isRecurring,
            is_active: charge.isActive,
            effective_from: charge.effectiveFrom ?? '',
            effective_until: charge.effectiveUntil ?? '',
            applies_to: charge.appliesTo,
        });
        setEditingId(charge.id);
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
            form.put(route('charges.update', editingId), options);
        } else {
            form.post(route('charges.store'), options);
        }
    };

    const toggleActive = (charge: Charge) => {
        router.put(route('charges.update', charge.id), {
            name: charge.name,
            category: charge.category,
            classification: charge.classification,
            calculation_method: charge.calculationMethod,
            amount: charge.amount,
            is_recurring: charge.isRecurring,
            is_active: !charge.isActive,
            effective_from: charge.effectiveFrom,
            effective_until: charge.effectiveUntil,
            applies_to: charge.appliesTo,
        }, { preserveScroll: true });
    };

    const amountLabel = form.data.calculation_method === 'percentage'
        ? 'Rate (%)'
        : form.data.calculation_method === 'per_area'
            ? `Rate per m² (${selectedOrganization?.currency ?? 'NGN'})`
            : `Amount (${selectedOrganization?.currency ?? 'NGN'})`;

    return (
        <AuthenticatedLayout>
            <Head title="Charge policies" />
            <div className="space-y-8">
                <div className="flex flex-col gap-5 border-b border-[#dfe5df] pb-6 sm:flex-row sm:items-end sm:justify-between">
                    <div><p className="eyebrow">Company configuration</p><h1 className="page-title">Charge policies</h1><p className="mt-2 max-w-2xl text-sm leading-6 text-[#64756e]">Configure company-specific charges, their classification, calculation basis, applicability, and effective dates. Existing agreements must retain their own charge snapshots.</p></div>
                    <button type="button" onClick={openCreate} disabled={organizations.length === 0} className="inline-flex h-11 items-center justify-center gap-2 rounded-lg bg-[#17352d] px-4 text-sm font-semibold text-white hover:bg-[#244b40] disabled:opacity-50"><Plus size={17} /> New charge policy</button>
                </div>

                <div className="grid gap-4 border-b border-[#dfe5df] pb-6 sm:grid-cols-3">
                    <Summary label="Configured policies" value={charges.length} />
                    <Summary label="Currently active" value={charges.filter((charge) => charge.isActive).length} />
                    <Summary label="Organizations" value={new Set(charges.map((charge) => charge.organizationId)).size} />
                </div>

                <p className="border-l-2 border-[#8ba344] bg-white px-5 py-4 text-sm leading-6 text-[#53675e]">A charge is not a statutory fee merely because of its name. Mark it statutory only after confirming the applicable legal basis and jurisdiction with qualified local advisers.</p>

                {isOpen && <form onSubmit={submit} className="space-y-5 border border-[#dfe5df] bg-white p-5 sm:p-7">
                    <div className="flex items-start justify-between gap-4"><div><p className="eyebrow">{editingId ? 'Edit policy' : 'New policy'}</p><h2 className="mt-1 font-display text-xl text-[#18342c]">Charge definition</h2></div><button type="button" onClick={closeForm} aria-label="Close form" className="grid size-9 place-items-center rounded-lg text-[#6d7d76] hover:bg-[#f1f4ef]"><X size={18} /></button></div>
                    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        <div><label htmlFor="charge-organization" className="field-label">Organization</label><select id="charge-organization" disabled={Boolean(editingId)} value={form.data.organization_id} onChange={(event) => form.setData('organization_id', event.target.value)} className="field-input">{organizations.map((organization) => <option key={organization.id} value={organization.id}>{organization.name}</option>)}</select><InputError message={form.errors.organization_id} className="mt-2" /></div>
                        <Field label="Charge name" id="charge-name" value={form.data.name} error={form.errors.name} onChange={(value) => form.setData('name', value)} />
                        <SelectField label="Category" id="charge-category" value={form.data.category} error={form.errors.category} onChange={(value) => form.setData('category', value)} options={categories} />
                        <SelectField label="Classification" id="charge-classification" value={form.data.classification} error={form.errors.classification} onChange={(value) => form.setData('classification', value)} options={[[ 'statutory', 'Statutory' ], [ 'contractual', 'Contractual' ], [ 'company_defined', 'Company-defined' ]]} />
                        <SelectField label="Calculation method" id="charge-method" value={form.data.calculation_method} error={form.errors.calculation_method} onChange={(value) => form.setData('calculation_method', value)} options={[[ 'fixed', 'Fixed amount' ], [ 'percentage', 'Percentage of base amount' ], [ 'per_area', 'Per square metre' ], [ 'manual_quote', 'Manually quoted' ]]} />
                        <div><label htmlFor="charge-amount" className="field-label">{amountLabel}</label><input id="charge-amount" type="number" min="0" step="0.01" required value={form.data.amount} onChange={(event) => form.setData('amount', event.target.value)} className="field-input" /><InputError message={form.errors.amount} className="mt-2" /><p className="mt-1 text-xs text-[#87938d]">{form.data.calculation_method === 'manual_quote' ? 'The amount is entered and confirmed when a transaction is prepared.' : 'This is a rule value, not a quoted customer total.'}</p></div>
                        <Field label="Effective from" id="charge-effective-from" type="date" value={form.data.effective_from} error={form.errors.effective_from} onChange={(value) => form.setData('effective_from', value)} />
                        <Field label="Effective until" id="charge-effective-until" type="date" value={form.data.effective_until} error={form.errors.effective_until} onChange={(value) => form.setData('effective_until', value)} />
                        {editingId && <label className="flex h-11 items-center gap-3 self-end rounded-lg border border-[#cfd8ce] px-3 text-sm text-[#53675e]"><input type="checkbox" checked={form.data.is_active} onChange={(event) => form.setData('is_active', event.target.checked)} className="size-4 rounded border-[#b8c5bb] text-[#315447] focus:ring-[#8ba344]" /> Policy is active</label>}
                    </div>
                    <fieldset className="space-y-2 border-t border-[#e7ece6] pt-5">
                        <legend className="field-label">Applies to</legend>
                        <div className="flex flex-wrap gap-4">{applicabilityOptions.map(([value, label]) => <label key={value} className="flex items-center gap-2 text-sm text-[#53675e]"><input type="checkbox" checked={form.data.applies_to.includes(value)} onChange={(event) => form.setData('applies_to', event.target.checked ? [...form.data.applies_to, value] : form.data.applies_to.filter((item) => item !== value))} className="size-4 rounded border-[#b8c5bb] text-[#315447] focus:ring-[#8ba344]" />{label}</label>)}</div>
                        <InputError message={form.errors.applies_to} className="mt-2" />
                        {form.errors['applies_to.0'] && <InputError message={form.errors['applies_to.0']} className="mt-2" />}
                    </fieldset>
                    <label className="flex items-center gap-3 text-sm text-[#53675e]"><input type="checkbox" checked={form.data.is_recurring} onChange={(event) => form.setData('is_recurring', event.target.checked)} className="size-4 rounded border-[#b8c5bb] text-[#315447] focus:ring-[#8ba344]" /> Recurring charge</label>
                    <div className="flex justify-end"><button type="submit" disabled={form.processing || organizations.length === 0} className="h-10 rounded-lg bg-[#17352d] px-4 text-sm font-semibold text-white hover:bg-[#244b40] disabled:opacity-50">{form.processing ? 'Saving…' : editingId ? 'Save policy' : 'Create policy'}</button></div>
                </form>}

                <div className="overflow-x-auto border border-[#dfe5df] bg-white">
                    <table className="w-full min-w-[1050px] text-left">
                        <thead className="bg-[#f8faf6] text-[11px] font-semibold uppercase tracking-[0.12em] text-[#77857f]"><tr><th className="px-5 py-3">Policy</th><th className="px-5 py-3">Organization</th><th className="px-5 py-3">Method / value</th><th className="px-5 py-3">Classification</th><th className="px-5 py-3">Applicability</th><th className="px-5 py-3">Effective dates</th><th className="px-5 py-3">Status</th><th className="px-5 py-3" /></tr></thead>
                        <tbody className="divide-y divide-[#edf0ec]">
                            {charges.map((charge) => <tr key={charge.id}>
                                <td className="px-5 py-4"><p className="text-sm font-semibold text-[#233c33]">{charge.name}</p><p className="mt-1 text-xs capitalize text-[#7b8983]">{charge.category.replaceAll('_', ' ')}{charge.isRecurring ? ' · recurring' : ''}</p></td>
                                <td className="px-5 py-4 text-sm text-[#4c6259]">{charge.organizationName}</td>
                                <td className="px-5 py-4"><p className="text-sm capitalize text-[#29483e]">{charge.calculationMethod.replaceAll('_', ' ')}</p><p className="mt-1 text-xs text-[#7b8983]">{formatAmount(charge)}</p></td>
                                <td className="px-5 py-4 text-sm capitalize text-[#4c6259]">{charge.classification.replaceAll('_', ' ')}</td>
                                <td className="px-5 py-4 text-sm capitalize text-[#4c6259]">{charge.appliesTo.join(', ')}</td>
                                <td className="px-5 py-4 text-sm text-[#4c6259]">{charge.effectiveFrom ?? 'Any date'} – {charge.effectiveUntil ?? 'No end date'}</td>
                                <td className="px-5 py-4"><span className={`rounded-full px-2.5 py-1 text-xs font-medium ${charge.isActive ? 'bg-[#edf4eb] text-[#315447]' : 'bg-[#f1ecea] text-[#76554d]'}`}>{charge.isActive ? 'Active' : 'Inactive'}</span></td>
                                <td className="px-5 py-4 text-right"><div className="flex justify-end gap-2"><button type="button" onClick={() => openEdit(charge)} className="h-9 rounded-lg border border-[#d9e1d9] px-3 text-xs font-semibold text-[#53675e]">Edit</button><button type="button" onClick={() => toggleActive(charge)} className="h-9 rounded-lg bg-[#17352d] px-3 text-xs font-semibold text-white">{charge.isActive ? 'Deactivate' : 'Activate'}</button></div></td>
                            </tr>)}
                            {charges.length === 0 && <tr><td colSpan={8} className="px-5 py-12 text-center"><FileSliders size={24} className="mx-auto text-[#8a9791]" /><p className="mt-3 text-sm text-[#718078]">No charge policies are configured yet.</p></td></tr>}
                        </tbody>
                    </table>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}

function Summary({ label, value }: { label: string; value: number }) {
    return <div className="border-t-2 border-[#d9e1d9] bg-white px-4 py-4 sm:px-5"><p className="text-xs text-[#73827d]">{label}</p><p className="mt-1 text-lg font-semibold text-[#233c33]">{value}</p></div>;
}

function Field({ label, id, type = 'text', value, error, onChange }: { label: string; id: string; type?: string; value: string; error?: string; onChange: (value: string) => void }) {
    return <div><label htmlFor={id} className="field-label">{label}</label><input id={id} type={type} value={value} onChange={(event) => onChange(event.target.value)} className="field-input" /><InputError message={error} className="mt-2" /></div>;
}

function SelectField({ label, id, value, error, options, onChange }: { label: string; id: string; value: string; error?: string; options: string[][]; onChange: (value: string) => void }) {
    return <div><label htmlFor={id} className="field-label">{label}</label><select id={id} value={value} onChange={(event) => onChange(event.target.value)} className="field-input">{options.map(([option, title]) => <option key={option} value={option}>{title}</option>)}</select><InputError message={error} className="mt-2" /></div>;
}

function formatAmount(charge: Charge): string {
    if (charge.calculationMethod === 'percentage') return `${Number(charge.amount).toLocaleString()}%`;
    if (charge.calculationMethod === 'per_area') return `${new Intl.NumberFormat(undefined, { style: 'currency', currency: charge.currency, maximumFractionDigits: 2 }).format(Number(charge.amount))} / m²`;
    if (charge.calculationMethod === 'manual_quote') return 'Quoted per transaction';

    return new Intl.NumberFormat(undefined, { style: 'currency', currency: charge.currency, maximumFractionDigits: 2 }).format(Number(charge.amount));
}
