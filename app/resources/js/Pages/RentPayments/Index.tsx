import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import InputError from '@/Components/InputError';
import { Head, router, useForm } from '@inertiajs/react';
import { BellRing, Check, Download, ReceiptText, WalletCards, X } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

type Schedule = {
    id: string;
    tenancyNumber: string;
    tenantName: string;
    property: string;
    category: string;
    chargeName: string | null;
    dueOn: string;
    baseAmountDue: string;
    lateFeeAmount: string;
    amountDue: string;
    amountPaid: string;
    pendingAmount: string;
    arrearsAmount: string;
    availableAmount: string;
    currency: string;
    canSubmit: boolean;
    canNotify: boolean;
    contactCount: number;
    lastContactAt: string | null;
    lastContactChannel: string | null;
};
type Payment = {
    id: string;
    tenancyNumber: string;
    tenantName: string;
    property: string;
    amount: string;
    currency: string;
    channel: string;
    reference: string | null;
    status: string;
    rejectionReason: string | null;
    submittedBy: string | null;
    reviewedBy: string | null;
    proofUrl: string | null;
    canReview: boolean;
    createdAt: string | null;
};
type FormData = { rent_schedule_id: string; amount: string; payment_channel: string; transaction_reference: string; proof_document: File | null };
type Props = { schedules: Schedule[]; payments: Payment[]; canSubmit: boolean; canReview: boolean };

const formatMoney = (amount: string, currency: string) => new Intl.NumberFormat(undefined, {
    style: 'currency',
    currency,
    maximumFractionDigits: 2,
}).format(Number(amount));

export default function Index({ schedules, payments, canSubmit, canReview }: Props) {
    const [contactingScheduleId, setContactingScheduleId] = useState<string | null>(null);
    const submittableSchedules = schedules.filter((schedule) => schedule.canSubmit && schedule.availableAmount !== '0.00');
    const form = useForm<FormData>({
        rent_schedule_id: submittableSchedules[0]?.id ?? '',
        amount: submittableSchedules[0]?.availableAmount ?? '',
        payment_channel: 'bank_transfer',
        transaction_reference: '',
        proof_document: null,
    });
    const contactForm = useForm({ channel: 'phone', notes: '' });
    const selectedSchedule = schedules.find((schedule) => schedule.id === form.data.rent_schedule_id);

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        form.post(route('rent-payments.store'), {
            forceFormData: true,
            onSuccess: () => {
                form.reset('amount', 'transaction_reference', 'proof_document');
            },
        });
    };

    const review = (payment: Payment, decision: 'approved' | 'rejected') => {
        const rejectionReason = decision === 'rejected' ? window.prompt('Reason for rejecting this payment?') : null;
        if (decision === 'rejected' && !rejectionReason?.trim()) return;
        router.patch(route('rent-payments.review', payment.id), {
            decision,
            rejection_reason: rejectionReason,
        }, { preserveScroll: true });
    };

    const submitContact: FormEventHandler = (event) => {
        event.preventDefault();
        if (!contactingScheduleId) return;
        contactForm.post(route('rent-schedules.contact-logs.store', contactingScheduleId), {
            preserveScroll: true,
            onSuccess: () => {
                setContactingScheduleId(null);
                contactForm.reset();
            },
        });
    };

    return (
        <AuthenticatedLayout>
            <Head title="Rent payments" />
            <div className="space-y-8">
                <div className="border-b border-[#dfe5df] pb-6"><p className="eyebrow">Rent accounting</p><h1 className="page-title">Rent payments</h1><p className="mt-2 max-w-2xl text-sm leading-6 text-[#64756e]">Submit receipts against scheduled rent charges and review them before they affect balances.</p></div>

                {canReview && <div className="flex flex-col gap-3 border border-[#dfe5df] bg-white px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                    <p className="text-sm text-[#64756e]">Assess due late fees under active company policies. The assessment is idempotent and does not send tenant messages.</p>
                    <button type="button" onClick={() => router.post(route('rent-payments.late-fees.assess'), {}, { preserveScroll: true })} className="inline-flex h-10 shrink-0 items-center justify-center gap-2 rounded-lg bg-[#17352d] px-4 text-sm font-semibold text-white hover:bg-[#244b40]"><BellRing size={16} />Assess late fees</button>
                </div>}

                {canSubmit && submittableSchedules.length > 0 && (
                    <form onSubmit={submit} className="grid gap-4 border border-[#dfe5df] bg-white p-5 sm:grid-cols-2 lg:grid-cols-4 sm:p-7">
                        <div className="sm:col-span-2"><label htmlFor="rent-schedule" className="field-label">Rent charge</label><select id="rent-schedule" required value={form.data.rent_schedule_id} onChange={(event) => {
                            const schedule = schedules.find((item) => item.id === event.target.value);
                            form.setData((current) => ({ ...current, rent_schedule_id: event.target.value, amount: schedule?.availableAmount ?? '' }));
                        }} className="field-input">
                            {submittableSchedules.map((schedule) => <option key={schedule.id} value={schedule.id}>{schedule.tenancyNumber} · {schedule.tenantName} · {schedule.chargeName ?? schedule.category.replaceAll('_', ' ')} · due {schedule.dueOn} · outstanding {formatMoney(schedule.availableAmount, schedule.currency)}</option>)}
                        </select><InputError message={form.errors.rent_schedule_id} className="mt-2" /></div>
                        <div><label htmlFor="rent-amount" className="field-label">Amount</label><input id="rent-amount" type="number" min="0.01" max={selectedSchedule?.availableAmount} step="0.01" required value={form.data.amount} onChange={(event) => form.setData('amount', event.target.value)} className="field-input" /><InputError message={form.errors.amount} className="mt-2" /></div>
                        <div><label htmlFor="rent-channel" className="field-label">Payment channel</label><select id="rent-channel" value={form.data.payment_channel} onChange={(event) => form.setData('payment_channel', event.target.value)} className="field-input"><option value="bank_transfer">Bank transfer</option><option value="cash_deposit">Cash deposit</option><option value="cheque">Cheque</option><option value="other">Other</option></select><InputError message={form.errors.payment_channel} className="mt-2" /></div>
                        <div><label htmlFor="rent-reference" className="field-label">Transaction reference</label><input id="rent-reference" value={form.data.transaction_reference} onChange={(event) => form.setData('transaction_reference', event.target.value)} className="field-input" /><InputError message={form.errors.transaction_reference} className="mt-2" /></div>
                        <div><label htmlFor="rent-proof" className="field-label">Proof document</label><input id="rent-proof" type="file" accept=".pdf,.jpg,.jpeg,.png" required onChange={(event) => form.setData('proof_document', event.target.files?.[0] ?? null)} className="field-input file:mr-3 file:rounded-md file:border-0 file:bg-[#edf4eb] file:px-3 file:py-1.5 file:text-xs file:font-semibold file:text-[#315447]" /><InputError message={form.errors.proof_document} className="mt-2" /></div>
                        <div className="flex items-end"><button type="submit" disabled={form.processing || !form.data.rent_schedule_id} className="inline-flex h-11 items-center gap-2 rounded-lg bg-[#17352d] px-4 text-sm font-semibold text-white hover:bg-[#244b40] disabled:opacity-50"><ReceiptText size={16} />{form.processing ? 'Submitting…' : 'Submit payment'}</button></div>
                    </form>
                )}

                <section className="space-y-3">
                    <div className="flex items-center gap-2 text-[#315447]"><WalletCards size={18} /><h2 className="font-display text-xl text-[#18342c]">Open rent charges</h2></div>
                    <p className="text-sm text-[#64756e]">Overdue balances count approved receipts only. Pending receipts remain in arrears until reviewed and are shown separately.</p>
                    <div className="overflow-x-auto border border-[#dfe5df] bg-white"><table className="w-full min-w-[1200px] text-left">
                        <thead className="bg-[#f8faf6] text-[11px] font-semibold uppercase tracking-[0.12em] text-[#77857f]"><tr><th className="px-5 py-3">Tenant / property</th><th className="px-5 py-3">Charge</th><th className="px-5 py-3">Due date</th><th className="px-5 py-3 text-right">Total due</th><th className="px-5 py-3 text-right">Paid</th><th className="px-5 py-3 text-right">Overdue balance</th><th className="px-5 py-3 text-right">Available to collect</th><th className="px-5 py-3">Follow-up</th></tr></thead>
                        <tbody className="divide-y divide-[#edf0ec]">{schedules.map((schedule) => <tr key={schedule.id}><td className="px-5 py-4"><p className="text-sm font-medium text-[#29483e]">{schedule.tenantName}</p><p className="mt-1 text-xs text-[#7b8983]">{schedule.property} · {schedule.tenancyNumber}</p></td><td className="px-5 py-4 text-sm capitalize text-[#4c6259]">{schedule.chargeName ?? schedule.category.replaceAll('_', ' ')}{Number(schedule.lateFeeAmount) > 0 && <span className="mt-1 block text-xs text-[#9a4f3d]">Late fees {formatMoney(schedule.lateFeeAmount, schedule.currency)}</span>}</td><td className="px-5 py-4 text-sm text-[#4c6259]">{schedule.dueOn}{Number(schedule.arrearsAmount) > 0 && <span className="mt-1 block text-xs font-semibold text-[#9a4f3d]">Overdue</span>}</td><td className="px-5 py-4 text-right text-sm">{formatMoney(schedule.amountDue, schedule.currency)}</td><td className="px-5 py-4 text-right text-sm">{formatMoney(schedule.amountPaid, schedule.currency)}{Number(schedule.pendingAmount) > 0 && <span className="mt-1 block text-xs text-[#9a752f]">{formatMoney(schedule.pendingAmount, schedule.currency)} pending review</span>}</td><td className="px-5 py-4 text-right text-sm font-semibold text-[#9a4f3d]">{Number(schedule.arrearsAmount) > 0 ? formatMoney(schedule.arrearsAmount, schedule.currency) : '-'}</td><td className="px-5 py-4 text-right text-sm font-semibold text-[#29483e]">{formatMoney(schedule.availableAmount, schedule.currency)}</td><td className="px-5 py-4 text-sm text-[#64756e]">{schedule.contactCount > 0 && <p className="text-xs">{schedule.contactCount} logged · {schedule.lastContactChannel} · {schedule.lastContactAt ? new Date(schedule.lastContactAt).toLocaleDateString() : ''}</p>}{schedule.canNotify && Number(schedule.arrearsAmount) > 0 && <button type="button" onClick={() => { setContactingScheduleId(schedule.id); contactForm.clearErrors(); contactForm.setData({ channel: 'phone', notes: '' }); }} className="mt-1 inline-flex h-8 items-center gap-1.5 rounded-lg border border-[#d9e1d9] px-2.5 text-xs font-semibold text-[#315447] hover:bg-[#f1f4ef]"><BellRing size={13} />Log follow-up</button>}</td></tr>)}{schedules.length === 0 && <tr><td colSpan={8} className="px-5 py-10 text-center text-sm text-[#718078]">There are no unpaid rent charges available in your organization scope.</td></tr>}</tbody>
                    </table></div>
                </section>

                {contactingScheduleId && (
                    <form onSubmit={submitContact} className="space-y-4 border border-[#dfe5df] bg-white p-5 sm:p-7">
                        <div className="flex items-start justify-between gap-4"><div><p className="eyebrow">Arrears follow-up</p><h2 className="mt-1 font-display text-xl text-[#18342c]">Log tenant contact</h2><p className="mt-2 text-xs text-[#718078]">This records a contact attempt; it does not send an SMS or email.</p></div><button type="button" onClick={() => setContactingScheduleId(null)} aria-label="Close contact log" className="grid size-9 place-items-center rounded-lg text-[#6d7d76] hover:bg-[#f1f4ef]"><X size={18} /></button></div>
                        <div className="grid gap-4 sm:grid-cols-2">
                            <div><label htmlFor="contact-channel" className="field-label">Contact channel</label><select id="contact-channel" value={contactForm.data.channel} onChange={(event) => contactForm.setData('channel', event.target.value)} className="field-input"><option value="phone">Phone call</option><option value="sms">SMS</option><option value="email">Email</option><option value="whatsapp">WhatsApp</option><option value="in_person">In person</option><option value="other">Other</option></select><InputError message={contactForm.errors.channel} className="mt-2" /></div>
                            <div><label htmlFor="contact-notes" className="field-label">Outcome / notes</label><textarea id="contact-notes" required maxLength={2000} value={contactForm.data.notes} onChange={(event) => contactForm.setData('notes', event.target.value)} className="field-input min-h-20" /><InputError message={contactForm.errors.notes} className="mt-2" /></div>
                        </div>
                        <button type="submit" disabled={contactForm.processing} className="inline-flex h-10 items-center gap-2 rounded-lg bg-[#17352d] px-4 text-sm font-semibold text-white disabled:opacity-50">{contactForm.processing ? 'Saving…' : 'Save contact log'}</button>
                    </form>
                )}

                <section className="space-y-3">
                    <div className="flex items-center gap-2 text-[#315447]"><ReceiptText size={18} /><h2 className="font-display text-xl text-[#18342c]">Payment review history</h2></div>
                    <div className="overflow-x-auto border border-[#dfe5df] bg-white"><table className="w-full min-w-[900px] text-left">
                        <thead className="bg-[#f8faf6] text-[11px] font-semibold uppercase tracking-[0.12em] text-[#77857f]"><tr><th className="px-5 py-3">Submitted</th><th className="px-5 py-3">Tenant</th><th className="px-5 py-3">Reference / channel</th><th className="px-5 py-3 text-right">Amount</th><th className="px-5 py-3">Status</th><th className="px-5 py-3" /></tr></thead>
                        <tbody className="divide-y divide-[#edf0ec]">{payments.map((payment) => <tr key={payment.id}><td className="px-5 py-4"><p className="text-sm font-semibold text-[#233c33]">{payment.tenancyNumber}</p><p className="mt-1 text-xs text-[#7b8983]">{payment.createdAt ? new Date(payment.createdAt).toLocaleDateString() : ''}</p></td><td className="px-5 py-4"><p className="text-sm text-[#29483e]">{payment.tenantName}</p><p className="mt-1 text-xs text-[#7b8983]">{payment.property}</p></td><td className="px-5 py-4"><p className="text-sm text-[#4c6259]">{payment.reference ?? 'No reference'}</p><p className="mt-1 text-xs capitalize text-[#7b8983]">{payment.channel.replaceAll('_', ' ')}</p></td><td className="px-5 py-4 text-right text-sm font-medium">{formatMoney(payment.amount, payment.currency)}</td><td className="px-5 py-4"><span className={`rounded-full px-2.5 py-1 text-xs font-medium ${payment.status === 'approved' ? 'bg-[#edf4eb] text-[#315447]' : payment.status === 'rejected' ? 'bg-[#f8e9e5] text-[#8b4739]' : 'bg-[#f8f2dc] text-[#756128]'}`}>{payment.status}</span>{payment.rejectionReason && <p className="mt-1 max-w-xs text-xs text-[#8b4739]">{payment.rejectionReason}</p>}</td><td className="px-5 py-4 text-right"><div className="flex justify-end gap-2">{payment.proofUrl && <a href={payment.proofUrl} className="grid size-9 place-items-center rounded-lg border border-[#d9e1d9] text-[#53675e]" aria-label="Download payment proof"><Download size={15} /></a>}{canReview && payment.canReview && payment.status === 'pending' && <><button type="button" onClick={() => review(payment, 'approved')} className="grid size-9 place-items-center rounded-lg bg-[#17352d] text-white" aria-label="Approve rent payment"><Check size={15} /></button><button type="button" onClick={() => review(payment, 'rejected')} className="grid size-9 place-items-center rounded-lg border border-[#e5d4ce] text-[#8b4739]" aria-label="Reject rent payment"><X size={15} /></button></>}</div></td></tr>)}{payments.length === 0 && <tr><td colSpan={6} className="px-5 py-10 text-center text-sm text-[#718078]">No rent payments have been submitted.</td></tr>}</tbody>
                    </table></div>
                </section>
            </div>
        </AuthenticatedLayout>
    );
}
