# Implementation progress and checkpoints

## Status and scope

- **Current package:** WP-2 implementation, with WP-1 and WP-3 through WP-6 production-readiness engineering authorized to proceed
- **Checkpoint:** KernelBridge account linking and bound installation activation
- **Environment:** local, non-production only
- **Data policy:** synthetic data only; no patient, facility, account, license, credential, backup, or partner data

The updated final stakeholder acceptance records stakeholder approval for the planned exchange of real patient information. This records the stakeholders' decision; it does not demonstrate that the system is currently safe, lawful, or technically ready to exchange live records. Implementation, integration testing, and operational preparation continue in non-production environments using synthetic data until the release evidence and controls below are complete.

## Stakeholder acceptance and authorization

The supplied [MediPass-MediSphere final stakeholder acceptance](FINAL%20STAKEHOLDERS%20APPROVAL.docx), version 1.0 and dated 13 November 2026 (updated 15 November 2026), records all six stakeholder decision boxes as **Approved**, marks **WP-0 Accepted**, and states agreement that real patient information shall be exchanged. The Privacy Lead's comment also approves use of actual patient data. Accordingly, stakeholder approval for planned real patient-data exchange is recorded as **Approved**.

This supersedes the earlier WP-0 record's narrower authorization to progress only to the next work package: WP-1 through WP-6 readiness work may proceed in dependency order. The stakeholder approval does not certify implementation, legal compliance, clinical safety, security, or operational readiness. Live exchange remains disabled until the remaining evidence and release controls below are complete and the accountable owners authorize the specific production pilot.

## Remaining release evidence and controls

The stakeholder approval is recorded; the following implementation evidence, operational decisions, and release controls remain outstanding. Keep affected production capabilities disabled until the corresponding evidence has been reviewed and the accountable release owners authorize enablement:

| Area | Remaining evidence or implementation | Accountable owners | Effect until complete |
|---|---|---|---|
| Jurisdiction and privacy | Applicable jurisdiction, controller/processor roles, residency configuration, lawful basis, privacy impact assessment, retention/deletion rules, and executed data-sharing agreements | Legal and privacy leads | No identifiable data processing or patient exchange |
| Clinical governance | Clinical safety case, validated resource mappings, identity review and correction workflows, consent/grant lifecycle, and a defined pilot protocol | Clinical, privacy, and MedPass leads | Clinical exchange remains disabled |
| Hosting and operations | Production region and infrastructure, service ownership, SLOs, incident/support contacts, monitoring, recovery procedures, and deployment/rollback exercises | Product, operations, and security leads | No production environment or live deployment |
| Identity and security | Approved MFA/recovery and privileged-access policy, threat model, comprehensive audit, security review, tenant-isolation and penetration testing | Security, privacy, and product leads | No production user onboarding or privileged production access |
| Licensing | Sandbox end-to-end verification, key rotation/revocation and offline-grace acceptance, deployment secret configuration, and continuity policy | KernelBridge, clinical safety, legal, and product leads | No live licensing changes or activation-slot consumption |
| Backup | Implemented encryption and isolated storage, approved key custody and retention, restore authorization, and witnessed recovery testing | Security and organization owners | Backup capability remains disabled |
| Partners and interoperability | Verified MedPass source/API ownership, partner contract and test environment, jointly agreed FHIR profiles, and end-to-end interoperability testing | MedPass and platform leads | No partner connectivity or data transfer |
| Release acceptance | Completed package acceptance criteria, release-specific risk review, verified backup, production change plan, and explicit go-live authorization | Product, operations, security, clinical, and privacy owners | No production release or gate enablement |

## Production-readiness foundation added

- The machine operational-sync API is no longer coupled to the synthetic browser-management gate. Local/testing requests still require synthetic management; production requires the explicit `OPERATIONAL_SYNC_PRODUCTION_MACHINE_API_ENABLED` opt-in and HTTPS. Enrollment and ingestion retain their separate feature flags, which remain disabled by default. Staging and other environments stay denied.
- Browser administration and invitation/provisioning routes remain synthetic-only. This change does not enable clinical data exchange, backups, partner/provider traffic, or production user administration.
- The public page now receives its deployment environment and portal-access state from Laravel. Synthetic sign-in, invitation links, and session checks are available only when synthetic management is enabled in local/testing; production does not display test sign-in or make a session request. Availability copy remains explicit that integrations are not enabled.
- `.env.example` now documents the production machine API as disabled and retains one encrypted-session setting rather than a later contradictory override.

These are fail-closed foundations, not production activation or release acceptance. Keep the production machine API, enrollment, and ingestion flags disabled until the applicable release evidence is complete and the accountable owners authorize the specific capability.

The external privacy/legal, clinical safety/sharing, and MedPass interoperability items are organized in the [external readiness pack](15-external-readiness-pack.md). The product owner reviewed and approved the project-level readiness direction for planning on 15 November 2026; the recorded owner approvals and outstanding signatory/evidence gaps are summarized in the [decision record](16-draft-external-readiness-decisions.md). Neither document closes external evidence or authorizes real patient-data processing or production partner traffic.

## Synthetic sharing workflow prototype

- Added a local/testing-only organization workspace for a synthetic reference-pair review and demonstration-grant lifecycle. The server generates random UUID references; the API rejects extra fields rather than accepting patient attributes or clinical payloads.
- Synthetic reference pairs start pending and require a permitted human reviewer to confirm or reject them. Confirmation records only a review action on fictional references; it is not patient identity verification, consent, lawful authorization, or clinical approval.
- A confirmed pair can receive one fixed synthetic demonstration grant, limited to a non-clinical demo scope and expiring after 24 hours. Grants can be revoked; the application does not transmit or serve clinical data, call MedPass, implement FHIR exchange, or claim withdrawal/deletion behavior for real recipient copies.
- Access is tenant-scoped, MFA-protected, audited, and gated by `synthetic.exchange.manage`. The endpoint remains behind the existing synthetic-management route gate, which denies production access.

This prototype demonstrates workflow states only. It must not be used with real patient information or relied on for care. The external legal/privacy, identity/representative, clinical-safety, consent/grant, partner-contract, mapping, and production-readiness evidence remains open; real exchange and production partner traffic remain disabled.

## Completed in this checkpoint

- Created the [Laravel application](../) with PHP 8.3.16, Composer 2.8.4, and Laravel 13.40.0.
- Created the [React portal](../resources/js/portal) with Node.js 24.19.0, React 19.3.1, TypeScript 7.0.2, and Vite 8.3.6.
- Generated and retained Composer and npm dependency lockfiles for reproducible local installs.
- Added the initial versioned API contract: `GET /api/v1/health`.
- Added an API feature test for the health contract.
- Replaced the default portal template with a branded MediSphere welcome page that keeps non-production and synthetic-data notices explicit.
- Added clear mission/vision messaging and target-client sections (pharmacy, clinic, diagnostic center, maternity clinic, hospital, and hospital groups) while marking roadmap capabilities as planned.
- Added organization and facility database foundations with UUID primary keys, ownership foreign keys, restrictive deletion, and synthetic relationship fixtures.
- Added scoped membership foundations with management APIs. A composite database foreign key prevents a membership from referring to a facility from another organization, and organization administrators can list memberships, update role/status/facility assignments, and remove access. Suspension/removal take effect on subsequent organization-scoped requests, self-suspension/removal is denied, and organization-row locking protects the last active administrator from concurrent demotions/removals.
- Added a server-managed session foundation using Laravel Fortify. It uses CSRF-protected same-origin routes, login throttling, confirmed TOTP MFA, encrypted session configuration, recovery codes, and membership-gated organization authorization checks. Public registration, password reset, and passkeys are disabled pending invitation, outbound-notification, and recovery-policy approval.
- Added a typed portal session client and accessible sign-in/TOTP challenge form. It requests CSRF protection before state-changing calls and retains credentials, codes, and session details only in memory.
- Added authenticator setup in the portal with password confirmation, a locally generated QR code, manual setup key, confirmation code, and recovery-code display. The manual key remains available if the QR code cannot be loaded. Organization APIs require both confirmed MFA and a verified server session. Password-only sessions cannot read or change an enrolled user's MFA settings or password; recovery-code access requires verified MFA plus password confirmation within five minutes and responses use `Cache-Control: no-store`. Users can rotate recovery codes from Account security; rotation replaces all previous codes, is rate-limited, and is transactionally audited without recording the codes. A recovery code can be used once at sign-in. There is deliberately no administrator MFA bypass or automated recovery when all authenticators and recovery codes are lost; that requires an independently verified support process.
- Added opt-in, synthetic-only organization and facility management routes with tenant- and facility-scoped authorization and bounded lists. The temporary access decision approved for this slice permits an active, organization-wide `organization_admin` to update the organization name and create or update its facilities; an active `facility_admin` can read only assigned facilities. Other roles are denied. Organization creation, legal identity and status changes, facility status changes, and deletion are not exposed.
- Added an organization dashboard for MFA-verified sessions, with an overview, scoped facility count, sidebar navigation, organization selection, paginated facilities, organization renaming and facility creation/editing. Read-only users do not see write controls. The dashboard relies on the server for scope and permissions and operates only with the synthetic management API enabled; future monitoring and integrations remain unavailable.
- Added an explicitly invoked local/testing synthetic organization-administrator seeder, with a generated one-time-displayed password and normal mandatory MFA enrollment. It creates a sample organization and facility, rejects an existing account, and does not create a platform-wide administrator.
- Added a separate, explicitly seeded synthetic platform administrator and an MFA-protected platform workspace. It lists organization names/statuses and creates an inactive organization while inviting its first new administrator. The platform role does not grant organization or facility access without a separate membership; its routes are gated to local/testing. Provisioning is transactionally audited.
- Added synthetic-only SMTP invitations from organization administrators to new organization-administrator accounts. Invitations expire after 24 hours, store only a token hash and are consumed atomically with account and membership creation. The recipient signs in and must complete MFA. Existing accounts cannot be invited. Issuance is covered by the limited management audit; acceptance and full invitation lifecycle auditing remain outstanding.
- Added invitation lifecycle management for organization administrators: invitation status listing, resend for non-accepted/non-revoked invitations, and revocation endpoints with role-scoped access checks.
- Added transactional audit records for the gated management routes. Each record contains the actor ID, route action, organization/facility IDs when available, response status, time and a generated correlation ID; it does not contain request bodies or facility names. Audit insertion failure rolls back the management operation. A successful facility creation is recorded against its new facility ID.
- Added server-side KernelBridge MEDIFLOW account linking for an organization, using the organization UUID as its business UUID. The product API credential is read only from server configuration, API URLs require HTTPS except loopback development, and account-link activation codes are not stored or returned in audit data. Server enrollment codes are stored encrypted for authorized re-display as documented in portal setup.
- Added facility-scoped MediFlow server registration with stable installation UUIDs, one-time KernelBridge activation-code issuance (24-hour expiry), single-installation revocation, and whole-account unlink that revokes all installations. Plaintext activation codes are returned once with `Cache-Control: no-store`; no product or installation credential is stored in the MediFlow Platform.
- Extended KernelBridge's platform account-link API with individual installation revocation, including unactivated bound codes, while preserving the approved account-link and direct activation flows.
- MediFlow Server's existing KernelBridge client now reports activation source, installation UUID, signature-verification state, and signed snapshot issue time in its admin diagnostics. Server activation exchanges the bound code and stores the installation credential encrypted; direct activation behavior remains unchanged.
- Added synthetic-only organization onboarding after invitation, sign-in and MFA. Authorized organization administrators provide ownership, registration and address details and confirm authority and acceptance of a versioned test-processing notice. The acceptance actor and time are recorded. Organization routes are blocked until every active assigned organization has completed onboarding; platform provisioning remains separate. Self-reported registrations are not verified, and the test notice is not a production legal basis or patient-data consent.

## WP-1 decisions for the next slices

The product owner selected email-based, single-use administrator invitations valid for 24 hours, with initial synthetic provisioning by a trusted local operator. All organization users must enroll in MFA before accessing organization data. A synthetic platform administrator can provision an organization with an initial invitation, and an organization administrator can invite a new account via configured SMTP. Membership and invitation lifecycle operations are available in the synthetic workspace. These are development decisions, not production security, delivery, recovery or hosting approvals. MFA recovery is limited to one-time recovery codes and self-service rotation after fresh password confirmation and MFA; support identity-proofing, account-wide suspension, session revocation policy, setup rate limiting, comprehensive lifecycle audit, and production security review remain outstanding.

The new audit coverage is deliberately limited to authenticated requests reaching the gated management routes, including invitation issuance. It does not yet cover invitation acceptance, authentication, MFA, denied unauthenticated requests, requests rejected before route binding, or server failures. It does not provide production-grade tamper resistance, retention, access controls, monitoring or a review interface. These remain required before production.

## Synthetic management API

These routes are disabled by default and return 404 unless `SYNTHETIC_MANAGEMENT_ENABLED=true` **and** `APP_ENV` is `local` or `testing`. The switch is for local synthetic fixtures only; it is not production approval. Authentication uses the existing server-managed web session, organization access requires MFA verification, and state-changing requests require CSRF. Comprehensive audit and a reviewed role policy are still outstanding, so do not expose this API as a live service.

| Method and path (under `/api/v1`) | Scope |
|---|---|
| `GET /organizations` | Paginated organizations accessible to the current user. |
| `GET /onboarding` | Lists incomplete active organization assignments and whether the user may complete them. |
| `POST /organizations/{organization}/onboarding` | Organization administrator records details, authority confirmation and versioned synthetic test-processing consent. |
| `GET /organizations/{organization}` | Accessible organization's ID, name, status and `can_manage` permission for the current user. |
| `PATCH /organizations/{organization}` | Organization admin updates `name`. |
| `POST /organizations/{organization}/invitations` | Organization admin emails a single-use invitation to a new administrator account. |
| `GET /organizations/{organization}/invitations` | Organization admin lists invitation states. |
| `POST /organizations/{organization}/invitations/{invitation}/resend` | Organization admin reissues a valid, non-revoked invitation with a fresh token. |
| `DELETE /organizations/{organization}/invitations/{invitation}` | Organization admin revokes an unaccepted invitation. |
| `GET /organizations/{organization}/memberships` | Organization admin lists members and their roles, status, and facility scope. |
| `PATCH /organizations/{organization}/memberships/{membership}` | Organization admin changes a member's role, status, or facility assignment. |
| `DELETE /organizations/{organization}/memberships/{membership}` | Organization admin removes a member's organization access. |
| `GET /organizations/{organization}/licensing` | Organization admin views the last verified KernelBridge account-link snapshot and registered installations. |
| `POST /organizations/{organization}/licensing/link` | Organization admin links a KernelBridge MEDIFLOW activation code to this organization. The submitted code is not persisted. |
| `POST /organizations/{organization}/licensing/refresh` | Organization admin refreshes license and entitlement status from KernelBridge. |
| `DELETE /organizations/{organization}/licensing/link` | Organization admin unlinks the business and revokes all linked installation credentials/codes. |
| `POST /organizations/{organization}/installations` | Organization admin registers a facility-bound MediFlow installation UUID. |
| `POST /organizations/{organization}/installations/{installation}/activation-code` | Organization admin issues a one-time, installation-bound activation code. |
| `POST /organizations/{organization}/installations/{installation}/revoke` | Organization admin revokes one KernelBridge installation and any unused activation code. |
| `GET /organizations/{organization}/facilities` | Paginated facilities in the user's scope. |
| `POST /organizations/{organization}/facilities` | Organization admin creates an inactive facility with `name` and optional `locale`/`time_zone`. |
| `GET /organizations/{organization}/facilities/{facility}` | Facility in the user's scope. |
| `PATCH /organizations/{organization}/facilities/{facility}` | Organization admin updates `name`, `locale` and/or `time_zone`. |

Lists use 25 records per page. `GET /platform/organizations` and `POST /platform/organizations` list basic organization metadata and provision an organization with an initial invitation for a synthetic platform administrator. There is no public registration. `POST /invitations/accept` accepts a valid invitation for a new account, requires CSRF and is gated to local/testing. A facility admin can see the organization's ID, name and status but not its legal identity reference. This slice does not interpret organization or facility status as an access control; a status enforcement policy remains to be approved. The gate depends on correctly configured environment variables and does not replace deployment or data-governance controls.

## Intentionally not implemented

- Verified support recovery for users who lose all authenticators and recovery codes, account-wide suspension/session revocation policy, production platform-role administration, approved MFA/recovery policy, comprehensive audit coverage and audit operations, organization status changes and deletion, application-specific migrations beyond the foundation schema, queue/worker configuration, object storage, and deployment automation.
- WP-2 sandbox end-to-end verification with a contract-issued MEDIFLOW product credential, verification of activation codes from MediFlow Platform in an actual test server, key rotation/revocation and offline-grace acceptance, deployment secret configuration, and operator rollback/reconciliation procedures.
- MedPass, FHIR, backup, provider-network, and operational sync integrations.
- Any real data migration, production deployment, license-slot consumption, sensitive-data transfer, or modification to a live system.

## Validation and handoff checkpoint

Before handing this checkpoint to another developer, run:

```powershell
cd C:\laragon\www\mediflow
php artisan test
npm run build
```

The API requires PHP to be available on `PATH`; this workstation currently needs the Laragon PHP path configured explicitly. Record npm's exact version with each handoff, alongside the validation output.

**Validated:** after moving Laravel to the project root and integrating Laravel Vite, 55 API tests pass (373 assertions), excluding the platform-admin seeder test because the current locally modified seeder stops execution with `dd()`. Laravel serves the built portal and API, and Vite hot reload serves the React entry point. The portal build passes and lint exits successfully with two `react(set-state-in-effect)` warnings in `Management.tsx` (Node.js 24.19.0, npm 11.17.0). Earlier WP-2 checks passed the KernelBridge installation activation suite (18 tests, 145 assertions) and MediFlow Server central licensing suite (30 tests, 221 assertions). External HTTP calls in automated tests are faked; no real license activation or slot consumption is performed.

## Next implementation checkpoint

Proceed with WP-1 and WP-3 through WP-6 readiness work in dependency order, while completing WP-2 sandbox end-to-end verification with a dedicated MEDIFLOW test credential and test license. Production hosting and real data remain blocked on the release evidence and controls above. Do not use production activation codes or consume live license slots during validation.
