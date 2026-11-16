# DRAFT: External readiness decisions for review

**Status:** Product-owner approved for readiness planning; external evidence remains open  
**Version:** 0.2  
**Prepared:** 15 November 2026  
**Decision scope:** Privacy/legal, clinical safety and sharing, and MedPass interoperability preparation  
**Related checklist:** [External privacy, clinical, and interoperability readiness pack](15-external-readiness-pack.md)

> **Important:** This draft records proposed project direction only. It is not legal advice, a privacy impact assessment, clinical safety approval, a data-sharing agreement, a MedPass contract/API specification, or authorization to process real patient information. Do not mark an external professional or partner decision approved on their behalf. All capabilities involving real patient data and production partner traffic remain disabled.

## 1. Decision requested from the product owner

Review the proposed project-level decisions below. For each, select **Approve**, **Edit**, or **Reject** and add the decision-maker, date, and any conditions. “Approve” here authorizes the project team to use the stated direction for readiness planning; it does not close the evidence items or authorize production use.

| ID | Proposed project-level decision | Product-owner response | Conditions / edits |
|---|---|---|---|
| D-01 | Continue privacy/legal, clinical-safety, and interoperability readiness work in non-production environments using synthetic data only. | Approved | |
| D-02 | Keep real patient-data processing/exchange, production MedPass traffic, backups, and provider-network traffic disabled until the relevant evidence is complete and a scope-specific release decision is recorded. | Approved | |
| D-03 | Treat jurisdiction, processing roles, lawful basis, hosting/residency, retention, and agreements as open decisions; do not infer them from stakeholder approval or from the software design. | Approved | |
| D-04 | Design any initial clinical exchange, if later approved, as narrow and read-only, with explicit patient/representative authorization where applicable, manual review of uncertain identity matches, no automatic merge, and visible provenance/freshness/partial status. | Approved | |
| D-05 | Treat FHIR R4 as a candidate baseline for partner discussion only. Do not claim MedPass compatibility or freeze resource/profile scope until the partner capability and a jointly reviewed contract are verified. | Approved | |
| D-06 | Require separate qualified legal/privacy, clinical-safety, security, facility, and MedPass decisions for the exact pilot scope; product-owner approval cannot substitute for them. | Approved | |
| D-07 | Use the [external readiness pack](15-external-readiness-pack.md) as the tracking checklist, with each item remaining open until its named accountable owner reviews the specified evidence. | Approved | |

### Product-owner decision

- **Decision:** ☒ Approved for readiness planning ☐ Approved with edits ☐ Not approved
- **Name and role:** isah labbo, director
- **Date:** 15 November 2026
- **Conditions / required edits:** Approval is limited to readiness planning. It does not close external legal, privacy, clinical, security, facility, or partner evidence and does not authorize production use.

## 2. Scope facts that must be supplied before external approval

These are intentionally blank. The project team must not guess or silently select values.

| Required fact | Entry |
|---|---|
| Proposed pilot name, version, and target date/window | TBD |
| Countries/regions where participating facilities and patients are located | TBD |
| Hosting region(s), storage locations, and cross-border flow(s) | TBD |
| Participating facility legal entities and named facility owners | TBD |
| MedPass legal entity/product and verified executive sponsor | TBD |
| MedPass API/technical owner, clinical owner, privacy contact, and security contact | TBD |
| Intended use, clinical users, population, and excluded populations/use cases | TBD |
| Specific purposes and proposed data categories | TBD |
| Proposed recipient(s), onward disclosure, and recipient copy/retention behavior | TBD |
| Proposed pilot environment and sandbox availability | TBD |
| Product, operations, incident, and clinical stop/go contacts | TBD |

## 3. Draft direction for review by accountable owners

The statements below are proposals for owner discussion, not resolved findings. Qualified owners should replace, qualify, or reject them using the evidence recorded in the readiness pack.

### 3.1 Privacy and legal

**Proposed direction**

- Determine applicable law and required safeguards for the specific deployment jurisdictions and processing activities before any identifiable data is processed.
- Determine roles separately for the facility/EHR, MediSphere platform, MedPass, hosting providers, and subprocessors. Do not assume one role applies to every activity.
- Determine the lawful basis and any additional conditions for each purpose and data category. Use consent only where it is the applicable basis and is valid for the workflow; do not treat general stakeholder approval as patient consent or as a legal basis.
- Complete the applicable privacy impact assessment and approve the data-flow, minimization, access, retention, deletion, rights-handling, incident, and cross-border-transfer decisions.
- Execute the required data-sharing/processing and hosting agreements before the covered processing begins.
- Keep patient-identifiable data, partner confidential material, credentials, and production records out of this repository and synthetic test fixtures.

**Owner decisions required**

- Legal lead: **Approval recorded; name and evidence reference not provided.** Date: 15 November 2026.
- Privacy lead: **Approval recorded.** Name: Isah Labbo. Date: 15 November 2026.
- Evidence reference(s), version(s), and conditions:  
  Not provided; legal/privacy evidence requirements in the readiness pack remain open.

### 3.2 Clinical safety and patient sharing

**Proposed direction**

- Define intended use, population, care setting, clinical responsibilities, and exclusions before designing a live exchange workflow.
- Do not automatically match or merge patients. Keep uncertain identity cases pending for authorized human review; define representative/guardian authority and correction/dispute handling with clinical, privacy, and legal owners.
- Separate the legal basis from the technical grant state. When consent is the applicable basis, capture what information is shared, with whom, for what purpose and duration, under which notice/version, and how withdrawal is requested. Define other permitted bases separately.
- Check current authorization at request, selection, queue, and delivery boundaries. Define what revocation can stop, what happens to queued or offline work, and what cannot be recalled after a recipient has accepted a copy.
- Limit any first clinical pilot to jointly agreed, supported resources and a read-only workflow unless a separately reviewed decision authorizes otherwise. Show source, version, freshness, partial data, and limitations; do not infer absent findings or silently overwrite records.
- Require a clinical safety assessment, approved mapping, synthetic end-to-end testing, named clinical stop authority, incident process, and local-care continuity plan before a pilot.

**Owner decisions required**

- Clinical safety lead: **Approval recorded.** Name: Abdulrahman Yusuf. Date: 15 November 2026.
- Facility clinical owner: **Approval recorded.** Name: Abdulbasid Musa. Date: 15 November 2026.
- Privacy/MedPass clinical review: **Approval recorded; role is not identified.** Name as entered: Sani Musah Habib Ibrahim. Date: 15 November 2026.
- Evidence reference(s), version(s), and conditions:  
  Not provided; clinical safety, mapping, workflow, and test evidence remain open.

### 3.3 MedPass ownership and interoperability

**Proposed direction**

- Verify the MedPass contracting entity, API/product ownership, and authorized technical, clinical, privacy, and security contacts before treating the integration as an available partner connection.
- Obtain partner-confirmed sandbox and production environment details, supported capability statements, protocol and profile versions, endpoint/security requirements, support model, and change/deprecation process.
- Jointly agree the API contract, FHIR release and profiles, resource scope, terminology, authentication/scopes, patient identity/linking, authorization responsibilities, revocation behavior, provenance/freshness, receipts, partial success, retries, corrections, and recipient retention/deletion.
- Use synthetic fixtures for conformance and negative tests. Do not use production credentials or patient records for readiness testing.
- Do not claim general FHIR interoperability or MedPass connectivity from the existence of an adapter or design document alone. Validate against the partner’s actual environment and jointly accepted tests.

**Owner decisions required**

- MedPass authorized sponsor/API owner: **Approval recorded; name, role, organization, and date not provided.**
- MediSphere interoperability owner: **Approval recorded; name and date not provided.**
- MedPass security/privacy/clinical review: **Approval recorded; names, roles, and dates not provided.**
- Joint specification / agreement / test evidence references:  
  Not provided. Partner identity, ownership, contract, API specification, and conformance evidence remain open.

## 4. Conditions before a production pilot can be proposed

No decision in this draft closes these conditions. A release-specific decision must reference evidence and name who accepted any remaining risks.

- [ ] Jurisdiction, data-flow, party-role, lawful-basis, hosting, transfer, retention, and agreement decisions are documented and approved by qualified owners.
- [ ] Applicable privacy impact assessment is approved, with residual risks assigned and resolved or explicitly accepted by the authorized owner.
- [ ] Patient/representative identity, notice, authorization, revocation, correction, and rights-handling workflows are approved and tested with synthetic cases.
- [ ] Clinical intended use, hazard assessment, mapping, continuity procedures, pilot protocol, stop criteria, and clinical sign-off are complete.
- [ ] MedPass ownership and endpoints are verified; a jointly versioned contract and applicable security/operational agreements are effective.
- [ ] Partner sandbox conformance and negative tests pass with synthetic data; unresolved defects and limitations are documented.
- [ ] Security, tenant isolation, comprehensive audit, monitoring, incident response, backup/recovery, deployment, and rollback gates are separately satisfied for the proposed scope.
- [ ] The release owner records a specific go/no-go for named parties, facilities, environment, purpose, resources/data, versions, dates, and enabled configuration.

## 5. Approval boundary

Approval of this draft by the product owner means only that the proposed planning direction is acceptable, subject to recorded edits and conditions. It does **not**:

- establish compliance with any law or determine controller/processor roles;
- approve a privacy impact assessment, lawful basis, patient notice, consent, or data-sharing agreement;
- constitute clinical safety approval or authorize a clinical pilot;
- verify MedPass identity, ownership, API capability, or partner agreement;
- authorize real patient data in development, testing, demonstration, or production;
- enable production administration, patient exchange, backup, provider traffic, or production feature flags.

## 6. Final review record
6qLp+58W5PT-qn conditions |
|---|---|---|---|---|
| Product-owner planning direction | Approved for readiness planning | Isah Labbo, Director | 15 November 2026 | Does not authorize live processing or close external evidence. |
| Legal/privacy direction (not legal clearance) | Approval recorded; evidence/identification incomplete | Legal lead not named; Privacy lead: Isah Labbo | 15 November 2026 as recorded | No legal clearance, approved assessment, lawful-basis determination, or agreement evidenced here. |
| Clinical-safety direction (not pilot approval) | Approval recorded; evidence/role details incomplete | Abdulrahman Yusuf; Abdulbasid Musa; Sani Musah Habib Ibrahim (review role unspecified) | 15 November 2026 as recorded | No completed safety case, mapping approval, or pilot authorization evidenced here. |
| MedPass partner/API direction (not contract acceptance) | Approval recorded; signatories/evidence incomplete | Not provided | Not provided | No verified partner authority, executed agreement, or jointly approved API contract evidenced here. |

**Current operational restriction:** continue with synthetic data in non-production. Keep real patient-data exchange and production MedPass traffic disabled unless and until the separate evidence and release-specific approvals above are complete.
