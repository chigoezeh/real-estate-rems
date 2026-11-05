import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import InputError from '@/Components/InputError';
import { Head, useForm } from '@inertiajs/react';
import { Check, Clock3, FileCheck2, FileText, ReceiptText, Upload, X } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

type Payment = {
    id: string;
    currency: string;
    subscriptionNumber: string;
    clientName: string;
    category: string;
    installmentNumber: number;
    amount: string;
    paymentMethod: string;
    transactionReference: string | null;
    status: string;
    rejectionReason: string | null;
    submittedBy: string | null;
    reviewedBy: string | null;
    createdAt: string | null;
    reviewedAt: string | null;
};
type Schedule = {
    id: string;
    currency: string;
    subscriptionNumber: string;
    clientName: string;
    category: string;
    installmentNumber: number;
    dueDate: string;
    amount: string;
    amountPaid: string;
    outstanding: string;
};
type PaymentForm = {
    installment_schedule_id: string;
    amount: string;
    payment_method: 'bank_transfer' | 'cash_deposit' | 'cheque' | 'other';
    transaction_reference: string;
    proof_document: File | null;
};
type ReviewForm = { decision: 'approved' | 'rejected'; rejection_reason: string };
type Props = { payments: Payment[]; schedules: Schedule[]; canSubmit: boolean; canReview: boolean };

const formatMoney = (amount: string | number, currency: string): string => new Intl.NumberFormat('en-NG', {
    style: 'currency', currency, maximumFractionDigits: 2,
}).format(Number(amount));
const label = (value: string): string => value.replaceAll('_', ' ');

export default function Index({ payments, schedules, canSubmit, canReview }: Props) {
    const [isFormOpen, setIsFormOpen] = useState(false);
    const [reviewing, setReviewing] = useState<Payment | null>(null);
    const paymentForm = useForm<PaymentForm>({ installment_schedule_id: '', amount: '', payment_method: 'bank_transfer', transaction_reference: '', proof_document: null });
    const reviewForm = useForm<ReviewForm>({ decision: 'approved', rejection_reason: '' });
    const pendingCount = payments.filter((payment) => payment.status === 'pending').length;
    const approvedTotal = payments.filter((payment) => payment.status === 'approved').reduce((total, payment) => total + Number(payment.amount), 0);
    const selectedSchedule = schedules.find((schedule) => schedule.id === paymentForm.data.installment_schedule_id);

    const closeForm = () => {
        setIsFormOpen(false);
        paymentForm.reset();
        paymentForm.clearErrors();
    };
    const submitPayment: FormEventHandler = (event) => {
        event.preventDefault();
        paymentForm.post(route('payments.store'), { forceFormData: true, onSuccess: closeForm });
    };
    const openReview = (payment: Payment, decision: ReviewForm['decision']) => {
        setReviewing(payment);
        reviewForm.setData({ decision, rejection_reason: '' });
        reviewForm.clearErrors();
    };
    const submitReview: FormEventHandler = (event) => {
        event.preventDefault();
        if (!reviewing) return;
        reviewForm.patch(route('payments.review', reviewing.id), { onSuccess: () => setReviewing(null) });
    };

    return (
        <AuthenticatedLayout>
            <Head title="Payments" />
            <div className="space-y-8">
                <div className="flex flex-col gap-5 border-b border-[#dfe5df] pb-6 sm:flex-row sm:items-end sm:justify-between">
                    <div><p className="eyebrow">Collections & reconciliation</p><h1 className="page-title">Payments</h1><p className="mt-2 max-w-2xl text-sm leading-6 text-[#64756e]">Review submitted payment evidence and reconcile each amount against its scheduled fee.</p></div>
                    {canSubmit && <button type="button" onClick={() => setIsFormOpen(true)} disabled={schedules.length === 0} className="inline-flex h-11 items-center justify-center gap-2 rounded-lg bg-[#17352d] px-4 text-sm font-semibold text-white transition hover:bg-[#244b40] disabled:cursor-not-allowed disabled:opacity-50"><Upload size={16} /> Record payment</button>}
                </div>

                <div className="grid gap-6 border-b border-[#dfe5df] pb-6 sm:grid-cols-3">
                    <div className="flex items-center gap-3"><span className="grid size-10 place-items-center rounded-lg bg-[#e7eee1] text-[#315447]"><ReceiptText size={18} /></span><div><p className="text-xs text-[#73827d]">Payment records</p><p className="mt-1 text-lg font-semibold text-[#233c33]">{payments.length}</p></div></div>
                    <div className="flex items-center gap-3"><span className="grid size-10 place-items-center rounded-lg bg-[#f7f1e4] text-[#8a672c]"><Clock3 size={18} /></span><div><p className="text-xs text-[#73827d]">Awaiting review</p><p className="mt-1 text-lg font-semibold text-[#233c33]">{pendingCount}</p></div></div>
                    <div className="flex items-center gap-3"><span className="grid size-10 place-items-center rounded-lg bg-[#e5eef0] text-[#41636b]"><FileCheck2 size={18} /></span><div><p className="text-xs text-[#73827d]">Approved records</p><p className="mt-1 text-lg font-semibold text-[#233c33]">{formatMoney(approvedTotal, payments[0]?.currency ?? schedules[0]?.currency ?? 'NGN')}</p></div></div>
                </div>

                {canSubmit && schedules.length === 0 && <div className="border-l-2 border-[#8ba344] bg-white px-5 py-4 text-sm text-[#53675e]">There are no installment balances available for payment submission.</div>}

                <div className="overflow-x-auto rounded-lg border border-[#dfe5df] bg-white">
                    <table className="w-full min-w-[1000px] text-left">
                        <thead className="bg-[#f8faf6] text-[11px] font-semibold uppercase tracking-[0.12em] text-[#77857f]"><tr><th className="px-5 py-3 sm:px-7">Client / contract</th><th className="px-5 py-3">Fee schedule</th><th className="px-5 py-3">Submitted</th><th className="px-5 py-3 text-right">Amount</th><th className="px-5 py-3">Status</th><th className="px-5 py-3 text-right sm:px-7">Review</th></tr></thead>
                        <tbody className="divide-y divide-[#edf0ec]">
                            {payments.map((payment) => <tr key={payment.id} className="align-top hover:bg-[#fbfcfa]">
                                <td className="px-5 py-4 sm:px-7"><p className="text-sm font-semibold text-[#233c33]">{payment.clientName}</p><p className="mt-1 text-xs text-[#7b8983]">{payment.subscriptionNumber}</p></td>
                                <td className="px-5 py-4"><p className="text-sm capitalize text-[#4c6259]">{label(payment.category)} · #{payment.installmentNumber}</p><p className="mt-1 text-xs text-[#7b8983]">{label(payment.paymentMethod)}{payment.transactionReference ? ` · ${payment.transactionReference}` : ''}</p></td>
                                <td className="px-5 py-4"><p className="text-sm text-[#4c6259]">{payment.createdAt ? new Date(payment.createdAt).toLocaleDateString() : '—'}</p><a href={route('payments.proof', payment.id)} className="mt-1 inline-flex items-center gap-1 text-xs font-medium text-[#42685a] hover:text-[#17352d]"><FileText size={13} /> View proof</a></td>
                                <td className="px-5 py-4 text-right text-sm font-semibold text-[#29483e]">{formatMoney(payment.amount, payment.currency)}</td>
                                <td className="px-5 py-4"><span className={`inline-flex rounded-full px-2.5 py-1 text-xs font-medium capitalize ${payment.status === 'approved' ? 'bg-[#edf4eb] text-[#315447]' : payment.status === 'rejected' ? 'bg-[#fae9e5] text-[#9a4332]' : 'bg-[#f7f1e4] text-[#8a672c]'}`}>{payment.status}</span>{payment.rejectionReason && <p className="mt-1 max-w-48 text-xs text-[#8c5144]">{payment.rejectionReason}</p>}</td>
                                <td className="px-5 py-4 text-right sm:px-7">{canReview && payment.status === 'pending' ? <div className="inline-flex items-center gap-2"><button type="button" onClick={() => openReview(payment, 'approved')} title="Approve payment" aria-label="Approve payment" className="grid size-9 place-items-center rounded-lg bg-[#edf4eb] text-[#315447] hover:bg-[#dfead7]"><Check size={17} /></button><button type="button" onClick={() => openReview(payment, 'rejected')} title="Reject payment" aria-label="Reject payment" className="grid size-9 place-items-center rounded-lg bg-[#fae9e5] text-[#9a4332] hover:bg-[#f5d9d2]"><X size={17} /></button></div> : <span className="text-xs text-[#7b8983]">{payment.reviewedBy ?? payment.submittedBy ?? '—'}</span>}</td>
                            </tr>)}
                        </tbody>
                    </table>
                    {payments.length === 0 && <div className="border-t border-[#edf0ec] px-5 py-12 text-center"><ReceiptText size={22} className="mx-auto text-[#8a9791]" /><p className="mt-3 text-sm font-medium text-[#29483e]">No payments have been submitted yet.</p></div>}
                </div>
            </div>

            {isFormOpen && <div className="fixed inset-0 z-50 flex items-center justify-center bg-[#10251f]/45 px-4 py-6" role="presentation" onMouseDown={(event) => event.target === event.currentTarget && closeForm()}>
                <section role="dialog" aria-modal="true" aria-labelledby="payment-dialog-title" className="max-h-full w-full max-w-xl overflow-y-auto rounded-xl bg-white p-6 shadow-2xl sm:p-8">
                    <div className="flex items-start justify-between gap-5"><div><p className="eyebrow">Payment evidence</p><h2 id="payment-dialog-title" className="mt-2 font-display text-2xl text-[#18342c]">Record payment</h2></div><button type="button" onClick={closeForm} aria-label="Close dialog" className="grid size-9 place-items-center rounded-lg text-[#6d7d76] hover:bg-[#f1f4ef]"><X size={18} /></button></div>
                    <form onSubmit={submitPayment} className="mt-7 space-y-5">
                        <div><label htmlFor="installment_schedule_id" className="field-label">Installment</label><select id="installment_schedule_id" value={paymentForm.data.installment_schedule_id} onChange={(event) => paymentForm.setData('installment_schedule_id', event.target.value)} className="field-input"><option value="">Select a scheduled fee</option>{schedules.map((schedule) => <option key={schedule.id} value={schedule.id}>{schedule.clientName} · {schedule.subscriptionNumber} · {label(schedule.category)} #{schedule.installmentNumber} · outstanding {formatMoney(schedule.outstanding, schedule.currency)}</option>)}</select><InputError message={paymentForm.errors.installment_schedule_id} className="mt-2" /></div>
                        {selectedSchedule && <p className="rounded-lg bg-[#f8faf6] px-4 py-3 text-sm text-[#53675e]">Outstanding balance: <strong className="text-[#29483e]">{formatMoney(selectedSchedule.outstanding, selectedSchedule.currency)}</strong></p>}
                        <div className="grid gap-4 sm:grid-cols-2"><div><label htmlFor="amount" className="field-label">Amount</label><input id="amount" type="number" min="0.01" max={selectedSchedule?.outstanding} step="0.01" value={paymentForm.data.amount} onChange={(event) => paymentForm.setData('amount', event.target.value)} className="field-input" /><InputError message={paymentForm.errors.amount} className="mt-2" /></div><div><label htmlFor="payment_method" className="field-label">Payment method</label><select id="payment_method" value={paymentForm.data.payment_method} onChange={(event) => paymentForm.setData('payment_method', event.target.value as PaymentForm['payment_method'])} className="field-input"><option value="bank_transfer">Bank transfer</option><option value="cash_deposit">Cash deposit</option><option value="cheque">Cheque</option><option value="other">Other</option></select><InputError message={paymentForm.errors.payment_method} className="mt-2" /></div></div>
                        <div><label htmlFor="transaction_reference" className="field-label">Transaction reference</label><input id="transaction_reference" value={paymentForm.data.transaction_reference} onChange={(event) => paymentForm.setData('transaction_reference', event.target.value)} className="field-input" /><InputError message={paymentForm.errors.transaction_reference} className="mt-2" /></div>
                        <div><label htmlFor="proof_document" className="field-label">Proof document</label><input id="proof_document" type="file" accept=".pdf,.jpg,.jpeg,.png" onChange={(event) => paymentForm.setData('proof_document', event.target.files?.[0] ?? null)} className="field-input file:mr-3 file:border-0 file:bg-transparent file:text-sm file:font-medium file:text-[#42685a]" /><p className="mt-1 text-xs text-[#7b8983]">PDF or image, up to 5 MB.</p><InputError message={paymentForm.errors.proof_document} className="mt-2" /></div>
                        <div className="flex justify-end gap-3 border-t border-[#e7ece6] pt-5"><button type="button" onClick={closeForm} className="h-10 rounded-lg px-4 text-sm font-medium text-[#5f7169] hover:bg-[#f3f5ef]">Cancel</button><button type="submit" disabled={paymentForm.processing || !paymentForm.data.installment_schedule_id || !paymentForm.data.proof_document} className="h-10 rounded-lg bg-[#17352d] px-4 text-sm font-semibold text-white hover:bg-[#244b40] disabled:opacity-60">{paymentForm.processing ? 'Submitting…' : 'Submit for review'}</button></div>
                    </form>
                </section>
            </div>}

            {reviewing && <div className="fixed inset-0 z-50 flex items-center justify-center bg-[#10251f]/45 px-4 py-6" role="presentation" onMouseDown={(event) => event.target === event.currentTarget && setReviewing(null)}>
                <section role="dialog" aria-modal="true" aria-labelledby="review-dialog-title" className="w-full max-w-lg rounded-xl bg-white p-6 shadow-2xl sm:p-8">
                    <div className="flex items-start justify-between gap-5"><div><p className="eyebrow">Payment review</p><h2 id="review-dialog-title" className="mt-2 font-display text-2xl capitalize text-[#18342c]">{reviewForm.data.decision} payment</h2></div><button type="button" onClick={() => setReviewing(null)} aria-label="Close dialog" className="grid size-9 place-items-center rounded-lg text-[#6d7d76] hover:bg-[#f1f4ef]"><X size={18} /></button></div>
                    <p className="mt-5 text-sm leading-6 text-[#64756e]">{reviewing.clientName} · {reviewing.subscriptionNumber} · {formatMoney(reviewing.amount, reviewing.currency)}</p>
                    <form onSubmit={submitReview} className="mt-6 space-y-5">
                        {reviewForm.data.decision === 'rejected' && <div><label htmlFor="rejection_reason" className="field-label">Reason for rejection</label><textarea id="rejection_reason" rows={4} value={reviewForm.data.rejection_reason} onChange={(event) => reviewForm.setData('rejection_reason', event.target.value)} className="field-input" /><InputError message={reviewForm.errors.rejection_reason} className="mt-2" /></div>}
                        <div className="flex justify-end gap-3 border-t border-[#e7ece6] pt-5"><button type="button" onClick={() => setReviewing(null)} className="h-10 rounded-lg px-4 text-sm font-medium text-[#5f7169] hover:bg-[#f3f5ef]">Cancel</button><button type="submit" disabled={reviewForm.processing || (reviewForm.data.decision === 'rejected' && !reviewForm.data.rejection_reason.trim())} className={`h-10 rounded-lg px-4 text-sm font-semibold text-white disabled:opacity-60 ${reviewForm.data.decision === 'approved' ? 'bg-[#315447] hover:bg-[#244b40]' : 'bg-[#9a4332] hover:bg-[#803829]'}`}>{reviewForm.processing ? 'Saving…' : `Confirm ${reviewForm.data.decision}`}</button></div>
                    </form>
                </section>
            </div>}
        </AuthenticatedLayout>
    );
}
