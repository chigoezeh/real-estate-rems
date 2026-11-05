import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import InputError from '@/Components/InputError';
import { Head, useForm } from '@inertiajs/react';
import { ChevronDown, KeyRound, Pencil, Plus, ShieldCheck, X } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

type Organization = { id: string; name: string };
type Permission = { key: string; label: string; description: string | null };
type Role = {
    id: string;
    name: string;
    key: string;
    description: string | null;
    isSystem: boolean;
    organization: Organization | null;
    permissions: string[];
};
type Props = { organizations: Organization[]; roles: Role[]; permissions: Permission[] };
type RoleForm = { organization_id: string; name: string; description: string; permissions: string[] };

const emptyForm = (organizationId: string): RoleForm => ({
    organization_id: organizationId,
    name: '',
    description: '',
    permissions: [],
});

export default function Index({ organizations, roles, permissions }: Props) {
    const [activeRole, setActiveRole] = useState<Role | null>(null);
    const [isOpen, setIsOpen] = useState(false);
    const { data, setData, post, put, processing, errors, reset, clearErrors } = useForm<RoleForm>(emptyForm(organizations[0]?.id ?? ''));

    const openCreate = () => {
        setActiveRole(null);
        clearErrors();
        setData(emptyForm(organizations[0]?.id ?? ''));
        setIsOpen(true);
    };

    const openEdit = (role: Role) => {
        setActiveRole(role);
        clearErrors();
        setData({
            organization_id: role.organization?.id ?? '',
            name: role.name,
            description: role.description ?? '',
            permissions: role.permissions,
        });
        setIsOpen(true);
    };

    const close = () => {
        setIsOpen(false);
        reset();
        setActiveRole(null);
    };

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        const options = { onSuccess: close };
        if (activeRole) {
            put(route('roles.update', activeRole.id), options);
        } else {
            post(route('roles.store'), options);
        }
    };

    const togglePermission = (key: string) => {
        setData('permissions', data.permissions.includes(key)
            ? data.permissions.filter((permission) => permission !== key)
            : [...data.permissions, key]);
    };

    return (
        <AuthenticatedLayout>
            <Head title="Access & roles" />
            <div className="space-y-8">
                <div className="flex flex-col gap-5 border-b border-[#dfe5df] pb-6 sm:flex-row sm:items-end sm:justify-between">
                    <div>
                        <p className="eyebrow">Administration</p>
                        <h1 className="page-title">Access & roles</h1>
                        <p className="mt-2 max-w-2xl text-sm leading-6 text-[#64756e]">Define what each team can see and manage within its organization.</p>
                    </div>
                    <button type="button" onClick={openCreate} className="inline-flex h-11 items-center justify-center gap-2 rounded-lg bg-[#17352d] px-4 text-sm font-semibold text-white transition hover:bg-[#244b40] focus:outline-none focus:ring-2 focus:ring-[#8ba344] focus:ring-offset-2">
                        <Plus size={17} /> Add role
                    </button>
                </div>

                <div className="flex items-center gap-2 text-sm text-[#73827d]">
                    <KeyRound size={16} className="text-[#315447]" />
                    <span>{roles.length} {roles.length === 1 ? 'role' : 'roles'} across {organizations.length} {organizations.length === 1 ? 'organization' : 'organizations'}</span>
                </div>

                <div className="overflow-hidden rounded-xl border border-[#dfe5df] bg-white">
                    <div className="grid grid-cols-[minmax(190px,1fr)_minmax(140px,0.8fr)_minmax(220px,1.4fr)_44px] gap-4 border-b border-[#e7ece6] bg-[#f8faf6] px-5 py-3 text-[11px] font-semibold uppercase tracking-[0.12em] text-[#77857f] sm:px-7">
                        <span>Role</span><span>Organization</span><span>Permissions</span><span />
                    </div>
                    <div className="divide-y divide-[#edf0ec]">
                        {roles.map((role) => (
                            <div key={role.id} className="grid grid-cols-[minmax(190px,1fr)_minmax(140px,0.8fr)_minmax(220px,1.4fr)_44px] items-center gap-4 px-5 py-4 sm:px-7">
                                <div className="flex min-w-0 items-center gap-3">
                                    <span className="grid size-10 shrink-0 place-items-center rounded-lg bg-[#edf4eb] text-[#315447]"><ShieldCheck size={18} /></span>
                                    <div className="min-w-0">
                                        <p className="truncate text-sm font-semibold text-[#233c33]">{role.name}</p>
                                        <p className="mt-1 truncate text-xs text-[#7b8983]">{role.description || role.key}</p>
                                    </div>
                                </div>
                                <span className="truncate text-sm text-[#4c6259]">{role.organization?.name ?? 'System'}</span>
                                <div className="flex flex-wrap gap-1.5">
                                    {role.permissions.map((key) => {
                                        const permission = permissions.find((item) => item.key === key);
                                        return <span key={key} className="rounded-md bg-[#f0f3ed] px-2 py-1 text-xs text-[#53675e]">{permission?.label ?? key}</span>;
                                    })}
                                    {role.permissions.length === 0 && <span className="text-xs text-[#8a9791]">No permissions</span>}
                                </div>
                                <button type="button" onClick={() => openEdit(role)} disabled={role.isSystem} aria-label={`Edit ${role.name}`} title={role.isSystem ? 'System roles cannot be changed' : 'Edit role'} className="grid size-9 place-items-center rounded-lg text-[#64756e] transition hover:bg-[#eff3ed] hover:text-[#17352d] disabled:cursor-not-allowed disabled:opacity-35">
                                    <Pencil size={16} />
                                </button>
                            </div>
                        ))}
                    </div>
                </div>

                {roles.length === 0 && (
                    <div className="border-t border-[#dfe5df] py-10 text-center">
                        <p className="text-sm font-medium text-[#29483e]">No roles are available in your organizations.</p>
                    </div>
                )}
            </div>

            {isOpen && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-[#10251f]/45 px-4 py-8" role="presentation" onMouseDown={(event) => event.target === event.currentTarget && close()}>
                    <section role="dialog" aria-modal="true" aria-labelledby="role-dialog-title" className="max-h-full w-full max-w-2xl overflow-y-auto rounded-xl bg-white p-6 shadow-2xl sm:p-8">
                        <div className="flex items-start justify-between gap-5">
                            <div>
                                <p className="eyebrow">Permission setup</p>
                                <h2 id="role-dialog-title" className="mt-2 font-display text-2xl text-[#18342c]">{activeRole ? 'Edit role' : 'Create a role'}</h2>
                            </div>
                            <button type="button" onClick={close} aria-label="Close dialog" className="grid size-9 place-items-center rounded-lg text-[#6d7d76] hover:bg-[#f1f4ef] hover:text-[#17352d]"><X size={18} /></button>
                        </div>
                        <form onSubmit={submit} className="mt-7 space-y-5">
                            {!activeRole && (
                                <div>
                                    <label htmlFor="organization_id" className="field-label">Organization</label>
                                    <div className="relative">
                                        <select id="organization_id" value={data.organization_id} onChange={(event) => setData('organization_id', event.target.value)} className="field-input appearance-none pr-10">
                                            {organizations.map((organization) => <option key={organization.id} value={organization.id}>{organization.name}</option>)}
                                        </select>
                                        <ChevronDown size={16} className="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-[#718078]" />
                                    </div>
                                    <InputError message={errors.organization_id} className="mt-2" />
                                </div>
                            )}
                            <div>
                                <label htmlFor="name" className="field-label">Role name</label>
                                <input id="name" value={data.name} onChange={(event) => setData('name', event.target.value)} className="field-input" autoFocus />
                                <InputError message={errors.name} className="mt-2" />
                            </div>
                            <div>
                                <label htmlFor="description" className="field-label">Description</label>
                                <textarea id="description" value={data.description} onChange={(event) => setData('description', event.target.value)} className="field-input min-h-20 resize-y" rows={2} />
                                <InputError message={errors.description} className="mt-2" />
                            </div>
                            <fieldset>
                                <legend className="field-label">Permissions</legend>
                                <div className="grid gap-2 sm:grid-cols-2">
                                    {permissions.map((permission) => (
                                        <label key={permission.key} className="flex cursor-pointer items-start gap-3 rounded-lg border border-[#e3e9e1] p-3 transition hover:bg-[#f8faf6]">
                                            <input type="checkbox" checked={data.permissions.includes(permission.key)} onChange={() => togglePermission(permission.key)} className="mt-0.5 size-4 rounded border-[#b8c5bb] text-[#315447] focus:ring-[#8ba344]" />
                                            <span>
                                                <span className="block text-sm font-medium text-[#29483e]">{permission.label}</span>
                                                {permission.description && <span className="mt-1 block text-xs leading-5 text-[#718078]">{permission.description}</span>}
                                            </span>
                                        </label>
                                    ))}
                                </div>
                                <InputError message={errors.permissions} className="mt-2" />
                            </fieldset>
                            <div className="flex justify-end gap-3 border-t border-[#e7ece6] pt-5">
                                <button type="button" onClick={close} className="h-10 rounded-lg px-4 text-sm font-medium text-[#5f7169] hover:bg-[#f3f5ef]">Cancel</button>
                                <button type="submit" disabled={processing || organizations.length === 0} className="h-10 rounded-lg bg-[#17352d] px-4 text-sm font-semibold text-white hover:bg-[#244b40] disabled:opacity-60">{processing ? 'Saving…' : activeRole ? 'Save role' : 'Create role'}</button>
                            </div>
                        </form>
                    </section>
                </div>
            )}
        </AuthenticatedLayout>
    );
}