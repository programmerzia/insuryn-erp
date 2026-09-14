/** People and Payroll MVP: shared words for the employee and payroll screens. */
export interface PeopleOption { id: string; label: string }
export interface PeopleOptions { branches: PeopleOption[]; departments: PeopleOption[]; designations: PeopleOption[]; grades: PeopleOption[] }

export const employmentTypes = ['permanent', 'probation', 'contract', 'intern'] as const;
const typeWords: Record<string, string> = { permanent: 'Permanent', probation: 'Probation', contract: 'Contract', intern: 'Intern' };
export const employmentTypeLabel = (type: string): string => typeWords[type] ?? type;

const changeWords: Record<string, string> = { hire: 'Hired', promotion: 'Promotion', transfer: 'Transfer', pay_change: 'Pay change', separation: 'Separation' };
export const changeKindLabel = (kind: string): string => changeWords[kind] ?? kind;

const runWords: Record<string, string> = { preview: 'Preview', posted: 'Posted', paid: 'Paid', cancelled: 'Cancelled' };
export const runStatusLabel = (status: string): string => runWords[status] ?? status;
