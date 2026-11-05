import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import InputError from '@/Components/InputError';
import { Head, Link, useForm } from '@inertiajs/react';
import { BadgeCheck, FileCheck2, FileText, Plus, Search, ShieldAlert, UserRound, Users, X } from 'lucide-react';
import { ChangeEvent, FormEventHandler, useMemo, useState } from 'react';

type Organization = { id: string; name: string };
type Client = {
    id: string;
    organizationId: string;
    organizationName: string;
    fullName: string;
    email: string | null;
    phone: string;
    identityType: 'nin' | 'drivers_license' | 'international_passport';
    kycStatus: 'pending' | 'verified' | 'rejected';
    hasIdentityDocument: boolean;
    createdAt: string | null;
};
type ClientForm = {
    organization_id: string;
    full_name: string;
    email: string;
    phone: string;
    address: string;
    next_of_kin_name: string;
    next_of_kin_phone: string;
    identity_type: Client['identityType'];
    identity_number: string;
    identity_document: File | null;
};
type ReviewForm = { status: 'verified' | 'rejected'; rejection_reason: string };
type Props = { clients: Client[]; organizations: Organization[]; canManage: boolean; canReviewKyc: boolean };

const emptyForm = (organizationId: string): ClientForm => ({
    organization_id: organizationId,
    full_name: '',
    email: '',
    phone: '',
    address: '',
    next_of_kin_name: '',
    next_of_kin_phone: '',
    identity_type: 'nin',
    identity_number: '',
    identity_document: null,
});

const identityLabels: Record<Client['identityType'], string> = {
    nin: 'National Identification Number',
    drivers_license: "Driver's licence",
    international_passport: 'International passport',
};

export default function Index({ clients, organizations, canManage, canReviewKyc }: Props) {
    const [isOpen, setIsOpen] = useState(false);
    const [reviewingClient, setReviewingClient] = useState<Client | null>(null);
    const [query, setQuery] = useState('');
    const firstOrganization = organizations[0];
    const { data, setData, post, processing, errors, reset, clearErrors } = useForm<ClientForm>(emptyForm(firstOrganization?.id ?? ''));
    const { data: reviewData, setData: setReviewData, patch: patchReview, processing: reviewProcessing, errors: reviewErrors, reset: resetReview, clearErrors: clearReviewErrors } = useForm<ReviewForm>({ status: 'verified', rejection_reason: '' });
    const visibleClients = useMemo(() => {
        const searchTerm = query.trim().toLocaleLowerCase();
        if (!searchTerm) return clients;

        return clients.filter((client) => [client.fullName, client.email ?? '', client.phone, client.organizationName]
            .some((value) => value.toLocaleLowerCase().includes(searchTerm)));
    }, [clients, query]);
    const pendingCount = clients.filter((client) => client.kycStatus === 'pending').length;
    const verifiedCount = clients.filter((client) => client.kycStatus === 'verified').length;

    const openForm = () => {
        clearErrors();
        setData(emptyForm(firstOrganization?.id ?? ''));
        setIsOpen(true);
    };

    const closeForm = () => {
        setIsOpen(false);
        reset();
        clearErrors();
    };

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        post(route('clients.store'), { forceFormData: true, onSuccess: closeForm });
    };

    const handleFile = (event: ChangeEvent<HTMLInputElement>) => {
        setData('identity_document', event.target.files?.[0] ?? null);
    };

    const openReview = (client: Client) => {
        clearReviewErrors();
        setReviewData({ status: 'verified', rejection_reason: '' });
        setReviewingClient(client);
    };

    const closeReview = () => {
        setReviewingClient(null);
        resetReview();
        clearReviewErrors();
    };

    const submitReview: FormEventHandler = (event) => {
        event.preventDefault();
        if (!reviewingClient) return;
        patchReview(route('clients.kyc.review', reviewingClient.id), { onSuccess: closeReview });
    };

    return (
        <AuthenticatedLayout>
            <Head title="Clients" />
            <div className="space-y-8">
                <div className="flex flex-col gap-5 border-b border-[#dfe5df] pb-6 sm:flex-row sm:items-end sm:justify-between">
                    <div>
                        <p className="eyebrow">Customer relationship management</p>
                        <h1 className="page-title">Clients</h1>
                        <p className="mt-2 max-w-2xl text-sm leading-6 text-[#64756e]">Manage buyer details and review identity verification across your organizations.</p>
                    </div>
                    {canManage && <button type="button" onClick={openForm} disabled={organizations.length === 0} className="inline-flex h-11 items-center justify-center gap-2 rounded-lg bg-[#17352d] px-4 text-sm font-semibold text-white transition hover:bg-[#244b40] disabled:cursor-not-allowed disabled:opacity-50"><Plus size={17} /> Add client</button>}
                </div>

                {canManage && organizations.length === 0 && <div className="border-l-2 border-[#8ba344] bg-white px-5 py-4 text-sm text-[#53675e]">You need client management access to an organization before adding a client.</div>}

                <div className="flex flex-wrap items-center gap-x-8 gap-y-3 border-b border-[#dfe5df] pb-5 text-sm">
                    <div className="flex items-center gap-2 text-[#73827d]"><Users size={16} /> Clients <strong className="text-[#17352d]">{clients.length}</strong></div>
                    <div className="flex items-center gap-2 text-[#73827d]"><ShieldAlert size={16} /> Pending review <strong className="text-[#9a6b27]">{pendingCount}</strong></div>
                    <div className="flex items-center gap-2 text-[#73827d]"><BadgeCheck size={16} /> Verified <strong className="text-[#315447]">{verifiedCount}</strong></div>
                    <label className="relative ml-auto w-full max-w-sm">
                        <Search size={16} className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-[#89958e]" />
                        <input value={query} onChange={(event) => setQuery(event.target.value)} placeholder="Search clients" aria-label="Search clients" className="field-input pl-9" />
                    </label>
                </div>

                <div className="overflow-x-auto rounded-lg border border-[#dfe5df] bg-white">
                    <table className="w-full min-w-[780px] text-left">
                        <thead className="bg-[#f8faf6] text-[11px] font-semibold uppercase tracking-[0.12em] text-[#77857f]">
                            <tr><th className="px-5 py-3 sm:px-7">Client</th><th className="px-5 py-3">Organization</th><th className="px-5 py-3">Identity</th><th className="px-5 py-3">KYC status</th><th className="px-5 py-3 text-right sm:px-7">Document</th></tr>
                        </thead>
                        <tbody className="divide-y divide-[#edf0ec]">
                            {visibleClients.map((client) => (
                                <tr key={client.id} className="hover:bg-[#fbfcfa]">
                                    <td className="px-5 py-4 sm:px-7">
                                        <div className="flex min-w-0 items-center gap-3">
                                            <span className="grid size-9 shrink-0 place-items-center rounded-full bg-[#edf4eb] text-[#315447]"><UserRound size={17} /></span>
                                            <div className="min-w-0"><p className="truncate text-sm font-semibold text-[#233c33]">{client.fullName}</p><p className="mt-1 truncate text-xs text-[#7b8983]">{client.email ?? client.phone}</p></div>
                                        </div>
                                    </td>
                                    <td className="px-5 py-4 text-sm text-[#4c6259]">{client.organizationName}</td>
                                    <td className="px-5 py-4 text-sm text-[#4c6259]">{identityLabels[client.identityType]}</td>
                                    <td className="px-5 py-4"><span className={`inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium ${client.kycStatus === 'verified' ? 'bg-[#edf4eb] text-[#315447]' : client.kycStatus === 'rejected' ? 'bg-[#f7eeeb] text-[#8b554a]' : 'bg-[#f7f1e4] text-[#8a672c]'}`}><span className="size-1.5 rounded-full bg-current" />{client.kycStatus === 'verified' ? 'Verified' : client.kycStatus === 'rejected' ? 'Needs correction' : 'Pending review'}</span></td>
                                    <td className="px-5 py-4 text-right sm:px-7">
                                        {canReviewKyc && client.hasIdentityDocument ? <div className="inline-flex items-center gap-2"><Link href={route('clients.identity-document', client.id)} className="grid size-9 place-items-center rounded-lg border border-[#d9e1d9] text-[#315447] hover:bg-[#f8faf6]" aria-label={`Download ${client.fullName}'s identity document`} title="Download identity document"><FileCheck2 size={15} /></Link><button type="button" onClick={() => openReview(client)} className="h-9 rounded-lg bg-[#edf4eb] px-3 text-xs font-semibold text-[#315447] hover:bg-[#e2ecdf]">Review KYC</button></div> : <span className="inline-flex items-center gap-1.5 text-xs text-[#89958e]"><FileText size={15} /> {client.hasIdentityDocument ? 'Restricted' : 'No file'}</span>}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                    {visibleClients.length === 0 && <div className="border-t border-[#edf0ec] px-5 py-12 text-center"><p className="text-sm font-medium text-[#29483e]">{clients.length === 0 ? 'No clients have been added yet.' : 'No clients match your search.'}</p></div>}
                </div>
            </div>

            {isOpen && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-[#10251f]/45 px-4 py-6" role="presentation" onMouseDown={(event) => event.target === event.currentTarget && closeForm()}>
                    <section role="dialog" aria-modal="true" aria-labelledby="client-dialog-title" className="max-h-full w-full max-w-2xl overflow-y-auto rounded-xl bg-white p-6 shadow-2xl sm:p-8">
                        <div className="flex items-start justify-between gap-5">
                            <div><p className="eyebrow">Client onboarding</p><h2 id="client-dialog-title" className="mt-2 font-display text-2xl text-[#18342c]">Add client</h2></div>
                            <button type="button" onClick={closeForm} aria-label="Close dialog" className="grid size-9 place-items-center rounded-lg text-[#6d7d76] hover:bg-[#f1f4ef] hover:text-[#17352d]"><X size={18} /></button>
                        </div>
                        <form onSubmit={submit} className="mt-7 space-y-6">
                            <div className="grid gap-4 sm:grid-cols-2">
                                <div className="sm:col-span-2"><label htmlFor="organization_id" className="field-label">Organization</label><select id="organization_id" value={data.organization_id} onChange={(event) => setData('organization_id', event.target.value)} className="field-input">{organizations.map((organization) => <option key={organization.id} value={organization.id}>{organization.name}</option>)}</select><InputError message={errors.organization_id} className="mt-2" /></div>
                                <div className="sm:col-span-2"><label htmlFor="full_name" className="field-label">Full name</label><input id="full_name" autoFocus value={data.full_name} onChange={(event) => setData('full_name', event.target.value)} className="field-input" /><InputError message={errors.full_name} className="mt-2" /></div>
                                <div><label htmlFor="email" className="field-label">Email address</label><input id="email" type="email" autoComplete="email" value={data.email} onChange={(event) => setData('email', event.target.value)} className="field-input" /><InputError message={errors.email} className="mt-2" /></div>
                                <div><label htmlFor="phone" className="field-label">Phone number</label><input id="phone" type="tel" autoComplete="tel" value={data.phone} onChange={(event) => setData('phone', event.target.value)} className="field-input" /><InputError message={errors.phone} className="mt-2" /></div>
                                <div className="sm:col-span-2"><label htmlFor="address" className="field-label">Residential address</label><textarea id="address" value={data.address} onChange={(event) => setData('address', event.target.value)} rows={2} className="field-input min-h-20 resize-y" /><InputError message={errors.address} className="mt-2" /></div>
                            </div>
                            <fieldset className="space-y-4 border-t border-[#e7ece6] pt-5">
                                <legend className="field-label">Next of kin</legend>
                                <div className="grid gap-4 sm:grid-cols-2">
                                    <div><label htmlFor="next_of_kin_name" className="sr-only">Next of kin name</label><input id="next_of_kin_name" placeholder="Full name" value={data.next_of_kin_name} onChange={(event) => setData('next_of_kin_name', event.target.value)} className="field-input" /><InputError message={errors.next_of_kin_name} className="mt-2" /></div>
                                    <div><label htmlFor="next_of_kin_phone" className="sr-only">Next of kin phone</label><input id="next_of_kin_phone" type="tel" placeholder="Phone number" value={data.next_of_kin_phone} onChange={(event) => setData('next_of_kin_phone', event.target.value)} className="field-input" /><InputError message={errors.next_of_kin_phone} className="mt-2" /></div>
                                </div>
                            </fieldset>
                            <fieldset className="space-y-4 border-t border-[#e7ece6] pt-5">
                                <legend className="field-label">Identity verification</legend>
                                <div className="grid gap-4 sm:grid-cols-2">
                                    <div><label htmlFor="identity_type" className="field-label">Document type</label><select id="identity_type" value={data.identity_type} onChange={(event) => setData('identity_type', event.target.value as Client['identityType'])} className="field-input">{Object.entries(identityLabels).map(([value, label]) => <option key={value} value={value}>{label}</option>)}</select><InputError message={errors.identity_type} className="mt-2" /></div>
                                    <div><label htmlFor="identity_number" className="field-label">Document number</label><input id="identity_number" autoComplete="off" value={data.identity_number} onChange={(event) => setData('identity_number', event.target.value)} className="field-input" /><InputError message={errors.identity_number} className="mt-2" /></div>
                                    <div className="sm:col-span-2"><label htmlFor="identity_document" className="field-label">Identity document</label><input id="identity_document" type="file" accept=".pdf,.jpg,.jpeg,.png" onChange={handleFile} className="field-input file:mr-3 file:rounded-md file:border-0 file:bg-[#edf4eb] file:px-3 file:py-1.5 file:text-xs file:font-semibold file:text-[#315447]" /><p className="mt-1.5 text-xs text-[#89958e]">PDF, JPG, or PNG, up to 5 MB. Stored privately.</p><InputError message={errors.identity_document} className="mt-2" /></div>
                                </div>
                            </fieldset>
                            <div className="flex justify-end gap-3 border-t border-[#e7ece6] pt-5">
                                <button type="button" onClick={closeForm} className="h-10 rounded-lg px-4 text-sm font-medium text-[#5f7169] hover:bg-[#f3f5ef]">Cancel</button>
                                <button type="submit" disabled={processing || !data.organization_id} className="h-10 rounded-lg bg-[#17352d] px-4 text-sm font-semibold text-white hover:bg-[#244b40] disabled:opacity-60">{processing ? 'Saving…' : 'Create client'}</button>
                            </div>
                        </form>
                    </section>
                </div>
            )}

            {reviewingClient && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-[#10251f]/45 px-4 py-6" role="presentation" onMouseDown={(event) => event.target === event.currentTarget && closeReview()}>
                    <section role="dialog" aria-modal="true" aria-labelledby="kyc-dialog-title" className="w-full max-w-lg rounded-xl bg-white p-6 shadow-2xl sm:p-8">
                        <div className="flex items-start justify-between gap-5">
                            <div><p className="eyebrow">Identity verification</p><h2 id="kyc-dialog-title" className="mt-2 font-display text-2xl text-[#18342c]">Review KYC</h2><p className="mt-2 text-sm text-[#64756e]">{reviewingClient.fullName} · {identityLabels[reviewingClient.identityType]}</p></div>
                            <button type="button" onClick={closeReview} aria-label="Close dialog" className="grid size-9 place-items-center rounded-lg text-[#6d7d76] hover:bg-[#f1f4ef] hover:text-[#17352d]"><X size={18} /></button>
                        </div>
                        <form onSubmit={submitReview} className="mt-7 space-y-5">
                            <fieldset>
                                <legend className="field-label">Decision</legend>
                                <div className="mt-2 grid grid-cols-2 gap-2">
                                    {(['verified', 'rejected'] as const).map((status) => <button key={status} type="button" onClick={() => setReviewData('status', status)} aria-pressed={reviewData.status === status} className={`h-10 rounded-lg border text-sm font-medium transition ${reviewData.status === status ? status === 'verified' ? 'border-[#6f8e63] bg-[#edf4eb] text-[#315447]' : 'border-[#b78072] bg-[#f7eeeb] text-[#8b554a]' : 'border-[#dfe5df] text-[#64756e] hover:bg-[#f8faf6]'}`}>{status === 'verified' ? 'Approve document' : 'Return for correction'}</button>)}
                                </div>
                                <InputError message={reviewErrors.status} className="mt-2" />
                            </fieldset>
                            {reviewData.status === 'rejected' && <div><label htmlFor="rejection_reason" className="field-label">Reason for return</label><textarea id="rejection_reason" autoFocus value={reviewData.rejection_reason} onChange={(event) => setReviewData('rejection_reason', event.target.value)} rows={3} className="field-input min-h-24 resize-y" /><InputError message={reviewErrors.rejection_reason} className="mt-2" /></div>}
                            <div className="flex justify-end gap-3 border-t border-[#e7ece6] pt-5">
                                <button type="button" onClick={closeReview} className="h-10 rounded-lg px-4 text-sm font-medium text-[#5f7169] hover:bg-[#f3f5ef]">Cancel</button>
                                <button type="submit" disabled={reviewProcessing || (reviewData.status === 'rejected' && !reviewData.rejection_reason.trim())} className="h-10 rounded-lg bg-[#17352d] px-4 text-sm font-semibold text-white hover:bg-[#244b40] disabled:opacity-60">{reviewProcessing ? 'Saving…' : 'Save review'}</button>
                            </div>
                        </form>
                    </section>
                </div>
            )}
        </AuthenticatedLayout>
    );
}