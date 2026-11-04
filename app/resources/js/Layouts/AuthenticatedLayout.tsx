import { Link, usePage } from '@inertiajs/react';
import { Building2, ChevronDown, KeyRound, LayoutDashboard, LogOut, Network, Users } from 'lucide-react';
import { PropsWithChildren, ReactNode } from 'react';
import { PageProps } from '@/types';

export default function Authenticated({ children, header }: PropsWithChildren<{ header?: ReactNode }>) {
    const { auth, company } = usePage<PageProps>().props;
    const user = auth.user;
    const isDashboard = route().current('dashboard');
    const isOrganizations = route().current('organizations.*');
    const isRoles = route().current('roles.*');
    const isStaff = route().current('staff.*');
    const sectionName = isDashboard ? 'Overview' : isRoles ? 'Access & roles' : isStaff ? 'Staff' : isOrganizations ? 'Companies' : 'Profile';

    return (
        <div className="min-h-screen bg-[#f3f5ef] lg:flex">
            <aside className="hidden w-[248px] shrink-0 flex-col bg-[#17352d] px-4 py-6 text-white lg:flex">
                <Link href={route('dashboard')} className="flex items-center gap-3 px-2">
                    <span className="grid size-10 place-items-center rounded-xl bg-[#d6f36a] text-[#17352d]"><Building2 size={21} /></span>
                    <span>
                        <span className="block text-sm font-bold uppercase tracking-[0.12em]">REMS</span>
                        <span className="mt-0.5 block text-[11px] text-white/55">Property operations</span>
                    </span>
                </Link>

                <div className="mt-9 rounded-lg border border-white/10 bg-white/5 px-3 py-3">
                    <p className="truncate text-xs font-medium text-white">{company?.name ?? 'Your workspace'}</p>
                    <p className="mt-1 text-[11px] text-white/55">Self-hosted workspace</p>
                </div>

                <nav aria-label="Main navigation" className="mt-8 space-y-1">
                    <p className="mb-3 px-3 text-[10px] font-semibold uppercase tracking-[0.16em] text-white/40">Workspace</p>
                    <Link href={route('dashboard')} className={`flex h-10 items-center gap-3 rounded-lg px-3 text-sm transition ${isDashboard ? 'bg-[#d6f36a] font-semibold text-[#17352d]' : 'text-white/70 hover:bg-white/8 hover:text-white'}`}>
                        <LayoutDashboard size={17} /> Overview
                    </Link>
                    <Link href={route('organizations.index')} className={`flex h-10 items-center gap-3 rounded-lg px-3 text-sm transition ${isOrganizations ? 'bg-white/10 font-medium text-white' : 'text-white/70 hover:bg-white/8 hover:text-white'}`}>
                        <Network size={17} /> Companies & branches
                    </Link>
                    {auth.canManageUsers && (
                        <>
                            <Link href={route('staff.index')} className={`flex h-10 items-center gap-3 rounded-lg px-3 text-sm transition ${isStaff ? 'bg-white/10 font-medium text-white' : 'text-white/70 hover:bg-white/8 hover:text-white'}`}>
                                <Users size={17} /> Staff
                            </Link>
                            <Link href={route('roles.index')} className={`flex h-10 items-center gap-3 rounded-lg px-3 text-sm transition ${isRoles ? 'bg-white/10 font-medium text-white' : 'text-white/70 hover:bg-white/8 hover:text-white'}`}>
                                <KeyRound size={17} /> Access & roles
                            </Link>
                        </>
                    )}
                </nav>

                <div className="mt-auto border-t border-white/10 pt-5">
                    <p className="truncate px-2 text-sm font-medium text-white">{user.name}</p>
                    <p className="mt-1 truncate px-2 text-xs text-white/55">{user.email}</p>
                    <Link href={route('logout')} method="post" as="button" className="mt-4 flex h-9 w-full items-center gap-2 rounded-lg px-2 text-sm text-white/65 transition hover:bg-white/8 hover:text-white">
                        <LogOut size={16} /> Sign out
                    </Link>
                </div>
            </aside>

            <main className="min-w-0 flex-1">
                <header className="flex min-h-[72px] items-center justify-between border-b border-[#dfe5df] bg-white px-5 sm:px-8 lg:px-10">
                    <div className="min-w-0">
                        <p className="hidden text-xs text-[#8a9791] sm:block">Workspace <span className="mx-1.5">/</span> {sectionName}</p>
                        <p className="mt-1 truncate text-sm font-semibold text-[#29483e] lg:hidden">{company?.name ?? 'REMS'}</p>
                    </div>
                    <div className="flex items-center gap-3">
                        <span className="hidden text-right sm:block">
                            <span className="block text-sm font-medium text-[#29483e]">{user.name}</span>
                            <span className="block text-xs text-[#809087]">Administrator</span>
                        </span>
                        <span className="grid size-9 place-items-center rounded-full bg-[#dfead7] text-xs font-bold text-[#315447]" aria-hidden="true">{user.name.split(' ').map((part) => part[0]).slice(0, 2).join('').toUpperCase()}</span>
                        <Link href={route('logout')} method="post" as="button" aria-label="Sign out" title="Sign out" className="grid size-9 place-items-center rounded-lg text-[#64756e] hover:bg-[#eff3ed] lg:hidden"><LogOut size={17} /></Link>
                        <ChevronDown size={15} className="hidden text-[#94a098] sm:block" />
                    </div>
                </header>
                {header && <div className="border-b border-[#dfe5df] bg-white px-5 py-5 sm:px-8 lg:px-10">{header}</div>}
                <div className="mx-auto w-full max-w-[1440px] px-5 py-8 sm:px-8 sm:py-10 lg:px-10">{children}</div>
            </main>
        </div>
    );
}
