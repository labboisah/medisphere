import { ApiError, csrfToken, request } from './session'

export function managementError(error: unknown): string {
  if (error instanceof ApiError) {
    if (error.status === 401) return 'Your session has ended. Sign in again.'
    if (error.status === 403) return 'You do not have permission for this action, or your sign-in needs verification.'
    if (error.status === 404) return 'This feature is unavailable or the selected item no longer exists.'
    if (error.status === 422) return 'Check the values and try again. Validation failed.'
  }
  return 'The request could not be completed. Check your connection and try again.'
}

export type Organization = { id: string; name: string; status: string }
export type OrganizationDetail = Organization & { can_manage: boolean; can_view_facilities: boolean; permissions: string[]; onboarding: {
  legal_name: string; organization_type: string; ownership_type: string; owner_name: string
  registration_authority: string; registration_number: string; address_line_1: string; address_line_2: string | null
  city: string; state_region: string; postal_code: string | null; country: string
  notice_version: string; authority_confirmed: boolean; processing_consent: boolean; accepted_at: string; accepted_by_name: string | null
} | null }
export type Facility = { id: string; organization_id: string; name: string; status: string; locale: string | null; time_zone: string | null }
export type OrganizationRole = { id: string; name: string; permissions: string[] }
export type AvailableRole = Pick<OrganizationRole, 'id' | 'name'>
export type Invitation = { id: string; email: string; role: string; invited_by: number; created_at: string; expires_at: string; accepted_at: string | null; revoked_at: string | null; status: 'pending' | 'expired' | 'revoked' | 'accepted'; can_resend: boolean; can_revoke: boolean }
export type Membership = { id: string; user_id: number; role: string; status: 'active' | 'suspended'; facility_id: string | null; facility: null | { id: string; name: string }; user: { id: number; name: string; email: string } }
export type SyntheticSharingGrant = {
  id: string
  purpose: 'synthetic-workflow-demonstration'
  scope: ['synthetic-demonstration-summary']
  status: 'active' | 'expired' | 'revoked'
  expires_at: string
  revoked_at: string | null
}
export type SyntheticExchangeLink = {
  id: string
  facility_id: string
  local_subject_reference: string
  recipient_subject_reference: string
  review_status: 'pending' | 'confirmed' | 'rejected'
  reviewed_at: string | null
  grants: SyntheticSharingGrant[]
}
export type KernelBridgeLicenseLink = {
  link_uuid?: string
  product_code?: string
  license_uuid?: string
  subscription_uuid?: string | null
  status: string
  license_status: string | null
  subscription_status?: string | null
  plan_name?: string | null
  entitlement_revision?: number | null
  entitlements?: { key: string; name?: string; enabled: boolean; limit?: string | number | null }[]
  starts_at?: string | null
  ends_at?: string | null
  expires_at?: string | null
  grace_expires_at?: string | null
  verified_at?: string | null
}
export type ServerInstallation = {
  id: string
  facility_id: string
  facility_name: string | null
  installation_uuid: string
  name: string
  status: 'registered' | 'activation_code_issued' | 'revoked'
  activation_code_expires_at: string | null
  created_at: string
}
export type ActivatedServer = {
  installation_uuid: string
  device_name: string | null
  runtime_type: string | null
  status: 'active' | 'revoked' | 'deactivated'
  activated_at: string | null
  last_verified_at: string | null
  expires_at: string | null
  operational_health?: { status: 'unknown' } | {
    status: 'online' | 'delayed' | 'offline'
    received_at: string
    observed_at: string
    version: string
    pending_count: number
    failed_count: number
    enabled_modules: string[]
  }
}
export type Page<T> = { data: T[]; current_page: number; last_page: number; total: number; operational_sync_enrollment_enabled?: boolean }
export type OperationalSyncSummary = { status: 'disabled' | 'unlinked' } | {
  status: 'available'
  enrolled: number
  online: number
  delayed: number
  offline: number
  unknown: number
  pending_count: number
  failed_count: number
  latest_received_at: string | null
}
type Envelope<T> = { data: T }
type LicensingOverview = { link: KernelBridgeLicenseLink | null; installations: ServerInstallation[] }
type ActivationCode = { installation_uuid: string; activation_code: string; expires_at: string }
export type EnrollmentCodeSummary = { id: string; created_at: string; expires_at: string }
type EnrollmentCode = { id: string; activation_code: string; expires_at: string }

const base = '/api/v1/organizations'
const organizationPath = (id: string) => `${base}/${encodeURIComponent(id)}`

export async function operationalSyncSummary(id: string): Promise<OperationalSyncSummary> {
  const result = await request<Envelope<OperationalSyncSummary>>(`${organizationPath(id)}/operational-sync-summary`, { cache: 'no-store' })
  return result.data
}

export function organizations(page: number, perPage = 100): Promise<Page<Organization>> {
  return request(`${base}?page=${page}&per_page=${perPage}`)
}

export async function organization(id: string): Promise<OrganizationDetail> {
  return (await request<Envelope<OrganizationDetail>>(organizationPath(id))).data
}

export function facilities(id: string, page: number, perPage = 25): Promise<Page<Facility>> {
  return request(`${organizationPath(id)}/facilities?page=${page}&per_page=${perPage}`)
}

async function write<T>(path: string, method: 'POST' | 'PATCH', body: object): Promise<T> {
  const token = await csrfToken()
  const result = await request<Envelope<T>>(path, {
    method,
    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': token },
    body: JSON.stringify(body),
  })
  return result.data
}

export function renameOrganization(id: string, name: string): Promise<Organization> {
  return write(organizationPath(id), 'PATCH', { name })
}

export async function inviteMember(id: string, email: string, roleId: string): Promise<void> {
  const token = await csrfToken()
  await request(`${organizationPath(id)}/invitations`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': token },
    body: JSON.stringify({ email, role_id: roleId }),
  })
}

export async function availableRoles(id: string): Promise<AvailableRole[]> {
  return (await request<Envelope<AvailableRole[]>>(`${organizationPath(id)}/roles`)).data
}

export function invitations(id: string): Promise<Page<Invitation>> {
  return request(`${organizationPath(id)}/invitations?per_page=100`)
}

export async function resendInvitation(id: string, invitationId: string): Promise<void> {
  const token = await csrfToken()
  await request(`${organizationPath(id)}/invitations/${encodeURIComponent(invitationId)}/resend`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': token },
  })
}

export async function revokeInvitation(id: string, invitationId: string): Promise<void> {
  const token = await csrfToken()
  await request(`${organizationPath(id)}/invitations/${encodeURIComponent(invitationId)}`, {
    method: 'DELETE',
    headers: { 'X-CSRF-TOKEN': token },
  })
}

export function syntheticExchangeLinks(id: string): Promise<Page<SyntheticExchangeLink>> {
  return request(`${organizationPath(id)}/synthetic-exchange/links?per_page=100`, { cache: 'no-store' })
}

export async function createSyntheticExchangeLink(
  id: string,
  facilityId: string,
): Promise<SyntheticExchangeLink> {
  return write(`${organizationPath(id)}/synthetic-exchange/links`, 'POST', { facility_id: facilityId })
}

export async function reviewSyntheticExchangeLink(
  id: string,
  linkId: string,
  decision: 'confirmed' | 'rejected',
): Promise<SyntheticExchangeLink> {
  return write(`${organizationPath(id)}/synthetic-exchange/links/${encodeURIComponent(linkId)}/review`, 'POST', { decision })
}

export async function createSyntheticSharingGrant(id: string, linkId: string): Promise<SyntheticSharingGrant> {
  return write(`${organizationPath(id)}/synthetic-exchange/links/${encodeURIComponent(linkId)}/grants`, 'POST', {})
}

export async function revokeSyntheticSharingGrant(id: string, grantId: string): Promise<SyntheticSharingGrant> {
  const token = await csrfToken()
  const result = await request<Envelope<SyntheticSharingGrant>>(`${organizationPath(id)}/synthetic-exchange/grants/${encodeURIComponent(grantId)}`, {
    method: 'DELETE',
    headers: { 'X-CSRF-TOKEN': token },
  })
  return result.data
}

export function memberships(id: string): Promise<Page<Membership>> {
  return request(`${organizationPath(id)}/memberships?per_page=100`)
}

export async function licensingOverview(id: string): Promise<LicensingOverview> {
  return (await request<Envelope<LicensingOverview>>(`${organizationPath(id)}/licensing`, { cache: 'no-store' })).data
}

export async function linkKernelBridgeAccount(id: string, activationCode: string): Promise<KernelBridgeLicenseLink> {
  return write(organizationPath(id) + '/licensing/link', 'POST', { activation_code: activationCode })
}

export async function refreshKernelBridgeLink(id: string): Promise<KernelBridgeLicenseLink> {
  return write(organizationPath(id) + '/licensing/refresh', 'POST', {})
}

export async function unlinkKernelBridgeAccount(id: string): Promise<KernelBridgeLicenseLink> {
  const token = await csrfToken()
  return (await request<Envelope<KernelBridgeLicenseLink>>(`${organizationPath(id)}/licensing/link`, {
    method: 'DELETE',
    headers: { 'X-CSRF-TOKEN': token },
  })).data
}

export async function registerServerInstallation(id: string, facilityId: string, name: string): Promise<ServerInstallation> {
  return write(organizationPath(id) + '/installations', 'POST', { facility_id: facilityId, name })
}

export async function generateServerActivationCode(id: string): Promise<EnrollmentCode> {
  return write(organizationPath(id) + '/server-enrollment-codes', 'POST', {})
}

export async function serverEnrollmentCodes(id: string): Promise<EnrollmentCodeSummary[]> {
  return (await request<Envelope<EnrollmentCodeSummary[]>>(organizationPath(id) + '/server-enrollment-codes')).data
}

export function activatedServers(id: string, page: number): Promise<Page<ActivatedServer>> {
  return request(organizationPath(id) + `/activated-servers?page=${page}`)
}

export async function revealServerEnrollmentCode(id: string, codeId: string): Promise<EnrollmentCode> {
  return write(organizationPath(id) + `/server-enrollment-codes/${encodeURIComponent(codeId)}/reveal`, 'POST', {})
}

export async function issueInstallationActivationCode(id: string, installationId: string): Promise<ActivationCode> {
  return write(organizationPath(id) + `/installations/${encodeURIComponent(installationId)}/activation-code`, 'POST', {})
}

export async function revokeServerInstallation(id: string, installationId: string): Promise<ServerInstallation> {
  return write(organizationPath(id) + `/installations/${encodeURIComponent(installationId)}/revoke`, 'POST', {})
}

export function issueOperationalSyncCode(id: string, installationUuid: string): Promise<{ code: string; expires_at: string }> {
  return write(`${organizationPath(id)}/operational-sync-enrollment`, 'POST', { installation_uuid: installationUuid })
}

export async function revokeOperationalSync(id: string, installationUuid: string): Promise<void> {
  const token = await csrfToken()
  await request(`${organizationPath(id)}/operational-sync/${encodeURIComponent(installationUuid)}`, {
    method: 'DELETE', headers: { 'X-CSRF-TOKEN': token },
  })
}

export function updateMembership(id: string, membershipId: string, payload: { role: string; status: 'active' | 'suspended'; facility_id: string | null }): Promise<Membership> {
  return write(`${organizationPath(id)}/memberships/${encodeURIComponent(membershipId)}`, 'PATCH', payload)
}

export async function removeMembership(id: string, membershipId: string): Promise<void> {
  const token = await csrfToken()
  await request(`${organizationPath(id)}/memberships/${encodeURIComponent(membershipId)}`, {
    method: 'DELETE',
    headers: { 'X-CSRF-TOKEN': token },
  })
}

export function saveFacility(id: string, facilityId: string | null, fields: { name: string; locale: string | null; time_zone: string | null }): Promise<Facility> {
  const path = `${organizationPath(id)}/facilities${facilityId ? `/${encodeURIComponent(facilityId)}` : ''}`
  return write(path, facilityId ? 'PATCH' : 'POST', fields)
}
