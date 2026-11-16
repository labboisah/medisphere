# MediSphere Platform: product and implementation documentation

This directory is the design workspace and non-production foundation for the proposed hosted MediSphere Platform (formerly MediFlow Platform). Laravel lives at the project root, with the React portal integrated through Laravel Vite. Authentication, tenant management and licensing have synthetic/local implementations; production infrastructure and patient-data handling are not implemented. Existing MediFlow server names, licensing product codes, entitlement keys and integration identifiers remain unchanged.

For local startup, build the assets with `npm run build` and run `php artisan serve` from this directory. See the [portal setup guide](docs/portal-setup.md) for hot reload and local configuration.

The platform is intended to connect healthcare organizations and their MediFlow installations to licensing, operational monitoring, controlled MedPass exchange, backup coordination, and eventually a trusted provider network. Local healthcare operations remain on the facility server, including during internet outages.

## Read in this order

1. [Product scope and decisions](docs/01-product-scope.md)
2. [Architecture and technology stack](docs/02-architecture-and-stack.md)
3. [System requirements and nonfunctional targets](docs/03-system-requirements.md)
4. [Organizations, servers, and KernelBridge licensing](docs/04-organizations-and-licensing.md)
5. [Offline synchronization and server monitoring](docs/05-offline-sync.md)
6. [FHIR, MedPass, and interoperability](docs/06-fhir-medpass.md)
7. [Backups and disaster recovery](docs/07-backup-recovery.md)
8. [Provider network and services](docs/08-provider-network.md)
9. [Security, privacy, and clinical governance](docs/09-security-privacy.md)
10. [UX and UI design](docs/10-ux-ui.md)
11. [Data model and proposed contracts](docs/11-data-and-contracts.md)
12. [Delivery, testing, and operations](docs/12-delivery-and-operations.md)
13. [Implementation work packages and handoffs](docs/13-implementation-work-packages.md)
14. [Implementation progress and checkpoints](docs/14-implementation-progress.md)
15. [WP-0 approval record (approved for synthetic-data progression)](docs/MEDIPASS–MEDIFLOW%20INTEGRATION%20PROJECT.docx)

## Baseline and caveats

- The selected proposed stack is a React and TypeScript frontend backed by a Laravel API and background workers. This is a new hosted application, not KernelBridge and not a replacement for the facility EHR; the existing MediFlow server retains its own stack.
- The local MediFlow Server checkout currently documents a **disabled-by-default, limited, read-only FHIR R4 adapter** and a separate MediFlow-specific sync subsystem. See [MediFlow Server's FHIR plan](https://github.com/labboisah/mediflow-server/blob/main/docs/FHIR_READINESS_AND_ENABLEMENT_PLAN.md), [licensing documentation](https://github.com/labboisah/mediflow-server/blob/main/docs/CENTRALIZED_ACTIVATION_AND_LICENSING.md), and [Care Network operations](https://github.com/labboisah/mediflow-server/blob/main/docs/CARE_NETWORK_OPERATIONS.md). Their documentation and actual deployed version must be verified before integration.
- MedPass was described by the product owner, but its source location, contracts, deployment and capabilities have not been verified. All MedPass interactions in these documents are proposals, not claims of existing support.
- Initial clinical exchange is a proposed pilot, subject to legal, clinical, operational and interoperability approval. FHIR support alone is not certification or proof of compatibility.
- Sample URLs, keys, payloads, deadlines, limits and performance targets in these documents are proposals or placeholders. Do not copy secrets or patient data into these files.

## Final stakeholder approval and production-readiness status

The updated [final stakeholder acceptance](docs/FINAL%20STAKEHOLDERS%20APPROVAL.docx) records stakeholder approval for planned real patient-data exchange. This approval does not establish that the system is ready for live exchange: production use remains blocked until technical, legal, clinical, security, and operational release evidence is complete. See the [implementation progress and remaining release evidence](docs/14-implementation-progress.md#remaining-release-evidence-and-controls) and the [decision register](docs/01-product-scope.md#decision-register).

## External reference standards

- [HL7 FHIR R4](https://hl7.org/fhir/R4/) and [CapabilityStatement](https://hl7.org/fhir/R4/capabilitystatement.html).
- [SMART App Launch](https://hl7.org/fhir/smart-app-launch/) and [FHIR Bulk Data Access](https://hl7.org/fhir/uv/bulkdata/); these are candidate specifications, not claims that MediFlow currently supports them.
- [WHO SMART Guidelines reference architecture](https://smart.who.int/ra/).
- [CISA StopRansomware guide](https://www.cisa.gov/stopransomware/ransomware-guide).
