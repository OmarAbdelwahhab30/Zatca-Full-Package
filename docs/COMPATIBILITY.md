# ZATCA Package Compatibility Matrix

Supported combinations of package version, ZATCA API version, SDK version, and UBL profile version. Do not mix combinations outside this table — each row has been verified end-to-end (signing, validation, and reporting) against the versions listed.

| Package version | `ZATCA_API_VERSION` | `ZATCA_SDK_VERSION` | `ZATCA_UBL_VERSION` | `ZATCA_XML_PROFILE_VERSION` | Notes |
|---|---|---|---|---|---|
| 1.0.x | `V2` | `3.4.8` | `2.1` | `V2` | Reporting model, Simplified Tax Invoice (B2C) only. Golden-file fixtures for this SDK version live at `tests/Fixtures/SdkOutput/V3_4_8/`. |

## Not covered by this table (unverified)

**Certificate renewal** is a separate, explicitly-flagged exception to "verified end-to-end" above: `ZatcaConnectorInterface::renewProductionCsid()` is implemented against the `/production/csids` endpoint only because it's the sole production-cert-issuing endpoint this package has verified ZATCA documentation for — the actual renewal contract (endpoint, OTP requirement, response shape) has not been confirmed against an official ZATCA document. `config('zatca.certificate.auto_renewal_enabled')` is `false` by default for exactly this reason. Do not treat renewal as "supported" until this note is removed and a row/callout here documents the verified contract.

## Adding a new combination

1. Add the new connector under `src/Services/Connectors/V{n}/` (RequestMapper + ResponseNormalizer), never modifying the existing version's files.
2. Add the new SDK output parser under `src/Services/SDK/OutputParsers/V{sdk_version_with_underscores}/` if the SDK's console output format changed, with golden-file fixtures under `tests/Fixtures/SdkOutput/V{sdk_version}/`.
3. Add the new XML generator under `src/Services/Invoice/XmlGenerators/V{ubl_profile}/` if the UBL profile changed.
4. Run the full contract + golden-file + `zatca:sdk-smoke-test` suite against the new versions before adding a row here.
5. Only then switch the relevant `.env` value in a host app — never change the default in `config/zatca.php` without a corresponding row in this table.
