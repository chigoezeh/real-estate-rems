import InputError from '@/Components/InputError';
import { Head, useForm } from '@inertiajs/react';
import { ArrowRight, Building2, ShieldCheck } from 'lucide-react';
import { FormEventHandler } from 'react';

type SetupForm = {
    organization_name: string;
    country: string;
    currency: string;
    timezone: string;
    administrator_name: string;
    administrator_email: string;
    password: string;
    password_confirmation: string;
    populate_demo_data: boolean;
};

export default function Setup({ canSeedDemoData }: { canSeedDemoData: boolean }) {
    const { data, setData, post, processing, errors } = useForm<SetupForm>({
        organization_name: '',
        country: 'NG',
        currency: 'NGN',
        timezone: 'Africa/Lagos',
        administrator_name: '',
        administrator_email: '',
        password: '',
        password_confirmation: '',
        populate_demo_data: false,
    });

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        post(route('setup.store'));
    };

    return (
        <div className="min-h-screen bg-[#f3f5ef] text-[#18342c]">
            <Head title="Set up your workspace" />
            <div className="grid min-h-screen lg:grid-cols-[minmax(320px,0.82fr)_1.18fr]">
                <aside className="relative hidden overflow-hidden bg-[#17352d] px-10 py-12 text-white lg:flex lg:flex-col lg:justify-between xl:px-16">
                    <div className="absolute -right-32 top-28 h-80 w-80 rounded-full border border-white/10" />
                    <div className="absolute -right-16 top-44 h-48 w-48 rounded-full border border-[#d6f36a]/30" />
                    <div className="relative">
                        <div className="flex items-center gap-3">
                            <span className="grid size-11 place-items-center rounded-xl bg-[#d6f36a] text-[#17352d]">
                                <Building2 size={22} strokeWidth={2.2} />
                            </span>
                            <div>
                                <div className="text-sm font-bold uppercase tracking-[0.12em]">REMS</div>
                                <div className="mt-0.5 text-xs text-white/60">Real estate management</div>
                            </div>
                        </div>
                    </div>
                    <div className="relative max-w-md pb-8">
                        <p className="mb-5 text-xs font-semibold uppercase tracking-[0.18em] text-[#d6f36a]">Your workspace starts here</p>
                        <h1 className="font-display text-4xl leading-[1.12] xl:text-5xl">Set up the way your company works.</h1>
                        <p className="mt-6 max-w-sm text-base leading-7 text-white/70">Create your company workspace and its first administrator. Your organization details and policies stay under your control.</p>
                        <div className="mt-10 flex items-center gap-3 border-t border-white/15 pt-5 text-sm text-white/65">
                            <ShieldCheck size={17} className="text-[#d6f36a]" />
                            <span>Private installation · MySQL · UUID identifiers</span>
                        </div>
                    </div>
                    <p className="relative text-xs text-white/45">Self-hosted edition</p>
                </aside>

                <main className="flex items-center justify-center px-5 py-10 sm:px-10 lg:px-14">
                    <div className="w-full max-w-xl">
                        <div className="mb-9 lg:hidden">
                            <div className="flex items-center gap-3">
                                <span className="grid size-10 place-items-center rounded-xl bg-[#17352d] text-[#d6f36a]">
                                    <Building2 size={20} />
                                </span>
                                <div className="text-sm font-bold uppercase tracking-[0.12em]">REMS</div>
                            </div>
                        </div>
                        <div className="mb-8">
                            <p className="text-xs font-semibold uppercase tracking-[0.16em] text-[#5b756d]">Initial configuration</p>
                            <h2 className="mt-3 font-display text-3xl leading-tight sm:text-4xl">Create your company workspace</h2>
                            <p className="mt-3 max-w-lg text-sm leading-6 text-[#61736d]">This one-time setup creates the primary company and its owner account. Additional companies and branches can be added afterward.</p>
                        </div>

                        <form onSubmit={submit} className="space-y-7">
                            <section className="space-y-4">
                                <h3 className="border-b border-[#dce3db] pb-3 text-sm font-semibold text-[#29483e]">Company details</h3>
                                <div>
                                    <label htmlFor="organization_name" className="field-label">Company name</label>
                                    <input id="organization_name" value={data.organization_name} onChange={(event) => setData('organization_name', event.target.value)} className="field-input" autoComplete="organization" autoFocus />
                                    <InputError message={errors.organization_name} className="mt-2" />
                                </div>
                                <div className="grid gap-4 sm:grid-cols-3">
                                    <div>
                                        <label htmlFor="country" className="field-label">Country code</label>
                                        <input id="country" value={data.country} onChange={(event) => setData('country', event.target.value.toUpperCase())} className="field-input" maxLength={2} />
                                        <InputError message={errors.country} className="mt-2" />
                                    </div>
                                    <div>
                                        <label htmlFor="currency" className="field-label">Currency</label>
                                        <input id="currency" value={data.currency} onChange={(event) => setData('currency', event.target.value.toUpperCase())} className="field-input" maxLength={3} />
                                        <InputError message={errors.currency} className="mt-2" />
                                    </div>
                                    <div>
                                        <label htmlFor="timezone" className="field-label">Time zone</label>
                                        <input id="timezone" value={data.timezone} onChange={(event) => setData('timezone', event.target.value)} className="field-input" />
                                        <InputError message={errors.timezone} className="mt-2" />
                                    </div>
                                </div>
                            </section>

                            <section className="space-y-4">
                                <h3 className="border-b border-[#dce3db] pb-3 text-sm font-semibold text-[#29483e]">First administrator</h3>
                                <div>
                                    <label htmlFor="administrator_name" className="field-label">Full name</label>
                                    <input id="administrator_name" value={data.administrator_name} onChange={(event) => setData('administrator_name', event.target.value)} className="field-input" autoComplete="name" />
                                    <InputError message={errors.administrator_name} className="mt-2" />
                                </div>
                                <div>
                                    <label htmlFor="administrator_email" className="field-label">Work email</label>
                                    <input id="administrator_email" type="email" value={data.administrator_email} onChange={(event) => setData('administrator_email', event.target.value)} className="field-input" autoComplete="email" />
                                    <InputError message={errors.administrator_email} className="mt-2" />
                                </div>
                                <div className="grid gap-4 sm:grid-cols-2">
                                    <div>
                                        <label htmlFor="password" className="field-label">Password</label>
                                        <input id="password" type="password" value={data.password} onChange={(event) => setData('password', event.target.value)} className="field-input" autoComplete="new-password" />
                                        <InputError message={errors.password} className="mt-2" />
                                    </div>
                                    <div>
                                        <label htmlFor="password_confirmation" className="field-label">Confirm password</label>
                                        <input id="password_confirmation" type="password" value={data.password_confirmation} onChange={(event) => setData('password_confirmation', event.target.value)} className="field-input" autoComplete="new-password" />
                                    </div>
                                </div>
                            </section>

                            {canSeedDemoData && (
                                <section className="space-y-3 rounded-xl border border-[#dce3db] bg-white/70 p-4">
                                    <label htmlFor="populate_demo_data" className="flex cursor-pointer items-start gap-3">
                                        <input
                                            id="populate_demo_data"
                                            type="checkbox"
                                            checked={data.populate_demo_data}
                                            onChange={(event) => setData('populate_demo_data', event.target.checked)}
                                            className="mt-1 size-4 rounded border-[#aab8ad] text-[#17352d] focus:ring-[#8ba344]"
                                        />
                                        <span>
                                            <span className="block text-sm font-semibold text-[#29483e]">Populate with development demo data</span>
                                            <span className="mt-1 block text-xs leading-5 text-[#61736d]">
                                                Add synthetic clients, projects, property units, sales, rent, inspections, maintenance records, and a branch for local testing.
                                            </span>
                                        </span>
                                    </label>
                                    <InputError message={errors.populate_demo_data} />
                                </section>
                            )}

                            <button type="submit" disabled={processing} className="inline-flex min-h-12 w-full items-center justify-center gap-2 rounded-lg bg-[#17352d] px-5 text-sm font-semibold text-white transition hover:bg-[#244b40] focus:outline-none focus:ring-2 focus:ring-[#8ba344] focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-60">
                                {processing ? 'Creating workspace…' : 'Create workspace'}
                                {!processing && <ArrowRight size={17} />}
                            </button>
                            <p className="text-center text-xs text-[#73827d]">Use at least 12 characters for the administrator password.</p>
                        </form>
                    </div>
                </main>
            </div>
        </div>
    );
}
