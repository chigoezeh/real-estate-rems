import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import InputError from '@/Components/InputError';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { Boxes, Building2, ChevronDown, Clock3, MapPin, Plus, RotateCcw, Settings2, ShieldCheck, X } from 'lucide-react';
import { FormEventHandler, useMemo, useState } from 'react';

type Organization = { id: string; name: string };
type Project = {
    id: string;
    organizationId: string;
    organizationName: string;
    organizationCurrency: string;
    name: string;
    code: string;
    location: string | null;
    description: string | null;
    titleTypes: string[];
    statusLabels: Record<InventoryStatus, string>;
    reservationHours: number;
    unitsCount: number;
    canManage: boolean;
    canReserve: boolean;
};
type Unit = {
    id: string;
    phase: string;
    block: string;
    code: string;
    unitType: string;
    usage: string | null;
    areaSqm: string | null;
    basePrice: string;
    titleType: string | null;
    titleStatus: string | null;
    rentalStatus: string;
    status: string;
    isCorner: boolean;
    cornerPremiumPercent: string;
    topographyPremiumAmount: string;
    reservedUntil: string | null;
    reservedBy: string | null;
    reservationExpired: boolean;
};
type Props = {
    projects: Project[];
    selectedProjectId: string | null;
    units: Unit[];
    organizations: Organization[];
    canManage: boolean;
    canReserve: boolean;
};
type InventoryStatus = 'available' | 'reserved' | 'under_installment' | 'allocated' | 'sold';
type ProjectForm = {
    organization_id: string;
    name: string;
    code: string;
    location: string;
    description: string;
    title_types: string[];
    status_labels: Record<InventoryStatus, string>;
    reservation_hours: string;
};
type UnitForm = {
    phase: string;
    block: string;
    code: string;
    unit_type: string;
    usage: string;
    area_sqm: string;
    base_price: string;
    title_type: string;
    title_status: string;
    rental_status: 'not_listed' | 'available';
    is_corner: boolean;
    corner_premium_percent: string;
    topography_premium_amount: string;
};

const emptyUnit = (titleType: string): UnitForm => ({
    phase: '',
    block: '',
    code: '',
    unit_type: 'land',
    usage: 'residential',
    area_sqm: '',
    base_price: '',
    title_type: titleType,
    title_status: '',
    rental_status: 'not_listed',
    is_corner: false,
    corner_premium_percent: '0',
    topography_premium_amount: '0',
});

const defaultStatusLabels: Record<InventoryStatus, string> = {
    available: 'Available',
    reserved: 'Reserved',
    under_installment: 'Under installment',
    allocated: 'Allocated',
    sold: 'Sold',
};

export default function Index({ projects, selectedProjectId, units, organizations, canManage, canReserve }: Props) {
    const [isAddingProject, setIsAddingProject] = useState(false);
    const [isAddingUnit, setIsAddingUnit] = useState(false);
    const [editingProjectId, setEditingProjectId] = useState<string | null>(null);
    const selectedProject = projects.find((project) => project.id === selectedProjectId) ?? null;
    const availableCount = units.filter((unit) => unit.status === 'available' || unit.reservationExpired).length;
    const reservedCount = units.filter((unit) => unit.status === 'reserved' && !unit.reservationExpired).length;
    const totalValue = units.reduce((total, unit) => total + Number(unit.basePrice), 0);
    const currencyFormat = useMemo(() => new Intl.NumberFormat(undefined, {
        style: 'currency',
        currency: selectedProject?.organizationCurrency ?? 'NGN',
        maximumFractionDigits: 0,
    }), [selectedProject?.organizationCurrency]);

    const projectForm = useForm<ProjectForm>({
        organization_id: organizations[0]?.id ?? '',
        name: '',
        code: '',
        location: '',
        description: '',
        title_types: ['Certificate of Occupancy', 'Governor Consent', 'Excision'],
        status_labels: defaultStatusLabels,
        reservation_hours: '24',
    });
    const unitForm = useForm<UnitForm>(emptyUnit(selectedProject?.titleTypes[0] ?? ''));

    const openProjectForm = (project?: Project) => {
        projectForm.clearErrors();
        setEditingProjectId(project?.id ?? null);
        projectForm.setData({
            organization_id: project?.organizationId ?? organizations[0]?.id ?? '',
            name: project?.name ?? '',
            code: project?.code ?? '',
            location: project?.location ?? '',
            description: project?.description ?? '',
            title_types: project?.titleTypes ?? ['Certificate of Occupancy', 'Governor Consent', 'Excision'],
            status_labels: project?.statusLabels ?? defaultStatusLabels,
            reservation_hours: String(project?.reservationHours ?? 24),
        });
        setIsAddingProject(true);
    };

    const closeProjectForm = () => {
        setIsAddingProject(false);
        setEditingProjectId(null);
    };

    const submitProject: FormEventHandler = (event) => {
        event.preventDefault();
        const options = {
            onSuccess: () => {
                closeProjectForm();
            },
        };
        if (editingProjectId) {
            projectForm.put(route('projects.update', editingProjectId), options);
        } else {
            projectForm.post(route('projects.store'), options);
        }
    };

    const submitUnit: FormEventHandler = (event) => {
        event.preventDefault();
        if (!selectedProject) return;
        unitForm.post(route('projects.units.store', selectedProject.id), {
            onSuccess: () => {
                setIsAddingUnit(false);
                unitForm.reset();
                unitForm.setData(emptyUnit(selectedProject.titleTypes[0] ?? ''));
            },
        });
    };

    const reserve = (unit: Unit) => router.post(route('inventory.reserve', unit.id), {}, { preserveScroll: true });
    const release = (unit: Unit) => router.post(route('inventory.release', unit.id), {}, { preserveScroll: true });
    const statusClass = (unit: Unit) => unit.status === 'available' || unit.reservationExpired
        ? 'bg-[#edf4eb] text-[#315447]'
        : unit.status === 'reserved'
            ? 'bg-[#f8f2dc] text-[#756128]'
            : 'bg-[#f1ecea] text-[#76554d]';

    return (
        <AuthenticatedLayout>
            <Head title="Property inventory" />
            <div className="space-y-8">
                <div className="flex flex-col gap-5 border-b border-[#dfe5df] pb-6 sm:flex-row sm:items-end sm:justify-between">
                    <div>
                        <p className="eyebrow">Portfolio operations</p>
                        <h1 className="page-title">Property inventory</h1>
                        <p className="mt-2 max-w-2xl text-sm leading-6 text-[#64756e]">Track land, buildings, and other units across your projects.</p>
                    </div>
                    {canManage && (
                        <div className="flex flex-wrap gap-2">
                            {selectedProject && selectedProject.canManage && <button type="button" onClick={() => { unitForm.clearErrors(); unitForm.setData(emptyUnit(selectedProject.titleTypes[0] ?? '')); setIsAddingUnit(true); }} className="inline-flex h-11 items-center justify-center gap-2 rounded-lg border border-[#cbd8ce] bg-white px-4 text-sm font-semibold text-[#29483e] transition hover:bg-[#f9fbf8]"><Plus size={17} /> Add unit</button>}
                            {selectedProject && selectedProject.canManage && <button type="button" onClick={() => openProjectForm(selectedProject)} className="grid size-11 place-items-center rounded-lg border border-[#cbd8ce] bg-white text-[#29483e] transition hover:bg-[#f9fbf8]" aria-label="Project settings" title="Project settings"><Settings2 size={17} /></button>}
                            {canManage && <button type="button" onClick={() => openProjectForm()} disabled={organizations.length === 0} className="inline-flex h-11 items-center justify-center gap-2 rounded-lg bg-[#17352d] px-4 text-sm font-semibold text-white transition hover:bg-[#244b40] disabled:opacity-50"><Building2 size={17} /> New project</button>}
                        </div>
                    )}
                </div>

                {projects.length > 0 ? (
                    <>
                        <div className="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                            <div className="min-w-0 flex-1 sm:max-w-md">
                                <label htmlFor="project-select" className="field-label">Project</label>
                                <div className="relative">
                                    <select id="project-select" value={selectedProjectId ?? ''} onChange={(event) => router.get(route('inventory.index'), { project_id: event.target.value })} className="field-input appearance-none pr-10">
                                        {projects.map((project) => <option key={project.id} value={project.id}>{project.name} · {project.code}</option>)}
                                    </select>
                                    <ChevronDown size={16} className="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-[#718078]" />
                                </div>
                            </div>
                            {selectedProject?.location && <p className="flex items-center gap-2 text-sm text-[#687970]"><MapPin size={16} /> {selectedProject.location}</p>}
                        </div>

                        <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                            <Summary label="Total units" value={String(units.length)} icon={Boxes} />
                            <Summary label="Available" value={String(availableCount)} icon={ShieldCheck} />
                            <Summary label="Reserved" value={String(reservedCount)} icon={Clock3} />
                            <Summary label="Listed base value" value={currencyFormat.format(totalValue)} icon={Building2} />
                        </div>

                        <div className="overflow-x-auto rounded-xl border border-[#dfe5df] bg-white">
                            <div className="min-w-[1040px]">
                                <div className="grid grid-cols-[minmax(190px,1.3fr)_90px_110px_110px_minmax(130px,0.9fr)_150px_150px_110px] gap-3 border-b border-[#e7ece6] bg-[#f8faf6] px-5 py-3 text-[11px] font-semibold uppercase tracking-[0.12em] text-[#77857f]">
                                    <span>Unit</span><span>Type</span><span>Area</span><span>Base price</span><span>Title</span><span>Premiums</span><span>Status</span><span />
                                </div>
                                <div className="divide-y divide-[#edf0ec]">
                                    {units.map((unit) => (
                                        <div key={unit.id} className="grid min-h-[72px] grid-cols-[minmax(190px,1.3fr)_90px_110px_110px_minmax(130px,0.9fr)_150px_150px_110px] items-center gap-3 px-5 py-3">
                                            <div className="min-w-0">
                                                <p className="truncate text-sm font-semibold text-[#233c33]">{unit.code}</p>
                                                <p className="mt-1 truncate text-xs text-[#7b8983]">{[unit.phase, unit.block].filter(Boolean).join(' · ') || selectedProject?.name}</p>
                                            </div>
                                            <span className="truncate text-sm capitalize text-[#4c6259]">{unit.unitType}</span>
                                            <span className="text-sm tabular-nums text-[#4c6259]">{unit.areaSqm ? `${Number(unit.areaSqm).toLocaleString()} m²` : '—'}</span>
                                            <span className="text-sm tabular-nums text-[#4c6259]">{currencyFormat.format(Number(unit.basePrice))}</span>
                                            <span className="truncate text-sm text-[#4c6259]">{unit.titleType ?? '—'}{unit.titleStatus ? <span className="mt-1 block truncate text-xs text-[#89958e]">{unit.titleStatus}</span> : null}<span className="mt-1 block truncate text-xs text-[#89958e]">Rent: {unit.rentalStatus === 'available' ? 'Available' : unit.rentalStatus === 'occupied' ? 'Occupied' : 'Not listed'}</span></span>
                                            <span className="text-xs leading-5 text-[#65776e]">{unit.isCorner ? `Corner +${unit.cornerPremiumPercent}%` : 'None'}{Number(unit.topographyPremiumAmount) > 0 ? <span className="block">Topography {currencyFormat.format(Number(unit.topographyPremiumAmount))}</span> : null}</span>
                                            <div>
                                                <span className={`inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium ${statusClass(unit)}`}>
                                                    <span className="size-1.5 rounded-full bg-current" />
                                                    {unit.reservationExpired ? 'Hold expired' : selectedProject?.statusLabels[unit.status as InventoryStatus] ?? unit.status.replaceAll('_', ' ')}
                                                </span>
                                                {unit.reservedBy && !unit.reservationExpired && <p className="mt-1 truncate text-xs text-[#89958e]">Held by {unit.reservedBy}</p>}
                                                {unit.reservedUntil && !unit.reservationExpired && <p className="mt-1 truncate text-xs text-[#89958e]">Expires {new Date(unit.reservedUntil).toLocaleString(undefined, { dateStyle: 'short', timeStyle: 'short' })}</p>}
                                            </div>
                                            <div className="flex justify-end">
                                                {canReserve && selectedProject?.canReserve && (unit.status === 'available' || unit.reservationExpired) && <button type="button" onClick={() => reserve(unit)} className="inline-flex h-9 items-center gap-1.5 rounded-lg bg-[#17352d] px-3 text-xs font-semibold text-white hover:bg-[#244b40]"><Clock3 size={14} /> Reserve</button>}
                                                {canManage && selectedProject?.canManage && unit.status === 'reserved' && !unit.reservationExpired && <button type="button" onClick={() => release(unit)} className="inline-flex h-9 items-center gap-1.5 rounded-lg border border-[#d9e1d9] px-3 text-xs font-semibold text-[#53675e] hover:bg-[#f8faf6]"><RotateCcw size={14} /> Release</button>}
                                            </div>
                                        </div>
                                    ))}
                                </div>
                            </div>
                        </div>
                        {units.length === 0 && <p className="border-t border-[#dfe5df] py-10 text-center text-sm text-[#718078]">No units have been added to this project.</p>}
                    </>
                ) : (
                    <div className="flex flex-col items-center border-y border-[#dfe5df] py-16 text-center">
                        <span className="grid size-12 place-items-center rounded-xl bg-[#edf4eb] text-[#315447]"><Boxes size={22} /></span>
                        <h2 className="mt-4 font-display text-xl text-[#18342c]">No projects yet</h2>
                        <p className="mt-2 max-w-md text-sm leading-6 text-[#718078]">Create a project to start organizing plots, buildings, and other property units.</p>
                        {canManage && <button type="button" onClick={() => openProjectForm()} disabled={organizations.length === 0} className="mt-5 inline-flex h-10 items-center gap-2 rounded-lg bg-[#17352d] px-4 text-sm font-semibold text-white hover:bg-[#244b40] disabled:opacity-50"><Plus size={16} /> New project</button>}
                    </div>
                )}
            </div>

            {isAddingProject && (
                <Dialog title={editingProjectId ? 'Project settings' : 'Create a project'} eyebrow="Portfolio setup" onClose={closeProjectForm}>
                    <form onSubmit={submitProject} className="space-y-5">
                        {!editingProjectId && <div>
                            <label htmlFor="project-organization" className="field-label">Organization</label>
                            <Select id="project-organization" value={projectForm.data.organization_id} onChange={(value) => projectForm.setData('organization_id', value)}>
                                {organizations.map((organization) => <option key={organization.id} value={organization.id}>{organization.name}</option>)}
                            </Select>
                            <InputError message={projectForm.errors.organization_id} className="mt-2" />
                        </div>}
                        <div className="grid gap-4 sm:grid-cols-2">
                            <Field label="Project name" id="project-name" value={projectForm.data.name} onChange={(value) => projectForm.setData('name', value)} error={projectForm.errors.name} />
                            <Field label="Project code" id="project-code" value={projectForm.data.code} onChange={(value) => projectForm.setData('code', value)} error={projectForm.errors.code} />
                            <Field label="Location" id="project-location" value={projectForm.data.location} onChange={(value) => projectForm.setData('location', value)} error={projectForm.errors.location} />
                            <Field label="Title types" id="project-title-types" value={projectForm.data.title_types.join(', ')} onChange={(value) => projectForm.setData('title_types', [...new Set(value.split(',').map((titleType) => titleType.trim()).filter(Boolean))])} error={projectForm.errors.title_types} hint="Separate each configurable title type with a comma." />
                            <Field label="Reservation duration (hours)" id="project-reservation-hours" type="number" min="1" max="168" value={projectForm.data.reservation_hours} onChange={(value) => projectForm.setData('reservation_hours', value)} error={projectForm.errors.reservation_hours} hint="Between 1 and 168 hours." />
                        </div>
                        <fieldset className="border-t border-[#e7ece6] pt-5">
                            <legend className="field-label">Inventory status labels</legend>
                            <div className="grid gap-4 sm:grid-cols-2">
                                {(Object.keys(defaultStatusLabels) as InventoryStatus[]).map((status) => (
                                    <Field key={status} label={status.replaceAll('_', ' ')} id={`status-${status}`} value={projectForm.data.status_labels[status]} onChange={(value) => projectForm.setData('status_labels', { ...projectForm.data.status_labels, [status]: value })} error={projectForm.errors[`status_labels.${status}` as keyof typeof projectForm.errors]} />
                                ))}
                            </div>
                        </fieldset>
                        <div>
                            <label htmlFor="project-description" className="field-label">Description</label>
                            <textarea id="project-description" rows={2} value={projectForm.data.description} onChange={(event) => projectForm.setData('description', event.target.value)} className="field-input resize-y" />
                            <InputError message={projectForm.errors.description} className="mt-2" />
                        </div>
                        <DialogActions onClose={closeProjectForm} processing={projectForm.processing} submitLabel={editingProjectId ? 'Save settings' : 'Create project'} />
                    </form>
                </Dialog>
            )}

            {isAddingUnit && selectedProject && (
                <Dialog title="Add a property unit" eyebrow={selectedProject.name} onClose={() => setIsAddingUnit(false)} wide>
                    <form onSubmit={submitUnit} className="space-y-5">
                        <div className="grid gap-4 sm:grid-cols-2">
                            <Field label="Unit code" id="unit-code" value={unitForm.data.code} onChange={(value) => unitForm.setData('code', value)} error={unitForm.errors.code} />
                            <Field label="Unit type" id="unit-type" value={unitForm.data.unit_type} onChange={(value) => unitForm.setData('unit_type', value)} error={unitForm.errors.unit_type} hint="Examples: land, house, apartment, commercial." />
                            <Field label="Phase (optional)" id="unit-phase" value={unitForm.data.phase} onChange={(value) => unitForm.setData('phase', value)} error={unitForm.errors.phase} />
                            <Field label="Block (optional)" id="unit-block" value={unitForm.data.block} onChange={(value) => unitForm.setData('block', value)} error={unitForm.errors.block} />
                            <Field label="Usage" id="unit-usage" value={unitForm.data.usage} onChange={(value) => unitForm.setData('usage', value)} error={unitForm.errors.usage} hint="Residential, commercial, mixed use, etc." />
                            <Field label="Area (m²)" id="unit-area" type="number" min="0" step="0.01" value={unitForm.data.area_sqm} onChange={(value) => unitForm.setData('area_sqm', value)} error={unitForm.errors.area_sqm} />
                            <Field label={`Base price (${selectedProject.organizationCurrency})`} id="unit-price" type="number" min="0" step="0.01" value={unitForm.data.base_price} onChange={(value) => unitForm.setData('base_price', value)} error={unitForm.errors.base_price} />
                            <div>
                                <label htmlFor="unit-title-type" className="field-label">Title type</label>
                                <Select id="unit-title-type" value={unitForm.data.title_type} onChange={(value) => unitForm.setData('title_type', value)}>
                                    <option value="">Not specified</option>
                                    {selectedProject.titleTypes.map((titleType) => <option key={titleType} value={titleType}>{titleType}</option>)}
                                </Select>
                                <InputError message={unitForm.errors.title_type} className="mt-2" />
                            </div>
                            <Field label="Title status" id="unit-title-status" value={unitForm.data.title_status} onChange={(value) => unitForm.setData('title_status', value)} error={unitForm.errors.title_status} hint="Company-defined progress, e.g. applied or registered." />
                            <div>
                                <label htmlFor="unit-rental-status" className="field-label">Rental listing</label>
                                <Select id="unit-rental-status" value={unitForm.data.rental_status} onChange={(value) => unitForm.setData('rental_status', value as UnitForm['rental_status'])}>
                                    <option value="not_listed">Not listed for rent</option>
                                    <option value="available">Available for rent</option>
                                </Select>
                                <InputError message={unitForm.errors.rental_status} className="mt-2" />
                            </div>
                        </div>
                        <fieldset className="border-t border-[#e7ece6] pt-5">
                            <legend className="field-label">Premiums</legend>
                            <div className="grid gap-4 sm:grid-cols-3">
                                <label className="flex h-11 items-center gap-3 rounded-lg border border-[#cfd8ce] px-3 text-sm text-[#53675e]">
                                    <input type="checkbox" checked={unitForm.data.is_corner} onChange={(event) => unitForm.setData('is_corner', event.target.checked)} className="size-4 rounded border-[#b8c5bb] text-[#315447] focus:ring-[#8ba344]" />
                                    Corner unit
                                </label>
                                <Field label="Corner premium (%)" id="unit-corner-premium" type="number" min="0" max="100" step="0.01" value={unitForm.data.corner_premium_percent} onChange={(value) => unitForm.setData('corner_premium_percent', value)} error={unitForm.errors.corner_premium_percent} />
                                <Field label={`Topography premium (${selectedProject.organizationCurrency})`} id="unit-topography-premium" type="number" min="0" step="0.01" value={unitForm.data.topography_premium_amount} onChange={(value) => unitForm.setData('topography_premium_amount', value)} error={unitForm.errors.topography_premium_amount} />
                            </div>
                        </fieldset>
                        <DialogActions onClose={() => setIsAddingUnit(false)} processing={unitForm.processing} submitLabel="Add unit" />
                    </form>
                </Dialog>
            )}
        </AuthenticatedLayout>
    );
}

function Summary({ label, value, icon: Icon }: { label: string; value: string; icon: typeof Boxes }) {
    return <div className="min-w-0 border-t-2 border-[#d9e1d9] bg-white px-4 py-4 sm:px-5"><div className="flex items-center justify-between gap-4"><span className="text-xs font-medium text-[#75847d]">{label}</span><Icon size={17} className="shrink-0 text-[#73917e]" /></div><p className="mt-4 truncate text-2xl font-semibold tabular-nums text-[#203c32]">{value}</p></div>;
}

function Dialog({ children, title, eyebrow, onClose, wide = false }: { children: React.ReactNode; title: string; eyebrow: string; onClose: () => void; wide?: boolean }) {
    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-[#10251f]/45 px-4 py-6" role="presentation" onMouseDown={(event) => event.target === event.currentTarget && onClose()}>
            <section role="dialog" aria-modal="true" aria-labelledby="inventory-dialog-title" className={`max-h-full w-full ${wide ? 'max-w-2xl' : 'max-w-xl'} overflow-y-auto rounded-xl bg-white p-6 shadow-2xl sm:p-8`}>
                <div className="flex items-start justify-between gap-5"><div><p className="eyebrow">{eyebrow}</p><h2 id="inventory-dialog-title" className="mt-2 font-display text-2xl text-[#18342c]">{title}</h2></div><button type="button" onClick={onClose} aria-label="Close dialog" className="grid size-9 place-items-center rounded-lg text-[#6d7d76] hover:bg-[#f1f4ef] hover:text-[#17352d]"><X size={18} /></button></div>
                <div className="mt-7">{children}</div>
            </section>
        </div>
    );
}

function Field({ label, id, value, onChange, error, hint, type = 'text', min, max, step }: { label: string; id: string; value: string; onChange: (value: string) => void; error?: string; hint?: string; type?: string; min?: string; max?: string; step?: string }) {
    return <div><label htmlFor={id} className="field-label">{label}</label><input id={id} type={type} min={min} max={max} step={step} value={value} onChange={(event) => onChange(event.target.value)} className="field-input" />{hint && <p className="mt-1 text-xs leading-5 text-[#829087]">{hint}</p>}<InputError message={error} className="mt-2" /></div>;
}

function Select({ children, id, value, onChange }: { children: React.ReactNode; id: string; value: string; onChange: (value: string) => void }) {
    return <div className="relative"><select id={id} value={value} onChange={(event) => onChange(event.target.value)} className="field-input appearance-none pr-10">{children}</select><ChevronDown size={16} className="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-[#718078]" /></div>;
}

function DialogActions({ onClose, processing, submitLabel }: { onClose: () => void; processing: boolean; submitLabel: string }) {
    return <div className="flex justify-end gap-3 border-t border-[#e7ece6] pt-5"><button type="button" onClick={onClose} className="h-10 rounded-lg px-4 text-sm font-medium text-[#5f7169] hover:bg-[#f3f5ef]">Cancel</button><button type="submit" disabled={processing} className="h-10 rounded-lg bg-[#17352d] px-4 text-sm font-semibold text-white hover:bg-[#244b40] disabled:opacity-60">{processing ? 'Saving…' : submitLabel}</button></div>;
}