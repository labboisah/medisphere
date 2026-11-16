# MediFlow Platform portal

The React and TypeScript portal provides the welcome page, server-managed sign-in and two-factor challenge, account security settings, and an organization dashboard after MFA verification. Users can use a one-time recovery code at sign-in and rotate their recovery-code set after fresh password confirmation and MFA verification. Organization administrators can list invitations, resend or revoke eligible invitations, and manage member roles, facility assignments, suspension, and access removal. A suspended or removed membership immediately loses access to that organization on subsequent API requests. The platform remains a non-production foundation: use synthetic data only. Monitoring, MedPass exchange, backups, and provider services are not yet available.

## Run from Laravel

From the project root, install the locked dependencies if needed, build the React assets, and start Laravel:

```powershell
cd C:\laragon\www\mediflow
npm ci
npm run build
php artisan serve --host=127.0.0.1 --port=8000
```

Open `http://127.0.0.1:8000`. Laravel serves the portal at `/` and its API at `/api`. The React sources are in `resources/js/portal` and Laravel Vite builds them into `public/build`. Rebuild after portal changes when not using the development server. If PHP is not on `PATH`, use the Laragon PHP executable instead. Laravel must remain running, and its database and session configuration must be valid for sign-in. Use `SESSION_SECURE_COOKIE=false` only for local HTTP; use HTTPS and secure cookies in any approved deployment.

For frontend development with hot reload, start Laravel as above and run `npm run dev` in a second terminal at the project root. Continue opening the Laravel address, not the Vite address; Laravel loads the development assets from Vite and handles all API requests itself. Run `npm run lint` to check the portal.

## Local synthetic administrator

For a local, synthetic-data-only trial, set `APP_ENV=local` and `SYNTHETIC_MANAGEMENT_ENABLED=true` in the root `.env`, apply migrations with `php artisan migrate`, then run `php artisan db:seed --class=SyntheticOrganizationAdminSeeder` from the project root. The explicit seeder creates a synthetic organization, a clinic, and an organization administrator; it does not create a platform-wide administrator. It prints a randomly generated password once. Keep it private, sign in at the portal, and enroll an authenticator and save the recovery codes before accessing management. Scan the locally generated QR code in the authenticator app, or enter the manual key if scanning is not possible; treat both as secrets. The seeder refuses to run outside local/testing or when the synthetic gate is disabled, and refuses to overwrite an existing account with its fixed test email. It is not included in the default database seeder and must never be used for production or real data.

### Organization onboarding

Invited administrators sign in and complete MFA before onboarding. The onboarding page collects synthetic legal name and type, ownership, self-reported government or governing-body registration, address, authorization and acceptance of the displayed versioned test-processing notice. It records the accepting user and time. Registration details are not automatically verified.

All existing and new organizations must complete onboarding before their members can access organization features. A user assigned to several organizations must wait until all active assignments have completed onboarding; only an active organization-wide administrator can submit it. Facility administrators should contact their organization administrator and use **Check onboarding status** after completion. Platform administrators retain their separate provisioning workspace, not automatic organization access. An account with no active organization assignment should contact its platform administrator.

This flow is available only under the local/testing synthetic-management gate. Its notice is not an approved privacy policy, legal basis, production consent, or patient-data-processing authorization. Do not enter real organization or patient data. Production requires separately approved processing terms, legal basis, privacy safeguards, verification and deployment controls.

### Synthetic invitations

Configure the API's local `.env` with `MAIL_MAILER=smtp`, your SMTP connection and sender settings, and `SYNTHETIC_PORTAL_URL` pointing to the portal origin (for example `http://127.0.0.1:8000` when Laravel serves the portal). Use only controlled test recipients. HTTP is allowed only for localhost/loopback; other portal origins require HTTPS. Back up the database and apply new migrations with `php artisan migrate`. Restart long-running processes after deployment.

Platform administrators define organization-wide roles under **Roles and permissions**. Changes apply immediately to every assigned member across all organizations; review the impact before saving. Role creation and changes are recorded with their before/after permissions. A user with invitation or user-management permission can assign only roles whose permissions are a subset of their own; user managers cannot modify a higher-privilege member. License management includes unlinking the account, which revokes all installations. Server management includes issuing codes that reserve license slots. Grant these permissions deliberately.

The initial organization administrator invitation is sent during platform provisioning, before onboarding. Additional organization administrators cannot be invited or promoted through the organization workspace. Outstanding legacy administrator invitations issued outside platform provisioning, or after onboarding, cannot be accepted or resent. Existing administrators and facility administrators retain their existing access. To invite other users, an authorized member selects a platform-defined role under **Invitations**. The resulting membership is organization-wide and belongs only to the selected organization. The recipient opens the private link, creates a new account, signs in, and completes MFA setup. Links expire after 24 hours and work once; existing accounts cannot be invited in this slice. An invitation becomes invalid if its issuer no longer has the required invitation and delegation permissions. The invitation token is stored as a hash and placed in the URL fragment so it is not sent in the initial HTTP request; the portal removes it from the address bar. Do not forward the link or log email content.

SMTP is synchronous: delivery failures are reported, and a normal transport failure rolls back the invitation. A message sent immediately before a database or audit commit failure can contain an unusable link; the administrator must send a new invitation. Durable email delivery, existing-account invitations, and production approvals remain outstanding. Never use this flow for real facility or patient data.

### MFA recovery and access removal

Use an unused recovery code if an authenticator is unavailable. The **Account security** workspace requires password confirmation within five minutes and an MFA-verified session to show or rotate recovery codes; rotation invalidates every prior code. Each code works once. Store codes privately and do not send them to administrators or include them in support requests. If both the authenticator and all recovery codes are lost, use an independently verified deployment support process; organization administrators cannot bypass or reset MFA. That support/re-proofing procedure is not yet implemented.

Under **Organization → Organization users and roles**, an organization administrator can suspend, reinstate, or remove another member. A suspended or removed membership is denied by organization-scoped API checks on the next request. The API prevents self-suspension/removal and preserves at least one active organization administrator, including when multiple administrator changes race. Suspension is organization-scoped, not a global account suspension; account-wide suspension and session-revocation policy remain pending.

### KernelBridge account and server licensing (local/testing)

The WP-2 workflow is currently available only behind the existing `SYNTHETIC_MANAGEMENT_ENABLED` local/testing gate. Configure the API's uncommitted local environment with:

- `KERNELBRIDGE_API_URL` set to the KernelBridge Product Integration API base URL (HTTPS required outside localhost).
- `KERNELBRIDGE_PRODUCT_CODE=MEDIFLOW`.
- `KERNELBRIDGE_API_TOKEN` set to a dedicated MEDIFLOW product credential with the `licensing:account-link` scope. The existing `KERNELBRIDGE_PRODUCT_CREDENTIAL` setting remains supported and takes precedence when nonblank. Remove it if you intend to use `KERNELBRIDGE_API_TOKEN` instead.
- `KERNELBRIDGE_TIMEOUT_SECONDS` set to a short bounded request timeout.

After onboarding, every active organization assignment needs an approved, active KernelBridge link before its members can enter the organization workspace. Only organization-wide administrators can link or refresh an account; other members should contact an administrator. The platform administrator's separate provisioning workspace remains available. The gate checks KernelBridge on organization access; if the service is unreachable, a previously verified active link may be used for at most 24 hours, subject to its license, subscription and grace expiry times. Credential rejection, invalid responses and denied requests do not receive offline grace. Each access check can make a KernelBridge request, so configure an appropriate timeout and monitor availability.

The linking page uses the KernelBridge Laravel package's `installation` account-link view and Livewire, not its separate installation-code activation view. Composer expects the package checkout at `../kernelbridge-license/packages/laravel`, matching this local development layout. Composer's post-autoload script publishes its CSS and logo; if needed, run `php artisan vendor:publish --tag=kernelbridge-licensing-assets` from the project root. Only the server holds the scoped product credential. Synthetic-only deployment and production approval restrictions remain unchanged.

After changing credentials, clear Laravel's configuration cache with `php artisan config:clear` and restart long-running application workers. The token must be issued for MEDIFLOW; do not reuse another product's token.

Never put this product credential in the portal, browser storage, MediFlow Server, or source control. The account-link screen accepts a KernelBridge activation code, sends it server-to-server, and stores only public link/license/subscription identifiers and the last returned status/entitlements. The MediFlow organization UUID is the KernelBridge `business_uuid`.

An organization administrator or user with `servers.manage` can choose **Servers → Generate Server Activation Code** and enter the displayed code on the MediFlow Server platform-account activation page. An approved active account link and an available installation slot are required. The account-bound code is single-use and expires after 24 hours. It reserves a slot until used or expired; there is not yet a way to cancel an individual unused code. The Server binds its own installation identity at activation and verifies the signed entitlement snapshot. Legacy registered installations may continue using their existing ID-bound code flow and individual revocation. Unlinking the account revokes all installations and outstanding codes.

New account-bound codes are encrypted with Laravel's application encryption key in the Platform database and can be revealed again by members with `servers.manage` and verified MFA until expiry. The reveal is audited without recording the code. Codes issued before this change cannot be recovered. The list does not know whether a code has already been used or remotely revoked; it must not be interpreted as proof that a code is usable. Unlinking removes the locally saved codes. Protect and back up the application key separately from the database, and account for encrypted records in backup retention and key rotation procedures. Database access combined with the application key allows recovery of unexpired saved codes.

Back up the Platform database and run `php artisan migrate` before enabling this feature. Run Laravel's scheduler every minute (`php artisan schedule:run`) to delete expired encrypted codes; expired codes are not returned by the API even if the scheduler is delayed. Monitor scheduler failures. Database backups may retain older encrypted records after deletion. If issuing a code succeeds remotely but saving it or the response fails, an unavailable code may still reserve a slot until expiry.

The Servers screen retrieves activated installations directly from KernelBridge for the approved organization account link, including code-only activations. It shows the installation name, identity, status, activation, license verification and expiry times; use **Refresh activated servers** to reload. A license verification timestamp is not an operational heartbeat. This is a read-only live listing, not a local server inventory: code-only activations cannot yet be assigned to facilities or individually revoked in this workspace, and operational health data is not synchronized. KernelBridge's administrator can revoke an individual installation; whole-account unlink also revokes all installations. If KernelBridge is unavailable, the list reports an error instead of implying there are no servers. The Platform, KernelBridge and MediFlow Server must all run the updated code; rebuild the portal and restart or deploy each application as appropriate.

If issuing a code fails after KernelBridge receives the request, an unseen code may still reserve a slot until expiry. Do not repeatedly generate codes after a timeout; check the licensing service and allow the reservation to expire.

The MediFlow Server verifies the KernelBridge-signed entitlement snapshot and stores its installation credential encrypted. Existing direct activations are not automatically migrated or rebound. Automated tests fake KernelBridge responses; an actual sandbox end-to-end activation with a dedicated test credential/license is still required. Do not test with production credentials or consume a live license slot.

### Synthetic platform administrator

After enabling the synthetic management gate and applying migrations, run `php artisan db:seed --class=SyntheticPlatformAdminSeeder` from the project root. It creates a separate local/testing-only account and refuses to overwrite an existing account. Review the current seeder before using it: local modifications currently include a debug stop and a fixed password, which must be removed before safe use. Sign in, enroll in MFA, then use the platform workspace to create an organization and email a new initial organization administrator. SMTP and `SYNTHETIC_PORTAL_URL` must be configured as described above. The platform account can list basic organization names and statuses and provision new ones, but cannot access organization or facility data without an independent membership. Switch to the organization workspace to use any separately assigned membership.

The existing built-in roles remain: synthetic platform administrators provision organizations and define roles; organization administrators manage their own organization; facility administrators view assigned facilities only. Custom roles are organization-wide, not facility-scoped. The role and permission system is synthetic-only and is not a complete production permissions or privileged-access policy.

The portal self-hosts the Figtree font used by KernelBridge; its [Open Font License](../public/Figtree-OFL.txt) is included in the project. The welcome background and logo are copied from MediFlow Server.

See the [platform documentation](../README.md) for deployment boundaries and pending approvals. This page does not itself make the platform production-ready.
