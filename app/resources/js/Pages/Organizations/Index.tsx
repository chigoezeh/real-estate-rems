import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import InputError from '@/Components/InputError';
import { Head, useForm } from '@inertiajs/react';
import { Building2, ChevronDown, Plus, X } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

type Organization = {
    id: string;
    name: string;
    kind: 'company' | 'subsidiary' | 'branch';
    country: string;
    currency: string;
    parent: { id: string; name: string } | null;
    members_count: number;
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
    const { data, setData, post, processing, errors, reset } = useForm({
        name: '',
        kind: 'branch' as 'subsidiary' | 'branch',
        parent_id: organizations[0]?.id ?? '',
    });

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        post(route('organizations.store'), {
            onSuccess: () => {
                setIsAdding(false);
                reset('name');
            },
        });
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

                <div className="overflow-hidden rounded-xl border border-[#dfe5df] bg-white">
                    <div className="grid grid-cols-[minmax(180px,1.5fr)_minmax(110px,0.7fr)_minmax(120px,0.8fr)_100px] gap-4 border-b border-[#e7ece6] bg-[#f8faf6] px-5 py-3 text-[11px] font-semibold uppercase tracking-[0.12em] text-[#77857f] sm:px-7">
                        <span>Organization</span><span>Type</span><span>Region & currency</span><span className="text-right">Members</span>
                    </div>
                    <div className="divide-y divide-[#edf0ec]">
                        {organizations.map((organization) => (
                            <div key={organization.id} className="grid grid-cols-[minmax(180px,1.5fr)_minmax(110px,0.7fr)_minmax(120px,0.8fr)_100px] items-center gap-4 px-5 py-4 sm:px-7">
                                <div className="flex min-w-0 items-center gap-3">
                                    <span className="grid size-10 shrink-0 place-items-center rounded-lg bg-[#edf4eb] text-[#315447]"><Building2 size={18} /></span>
                                    <div className="min-w-0">
                                        <p className="truncate text-sm font-semibold text-[#233c33]">{organization.name}</p>
                                        <p className="mt-1 truncate text-xs text-[#7b8983]">{organization.parent ? `Part of ${organization.parent.name}` : 'Primary company'}</p>
                                    </div>
                                </div>
                                <span className="text-sm text-[#4c6259]">{kindLabels[organization.kind]}</span>
                                <span className="text-sm text-[#4c6259]">{organization.country} <span className="text-[#a1aba5]">·</span> {organization.currency}</span>
                                <span className="text-right text-sm tabular-nums text-[#4c6259]">{organization.members_count}</span>
                            </div>
                        ))}
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
        </AuthenticatedLayout>
    );
}
