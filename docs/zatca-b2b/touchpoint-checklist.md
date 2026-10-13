# B2C touchpoint checklist (blueprint Appendix B)

*Assumption for this pass: the full plan is deployed.* Every shared artefact that B2C also executes is listed. "Test" = the regression protection; "Rollback" = how to undo the touchpoint alone.

| TP | Artefact (file) | Why touched | Why safe for B2C | Regression test | Rollback |
|---|---|---|---|---|---|
| TP-01 | `packages/zatca/config/zatca.php` | b2b flag, b2b EGS composition, clearance retries/claim TTL, compliance steps | default entry built byte-identically; new keys unused by B2C | config golden (P14); package suite | revert file |
| TP-02 | `Support/ZatcaGate.php` | `b2bEnabled()` | `enabled()` untouched | `ZatcaKillSwitchTest` | revert methods |
| TP-03 | `State/EgsRegistry.php` | `primary()` | `for()/identifiers()/default()` unchanged | registry tests | revert |
| TP-04 | `Console/Commands/ZatcaHealthCheck.php` | identity + B2B section | B2C checks unchanged; new checks only when >1 EGS | `CommandsTest`, `OperationalVisibilityTest` | revert |
| TP-05 | `Enums/ZatcaComplianceDocumentType.php` | Standard cases + helpers | Simplified values/methods unchanged; exhaustive tests | enum tables; goldens | floor R1 (cannot remove once used) |
| TP-06 | `Enums/ZatcaInvoiceStatus.php` | clearance cases; `isTerminalSuccess()` extended | B2C rows never hold new values; `isRetryable()` unchanged | whitelist characterization | floor R1 |
| TP-07 | `Services/Execute/ProductionInvoiceOrchestrator.php` | (a) cross-EGS guard, (b) Standard validator call, (c) type widening, (d) submission_model + signing status, (e) reissue method | (a) only throws when another EGS holds the source — impossible with one EGS; (b) Standard only; (c) no behaviour; (d) Simplified literal status unchanged; (e) new method | orchestrator suites (≈40 tests), goldens, P06 | revert per sub-change (a–e independent commits) |
| TP-08 | `Services/Invoice/XmlGeneratorFactory.php` | Standard routing | identity test for Simplified classes | P25 identity test | revert arms |
| TP-09 | `Models/ZatcaInvoice.php` | casts, kind helpers | `isCreditNote()/isDebitNote()` semantics = kind (Simplified unchanged) | model tests | revert |
| TP-10 | `Services/OnBoarding/ComplianceOnboardingOrchestrator.php` | Standard steps | Simplified steps untouched; payload assertions | `ComplianceOnboardingOrchestratorNoteTest` | revert |
| TP-11 | `app/Services/Admin/ZatcaSettingService.php` | `primary()`, policy owner, b2b settings | identical values for `default`; property test | P07/P15 | revert |
| TP-12 | `app/Zatca/InvoiceSellerProfile.php` | fallback to `primary()` | same EGS resolved today | `InvoiceSellerProfileTest` (ported) | revert |
| TP-13 | `ZatcaServiceProvider.php` | register commands | registration only | `ServiceProviderTest` | revert |
| TP-14 | `DTOs/ZatcaInvoiceData.php` | trailing optional params | positional/named callers unchanged | P05 constructor test | revert |
| TP-15 | `Support/InvoiceDataSnapshot.php` | v3 read/write | v1/v2 bytes unchanged | P05 goldens | floor R1 |
| TP-16 | `XmlGenerators/V2/AbstractSimplifiedUblDocumentXmlGenerator.php` | `implements UblDocumentXmlGenerator` | signature already matches; no body change | P04 goldens | revert |
| TP-17 | `app/Services/PostPurchase/PurchaseEmailDispatcher.php` | company branch | branch after claim, only for company sales; B2C lines untouched | P08 email tests | revert |
| TP-18 | `Console/Commands/Onboard.php` | Standard steps | existing steps identical | `CommandsTest` | revert |
| TP-19 | `Jobs/Concerns/ReportsZatcaInvoice.php` | Standard guard; acceptance helper extraction | guard only for clearance/Standard docs; helper behaviour identical | reporting & note acceptance suites; P06 | revert |
| TP-20 | `Contracts/*Interface.php` (connector/mapper/normalizer) | new methods | no B2C caller changes; test doubles updated | package suite | revert |
| TP-21 | `Connectors/V2/HttpConnector.php`, `RequestMapper.php`, `ResponseNormalizer.php` | clearance methods | reporting methods and `send()` untouched | connector characterization | revert |
| TP-22 | `app/Providers/EventServiceProvider.php` | new listeners | additive mappings | listener tests | unregister |
| TP-23 | `Console/Commands/RequeueInvoices.php` | terminal-safety + `--model` | default invocation selection identical for B2C | P06 requeue tests | revert |
| TP-24 | `Console/Commands/ReportPendingInvoices.php` | defensive model filter | B2C rows all `reporting` (default) | P06 | revert |
| TP-25 | `Services/Certificate/CertificateLifecycleManager.php` | resume by model | reporting docs dispatch unchanged | `CertificateLifecycleManagerTest` | revert |
| TP-26 | `Services/Regeneration/RegenerationPolicy.php` | clearance verdict | B2C verdict table identical | `RegenerationPolicyTest` (16) | revert |
| TP-27 | Jobs `GenerateZatcaInvoiceJob`, `GenerateZatcaCreditNoteJob`, `GenerateZatcaDebitNoteJob`, `RegenerateZatcaInvoiceJob`, `CompleteZatcaInvoiceGenerationJob` | dispatch via `ZatcaSubmission` | identical job/args/connection/queue for reporting docs | P06 dispatch assertions | revert |
| TP-28 | `app/Services/Admin/AdminZatcaInvoiceService.php` | isolation, retry routing, edit-XML refusal for Standard, list filters | B2C branches unchanged; new filters optional | harness admin tests (ported) | revert |
| TP-29 | `CreditNoteEligibilityPolicy.php`, `DebitNoteEligibilityPolicy.php` | kind helper | Simplified invoice still eligible, notes still ineligible | eligibility tests | revert |
| TP-30 | `CreditNoteDataFactory.php`, `DebitNoteDataFactory.php` | inheritance | Simplified originals produce identical data | `CreditNoteDataFactoryTest` + goldens | revert |
| TP-31 | note status resolvers & cancellers | clearance statuses | B2C tables identical | resolver/canceller tests | revert |
| TP-32 | `app/Zatca/ZatcaInvoiceSourceRouter.php` | decorator routing | B2C returns inner sources (identity) | P07 | revert |
| TP-33 | `app/Listeners/GenerateZatcaInvoiceForPaymentListener.php` | lane decision | B2C lane → same args; order and try/catch unchanged | P07/P55 | revert |
| TP-34 | `app/Services/Admin/TransactionService.php` | lane in dispatch; new company/settle methods | `createTransactions()` body unchanged except EGS source (same value) | P07 | revert |
| TP-35 | `app/Services/PostPurchase/InvoiceBuilder.php` | B2B lookup | B2C lookups unchanged | `InvoiceBuilderTest` | revert |
| TP-36 | `app/Http/Controllers/api/StudentInvoiceController.php` | B2B states/resolution | B2C branch unchanged; additive keys | P08 | revert |
| TP-37 | `CheckoutRequest`, `CheckoutService`, `PaymentTrait`, Tabby/Tamara/Tap services | buyer capture | company-only branch; individual path byte-identical | P07/P57 JSON snapshots | revert |
| TP-38 | `StoreTransactionRequest`, `TransactionsController` | company path | optional fields; individual path unchanged | P07 | revert |
| TP-39 | `app/Console/Commands/RecoverZatcaInvoice.php` | lane + submission router | B2C stages identical | `RecoverZatcaInvoiceCommandTest` (ported) | revert |
| TP-40 | `app/Console/Commands/RepairZatcaGeneratedDocuments.php` | none directly (service filters) | unchanged | P06 | — |
| TP-41 | admin resources (list/details/diagnostics/settings), `ZatcaInvoicesExport` | additive fields/columns | existing keys/columns unchanged | resource tests | revert |
| TP-42 | `AdminCreditNoteService`, `AdminDebitNoteService` | blocked reasons, model | B2C option payloads identical | P43 JSON snapshots | revert |
| TP-43 | `app/Console/Kernel.php` | `zatca:clear-pending`, purge schedules | additive schedule lines | schedule list test | remove lines |
| TP-44 | `database/seeders/RolesAndPermissionsSeeder.php` | new permission | added via `$entityActions` (cleanup sweep R-7) | seeder test on staging copy: existing roles unchanged | revert |
| TP-45 | `routes/admin.php`, `routes/student_dashboard.php`, `routes/api.php` | new endpoints | additive; route-list snapshot | route snapshot | remove routes |
| TP-46 | `resources/app/views/Cart.vue`, `useCart.js`, `cart.api.js`, locales | buyer-type selector (no default, #33496), company form, confirmation | selector hidden while B2B unavailable ⇒ payload and UX unchanged; with B2B available Individual adds only `buyer_type=individual` and one click (D-38) | P09 script/tests; E2E-B13, S19, S20 | revert build |
| TP-47 | `AssignCourseModal.vue`, `pages/students/index.vue` | company section | hidden unless new bank transfer + available | P09 specs | revert build |
| TP-48 | admin ZATCA pages/utils/stores | labels, cards, dialogs | additive | P09 specs | revert build |
| TP-49 | `.env.example` | documentation | no runtime effect | — | revert |
| TP-50 | `Console/Commands/ZatcaStatus.php` | B2B checks | B2C checks unchanged | `OperationalVisibilityTest` | revert |
| TP-51 | `Services/Diagnostics/ZatcaInspector.php` | clearance fields | additive | inspector tests | revert |
| TP-52 | `config/services.php` | `wathq` block | additive | — | revert |
| TP-53 | `.github/workflows/asas_prod.yml` (+stg) | `queue:restart` | workers restart (safe by resumable design) | staging observation | revert |
| TP-54 | `app/Providers/RouteServiceProvider.php` | WATHQ limiters | additive limiter names | limiter tests | revert |
| TP-55 | `Console/Commands/PruneSubmissionAttempts.php` | prune cleared outcomes too | B2C selection (reported outcomes) unchanged | `PruneSubmissionAttempts` test | revert |

**Isolation preferred and applied where possible:** new generator family (not a parameterised Simplified class), new clearance job (not a mode of the reporting job), new company sources (decorators), new admin methods (company assignment, settlement) instead of editing the B2C branch of `createTransactions()`, new PDF templates, new delivery service, separate EGS/chain/certificate.

**Residual B2C risk after the third pass:** TP-07(d) (`sign()` status conditional), TP-19 (guard + helper extraction), TP-11/TP-15 policy/primary change, TP-27 (dispatch router) — each has a byte/payload-identity test and ships in R1 with production B2C smoke (P73) before any B2B code can be activated.

## How to use it in review

For every pull request in a B2B phase: list each file it edits that appears above; for each one, confirm the "Regression test" ran green and the "Why safe for B2C" statement still holds. A shared file edited that is **not** in this list must be added here first, with its test.
