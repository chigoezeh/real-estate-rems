import { Building2 } from 'lucide-react';
import { PropsWithChildren } from 'react';

export default function Guest({ children }: PropsWithChildren) {
    return (
        <div className="grid min-h-screen bg-[#f3f5ef] lg:grid-cols-[minmax(320px,0.82fr)_1.18fr]">
            <aside className="hidden flex-col justify-between bg-[#17352d] px-10 py-12 text-white lg:flex xl:px-16">
                <div className="flex items-center gap-3">
                    <span className="grid size-11 place-items-center rounded-xl bg-[#d6f36a] text-[#17352d]"><Building2 size={22} /></span>
                    <span>
                        <span className="block text-sm font-bold uppercase tracking-[0.12em]">REMS</span>
                        <span className="mt-0.5 block text-xs text-white/60">Real estate management</span>
                    </span>
                </div>
                <div className="max-w-md pb-10">
                    <p className="eyebrow text-[#d6f36a]">Private company workspace</p>
                    <h1 className="mt-5 font-display text-4xl leading-tight xl:text-5xl">Property operations, in one place.</h1>
                    <p className="mt-5 max-w-sm text-sm leading-6 text-white/65">Sign in to your organization’s self-hosted real estate workspace.</p>
                </div>
                <p className="text-xs text-white/45">Self-hosted edition</p>
            </aside>
            <main className="flex items-center justify-center px-5 py-10 sm:px-10">
                <div className="w-full max-w-md">
                    <div className="mb-8 flex items-center gap-3 lg:hidden">
                        <span className="grid size-10 place-items-center rounded-xl bg-[#17352d] text-[#d6f36a]"><Building2 size={20} /></span>
                        <span className="text-sm font-bold uppercase tracking-[0.12em] text-[#17352d]">REMS</span>
                    </div>
                    <div className="border-y border-[#dfe5df] bg-white px-6 py-8 sm:px-9 sm:py-10">
                        {children}
                    </div>
                </div>
            </main>
        </div>
    );
}
