import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import InputError from '@/Components/InputError';
import { Head, useForm } from '@inertiajs/react';
import { Building2, ChevronDown, Pencil, Plus, X } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

type Organization = {
    id: string;
    name: string;
    kind: 'company' | 'subsidiary' | 'branch';
    country: string;
    currency: string;
    registration_number: string | null;
    email: string | null;
    phone: string | null;
    address: string | null;
    timezone: string;
    locale: string;
    branding: { display_name?: string | null; primary_color?: string; accent_color?: string } | null;
    parent: { id: string; name: string } | null;
    members_count: number;
    can_edit: boolean;
};

type Props = {
    organizations: Organization[];
    canCreate: boolean;
};

const kindLabels: Record<Organization['kind'], string> = {
    company: 'Company',
    subsidiary: 'Subsidiary',
    branch: 'Branch',
};

export default function Index({ organizations, canCreate }: Props) {
    const [isAdding, setIsAdding] = useState(false);
    const [editingOrganization, setEditingOrganization] = useState<Organization | null>(null);
    const { data, setData, post, processing, errors, reset } = useForm({
        name: '',
        kind: 'branch' as 'subsidiary' | 'branch',
        parent_id: organizations[0]?.id ?? '',
    });
    const { data: editData, setData: setEditData, put, processing: isUpdating, errors: editErrors, reset: resetEdit, clearErrors } = useForm({
        name: '',
        registration_number: '',
        email: '',
        phone: '',
        address: '',
        country: 'NG',
        currency: 'NGN',
        timezone: 'Africa/Lagos',
        locale: 'en',
        branding: {
            display_name: '',
            primary_color: '#17352d',
            accent_color: '#d6f36a',
        },
    });

    const openEdit = (organization: Organization) => {
        clearErrors();
        setEditingOrganization(organization);
        setEditData({
            name: organization.name,
            registration_number: organization.registration_number ?? '',
            email: organization.email ?? '',
            phone: organization.phone ?? '',
            address: organization.address ?? '',
            country: organization.country,
            currency: organization.currency,
            timezone: organization.timezone,
            locale: organization.locale,
            branding: {
                display_name: organization.branding?.display_name ?? '',
                primary_color: organization.branding?.primary_color ?? '#17352d',
                accent_color: organization.branding?.accent_color ?? '#d6f36a',
            },
        });
    };

    const closeEdit = () => {
        setEditingOrganization(null);
        resetEdit();
    };

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        post(route('organizations.store'), {
            onSuccess: () => {
                setIsAdding(false);
                reset('name');
            },
        });
    };

    const submitEdit: FormEventHandler = (event) => {
        event.preventDefault();
        if (!editingOrganization) return;
        put(route('organizations.update', editingOrganization.id), { onSuccess: closeEdit });
    };

    return (
        <AuthenticatedLayout>
            <Head title="Companies" />
            <div className="space-y-8">
                <div className="flex flex-col gap-5 border-b border-[#dfe5df] pb-6 sm:flex-row sm:items-end sm:justify-between">
                    <div>
                        <p className="eyebrow">Administration</p>
                        <h1 className="page-title">Companies & branches</h1>
                        <p className="mt-2 max-w-2xl text-sm leading-6 text-[#64756e]">Manage the organizations in this installation and keep access scoped to the right team.</p>
                    </div>
                    {canCreate && (
                        <button type="button" onClick={() => setIsAdding(true)} className="inline-flex h-11 items-center justify-center gap-2 rounded-lg bg-[#17352d] px-4 text-sm font-semibold text-white transition hover:bg-[#244b40] focus:outline-none focus:ring-2 focus:ring-[#8ba344] focus:ring-offset-2">
                            <Plus size={17} /> Add organization
                        </button>
                    )}
                </div>

                <div className="flex flex-wrap items-center gap-x-8 gap-y-3 text-sm">
                    <div><span className="text-[#73827d]">Organizations</span><strong className="ml-2 text-[#17352d]">{organizations.length}</strong></div>
                    <div><span className="text-[#73827d]">Company tree</span><span className="ml-2 inline-flex items-center gap-1 font-medium text-[#315447]"><Building2 size={15} /> Active</span></div>
                </div>

                <div className="overflow-x-auto rounded-xl border border-[#dfe5df] bg-white">
                    <div className="min-w-[700px]">
                        <div className="grid grid-cols-[minmax(180px,1.5fr)_minmax(110px,0.7fr)_minmax(120px,0.8fr)_80px_44px] gap-4 border-b border-[#e7ece6] bg-[#f8faf6] px-5 py-3 text-[11px] font-semibold uppercase tracking-[0.12em] text-[#77857f] sm:px-7">
                            <span>Organization</span><span>Type</span><span>Region & currency</span><span className="text-right">Members</span><span />
                        </div>
                        <div className="divide-y divide-[#edf0ec]">
                            {organizations.map((organization) => (
                                <div key={organization.id} className="grid grid-cols-[minmax(180px,1.5fr)_minmax(110px,0.7fr)_minmax(120px,0.8fr)_80px_44px] items-center gap-4 px-5 py-4 sm:px-7">
                                <div className="flex min-w-0 items-center gap-3">
                                    <span className="grid size-10 shrink-0 place-items-center rounded-lg text-[#315447]" style={{ backgroundColor: organization.branding?.accent_color ?? '#edf4eb' }}><Building2 size={18} /></span>
                                    <div className="min-w-0">
                                        <p className="truncate text-sm font-semibold text-[#233c33]">{organization.branding?.display_name || organization.name}</p>
                                        <p className="mt-1 truncate text-xs text-[#7b8983]">{organization.parent ? `Part of ${organization.parent.name}` : 'Primary company'}</p>
                                    </div>
                                </div>
                                <span className="text-sm text-[#4c6259]">{kindLabels[organization.kind]}</span>
                                <span className="text-sm text-[#4c6259]">{organization.country} <span className="text-[#a1aba5]">·</span> {organization.currency}</span>
                                <span className="text-right text-sm tabular-nums text-[#4c6259]">{organization.members_count}</span>
                                <button type="button" onClick={() => openEdit(organization)} disabled={!organization.can_edit} aria-label={`Edit ${organization.name}`} title={organization.can_edit ? 'Edit organization' : 'You do not have permission to edit this organization'} className="grid size-9 place-items-center rounded-lg text-[#64756e] transition hover:bg-[#eff3ed] hover:text-[#17352d] disabled:cursor-not-allowed disabled:opacity-30">
                                    <Pencil size={16} />
                                </button>
                            </div>
                        ))}
                        </div>
                    </div>
                </div>

                {organizations.length === 0 && (
                    <div className="border-t border-[#dfe5df] py-10 text-center">
                        <p className="text-sm font-medium text-[#29483e]">No organizations are available to your account.</p>
                    </div>
                )}
            </div>

            {isAdding && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-[#10251f]/45 px-4 py-8" role="presentation" onMouseDown={(event) => event.target === event.currentTarget && setIsAdding(false)}>
                    <section role="dialog" aria-modal="true" aria-labelledby="organization-dialog-title" className="w-full max-w-lg rounded-xl bg-white p-6 shadow-2xl sm:p-8">
                        <div className="flex items-start justify-between gap-5">
                            <div>
                                <p className="eyebrow">Organization structure</p>
                                <h2 id="organization-dialog-title" className="mt-2 font-display text-2xl text-[#18342c]">Add an organization</h2>
                            </div>
                            <button type="button" onClick={() => setIsAdding(false)} aria-label="Close dialog" className="grid size-9 place-items-center rounded-lg text-[#6d7d76] hover:bg-[#f1f4ef] hover:text-[#17352d]"><X size={18} /></button>
                        </div>
                        <form onSubmit={submit} className="mt-7 space-y-5">
                            <div>
                                <label htmlFor="name" className="field-label">Name</label>
                                <input id="name" value={data.name} onChange={(event) => setData('name', event.target.value)} className="field-input" autoFocus />
                                <InputError message={errors.name} className="mt-2" />
                            </div>
                            <div>
                                <label htmlFor="kind" className="field-label">Organization type</label>
                                <div className="relative">
                                    <select id="kind" value={data.kind} onChange={(event) => setData('kind', event.target.value as 'subsidiary' | 'branch')} className="field-input appearance-none pr-10">
                                        <option value="subsidiary">Subsidiary company</option>
                                        <option value="branch">Branch</option>
                                    </select>
                                    <ChevronDown size={16} className="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-[#718078]" />
                                </div>
                                <InputError message={errors.kind} className="mt-2" />
                            </div>
                            <div>
                                <label htmlFor="parent_id" className="field-label">Parent organization</label>
                                <div className="relative">
                                    <select id="parent_id" value={data.parent_id} onChange={(event) => setData('parent_id', event.target.value)} className="field-input appearance-none pr-10">
                                        {organizations.map((organization) => <option key={organization.id} value={organization.id}>{organization.name}</option>)}
                                    </select>
                                    <ChevronDown size={16} className="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-[#718078]" />
                                </div>
                                <InputError message={errors.parent_id} className="mt-2" />
                            </div>
                            <div className="flex justify-end gap-3 border-t border-[#e7ece6] pt-5">
                                <button type="button" onClick={() => setIsAdding(false)} className="h-10 rounded-lg px-4 text-sm font-medium text-[#5f7169] hover:bg-[#f3f5ef]">Cancel</button>
                                <button type="submit" disabled={processing} className="h-10 rounded-lg bg-[#17352d] px-4 text-sm font-semibold text-white hover:bg-[#244b40] disabled:opacity-60">{processing ? 'Saving…' : 'Create organization'}</button>
                            </div>
                        </form>
                    </section>
                </div>
            )}

            {editingOrganization && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-[#10251f]/45 px-4 py-6" role="presentation" onMouseDown={(event) => event.target === event.currentTarget && closeEdit()}>
                    <section role="dialog" aria-modal="true" aria-labelledby="organization-edit-title" className="max-h-full w-full max-w-2xl overflow-y-auto rounded-xl bg-white p-6 shadow-2xl sm:p-8">
                        <div className="flex items-start justify-between gap-5">
                            <div>
                                <p className="eyebrow">Organization profile</p>
                                <h2 id="organization-edit-title" className="mt-2 font-display text-2xl text-[#18342c]">Edit organization</h2>
                            </div>
                            <button type="button" onClick={closeEdit} aria-label="Close dialog" className="grid size-9 place-items-center rounded-lg text-[#6d7d76] hover:bg-[#f1f4ef] hover:text-[#17352d]"><X size={18} /></button>
                        </div>
                        <form onSubmit={submitEdit} className="mt-7 space-y-6">
                            <div className="grid gap-5 sm:grid-cols-2">
                                <div className="sm:col-span-2">
                                    <label htmlFor="edit-name" className="field-label">Legal organization name</label>
                                    <input id="edit-name" value={editData.name} onChange={(event) => setEditData('name', event.target.value)} className="field-input" autoFocus />
                                    <InputError message={editErrors.name} className="mt-2" />
                                </div>
                                <div>
                                    <label htmlFor="edit-registration" className="field-label">Registration number</label>
                                    <input id="edit-registration" value={editData.registration_number} onChange={(event) => setEditData('registration_number', event.target.value)} className="field-input" />
                                    <InputError message={editErrors.registration_number} className="mt-2" />
                                </div>
                                <div>
                                    <label htmlFor="edit-email" className="field-label">Contact email</label>
                                    <input id="edit-email" type="email" value={editData.email} onChange={(event) => setEditData('email', event.target.value)} className="field-input" />
                                    <InputError message={editErrors.email} className="mt-2" />
                                </div>
                                <div>
                                    <label htmlFor="edit-phone" className="field-label">Contact phone</label>
                                    <input id="edit-phone" type="tel" value={editData.phone} onChange={(event) => setEditData('phone', event.target.value)} className="field-input" />
                                    <InputError message={editErrors.phone} className="mt-2" />
                                </div>
                                <div>
                                    <label htmlFor="edit-locale" className="field-label">Locale</label>
                                    <input id="edit-locale" value={editData.locale} onChange={(event) => setEditData('locale', event.target.value)} className="field-input" />
                                    <InputError message={editErrors.locale} className="mt-2" />
                                </div>
                                <div>
                                    <label htmlFor="edit-country" className="field-label">Country code</label>
                                    <input id="edit-country" maxLength={2} value={editData.country} onChange={(event) => setEditData('country', event.target.value.toUpperCase())} className="field-input uppercase" />
                                    <InputError message={editErrors.country} className="mt-2" />
                                </div>
                                <div>
                                    <label htmlFor="edit-currency" className="field-label">Currency code</label>
                                    <input id="edit-currency" maxLength={3} value={editData.currency} onChange={(event) => setEditData('currency', event.target.value.toUpperCase())} className="field-input uppercase" />
                                    <InputError message={editErrors.currency} className="mt-2" />
                                </div>
                                <div className="sm:col-span-2">
                                    <label htmlFor="edit-timezone" className="field-label">Timezone</label>
                                    <input id="edit-timezone" value={editData.timezone} onChange={(event) => setEditData('timezone', event.target.value)} className="field-input" />
                                    <InputError message={editErrors.timezone} className="mt-2" />
                                </div>
                                <div className="sm:col-span-2">
                                    <label htmlFor="edit-address" className="field-label">Address</label>
                                    <textarea id="edit-address" rows={2} value={editData.address} onChange={(event) => setEditData('address', event.target.value)} className="field-input resize-y" />
                                    <InputError message={editErrors.address} className="mt-2" />
                                </div>
                            </div>

                            <fieldset className="border-t border-[#e7ece6] pt-5">
                                <legend className="field-label">Branding</legend>
                                <div className="grid gap-5 sm:grid-cols-2">
                                    <div className="sm:col-span-2">
                                        <label htmlFor="edit-display-name" className="field-label">Display name</label>
                                        <input id="edit-display-name" value={editData.branding.display_name} onChange={(event) => setEditData('branding', { ...editData.branding, display_name: event.target.value })} className="field-input" />
                                        <InputError message={editErrors['branding.display_name']} className="mt-2" />
                                    </div>
                                    <div>
                                        <label htmlFor="edit-primary-color" className="field-label">Primary color</label>
                                        <div className="flex h-11 items-center gap-3 rounded-lg border border-[#cfd8ce] px-3">
                                            <input id="edit-primary-color" type="color" value={editData.branding.primary_color} onChange={(event) => setEditData('branding', { ...editData.branding, primary_color: event.target.value })} className="size-7 cursor-pointer rounded border-0 bg-transparent p-0" />
                                            <span className="text-sm font-medium uppercase text-[#53675e]">{editData.branding.primary_color}</span>
                                        </div>
                                        <InputError message={editErrors['branding.primary_color']} className="mt-2" />
                                    </div>
                                    <div>
                                        <label htmlFor="edit-accent-color" className="field-label">Accent color</label>
                                        <div className="flex h-11 items-center gap-3 rounded-lg border border-[#cfd8ce] px-3">
                                            <input id="edit-accent-color" type="color" value={editData.branding.accent_color} onChange={(event) => setEditData('branding', { ...editData.branding, accent_color: event.target.value })} className="size-7 cursor-pointer rounded border-0 bg-transparent p-0" />
                                            <span className="text-sm font-medium uppercase text-[#53675e]">{editData.branding.accent_color}</span>
                                        </div>
                                        <InputError message={editErrors['branding.accent_color']} className="mt-2" />
                                    </div>
                                </div>
                            </fieldset>

                            <div className="flex justify-end gap-3 border-t border-[#e7ece6] pt-5">
                                <button type="button" onClick={closeEdit} className="h-10 rounded-lg px-4 text-sm font-medium text-[#5f7169] hover:bg-[#f3f5ef]">Cancel</button>
                                <button type="submit" disabled={isUpdating} className="h-10 rounded-lg bg-[#17352d] px-4 text-sm font-semibold text-white hover:bg-[#244b40] disabled:opacity-60">{isUpdating ? 'Saving…' : 'Save organization'}</button>
                            </div>
                        </form>
                    </section>
                </div>
            )}
        </AuthenticatedLayout>
    );
}
