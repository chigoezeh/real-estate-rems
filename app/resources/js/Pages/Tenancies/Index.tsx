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
    additionalChargesTotal: string;
    additionalCharges: Array<{ name: string; total_amount: string }>;
    currency: string;
    canManage: boolean;
};
type FormData = {
    client_id: string;
    property_unit_id: string;
    starts_on: string;
    ends_on: string;
    rent_frequency: string;
    rent_amount: string;
    security_deposit: string;
    service_charge: string;
    charge_quotes: Record<string, string>;
};
type Props = { tenancies: Tenancy[]; clients: Client[]; properties: Property[]; canManage: boolean };

const emptyForm = (): FormData => ({
    client_id: '',
    property_unit_id: '',
    starts_on: '',
    ends_on: '',
    rent_frequency: 'monthly',
    rent_amount: '',
    security_deposit: '0',
    service_charge: '0',
    charge_quotes: {},
});

const formatMoney = (amount: string, currency: string) => new Intl.NumberFormat(undefined, {
    style: 'currency',
    currency,
    maximumFractionDigits: 2,
}).format(Number(amount));

export default function Index({ tenancies, clients, properties, canManage }: Props) {
    const [isAdding, setIsAdding] = useState(false);
    const form = useForm<FormData>(emptyForm());
    const selectedProperty = properties.find((property) => property.id === form.data.property_unit_id);
    const activeChargePolicies = selectedProperty?.chargePolicies ?? [];
    const availableClients = useMemo(
        () => clients.filter((client) => client.organizationId === selectedProperty?.organizationId),
        [clients, selectedProperty],
    );
    const draftCount = tenancies.filter((tenancy) => tenancy.status === 'draft').length;
    const activeCount = tenancies.filter((tenancy) => tenancy.status === 'active').length;

    const openForm = () => {
        form.clearErrors();
        form.setData({
            ...emptyForm(),
            property_unit_id: properties[0]?.id ?? '',
            client_id: clients.find((client) => client.organizationId === properties[0]?.organizationId)?.id ?? '',
        });
        setIsAdding(true);
    };

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        form.post(route('tenancies.store'), { onSuccess: () => setIsAdding(false) });
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
                        <div className="flex items-start justify-between gap-4"><div><p className="eyebrow">New agreement</p><h2 className="mt-1 font-display text-xl text-[#18342c]">Tenancy details</h2></div><button type="button" onClick={() => setIsAdding(false)} aria-label="Close form" className="grid size-9 place-items-center rounded-lg text-[#6d7d76] hover:bg-[#f1f4ef]"><X size={18} /></button></div>
                        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                            <Field label="Property" id="tenancy-property">
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
                            </Field>
                            <Field label="Verified tenant" id="tenancy-client">
                                <select id="tenancy-client" required value={form.data.client_id} onChange={(event) => form.setData('client_id', event.target.value)} className="field-input">
                                    <option value="">Select a tenant</option>
                                    {availableClients.map((client) => <option key={client.id} value={client.id}>{client.name} · {client.phone}</option>)}
                                </select>
                                <InputError message={form.errors.client_id} className="mt-2" />
                            </Field>
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
                        <p className="text-xs leading-5 text-[#76857e]">The agreement is saved as a draft. Activating it checks for overlapping tenancies and creates the scheduled rent charges.</p>
                        <div className="flex justify-end"><button type="submit" disabled={form.processing || !selectedProperty || availableClients.length === 0} className="inline-flex h-10 items-center gap-2 rounded-lg bg-[#17352d] px-4 text-sm font-semibold text-white hover:bg-[#244b40] disabled:opacity-50">{form.processing ? 'Saving…' : 'Save draft'}</button></div>
                    </form>
                )}

                <div className="overflow-x-auto border border-[#dfe5df] bg-white">
                    <table className="w-full min-w-[900px] text-left">
                        <thead className="bg-[#f8faf6] text-[11px] font-semibold uppercase tracking-[0.12em] text-[#77857f]"><tr><th className="px-5 py-3">Tenancy</th><th className="px-5 py-3">Tenant</th><th className="px-5 py-3">Property</th><th className="px-5 py-3">Term</th><th className="px-5 py-3 text-right">Rent</th><th className="px-5 py-3">Status</th><th className="px-5 py-3" /></tr></thead>
                        <tbody className="divide-y divide-[#edf0ec]">
                            {tenancies.map((tenancy) => <tr key={tenancy.id}>
                                <td className="px-5 py-4"><p className="text-sm font-semibold text-[#233c33]">{tenancy.number}</p><p className="mt-1 text-xs capitalize text-[#7b8983]">{tenancy.frequency.replaceAll('_', ' ')}</p></td>
                                <td className="px-5 py-4"><p className="text-sm font-medium text-[#29483e]">{tenancy.tenantName}</p><p className="mt-1 text-xs text-[#7b8983]">{tenancy.tenantPhone}</p></td>
                                <td className="px-5 py-4 text-sm text-[#4c6259]">{tenancy.property}</td>
                                <td className="px-5 py-4 text-sm text-[#4c6259]">{tenancy.startsOn} – {tenancy.endsOn ?? 'Open ended'}</td>
                                <td className="px-5 py-4 text-right text-sm font-medium text-[#29483e]">{formatMoney(tenancy.rentAmount, tenancy.currency)}{tenancy.additionalCharges.length > 0 && <p className="mt-1 text-xs font-normal text-[#7b8983]" title={tenancy.additionalCharges.map((charge) => charge.name).join(', ')}>Charges {formatMoney(tenancy.additionalChargesTotal, tenancy.currency)} · {tenancy.additionalCharges.map((charge) => charge.name).join(', ')}</p>}</td>
                                <td className="px-5 py-4"><span className={`rounded-full px-2.5 py-1 text-xs font-medium ${tenancy.status === 'active' ? 'bg-[#edf4eb] text-[#315447]' : 'bg-[#f8f2dc] text-[#756128]'}`}>{tenancy.status.replaceAll('_', ' ')}</span></td>
                                <td className="px-5 py-4 text-right">{canManage && tenancy.canManage && tenancy.status === 'draft' && <button type="button" onClick={() => router.patch(route('tenancies.activate', tenancy.id), {}, { preserveScroll: true })} className="inline-flex h-9 items-center gap-1.5 rounded-lg bg-[#17352d] px-3 text-xs font-semibold text-white hover:bg-[#244b40]"><Check size={14} /> Activate</button>}</td>
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
