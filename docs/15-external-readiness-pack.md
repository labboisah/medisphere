# External privacy, clinical, and interoperability readiness pack

## Purpose and status

Use this pack to obtain and record the external decisions and evidence needed before production privacy/legal approval, clinical approval, or a MedPass connection can be claimed. It turns the open items in the [implementation progress record](14-implementation-progress.md#remaining-release-evidence-and-controls) into reviewable work.

**Current status: OPEN.** No item below is marked complete by this document. The stakeholder acceptance records approval to proceed with planned patient-data exchange; it is not, by itself, a jurisdiction-specific legal basis, a privacy impact assessment, clinical safety acceptance, an executed data-sharing agreement, or an API contract.

This is a project coordination checklist, not legal, privacy, regulatory, or clinical advice. The accountable qualified owners must determine applicable requirements and approve the evidence. Do not enter patient-identifiable information, credentials, or confidential partner material in this repository. Use synthetic data for technical examples and tests.

## How to use

1. Assign a named accountable owner and reviewer for each row; role titles alone are not a completed assignment.
2. Obtain the cited evidence from the responsible organization or professional. Record its controlled location, title, version, date, and scope; do not copy confidential documents into this repository without authorization.
3. Record an explicit decision, rationale, conditions, and approval date in the decision record below. “Discussed,” “approved in principle,” and stakeholder acceptance are not substitutes for the required evidence.
4. Set an item to **Closed** only after the accountable reviewer confirms evidence is sufficient for the stated scope. Use **Not applicable** only with written rationale and reviewer approval.
5. Reopen decisions when jurisdiction, purpose, patient population, data scope, partner, API version, hosting, or processing changes.
6. Keep patient-data exchange, production partner traffic, and associated feature gates disabled until all applicable rows are closed and the release-specific authorization is recorded.

Status values: **Open**, **In progress**, **Blocked**, **Closed**, **Not applicable**. All entries begin **Open**.

## A. Privacy and legal readiness

| ID | Required decision or evidence | Accountable owner | Reviewer / evidence required to close | Status |
|---|---|---|---|---|
| PL-01 | Identify the intended pilot countries/regions, facility locations, hosting locations, and any cross-border data flows. | Product owner + legal lead | Privacy/legal review of a versioned data-flow map and deployment-region decision. | Open |
| PL-02 | Map the entities and roles for each processing activity, including the facility/EHR, platform, MedPass, hosting providers, and subprocessors. Determine controller, joint-controller, processor, or other applicable roles rather than assuming one global role. | Legal lead | Written, activity-specific role analysis approved by the relevant organizations. | Open |
| PL-03 | Determine applicable privacy/health-data laws and requirements, lawful basis and any additional conditions for each purpose and data category. Distinguish consent from other legally permitted bases; stakeholder approval alone does not establish a lawful basis. | Legal + privacy leads | Written legal/privacy determination with jurisdiction and purpose scope. | Open |
| PL-04 | Complete and approve a privacy impact assessment (or applicable equivalent) before identifiable data is processed. Include necessity, proportionality, risks to patients, mitigations, residual risks, and consultation/approval requirements. | Privacy lead | Approved assessment, dated and versioned, with residual-risk owners. | Open |
| PL-05 | Agree hosting/residency, international transfer mechanism if required, subprocessors, security obligations, audit rights, breach notification, assistance, and end-of-contract handling. | Legal + security + operations leads | Approved hosting/subprocessor schedule and executed agreements where required. | Open |
| PL-06 | Define patient-facing and facility-facing transparency: purposes, data items, recipients, sharing choices, consequences, rights, complaints, and contact route. | Privacy + product leads | Approved notice(s) mapped to the actual pilot workflow and data flows. | Open |
| PL-07 | Define processes, authority, and response times for access, correction, objection/withdrawal, restriction, deletion, complaints, and legally required retention. Include minors and representatives where relevant. | Privacy + legal + clinical leads | Approved rights-handling procedure and responsibility matrix. | Open |
| PL-08 | Set retention and deletion rules for source extracts, queues, platform copies, recipient copies, logs, backups, and legal holds. State what revocation can and cannot remove after delivery. | Privacy + legal + MedPass leads | Approved retention schedule and documented recipient/deletion obligations. | Open |
| PL-09 | Execute data-sharing/processing agreements and define permitted purposes, dataset, recipient, onward disclosure, security, incident handling, audit, retention, and termination. | Legal leads for each party | Signed, effective agreements for the named pilot parties and scope. | Open |
| PL-10 | Confirm incident/breach escalation contacts, decision authority, notification assessment and timelines, evidence preservation, and patient/facility communications. | Security + privacy + operations leads | Exercised or reviewed incident procedure with named on-call contacts. | Open |

### Privacy/legal decision record

Complete one record per deployment/pilot scope; add a separate controlled record if a material change occurs.

| Field | Entry |
|---|---|
| Pilot / deployment scope and version | TBD |
| Countries/regions and hosting locations | TBD |
| Processing parties and roles | TBD |
| Purposes and data categories | TBD |
| Lawful basis and any additional health-data condition, by purpose | TBD |
| Assessment/agreement evidence references and versions | TBD |
| Open conditions, residual risks, and accountable owners | TBD |
| Legal lead decision / name / date | OPEN |
| Privacy lead decision / name / date | OPEN |
| Review or expiry trigger | TBD |

## B. Clinical safety and consent/sharing workflow

| ID | Required decision or evidence | Accountable owner | Reviewer / evidence required to close | Status |
|---|---|---|---|---|
| CS-01 | Define intended use, patient population, clinical users, supported setting, and explicit exclusions. Confirm any regulatory classification or clinical-safety obligations with qualified owners. | Clinical safety lead + product owner | Approved intended-use statement and applicable regulatory determination. | Open |
| CS-02 | Complete a clinical hazard/risk assessment covering wrong-patient links, incomplete/stale records, mapping/unit errors, delayed or duplicate delivery, revocation races, downtime, and inappropriate reliance. | Clinical safety lead | Approved safety case with mitigations, residual-risk acceptance, and traceability to tests. | Open |
| CS-03 | Define patient/representative identity verification and authority, including ambiguous matches, duplicate records, corrections, disputes, minors, guardians, and loss of authority. No automatic patient merge. | Clinical + privacy + MedPass leads | Approved workflow and synthetic test cases demonstrating safe holds and review. | Open |
| CS-04 | Define grant/consent capture where consent is the applicable basis: who presents it, identity/authority, data scope, named recipient, purpose, duration, version of notice, evidence, and audit fields. Define other lawful bases separately. | Privacy + clinical + MedPass leads | Approved interaction and evidence schema consistent with the legal decision. | Open |
| CS-05 | Define authorization checks at request, source selection, queueing, and delivery; include expiry, suspension, revocation, queued items, offline facilities, retries, and recipient acknowledgement. | Clinical + security + MedPass leads | Approved state model and tests showing revoked/expired grants block future dispatch as specified. | Open |
| CS-06 | Define patient and clinician views of shared items, history, recipients, delivery/partial/failure status, source, provenance, freshness, and known incompleteness. | Clinical + product + MedPass leads | Clinical UX review and synthetic usability scenarios. | Open |
| CS-07 | Approve the exact resource/profile/terminology mappings, required fields, units, provenance, amendment/correction behavior, and exclusions. Do not infer missing data or unsupported resources. | Clinical informatics lead + MedPass clinical owner | Signed mapping specification and independent clinical review against synthetic fixtures. | Open |
| CS-08 | Define clinical responsibility for reviewing incoming information, discrepancy handling, patient correction, incident escalation, and safe local-care continuity during platform or network outage. | Clinical lead + facility clinical owner | Approved operating procedure and downtime exercise results. | Open |
| CS-09 | Establish pilot protocol: facilities, duration, enrollment, training, support, measurable safety/quality endpoints, inclusion/exclusion, stop criteria, rollback, and incident review. | Clinical lead + product owner + participating facility | Approved pilot protocol and named decision authority for pause/resume. | Open |
| CS-10 | Validate all safety-critical workflows with synthetic end-to-end cases, including false/uncertain match, representative authority, partial/stale records, invalid mapping, revocation during queueing, delayed receipt, and correction. | Clinical safety + QA leads | Signed test report, unresolved defects/risk acceptance, and release traceability. | Open |

### Clinical safety and sharing decision record

| Field | Entry |
|---|---|
| Intended use, population, setting, and exclusions | TBD |
| Approved resource/profile scope and version | TBD |
| Identity and representative-authority procedure | TBD |
| Applicable authorization basis and grant/consent procedure | TBD |
| Revocation, pending delivery, and recipient-copy behavior | TBD |
| Hazard assessment and residual-risk evidence | TBD |
| Pilot protocol, stop criteria, and rollback owner | TBD |
| Clinical safety lead decision / name / date | OPEN |
| Participating facility clinical owner decision / name / date | OPEN |
| Privacy/MedPass review / names / dates | OPEN |

## C. MedPass ownership, API, and interoperability

| ID | Required decision or evidence | Accountable owner | Reviewer / evidence required to close | Status |
|---|---|---|---|---|
| MP-01 | Verify MedPass legal entity/product owner, API/technical owner, security contact, clinical contact, and authority to approve the integration. | Product owner + MedPass sponsor | Independently verified organization/contact and written integration sponsorship. | Open |
| MP-02 | Confirm supported environments and endpoints, ownership, network/TLS requirements, maintenance/support model, sandbox access, and production change notification. | MedPass API owner + operations leads | Partner-confirmed environment inventory and test access using synthetic data. | Open |
| MP-03 | Agree protocol/version and conformance material: FHIR release, CapabilityStatement, implementation guides/profiles, supported interactions/searches, terminology, extensions, and unsupported resources. | MediFlow interoperability lead + MedPass API/clinical owners | Jointly versioned and signed interface specification; validate against actual partner capability. | Open |
| MP-04 | Agree patient identity/linking and authorization responsibilities: identifiers, verification, matching/manual review, representative authority, grant evidence, purpose, recipient, expiry, and revocation semantics. | Clinical + privacy + API owners | Joint workflow and responsibility matrix, consistent with PL and CS decisions. | Open |
| MP-05 | Agree authentication, authorization scopes, credential provisioning, storage, rotation, revocation, certificate requirements, and incident response. No partner secrets in source control or browser code. | Security leads for both parties | Approved security design and partner-issued sandbox credentials; rotation/revocation demonstrated. | Open |
| MP-06 | Define request and response schemas, provenance/freshness, validation failures, idempotency, per-item receipts, partial success, retry/backoff, rate limits, timeouts, and correlation identifiers. | API owners for both parties | Joint contract plus examples and machine-readable schemas where available. | Open |
| MP-07 | Define corrections, amendments, recipient retention/deletion, revocation after dispatch, delayed/offline delivery, duplicate handling, and patient-visible status. | Clinical + privacy + API owners | Documented lifecycle contract, including what cannot be recalled after recipient acceptance. | Open |
| MP-08 | Complete synthetic sandbox conformance and negative tests for profile validation, wrong/uncertain patient, denied/expired/revoked access, stale/partial records, duplicate delivery, retries, errors, and partner outage. | QA + interoperability + clinical leads | Reproducible test report signed by both technical owners; no real patient information. | Open |
| MP-09 | Agree production support, monitoring, availability/maintenance communication, incident escalation, version deprecation, change control, and termination/credential revocation. | Operations + security + MedPass owners | Joint support and operational runbook with named contacts and escalation route. | Open |
| MP-10 | Confirm all partner agreements, privacy/legal conditions, clinical mapping approvals, security review, and pilot constraints are satisfied for the exact release. | Product owner + legal/privacy/clinical/security leads | Release-specific sign-off referencing the signed contract and supporting evidence. | Open |

### MedPass/API decision record

| Field | Entry |
|---|---|
| Partner legal entity and verified product/API owner | TBD |
| Sandbox and production endpoint ownership | TBD |
| FHIR/API version, profiles, and CapabilityStatement reference | TBD |
| Supported operations, resource scope, and exclusions | TBD |
| Authentication, scopes, key custody, and rotation | TBD |
| Patient identity, grant, and revocation responsibility split | TBD |
| Delivery receipts, errors, retry, and correction contract | TBD |
| Executed agreement and joint specification references/versions | TBD |
| MedPass technical owner decision / name / date | OPEN |
| MediSphere interoperability owner decision / name / date | OPEN |
| Partner clinical/privacy/security decisions / names / dates | OPEN |

## D. Cross-workstream release gate

Do not treat completion of this checklist as blanket permission to exchange data. Approval is limited to the exact named pilot, parties, environment, data scope, API/profile versions, purposes, and dates recorded in the evidence.

Before any real patient-data processing or production partner connection, the release owner must verify:

- [ ] Applicable privacy/legal decisions and required agreements are approved and effective for this deployment.
- [ ] Privacy impact assessment and data-flow inventory are approved; residual risks have named owners and accepted dispositions.
- [ ] Clinical safety case, mappings, identity workflow, sharing authorization, revocation, pilot protocol, and stop criteria are approved.
- [ ] MedPass ownership and endpoint are verified; joint contract, security design, and synthetic sandbox tests are accepted.
- [ ] Security, tenant-isolation, audit, monitoring, incident response, and operational readiness gates are separately satisfied.
- [ ] Required backup/recovery evidence and production deployment/rollback evidence are complete for the capabilities in scope.
- [ ] Release-specific approval is recorded by product, legal, privacy, clinical safety, security, operations, facility, and MedPass owners as applicable.
- [ ] Configuration and deployment evidence confirm only the specifically approved capability is enabled; all unrelated capabilities remain off.

| Release record | Entry |
|---|---|
| Release / deployment / pilot identifier | TBD |
| Approved parties, facilities, environment, purpose, and data scope | TBD |
| Evidence bundle and version references | TBD |
| Unresolved risks/conditions and accepted owners | TBD |
| Go/no-go decision and scope | OPEN |
| Decision-makers, roles, dates, and signatures/reference | OPEN |
| Expiry, review date, or change trigger | TBD |

**Until this gate is explicitly approved:** continue using synthetic data in non-production. Do not enable patient-data exchange, production MedPass traffic, or production feature flags.
