import type { KernelBridgeLicenseLink } from './api/management'

export type ReportCategory = { key: string; label: string; modules: string[]; sampleCounts?: { date: string; count: number }[] }

const sampleCounts = (first: number, second: number, third: number) => [
  { date: '2026-10-01', count: first },
  { date: '2026-11-01', count: second },
  { date: '2026-11-12', count: third },
]

// Keep these proposed categories aligned with Clinic's InsightCategoryCatalog.
const categories: ReportCategory[] = [
  { key: 'patients', label: 'New patient registrations', modules: ['patient_records'], sampleCounts: sampleCounts(12, 16, 20) },
  { key: 'visits', label: 'Patient visits', modules: ['patient_records'], sampleCounts: sampleCounts(30, 40, 50) },
  { key: 'prescriptions', label: 'Prescriptions', modules: ['clinical_care'] },
  { key: 'care', label: 'Drug charts', modules: ['clinical_care'], sampleCounts: sampleCounts(15, 20, 30) },
  { key: 'consultations', label: 'Consultations', modules: ['doctor', 'specialist'], sampleCounts: sampleCounts(18, 24, 30) },
  { key: 'investigations', label: 'Investigation results', modules: ['laboratory', 'radiology'], sampleCounts: sampleCounts(12, 18, 24) },
  { key: 'labour_services', label: 'Labour services', modules: ['clinical_care'], sampleCounts: sampleCounts(3, 5, 6) },
  { key: 'surgery', label: 'Surgery-category services', modules: ['clinical_care'], sampleCounts: sampleCounts(2, 3, 4) },
  { key: 'admissions', label: 'Admissions', modules: ['clinical_care'], sampleCounts: sampleCounts(5, 7, 9) },
  { key: 'labours', label: 'Services named labor', modules: ['maternity'] },
  { key: 'labour_episodes', label: 'Labour episodes', modules: ['maternity'] },
  { key: 'deliveries', label: 'Deliveries', modules: ['maternity'], sampleCounts: sampleCounts(3, 4, 5) },
  { key: 'postnatal_examinations', label: 'Postnatal examinations', modules: ['maternity'] },
  { key: 'antenatal', label: 'ANC consultations', modules: ['maternity'] },
  { key: 'newborns', label: 'Newborns', modules: ['maternity'] },
  { key: 'medicines', label: 'Medicines', modules: ['pharmacy'] },
  { key: 'medicine_stock', label: 'Medicine stock', modules: ['pharmacy'] },
  { key: 'consumable_stock', label: 'Consumable stock', modules: ['department_management'] },
  { key: 'sales', label: 'Pharmacy transactions', modules: ['pharmacy'], sampleCounts: sampleCounts(20, 30, 45) },
  { key: 'finance', label: 'Finance', modules: ['finance'] },
  { key: 'revenue', label: 'Revenue', modules: ['finance'] },
  { key: 'expenses', label: 'Expenses', modules: ['finance'] },
  { key: 'bills', label: 'Bills', modules: ['billing'] },
  { key: 'payments', label: 'Payments', modules: ['billing'] },
  { key: 'staff', label: 'Users and staff', modules: ['access_control'] },
]

export function reportLinkForTemplate(link: KernelBridgeLicenseLink | null): KernelBridgeLicenseLink | null {
  return link?.status === 'approved'
    && ['active', 'trial', 'grace'].includes(link.license_status ?? '')
    && link.product_code?.toUpperCase() === 'MEDIFLOW'
    && link.verified_at
    ? link : null
}

export function reportCategoriesForLink(link: KernelBridgeLicenseLink): ReportCategory[] {
  const grants = new Map((link.entitlements ?? []).map((grant) => [grant.key, grant.enabled]))
  const enabled = (module: string) => grants.get(`mediflow.module.${module}`) ?? grants.get(module) ?? false
  return categories.filter((category) => category.modules.some(enabled))
}

export function searchReportCategories(categories: ReportCategory[], search: string): ReportCategory[] {
  const query = search.trim().toLowerCase()
  return categories.filter((category) => category.label.toLowerCase().includes(query))
}

export function sampleCountForRange(category: ReportCategory, from: string, to: string): number | undefined {
  if (!category.sampleCounts) return undefined
  return category.sampleCounts
    .filter((sample) => (!from || sample.date >= from) && (!to || sample.date <= to))
    .reduce((total, sample) => total + sample.count, 0)
}
