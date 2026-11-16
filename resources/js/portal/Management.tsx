import { useEffect, useMemo, useState, type FormEvent } from 'react'
import { ApiError } from './api/session'
import { Loading } from './Loading'
import {
  facilities,
  activatedServers,
  availableRoles,
  generateServerActivationCode,
  invitations,
  inviteMember,
  issueInstallationActivationCode,
  issueOperationalSyncCode,
  licensingOverview as getLicensingOverview,
  linkKernelBridgeAccount,
  managementError,
  memberships,
  operationalSyncSummary,
  removeMembership,
  refreshKernelBridgeLink,
  revealServerEnrollmentCode,
  revokeServerInstallation,
  revokeOperationalSync,
  resendInvitation,
  revokeInvitation,
  saveFacility,
  serverEnrollmentCodes,
  unlinkKernelBridgeAccount,
  updateMembership,
  type Facility,
  type ActivatedServer,
  type EnrollmentCodeSummary,
  type Invitation,
  type KernelBridgeLicenseLink,
  type Membership,
  type OrganizationDetail,
  type OperationalSyncSummary,
  type AvailableRole,
  type Page,
  type ServerInstallation,
} from './api/management'

export function Pagination({ page, last, onChange }: { page: number; last: number; onChange: (page: number) => void }) {
  return <div className="management-pagination">
    <button type="button" disabled={page <= 1} onClick={() => onChange(page - 1)}>Previous</button>
    <span>Page {page} of {last}</span>
    <button type="button" disabled={page >= last} onClick={() => onChange(page + 1)}>Next</button>
  </div>
}

export function OrganizationManagement({ id, initialDetail, view, onNavigate }: { id: string; initialDetail: OrganizationDetail; view: 'overview' | 'facilities' | 'organization' | 'license' | 'servers' | 'sync' | 'invitations' | 'users'; onNavigate: (view: 'facilities' | 'organization' | 'sync') => void }) {
  const [detail, setDetail] = useState<OrganizationDetail>(initialDetail)
  const [page, setPage] = useState(1)
  const [list, setList] = useState<Page<Facility> | null>(null)
  const [reload, setReload] = useState(0)
  const [membershipReload, setMembershipReload] = useState(0)
  const [error, setError] = useState('')
  const [facilitiesError, setFacilitiesError] = useState('')
  const [membershipError, setMembershipError] = useState('')
  const [invitationError, setInvitationError] = useState('')
  const [busy, setBusy] = useState(false)
  const [facilityName, setFacilityName] = useState('')
  const [locale, setLocale] = useState('')
  const [zone, setZone] = useState('')
  const [editing, setEditing] = useState<string | null>(null)
  const [notice, setNotice] = useState('')
  const [inviteEmail, setInviteEmail] = useState('')
  const [inviteRole, setInviteRole] = useState('')
  const [roles, setRoles] = useState<AvailableRole[]>([])
  const [roleError, setRoleError] = useState('')
  const [invitationList, setInvitationList] = useState<Page<Invitation> | null>(null)
  const [memberList, setMemberList] = useState<Page<Membership> | null>(null)
  const [facilityOptions, setFacilityOptions] = useState<Facility[]>([])
  const [facilityOptionsError, setFacilityOptionsError] = useState('')
  const [editingMembership, setEditingMembership] = useState<string | null>(null)
  const [memberRole, setMemberRole] = useState('')
  const [memberStatus, setMemberStatus] = useState<'active' | 'suspended'>('active')
  const [memberFacilityId, setMemberFacilityId] = useState('')
  const [licensingOverview, setLicensingOverview] = useState<{ link: KernelBridgeLicenseLink | null; installations: ServerInstallation[] } | null>(null)
  const [licensingError, setLicensingError] = useState('')
  const [licensingBusy, setLicensingBusy] = useState(false)
  const [activationCode, setActivationCode] = useState('')
  const [issuedCode, setIssuedCode] = useState<{ installation_uuid: string; activation_code: string; expires_at: string } | null>(null)
  const [enrollmentCode, setEnrollmentCode] = useState<{ id: string; activation_code: string; expires_at: string } | null>(null)
  const [savedCodes, setSavedCodes] = useState<EnrollmentCodeSummary[] | null>(null)
  const [savedCodesError, setSavedCodesError] = useState('')
  const [codesReload, setCodesReload] = useState(0)
  const [activatedList, setActivatedList] = useState<Page<ActivatedServer> | null>(null)
  const [activatedError, setActivatedError] = useState('')
  const [activatedPage, setActivatedPage] = useState(1)
  const [activatedReload, setActivatedReload] = useState(0)
  const [syncCode, setSyncCode] = useState<{ code: string; expires_at: string; installation_uuid: string } | null>(null)
  const [syncSummary, setSyncSummary] = useState<OperationalSyncSummary | null>(null)
  const [syncSummaryError, setSyncSummaryError] = useState('')
  const [syncSummaryReload, setSyncSummaryReload] = useState(0)

  useEffect(() => {
    if (view !== 'overview' || !detail?.permissions.includes('servers.manage')) return
    let active = true
    operationalSyncSummary(id).then((value) => {
      if (active) { setSyncSummary(value); setSyncSummaryError('') }
    }).catch((cause: unknown) => {
      if (active) { setSyncSummary(null); setSyncSummaryError(managementError(cause)) }
    })
    return () => { active = false }
  }, [id, view, detail, syncSummaryReload])

  useEffect(() => {
    if (!syncCode) return
    const timer = window.setTimeout(() => setSyncCode(null), Math.max(0, new Date(syncCode.expires_at).getTime() - Date.now()))
    return () => window.clearTimeout(timer)
  }, [syncCode])

  useEffect(() => {
    setDetail(initialDetail)
  }, [initialDetail])

  useEffect(() => {
    if (view !== 'overview' && view !== 'facilities') return
    if (!detail?.can_view_facilities) return
    let active = true
    facilities(id, page).then((value) => {
      if (active) { setList(value); setFacilitiesError('') }
    }).catch((cause: unknown) => { if (active) { setList(null); setFacilitiesError(managementError(cause)) } })
    return () => { active = false }
  }, [id, page, reload, detail, view])

  useEffect(() => {
    if (!detail) return
    const showInvitationSummary = view === 'overview' && detail.permissions.includes('invitations.manage')
    const manageInvitations = view === 'invitations' && detail.permissions.includes('invitations.manage')
    const manageUsers = view === 'users' && detail.permissions.includes('users.manage')
    if (!showInvitationSummary && !manageInvitations && !manageUsers) return
    let active = true
    if (showInvitationSummary || manageInvitations) invitations(id).then((value) => { if (active) { setInvitationList(value); setInvitationError('') } })
      .catch((cause: unknown) => { if (active) { setInvitationList(null); setInvitationError(managementError(cause)) } })
    if (manageUsers) memberships(id).then((value) => { if (active) { setMemberList(value); setMembershipError('') } })
      .catch((cause: unknown) => { if (active) { setMemberList(null); setMembershipError(managementError(cause)) } })
    if (manageUsers) facilities(id, 1, 100).then((value) => { if (active) { setFacilityOptions(value.data); setFacilityOptionsError('') } })
      .catch((cause: unknown) => { if (active) { setFacilityOptions([]); setFacilityOptionsError(managementError(cause)) } })
    if (manageInvitations || manageUsers) availableRoles(id).then((value) => { if (active) { setRoles(value); setRoleError('') } })
      .catch((cause: unknown) => { if (active) setRoleError(managementError(cause)) })
    return () => { active = false }
  }, [id, detail, membershipReload, view])

  useEffect(() => {
    if (view !== 'license' && view !== 'servers') return
    if (!detail || (!detail.permissions.includes('license.manage') && !detail.permissions.includes('servers.manage'))) return
    let active = true
    getLicensingOverview(id).then((value) => {
      if (active) { setLicensingOverview(value); setLicensingError('') }
    }).catch((cause: unknown) => {
      if (active) { setLicensingOverview(null); setLicensingError(managementError(cause)) }
    })
    return () => { active = false }
  }, [id, detail, membershipReload, view])

  useEffect(() => {
    if (view !== 'servers' || !detail?.permissions.includes('servers.manage')) return
    let active = true
    serverEnrollmentCodes(id).then((codes) => {
      if (active) { setSavedCodes(codes); setSavedCodesError('') }
    }).catch((cause: unknown) => {
      if (active) { setSavedCodes(null); setSavedCodesError(managementError(cause)) }
    })
    return () => { active = false }
  }, [id, view, detail, codesReload])

  useEffect(() => {
    if ((view !== 'servers' && view !== 'sync') || !detail?.permissions.includes('servers.manage')) return
    let active = true
    activatedServers(id, activatedPage).then((servers) => {
      if (active) { setActivatedList(servers); setActivatedError('') }
    }).catch((cause: unknown) => {
      if (active) { setActivatedList(null); setActivatedError(managementError(cause)) }
    })
    return () => { active = false }
  }, [id, view, detail, activatedPage, activatedReload])

  useEffect(() => {
    if (!enrollmentCode) return
    const remaining = new Date(enrollmentCode.expires_at).getTime() - Date.now()
    const timer = window.setTimeout(() => {
      setEnrollmentCode(null)
      setCodesReload((value) => value + 1)
    }, Math.max(0, Math.min(remaining, 2_147_483_647)))
    return () => window.clearTimeout(timer)
  }, [enrollmentCode])

  const invitationStatus = useMemo(() => {
    const map = { pending: 0, expired: 0, revoked: 0, accepted: 0 }
    invitationList?.data.forEach((item) => { map[item.status] += 1 })
    return map
  }, [invitationList])
  const licenseCanIssue = licensingOverview?.link?.status === 'approved'
    && ['active', 'trial', 'grace'].includes(licensingOverview.link.license_status ?? '')

  async function save(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setBusy(true); setError(''); setNotice('')
    try {
      await saveFacility(id, editing, { name: facilityName, locale: locale || null, time_zone: zone || null })
      setNotice(editing ? 'Facility updated.' : 'Facility created.')
      setFacilityName(''); setLocale(''); setZone(''); setEditing(null); setPage(1); setReload((value) => value + 1); setMembershipReload((value) => value + 1)
    } catch (cause) { setError(managementError(cause)) } finally { setBusy(false) }
  }

  async function invite(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setBusy(true); setError(''); setNotice('')
    try {
      await inviteMember(id, inviteEmail, inviteRole)
      setInviteEmail('')
      setMembershipReload((value) => value + 1)
      setNotice('Invitation sent. It expires in 24 hours.')
    } catch (cause) {
      setError(cause instanceof ApiError && cause.status === 422 ? 'Check the email and role. Invitations are only for new accounts.' : cause instanceof ApiError && cause.status >= 500 ? 'The invitation could not be sent. Check the test SMTP and portal URL configuration, then try again.' : managementError(cause))
    } finally { setBusy(false) }
  }

  async function resend(invitationId: string) {
    setBusy(true); setError(''); setNotice(''); setInvitationError('')
    try {
      await resendInvitation(id, invitationId)
      setMembershipReload((value) => value + 1)
      setNotice('Invitation resent with a fresh 24-hour expiry.')
    } catch (cause) {
      setInvitationError(cause instanceof ApiError && cause.status === 422 ? 'Accepted invitations cannot be resent.' : managementError(cause))
    } finally { setBusy(false) }
  }

  async function revoke(invitationId: string) {
    setBusy(true); setError(''); setNotice(''); setInvitationError('')
    try {
      await revokeInvitation(id, invitationId)
      setMembershipReload((value) => value + 1)
      setNotice('Invitation revoked.')
    } catch (cause) {
      setInvitationError(cause instanceof ApiError && cause.status === 422 ? 'This invitation cannot be revoked.' : managementError(cause))
    } finally { setBusy(false) }
  }

  function startMembershipEdit(item: Membership) {
    setEditingMembership(item.id)
    setMemberRole(item.role)
    setMemberStatus(item.status)
    setMemberFacilityId(item.facility_id ?? '')
    setMembershipError('')
  }

  async function saveMembership(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (!editingMembership) return
    setBusy(true); setError(''); setNotice(''); setMembershipError('')
    try {
      await updateMembership(id, editingMembership, {
        role: memberRole,
        status: memberStatus,
        facility_id: memberRole === 'facility_admin' ? (memberFacilityId || null) : null,
      })
      setEditingMembership(null); setMemberFacilityId('')
      setMembershipReload((value) => value + 1)
      setNotice('Membership updated.')
    } catch (cause) {
      setMembershipError(cause instanceof ApiError && cause.status === 422 ? 'Role update failed. Organization administrators cannot be newly promoted; keep one active administrator and assign a facility to facility administrators.' : managementError(cause))
    } finally { setBusy(false) }
  }

  async function remove(item: Membership) {
    if (!window.confirm(`Remove ${item.user.name}'s access to this organization?`)) return
    setBusy(true); setError(''); setNotice(''); setMembershipError('')
    try {
      await removeMembership(id, item.id)
      setMembershipReload((value) => value + 1)
      setNotice('Membership removed.')
    } catch (cause) {
      setMembershipError(cause instanceof ApiError && cause.status === 422 ? 'This organization must keep at least one active organization administrator.' : managementError(cause))
    } finally { setBusy(false) }
  }

  async function linkLicense(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setLicensingBusy(true); setLicensingError(''); setNotice(''); setIssuedCode(null)
    try {
      const link = await linkKernelBridgeAccount(id, activationCode)
      setLicensingOverview((current) => ({ link, installations: current?.installations ?? [] }))
      setActivationCode('')
      setNotice('KernelBridge account linked. Review the current status and expiry before registering installations.')
    } catch (cause) {
      setLicensingError(managementError(cause))
    } finally { setLicensingBusy(false) }
  }

  async function refreshLicense() {
    setLicensingBusy(true); setLicensingError(''); setNotice(''); setIssuedCode(null)
    try {
      const link = await refreshKernelBridgeLink(id)
      setLicensingOverview((current) => ({ link, installations: current?.installations ?? [] }))
      setNotice('Licensing status refreshed from KernelBridge.')
    } catch (cause) {
      setLicensingError(managementError(cause))
    } finally { setLicensingBusy(false) }
  }

  async function unlinkLicense() {
    if (!window.confirm('Unlink this KernelBridge account? KernelBridge will revoke every installation and outstanding activation code for this organization.')) return
    setLicensingBusy(true); setLicensingError(''); setNotice(''); setIssuedCode(null)
    try {
      const link = await unlinkKernelBridgeAccount(id)
      setLicensingOverview((current) => ({
        link,
        installations: (current?.installations ?? []).map((item) => ({ ...item, status: 'revoked' })),
      }))
      setNotice('KernelBridge account unlinked. All installations and outstanding activation codes were revoked.')
    } catch (cause) {
      setLicensingError(managementError(cause))
    } finally { setLicensingBusy(false) }
  }

  async function generateCode() {
    if (!window.confirm('Generate a single-use server activation code? This reserves a license slot for up to 24 hours, even if the code is lost or unused.')) return
    setLicensingBusy(true); setLicensingError(''); setNotice(''); setIssuedCode(null); setEnrollmentCode(null)
    try {
      setEnrollmentCode(await generateServerActivationCode(id))
      setCodesReload((value) => value + 1)
    } catch (cause) {
      setLicensingError(cause instanceof ApiError && cause.status === 422 ? 'KernelBridge could not issue a code. Check the license status and available installation slots.' : `${managementError(cause)} A code may have been issued and reserved a slot; check saved codes before trying again.`)
    } finally { setLicensingBusy(false) }
  }

  async function revealCode(codeId: string) {
    setLicensingBusy(true); setLicensingError(''); setEnrollmentCode(null)
    try {
      setEnrollmentCode(await revealServerEnrollmentCode(id, codeId))
    } catch (cause) {
      setLicensingError(managementError(cause))
      setCodesReload((value) => value + 1)
    } finally { setLicensingBusy(false) }
  }

  async function issueCode(item: ServerInstallation) {
    if (!window.confirm(`Issue a one-time activation code for ${item.name}? This reserves an installation slot and expires in 24 hours.`)) return
    setLicensingBusy(true); setLicensingError(''); setNotice(''); setIssuedCode(null)
    try {
      const result = await issueInstallationActivationCode(id, item.id)
      setIssuedCode(result)
      setLicensingOverview((current) => current && ({
        ...current,
        installations: current.installations.map((installation) => installation.id === item.id
          ? { ...installation, status: 'activation_code_issued', activation_code_expires_at: result.expires_at }
          : installation),
      }))
    } catch (cause) {
      setLicensingError(managementError(cause))
    } finally { setLicensingBusy(false) }
  }

  async function revokeInstallation(item: ServerInstallation) {
    if (!window.confirm(`Revoke ${item.name}? Its KernelBridge credential and any unused activation code will stop working.`)) return
    setLicensingBusy(true); setLicensingError(''); setNotice(''); setIssuedCode(null)
    try {
      const revoked = await revokeServerInstallation(id, item.id)
      setLicensingOverview((current) => current && ({
        ...current,
        installations: current.installations.map((installation) => installation.id === item.id ? revoked : installation),
      }))
      setNotice('Installation and any outstanding activation code were revoked.')
    } catch (cause) {
      setLicensingError(managementError(cause))
    } finally { setLicensingBusy(false) }
  }

  async function generateSyncCode(server: ActivatedServer) {
    if (!window.confirm('Generate an operational sync enrollment code for this server? This replaces any outstanding sync enrollment code. Redeeming it rotates the existing sync credential.')) return
    setLicensingBusy(true); setLicensingError(''); setNotice(''); setSyncCode(null)
    try {
      const code = await issueOperationalSyncCode(id, server.installation_uuid)
      setSyncCode({ ...code, installation_uuid: server.installation_uuid })
    } catch (cause) {
      setLicensingError(managementError(cause))
    } finally { setLicensingBusy(false) }
  }

  async function revokeSync(server: ActivatedServer) {
    if (!window.confirm('Revoke this server’s operational sync credential? The server will stop synchronizing; this does not revoke its license.')) return
    setLicensingBusy(true); setLicensingError(''); setNotice(''); setSyncCode(null)
    try {
      await revokeOperationalSync(id, server.installation_uuid)
      setNotice('Operational sync credential revoked. Server licensing was not changed.')
      setActivatedReload((value) => value + 1)
    } catch (cause) {
      setLicensingError(managementError(cause))
    } finally { setLicensingBusy(false) }
  }

  return <div className="dashboard-data">
    {error && <p className="auth-error" role="alert">{error}</p>}
    {notice && <p role="status">{notice}</p>}
    <>
      {view === 'overview' && <>
        <div className="dashboard-welcome"><div><p className="dashboard-kicker">YOUR WORKSPACE</p><h2>{detail.name}</h2><p>A simple view of the organization and facilities available to you.</p></div><span className="dashboard-status">{detail.status}</span></div>
        <div className="dashboard-stats">
          <article><span>Visible facilities</span><strong>{list ? list.total : '—'}</strong><small>{facilitiesError ? 'Could not load facilities' : 'Facilities in your access scope'}</small></article>
          <article><span>Organization status</span><strong className="dashboard-stat-text">{detail.status}</strong><small>Current recorded status</small></article>
          <article><span>Your access</span><strong className="dashboard-stat-text">{detail.can_manage ? 'Administrator' : detail.permissions.length ? 'Custom role' : 'Read-only'}</strong><small>{detail.can_manage ? 'Organization-wide management' : detail.permissions.length ? 'Permissions assigned by your role' : 'Assigned facilities only'}</small></article>
        </div>
        {detail.permissions.includes('servers.manage') && <section className="dashboard-panel">
          <div className="dashboard-panel-heading"><div><h3>Operational sync</h3><p>Latest reported server health for this organization.</p></div><div className="dashboard-sync-actions"><button className="dashboard-action" type="button" onClick={() => { setSyncSummary(null); setSyncSummaryError(''); setSyncSummaryReload((value) => value + 1) }}>Refresh</button><button className="dashboard-action" type="button" onClick={() => onNavigate('sync')}>Manage sync <span aria-hidden="true">→</span></button></div></div>
          {syncSummaryError && <p className="auth-error" role="alert">Could not load sync summary: {syncSummaryError}</p>}
          {!syncSummary && !syncSummaryError && <Loading message="Loading sync summary..." />}
          {syncSummary?.status === 'disabled' && <p>Operational health monitoring is disabled. Ask the deployment administrator to enable it for this test environment.</p>}
          {syncSummary?.status === 'unlinked' && <p>An approved KernelBridge account link is required to show sync health.</p>}
          {syncSummary?.status === 'available' && <>
            {syncSummary.enrolled === 0 ? <p>No servers are enrolled for operational sync on the current account link.</p> : <>
              <div className="dashboard-sync-stats">
                <div><span>Enrolled</span><strong>{syncSummary.enrolled}</strong></div>
                <div><span>Online</span><strong>{syncSummary.online}</strong></div>
                <div><span>Delayed</span><strong>{syncSummary.delayed}</strong></div>
                <div><span>Offline</span><strong>{syncSummary.offline}</strong></div>
                <div><span>Unknown</span><strong>{syncSummary.unknown}</strong></div>
                <div><span>Reported pending</span><strong>{syncSummary.pending_count}</strong></div>
                <div><span>Reported failed</span><strong>{syncSummary.failed_count}</strong></div>
              </div>
              <p>Last received: {syncSummary.latest_received_at ? new Date(syncSummary.latest_received_at).toLocaleString() : 'No usable health reports yet'}.</p>
              {(syncSummary.failed_count > 0 || syncSummary.delayed > 0 || syncSummary.offline > 0 || syncSummary.unknown > 0) && <p role="status">Some servers need attention or have unavailable health reports. Check Operational sync for details and retry failed messages on the server.</p>}
            </>}
            <p>Counts are the latest server-reported snapshots and can be stale; unknown reports are excluded from queue totals. Only locally enrolled sync connections on the current link are included, not all licensed servers. Licensing status is not rechecked for this summary.</p>
            <p>Only operational health and enabled modules are synchronized, not patient, clinical or financial records.</p>
          </>}
        </section>}
        {detail.can_view_facilities && <section className="dashboard-panel"><div className="dashboard-panel-heading"><div><h3>Facilities</h3><p>Facilities you can access in this organization.</p></div><button className="dashboard-action" type="button" onClick={() => onNavigate('facilities')}>View facilities <span aria-hidden="true">→</span></button></div>
          {facilitiesError && <div><p className="auth-error" role="alert">{facilitiesError}</p><button className="dashboard-action" type="button" onClick={() => { setFacilitiesError(''); setReload((value) => value + 1) }}>Try loading facilities again</button></div>}
          {!list && !facilitiesError && <Loading message="Loading facilities..." />}
          {list && (list.data.length ? <ul className="dashboard-preview">{list.data.slice(0, 5).map((item) => <li key={item.id}><span className="dashboard-facility-icon" aria-hidden="true">+</span><span><strong>{item.name}</strong><small>{item.time_zone || 'No time zone set'}</small></span><span className="dashboard-status">{item.status}</span></li>)}</ul> : <p>No facilities are available.</p>)}
        </section>}
        {detail.permissions.includes('invitations.manage') && <section className="dashboard-panel">
          <h3>Invitations</h3>
          <p>Pending: {invitationStatus.pending} · Accepted: {invitationStatus.accepted} · Expired: {invitationStatus.expired} · Revoked: {invitationStatus.revoked}</p>
          {invitationError && <p className="auth-error" role="alert">{invitationError}</p>}
        </section>}
        <div className="dashboard-roadmap"><strong>More tools are on the way</strong><p>Facility monitoring, MedPass and backups are planned and not available yet.</p></div>
      </>}
      {view === 'organization' && <section className="dashboard-panel"><h3>Organization details</h3>
        <dl className="organization-details">
          <div><dt>Organization name</dt><dd>{detail.name}</dd></div>
          <div><dt>Status</dt><dd>{detail.status}</dd></div>
          {detail.onboarding && <>
            <div><dt>Legal name</dt><dd>{detail.onboarding.legal_name}</dd></div>
            <div><dt>Organization type</dt><dd>{detail.onboarding.organization_type}</dd></div>
            <div><dt>Ownership type</dt><dd>{detail.onboarding.ownership_type}</dd></div>
            <div><dt>Owner</dt><dd>{detail.onboarding.owner_name}</dd></div>
            <div><dt>Registration authority</dt><dd>{detail.onboarding.registration_authority}</dd></div>
            <div><dt>Registration number</dt><dd>{detail.onboarding.registration_number}</dd></div>
            <div><dt>Address</dt><dd>{[detail.onboarding.address_line_1, detail.onboarding.address_line_2, detail.onboarding.city, detail.onboarding.state_region, detail.onboarding.postal_code, detail.onboarding.country].filter(Boolean).join(', ')}</dd></div>
            <div><dt>Authority confirmed</dt><dd>{detail.onboarding.authority_confirmed ? 'Yes' : 'No'}</dd></div>
            <div><dt>Processing consent</dt><dd>{detail.onboarding.processing_consent ? 'Accepted' : 'Not accepted'}</dd></div>
            <div><dt>Notice version</dt><dd>{detail.onboarding.notice_version}</dd></div>
            <div><dt>Accepted at</dt><dd>{new Date(detail.onboarding.accepted_at).toLocaleString()}</dd></div>
            <div><dt>Accepted by</dt><dd>{detail.onboarding.accepted_by_name ?? 'Not available'}</dd></div>
          </>}
        </dl>
        {!detail.onboarding && <p>Full onboarding details require permission to view organization information.</p>}
      </section>}
      {view === 'invitations' && detail.permissions.includes('invitations.manage') && <section className="dashboard-panel"><form className="management-form" onSubmit={invite}>
        <h4>Invite an organization user</h4>
        <p>For synthetic testing only. Invitations are for new accounts and expire after 24 hours.</p>
        <label htmlFor="invite-email">Email address</label>
        <input id="invite-email" type="email" required maxLength={255} value={inviteEmail} onChange={(event) => setInviteEmail(event.target.value)} />
        <label htmlFor="invite-role">Role</label>
        <select id="invite-role" required value={inviteRole} onChange={(event) => setInviteRole(event.target.value)}><option value="">Select a role</option>{roles.map((role) => <option key={role.id} value={role.id}>{role.name}</option>)}</select>
        {roleError && <p className="auth-error" role="alert">{roleError}</p>}
        {!roles.length && !roleError && <p>Ask a platform administrator to configure an eligible role.</p>}
        <button className="button button-primary" disabled={busy || !roles.length} type="submit">{busy ? 'Please wait...' : 'Send invitation'}</button>
      </form>
        <h4>Invitation status</h4>
        {invitationError && <p className="auth-error" role="alert">{invitationError}</p>}
        {!invitationList && !invitationError && <Loading message="Loading invitations..." />}
        {invitationList && (invitationList.data.length === 0 ? <p>No invitations yet.</p> :
          <ul className="management-facilities">{invitationList.data.map((item) => <li key={item.id}>
            <div><strong>{item.email}</strong><br /><span>{roles.find((role) => `custom:${role.id}` === item.role)?.name ?? item.role} · {item.status} · Expires {new Date(item.expires_at).toLocaleString()}</span></div>
            <div className="management-actions">
              {item.can_resend && <button type="button" disabled={busy} onClick={() => { void resend(item.id) }}>Resend</button>}
              {item.can_revoke && <button type="button" disabled={busy} onClick={() => { void revoke(item.id) }}>Revoke</button>}
            </div>
          </li>)}</ul>)}
      </section>}
      {((view === 'license' && detail.permissions.includes('license.manage')) || ((view === 'servers' || view === 'sync') && detail.permissions.includes('servers.manage'))) && <section className="dashboard-panel licensing-management">
        <h4>{view === 'license' ? 'KernelBridge licensing' : view === 'sync' ? 'Operational sync' : 'MediFlow servers'}</h4>
        <p>{view === 'license' ? 'Link this organization to its MEDIFLOW license. Account-link activation codes are not stored in MediSphere.' : view === 'sync' ? 'Monitor server health and manage sync enrollment. Only operational health and enabled modules are synchronized, not patient, clinical or financial records.' : 'View this organization’s activated servers and manage activation codes.'}</p>
        {licensingError && <p className="auth-error" role="alert">{licensingError}</p>}
        {!licensingOverview && !licensingError && <Loading message="Loading licensing status..." />}
        {licensingOverview && <>
          {view === 'license' && licensingOverview.link && <div className="licensing-summary">
            <div><span>Account link</span><strong>{licensingOverview.link.status}</strong></div>
            <div><span>License status</span><strong>{licensingOverview.link.license_status || 'Not verified'}</strong></div>
            <div><span>Subscription</span><strong>{licensingOverview.link.plan_name || 'Not available'}</strong></div>
            <div><span>Expires</span><strong>{licensingOverview.link.expires_at ? new Date(licensingOverview.link.expires_at).toLocaleDateString() : 'No expiry reported'}</strong></div>
            <div><span>Last verified</span><strong>{licensingOverview.link.verified_at ? new Date(licensingOverview.link.verified_at).toLocaleString() : 'Not verified'}</strong></div>
            <div><span>Entitlement revision</span><strong>{licensingOverview.link.entitlement_revision ?? 'Not available'}</strong></div>
          </div>}
          {view === 'license' && licensingOverview.link?.status === 'approved' && <div className="licensing-entitlements">
            <h5>Verified entitlements</h5>
            {licensingOverview.link.entitlements?.length
              ? <ul>{licensingOverview.link.entitlements.map((grant) => <li key={grant.key}><code>{grant.key}</code><span>{grant.enabled ? 'Enabled' : 'Disabled'}{grant.limit !== undefined && grant.limit !== null ? ` · Limit ${grant.limit}` : ''}</span></li>)}</ul>
              : <p>No entitlements were returned.</p>}
          </div>}
          {view === 'license' && <div className="management-actions licensing-buttons">
            {licensingOverview.link?.status === 'approved' && <button type="button" disabled={licensingBusy} onClick={() => { void refreshLicense() }}>{licensingBusy ? 'Please wait...' : 'Refresh from KernelBridge'}</button>}
            {licensingOverview.link?.status === 'approved' && <button type="button" disabled={licensingBusy} onClick={() => { void unlinkLicense() }}>Unlink and revoke all installations</button>}
          </div>}
          {view === 'license' && (!licensingOverview.link || licensingOverview.link.status === 'revoked') && <form className="management-form" onSubmit={linkLicense}>
            <h5>{licensingOverview.link ? 'Link a replacement license' : 'Link a KernelBridge license'}</h5>
            <p>Enter the product activation code issued by KernelBridge. It will be sent securely to the configured KernelBridge API and not retained.</p>
            <label htmlFor="kernelbridge-activation-code">KernelBridge activation code</label>
            <input id="kernelbridge-activation-code" autoComplete="off" required maxLength={255} value={activationCode} onChange={(event) => setActivationCode(event.target.value)} />
            <button className="button button-primary" disabled={licensingBusy} type="submit">{licensingBusy ? 'Linking...' : 'Link account'}</button>
          </form>}
          {view === 'servers' && <>
          <p>Generate a single-use code and enter it on the MediFlow Server. Each code reserves a license slot and expires in 24 hours; the activated server entitlement follows the license.</p>
          <button className="button button-primary" type="button" disabled={licensingBusy || !licenseCanIssue || enrollmentCode !== null} onClick={() => { void generateCode() }}>Generate Server Activation Code</button>
          {!licenseCanIssue && <p>An approved, active license is required to generate a server activation code.</p>}
          {enrollmentCode && <div className="installation-activation-code" role="alert">
            <strong>Copy this activation code to the MediFlow server now</strong>
            <p>Expires {new Date(enrollmentCode.expires_at).toLocaleString()}. Keep it private and do not place it in logs. It can be used only once and may already have been used.</p>
            <code className="mfa-secret">{enrollmentCode.activation_code}</code>
            <button className="dashboard-action" type="button" onClick={() => setEnrollmentCode(null)}>Hide activation code</button>
          </div>}
          <h5>Saved server activation codes</h5>
          <p>Codes created after this feature was enabled can be revealed until they expire. Usage status is not synchronized, so a listed code may already have been used. Unused codes reserve a slot until expiry.</p>
          {savedCodesError && <p className="auth-error" role="alert">Could not load saved codes: {savedCodesError}</p>}
          {!savedCodes && !savedCodesError && <Loading message="Loading saved codes..." />}
          {savedCodes && (savedCodes.length === 0 ? <p>No saved unexpired codes.</p> :
            <ul className="management-facilities">{savedCodes.map((code) => <li key={code.id}>
              <div><strong>Server activation code</strong><br /><span>Created {new Date(code.created_at).toLocaleString()} · Expires {new Date(code.expires_at).toLocaleString()}</span></div>
              <button type="button" disabled={licensingBusy} onClick={() => { void revealCode(code.id) }}>Show code</button>
            </li>)}</ul>)}
          </>}
          {(view === 'servers' || view === 'sync') && <>
          <h5>Activated servers</h5>
          <p>Installation details are retrieved from KernelBridge. License verification is separate from operational health. Facility assignment is not yet generally available.</p>
          {view === 'sync' && <p>Generate a sync enrollment code for an active server, then enter it in that server's Operational sync section. Automatic delivery requires the server connector and schedulers to be running.</p>}
          <button type="button" onClick={() => { setActivatedList(null); setActivatedError(''); setActivatedReload((value) => value + 1) }}>Refresh activated servers</button>
          {activatedError && <p className="auth-error" role="alert">Could not load activated servers: {activatedError}</p>}
          {!activatedList && !activatedError && <Loading message="Loading activated servers..." />}
          {activatedList && !activatedList.operational_sync_enrollment_enabled && <p>Sync enrollment is disabled. Ask the deployment administrator to enable it for this test environment.</p>}
          {syncCode && <div className="installation-activation-code" role="alert">
            <strong>Operational sync enrollment code</strong>
            <p>Enter this separate code on the selected server. It expires {new Date(syncCode.expires_at).toLocaleString()} and is not the server activation code.</p>
            <p>Installation ID: <code>{syncCode.installation_uuid}</code></p>
            <code className="mfa-secret">{syncCode.code}</code>
            <button type="button" onClick={() => setSyncCode(null)}>Hide sync code</button>
          </div>}
          {activatedList && (activatedList.data.length === 0 ? <p>No activated servers found on this page.</p> :
            <ul className="management-facilities">{activatedList.data.map((server) => <li key={server.installation_uuid}>
              <div><strong>{server.device_name || 'Unnamed server'}</strong><br />
                <span>{server.status} · {server.runtime_type || 'Runtime not reported'} · Activated {server.activated_at ? new Date(server.activated_at).toLocaleString() : 'unknown'} · Last license verification {server.last_verified_at ? new Date(server.last_verified_at).toLocaleString() : 'not reported'} · License expires {server.expires_at ? new Date(server.expires_at).toLocaleString() : 'not reported'}</span><br />
                <code>{server.installation_uuid}</code>
                {view === 'sync' && !server.operational_health && <p>Operational health monitoring is disabled.</p>}
                {server.operational_health && <div>
                  <strong>Operational sync: {server.operational_health.status}</strong>
                  {server.operational_health.status === 'unknown' && <p>No current operational health is available for this server.</p>}
                  {server.operational_health.status !== 'unknown' && <>
                    <p>Last received {new Date(server.operational_health.received_at).toLocaleString()} · Observed {new Date(server.operational_health.observed_at).toLocaleString()} · Version {server.operational_health.version} · Pending {server.operational_health.pending_count} · Failed {server.operational_health.failed_count}</p>
                    <p>Server-reported enabled modules (not independently verified): {server.operational_health.enabled_modules.join(', ') || 'none'}</p>
                    {server.operational_health.failed_count > 0 && <p className="auth-error" role="alert">This server reported failed sync messages. Retry them on the server.</p>}
                    {server.operational_health.status !== 'online' && <p className="auth-error" role="alert">Operational health is {server.operational_health.status}; reported queue counts may be out of date.</p>}
                  </>}
                </div>}
                {activatedList.operational_sync_enrollment_enabled && server.status === 'active' && <div className="management-actions">
                  <button type="button" disabled={licensingBusy} onClick={() => { void generateSyncCode(server) }}>Generate sync enrollment code</button>
                  <button type="button" disabled={licensingBusy} onClick={() => { void revokeSync(server) }}>Revoke sync credential</button>
                </div>}
              </div>
            </li>)}</ul>)}
          {activatedList && <Pagination page={activatedList.current_page} last={activatedList.last_page} onChange={(page) => { setActivatedList(null); setActivatedPage(page) }} />}
          </>}
          {view === 'servers' && <>
          {issuedCode && <div className="installation-activation-code" role="alert">
            <strong>Copy this activation code to the MediFlow server now</strong>
            <p>This code is shown only once here and expires {new Date(issuedCode.expires_at).toLocaleString()}. Do not share it or place it in logs.</p>
            <p>Installation ID: <code>{issuedCode.installation_uuid}</code></p>
            <code className="mfa-secret">{issuedCode.activation_code}</code>
            <button className="dashboard-action" type="button" onClick={() => setIssuedCode(null)}>Hide activation code</button>
          </div>}
          <h5>Previously registered installations</h5>
          {licensingOverview.installations.length === 0 ? <p>No MediFlow servers registered.</p> :
            <ul className="management-facilities">{licensingOverview.installations.map((item) => <li key={item.id}>
              <div><strong>{item.name}</strong><br /><span>{item.facility_name || 'Facility unavailable'} · {item.status}{item.activation_code_expires_at ? ` · Code expires ${new Date(item.activation_code_expires_at).toLocaleString()}` : ''}</span><br /><code>{item.installation_uuid}</code></div>
              <div className="management-actions">
                {item.status !== 'revoked' && licenseCanIssue && <button type="button" disabled={licensingBusy} onClick={() => { void issueCode(item) }}>{item.status === 'activation_code_issued' ? 'Replace activation code' : 'Issue activation code'}</button>}
                {item.status !== 'revoked' && <button type="button" disabled={licensingBusy} onClick={() => { void revokeInstallation(item) }}>Revoke installation</button>}
              </div>
            </li>)}</ul>}
          </>}
        </>}
      </section>}
      {view === 'users' && detail.permissions.includes('users.manage') && <div className="dashboard-panel"><h4>Organization users and roles</h4>
        {roleError && <p className="auth-error" role="alert">{roleError}</p>}
        {facilityOptionsError && <p className="auth-error" role="alert">Could not load facilities: {facilityOptionsError}</p>}
        {membershipError && <p className="auth-error" role="alert">{membershipError}</p>}
        {!memberList && !membershipError && <Loading message="Loading members..." />}
        {memberList && (memberList.data.length === 0 ? <p>No members assigned yet.</p> :
          <ul className="management-facilities">{memberList.data.map((item) => <li key={item.id}>
            <div><strong>{item.user.name}</strong><br /><span>{item.user.email} · {roles.find((role) => `custom:${role.id}` === item.role)?.name ?? item.role.replace('_', ' ')} · {item.status}{item.facility?.name ? ` · ${item.facility.name}` : ''}</span></div>
            <div className="management-actions"><button type="button" disabled={busy} onClick={() => startMembershipEdit(item)}>Edit role</button><button type="button" disabled={busy} onClick={() => { void remove(item) }}>Remove access</button></div>
          </li>)}</ul>)}
        {editingMembership && <form className="management-form" onSubmit={saveMembership}>
          <h5>Edit membership</h5>
          <label htmlFor="member-role">Role</label>
          <select id="member-role" value={memberRole} onChange={(event) => setMemberRole(event.target.value)}>
            {memberList?.data.find((item) => item.id === editingMembership)?.role === 'organization_admin' && <option value="organization_admin">Organization administrator</option>}
            {detail.can_manage && <option value="facility_admin">Facility administrator</option>}
            {roles.map((role) => <option key={role.id} value={`custom:${role.id}`}>{role.name}</option>)}
          </select>
          <label htmlFor="member-status">Access status</label>
          <select id="member-status" value={memberStatus} onChange={(event) => setMemberStatus(event.target.value as 'active' | 'suspended')}>
            <option value="active">Active</option>
            <option value="suspended">Suspended</option>
          </select>
          {memberRole === 'facility_admin' && <>
            <label htmlFor="member-facility">Assigned facility</label>
            <select id="member-facility" required value={memberFacilityId} onChange={(event) => setMemberFacilityId(event.target.value)}>
              <option value="">Select a facility</option>
              {facilityOptions.map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}
            </select>
          </>}
          <div className="management-actions"><button className="button button-primary" disabled={busy} type="submit">Save membership</button><button type="button" disabled={busy} onClick={() => setEditingMembership(null)}>Cancel</button></div>
        </form>}
      </div>}
      {view === 'facilities' && <section className="dashboard-panel"><h3>Facilities</h3>
      <p>{detail.permissions.includes('facilities.manage') ? 'View and manage facilities in this organization.' : 'You can view facilities assigned to you.'}</p>
      {facilitiesError && <div><p className="auth-error" role="alert">{facilitiesError}</p><button className="dashboard-action" type="button" onClick={() => { setFacilitiesError(''); setReload((value) => value + 1) }}>Try loading facilities again</button></div>}
      {!list ? !facilitiesError && <Loading message="Loading facilities..." /> : <>
        {list.data.length === 0 ? <p>No facilities are available.</p> :
          <ul className="management-facilities">{list.data.map((item) => <li key={item.id}>
            <div><strong>{item.name}</strong><br /><span>{item.status} · {item.time_zone || 'No time zone set'}</span></div>
            {detail.permissions.includes('facilities.manage') && <button type="button" onClick={() => { setEditing(item.id); setFacilityName(item.name); setLocale(item.locale || ''); setZone(item.time_zone || ''); setNotice('') }}>Edit <span className="sr-only">{item.name}</span></button>}
          </li>)}</ul>}
        <Pagination page={list.current_page} last={list.last_page} onChange={(next) => { setList(null); setPage(next) }} />
      </>}
      {detail.permissions.includes('facilities.manage') && <form className="management-form" onSubmit={save}>
        <h4>{editing ? 'Edit facility' : 'Add facility'}</h4>
        <label htmlFor="facility-name">Facility name</label>
        <input id="facility-name" required maxLength={255} value={facilityName} onChange={(event) => setFacilityName(event.target.value)} />
        <label htmlFor="facility-locale">Locale (optional)</label>
        <input id="facility-locale" maxLength={35} placeholder="en" value={locale} onChange={(event) => setLocale(event.target.value)} />
        <label htmlFor="facility-zone">Time zone (optional)</label>
        <input id="facility-zone" maxLength={64} placeholder="Africa/Lagos" value={zone} onChange={(event) => setZone(event.target.value)} />
        <div className="management-actions"><button className="button button-primary" disabled={busy} type="submit">{editing ? 'Save facility' : 'Add facility'}</button>
          {editing && <button type="button" disabled={busy} onClick={() => { setEditing(null); setFacilityName(''); setLocale(''); setZone('') }}>Cancel edit</button>}</div>
      </form>}
      </section>}
    </>
  </div>
}
