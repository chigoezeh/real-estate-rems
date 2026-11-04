import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head } from '@inertiajs/react';
import { ArrowUpRight, Building2, Clock3, UsersRound } from 'lucide-react';
import { PageProps } from '@/types';

type Activity = {
    id: string;
    action: string;
    created_at: string;
    user: { id: string; name: string } | null;
};

type Props = PageProps & {
    company: { name: string; country: string; currency: string; timezone: string } | null;
    organizationCount: number;
    staffCount: number;
    recentActivity: Activity[];
    canManageOrganizations: boolean;
};

const activityLabels: Record<string, string> = {
    'installation.completed': 'Workspace installation completed',
    'organization.created': 'Organization added',
};

export default function Dashboard({ company, organizationCount, staffCount, recentActivity, canManageOrganizations }: Props) {
    return (
        <AuthenticatedLayout>
            <Head title="Overview" />
            <div className="space-y-9">
                <section className="flex flex-col gap-5 border-b border-[#dfe5df] pb-7 sm:flex-row sm:items-end sm:justify-between">
                    <div>
                        <p className="eyebrow">Workspace overview</p>
                        <h1 className="page-title">Welcome back</h1>
                        <p className="mt-2 text-sm text-[#687970]">A clear view of {company?.name ?? 'your organization'} and its teams.</p>
                    </div>
                    {canManageOrganizations && (
                        <a href={route('organizations.index')} className="inline-flex h-10 items-center justify-center gap-2 self-start rounded-lg border border-[#cbd8ce] bg-white px-4 text-sm font-semibold text-[#29483e] transition hover:border-[#9eb2a3] hover:bg-[#f9fbf8] sm:self-auto">
                            Manage organizations <ArrowUpRight size={16} />
                        </a>
                    )}
                </section>

                <section aria-label="Workspace summary" className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <SummaryItem icon={Building2} label="Organizations" value={String(organizationCount)} detail="Companies and branches" />
                    <SummaryItem icon={UsersRound} label="Team members" value={String(staffCount)} detail="Across your organization" />
                    <SummaryItem icon={Clock3} label="Default currency" value={company?.currency ?? '—'} detail="Used for new records" />
                    <SummaryItem icon={Clock3} label="Workspace time zone" value={company?.timezone ?? '—'} detail="Dates and schedules" />
                </section>

                <section className="grid gap-8 xl:grid-cols-[minmax(0,1.35fr)_minmax(300px,0.65fr)]">
                    <div>
                        <div className="mb-4 flex items-end justify-between gap-4">
                            <div>
                                <p className="eyebrow">Recent changes</p>
                                <h2 className="mt-2 font-display text-2xl text-[#18342c]">Workspace activity</h2>
                            </div>
                            <span className="text-xs text-[#8a9791]">Latest 5 events</span>
                        </div>
                        <div className="divide-y divide-[#e7ece6] border-y border-[#dfe5df] bg-white">
                            {recentActivity.map((activity) => (
                                <div key={activity.id} className="flex items-center gap-4 px-4 py-4 sm:px-5">
                                    <span className="grid size-9 shrink-0 place-items-center rounded-full bg-[#edf4eb] text-[#315447]"><Building2 size={16} /></span>
                                    <div className="min-w-0 flex-1">
                                        <p className="truncate text-sm font-medium text-[#29483e]">{activityLabels[activity.action] ?? activity.action.replaceAll('.', ' ')}</p>
                                        <p className="mt-1 truncate text-xs text-[#819087]">{activity.user?.name ?? 'System'} · {new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short', timeZone: company?.timezone ?? 'Africa/Lagos' }).format(new Date(activity.created_at))}</p>
                                    </div>
                                </div>
                            ))}
                            {recentActivity.length === 0 && <p className="px-5 py-8 text-sm text-[#7b8983]">No workspace activity yet.</p>}
                        </div>
                    </div>

                    <aside className="self-start border-l-2 border-[#d6f36a] bg-[#eaf0e5] px-5 py-5 sm:px-6">
                        <p className="eyebrow">Company profile</p>
                        <h2 className="mt-2 font-display text-2xl text-[#18342c]">{company?.name ?? 'Workspace not configured'}</h2>
                        <dl className="mt-6 space-y-4 border-t border-[#d4dfd1] pt-5 text-sm">
                            <div className="flex justify-between gap-4"><dt className="text-[#6b7b72]">Country</dt><dd className="font-medium text-[#29483e]">{company?.country ?? '—'}</dd></div>
                            <div className="flex justify-between gap-4"><dt className="text-[#6b7b72]">Currency</dt><dd className="font-medium text-[#29483e]">{company?.currency ?? '—'}</dd></div>
                            <div className="flex justify-between gap-4"><dt className="text-[#6b7b72]">Time zone</dt><dd className="max-w-[65%] truncate text-right font-medium text-[#29483e]">{company?.timezone ?? '—'}</dd></div>
                        </dl>
                    </aside>
                </section>
            </div>
        </AuthenticatedLayout>
    );
}

function SummaryItem({ icon: Icon, label, value, detail }: { icon: typeof Building2; label: string; value: string; detail: string }) {
    return (
        <div className="min-w-0 border-t-2 border-[#d9e1d9] bg-white px-4 py-4 sm:px-5">
            <div className="flex items-center justify-between gap-4">
                <span className="text-xs font-medium text-[#75847d]">{label}</span>
                <Icon size={17} className="shrink-0 text-[#73917e]" />
            </div>
            <p className="mt-4 truncate text-2xl font-semibold tabular-nums text-[#203c32]">{value}</p>
            <p className="mt-1 text-xs text-[#8a9791]">{detail}</p>
        </div>
    );
}
