import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import InputError from '@/Components/InputError';
import { Head, Link, useForm } from '@inertiajs/react';
import { ChevronDown, MailPlus, Pencil, ShieldCheck, UserRound, X } from 'lucide-react';
import { FormEventHandler, useMemo, useState } from 'react';

type Organization = { id: string; name: string };
type Role = { id: string; organization_id: string; name: string; is_system: boolean };
type Member = {
    id: string;
    name: string;
    email: string;
    organizationId: string;
    organizationName: string;
    roleId: string;
    roleName: string;
    status: 'active' | 'suspended';
    isSelf: boolean;
};
type Props = { organizations: Organization[]; roles: Role[]; members: Member[] };
type StaffForm = { organization_id: string; name: string; email: string; role_id: string; status: 'active' | 'suspended' };

const emptyForm = (organizationId: string, roleId: string): StaffForm => ({
    organization_id: organizationId,
    name: '',
    email: '',
    role_id: roleId,
    status: 'active',
});

export default function Index({ organizations, roles, members }: Props) {
    const [activeMember, setActiveMember] = useState<Member | null>(null);
    const [isOpen, setIsOpen] = useState(false);
    const initialRole = roles[0];
    const { data, setData, post, patch, processing, errors, reset, clearErrors } = useForm<StaffForm>(emptyForm(initialRole?.organization_id ?? '', initialRole?.id ?? ''));
    const availableRoles = useMemo(() => roles.filter((role) => role.organization_id === data.organization_id), [roles, data.organization_id]);
    const activeCount = members.filter((member) => member.status === 'active').length;
    const suspendedCount = members.length - activeCount;

    const openInvite = () => {
        setActiveMember(null);
        clearErrors();
        const role = roles[0];
        setData(emptyForm(role?.organization_id ?? '', role?.id ?? ''));
        setIsOpen(true);
    };

    const openEdit = (member: Member) => {
        setActiveMember(member);
        clearErrors();
        setData({
            organization_id: member.organizationId,
            name: member.name,
            email: member.email,
            role_id: member.roleId,
            status: member.status,
        });
        setIsOpen(true);
    };

    const close = () => {
        setIsOpen(false);
        reset();
        setActiveMember(null);
    };

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        const options = { onSuccess: close };
        if (activeMember) {
            patch(route('staff.update', activeMember.id), options);
        } else {
            post(route('staff.store'), options);
        }
    };

    const changeOrganization = (organizationId: string) => {
        const role = roles.find((item) => item.organization_id === organizationId);
        setData((current) => ({ ...current, organization_id: organizationId, role_id: role?.id ?? '' }));
    };

    return (
        <AuthenticatedLayout>
            <Head title="Staff" />
            <div className="space-y-8">
                <div className="flex flex-col gap-5 border-b border-[#dfe5df] pb-6 sm:flex-row sm:items-end sm:justify-between">
                    <div>
                        <p className="eyebrow">Administration</p>
                        <h1 className="page-title">Staff</h1>
                        <p className="mt-2 max-w-2xl text-sm leading-6 text-[#64756e]">Manage team access across the organizations you administer.</p>
                    </div>
                    <button type="button" onClick={openInvite} disabled={roles.length === 0} className="inline-flex h-11 items-center justify-center gap-2 rounded-lg bg-[#17352d] px-4 text-sm font-semibold text-white transition hover:bg-[#244b40] focus:outline-none focus:ring-2 focus:ring-[#8ba344] focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50">
                        <MailPlus size={17} /> Invite staff
                    </button>
                </div>

                {roles.length === 0 && (
                    <div className="flex flex-col gap-3 border-l-2 border-[#8ba344] bg-white px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                        <p className="text-sm text-[#53675e]">Create an organization role before inviting or assigning staff.</p>
                        <Link href={route('roles.index')} className="text-sm font-semibold text-[#315447] underline decoration-[#b6c798] underline-offset-4">Manage roles</Link>
                    </div>
                )}

                <div className="flex flex-wrap items-center gap-x-8 gap-y-3 text-sm">
                    <div><span className="text-[#73827d]">Memberships</span><strong className="ml-2 text-[#17352d]">{members.length}</strong></div>
                    <div><span className="text-[#73827d]">Active</span><strong className="ml-2 text-[#315447]">{activeCount}</strong></div>
                    <div><span className="text-[#73827d]">Suspended</span><strong className="ml-2 text-[#8b554a]">{suspendedCount}</strong></div>
                </div>

                <div className="overflow-x-auto rounded-xl border border-[#dfe5df] bg-white">
                    <div className="min-w-[760px]">
                        <div className="grid grid-cols-[minmax(210px,1.3fr)_minmax(150px,0.9fr)_minmax(140px,0.8fr)_100px_44px] gap-4 border-b border-[#e7ece6] bg-[#f8faf6] px-5 py-3 text-[11px] font-semibold uppercase tracking-[0.12em] text-[#77857f] sm:px-7">
                            <span>Team member</span><span>Organization</span><span>Role</span><span>Status</span><span />
                        </div>
                        <div className="divide-y divide-[#edf0ec]">
                            {members.map((member) => (
                                <div key={`${member.organizationId}-${member.id}`} className="grid min-h-[72px] grid-cols-[minmax(210px,1.3fr)_minmax(150px,0.9fr)_minmax(140px,0.8fr)_100px_44px] items-center gap-4 px-5 py-3 sm:px-7">
                                    <div className="flex min-w-0 items-center gap-3">
                                        <span className="grid size-9 shrink-0 place-items-center rounded-full bg-[#edf4eb] text-[#315447]"><UserRound size={17} /></span>
                                        <div className="min-w-0">
                                            <p className="truncate text-sm font-semibold text-[#233c33]">{member.name}{member.isSelf && <span className="ml-2 text-xs font-normal text-[#829087]">You</span>}</p>
                                            <p className="mt-1 truncate text-xs text-[#7b8983]">{member.email}</p>
                                        </div>
                                    </div>
                                    <span className="truncate text-sm text-[#4c6259]">{member.organizationName}</span>
                                    <span className="truncate text-sm text-[#4c6259]">{member.roleName}</span>
                                    <span className={`inline-flex w-fit items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium ${member.status === 'active' ? 'bg-[#edf4eb] text-[#315447]' : 'bg-[#f7eeeb] text-[#8b554a]'}`}>
                                        <span className={`size-1.5 rounded-full ${member.status === 'active' ? 'bg-[#578345]' : 'bg-[#b56755]'}`} />
                                        {member.status === 'active' ? 'Active' : 'Suspended'}
                                    </span>
                                    <button type="button" onClick={() => openEdit(member)} disabled={member.isSelf} aria-label={`Edit ${member.name}`} title={member.isSelf ? 'Your own membership cannot be changed here' : 'Edit membership'} className="grid size-9 place-items-center rounded-lg text-[#64756e] transition hover:bg-[#eff3ed] hover:text-[#17352d] disabled:cursor-not-allowed disabled:opacity-35">
                                        <Pencil size={16} />
                                    </button>
                                </div>
                            ))}
                        </div>
                    </div>
                </div>

                {members.length === 0 && (
                    <div className="border-t border-[#dfe5df] py-10 text-center">
                        <p className="text-sm font-medium text-[#29483e]">No staff memberships are available to your account.</p>
                    </div>
                )}
            </div>

            {isOpen && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-[#10251f]/45 px-4 py-8" role="presentation" onMouseDown={(event) => event.target === event.currentTarget && close()}>
                    <section role="dialog" aria-modal="true" aria-labelledby="staff-dialog-title" className="max-h-full w-full max-w-xl overflow-y-auto rounded-xl bg-white p-6 shadow-2xl sm:p-8">
                        <div className="flex items-start justify-between gap-5">
                            <div>
                                <p className="eyebrow">Team access</p>
                                <h2 id="staff-dialog-title" className="mt-2 font-display text-2xl text-[#18342c]">{activeMember ? 'Edit membership' : 'Invite staff'}</h2>
                            </div>
                            <button type="button" onClick={close} aria-label="Close dialog" className="grid size-9 place-items-center rounded-lg text-[#6d7d76] hover:bg-[#f1f4ef] hover:text-[#17352d]"><X size={18} /></button>
                        </div>
                        <form onSubmit={submit} className="mt-7 space-y-5">
                            <div>
                                <label htmlFor="organization_id" className="field-label">Organization</label>
                                <div className="relative">
                                    <select id="organization_id" value={data.organization_id} disabled={Boolean(activeMember)} onChange={(event) => changeOrganization(event.target.value)} className="field-input appearance-none pr-10 disabled:bg-[#f4f6f2]">
                                        {organizations.map((organization) => <option key={organization.id} value={organization.id}>{organization.name}</option>)}
                                    </select>
                                    <ChevronDown size={16} className="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-[#718078]" />
                                </div>
                                <InputError message={errors.organization_id} className="mt-2" />
                            </div>
                            {!activeMember && (
                                <>
                                    <div>
                                        <label htmlFor="name" className="field-label">Full name</label>
                                        <input id="name" value={data.name} onChange={(event) => setData('name', event.target.value)} className="field-input" autoFocus />
                                        <InputError message={errors.name} className="mt-2" />
                                    </div>
                                    <div>
                                        <label htmlFor="email" className="field-label">Work email</label>
                                        <input id="email" type="email" autoComplete="email" value={data.email} onChange={(event) => setData('email', event.target.value)} className="field-input" />
                                        <InputError message={errors.email} className="mt-2" />
                                    </div>
                                </>
                            )}
                            <div>
                                <label htmlFor="role_id" className="field-label">Role</label>
                                <div className="relative">
                                    <select id="role_id" value={data.role_id} onChange={(event) => setData('role_id', event.target.value)} className="field-input appearance-none pr-10">
                                        {availableRoles.map((role) => <option key={role.id} value={role.id}>{role.name}</option>)}
                                    </select>
                                    <ChevronDown size={16} className="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-[#718078]" />
                                </div>
                                <InputError message={errors.role_id} className="mt-2" />
                            </div>
                            {activeMember && (
                                <fieldset>
                                    <legend className="field-label">Membership status</legend>
                                    <div className="flex gap-2">
                                        {(['active', 'suspended'] as const).map((status) => (
                                            <button key={status} type="button" onClick={() => setData('status', status)} aria-pressed={data.status === status} className={`inline-flex h-10 items-center gap-2 rounded-lg border px-3 text-sm font-medium transition ${data.status === status ? 'border-[#315447] bg-[#edf4eb] text-[#23463b]' : 'border-[#dfe5df] text-[#64756e] hover:bg-[#f8faf6]'}`}>
                                                {status === 'active' ? <ShieldCheck size={16} /> : <UserRound size={16} />}
                                                {status === 'active' ? 'Active' : 'Suspended'}
                                            </button>
                                        ))}
                                    </div>
                                    <InputError message={errors.status} className="mt-2" />
                                </fieldset>
                            )}
                            {!activeMember && <p className="text-xs leading-5 text-[#718078]">A new team member will receive an email to set their own password.</p>}
                            <div className="flex justify-end gap-3 border-t border-[#e7ece6] pt-5">
                                <button type="button" onClick={close} className="h-10 rounded-lg px-4 text-sm font-medium text-[#5f7169] hover:bg-[#f3f5ef]">Cancel</button>
                                <button type="submit" disabled={processing || !data.role_id} className="h-10 rounded-lg bg-[#17352d] px-4 text-sm font-semibold text-white hover:bg-[#244b40] disabled:opacity-60">{processing ? 'Saving…' : activeMember ? 'Save membership' : 'Send invitation'}</button>
                            </div>
                        </form>
                    </section>
                </div>
            )}
        </AuthenticatedLayout>
    );
}