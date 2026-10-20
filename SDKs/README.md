# ZATCA SDKs

This folder holds the ZATCA e-invoicing SDK release that this package is built and tested against.

| Folder | SDK release | Jar | SHA-256 of the jar |
|---|---|---|---|
| [`zatca-einvoicing-sdk-Java-238-R3.4.8/`](zatca-einvoicing-sdk-Java-238-R3.4.8/) | Java SDK **3.4.8** (`238-R3.4.8`) | `Apps/zatca-einvoicing-sdk-238-R3.4.8.jar` | `48abeb828d453ef6fafba792fddbbb2701da5c7018c24bde918853e80ff5d530` |

The official download is on ZATCA's developer portal: <https://zatca.gov.sa/en/E-Invoicing/SystemsDevelopers/ComplianceEnablementToolbox/Pages/DownloadSDK.aspx>. Compare the jar's SHA-256 with the table before you trust a copy.

## What was tested with it (2026-10-14, PHP 8.3, Java 11, Windows)

- `composer smoke` (`zatca:sdk-smoke-test`): all 9 checks OK (Java, SDK paths, fingerprint, CSR, hash, signing, QR, validation).
- The real-SDK tests in `tests/Feature/ProductionInvoiceOrchestratorTest.php`: 2 passed. They generate and sign a Simplified invoice with this SDK.
- `tests/Feature/VatRealSdkValidationTest.php`: 5 passed. Invoices whose VAT the package calculated (inclusive and exclusive prices, quantities, mixed rates, zero-rated items, discounts) are signed and run through this SDK's validator: XSD, EN16931, KSA, QR and signature checks pass with no amount-rule errors or warnings. A control case confirms a wrong amount is reported.
- Full suite with this SDK enabled (2026-10-20, Windows): 890 tests, 2,921 assertions, 1 skipped (a file-permission test that only runs on Linux/macOS). This held on Laravel 9 to 12 and PHP 8.1 to 8.4; see the README's Laravel compatibility section.

This proves that signing, hashing and QR work with this SDK. It does **not** prove that ZATCA accepts the result: that needs the onboarding sandbox steps. B2B (Clearance) has still not been checked against the real ZATCA sandbox.

## How to use it

Point the package at the folder, using absolute paths:

```dotenv
ZATCA_SDK_ROOT=/path/to/zatca/SDKs/zatca-einvoicing-sdk-Java-238-R3.4.8
ZATCA_SDK_EXECUTABLE=/path/to/zatca/SDKs/zatca-einvoicing-sdk-Java-238-R3.4.8/Apps/zatca-einvoicing-sdk-238-R3.4.8.jar
```

You do not need to run the SDK's `install.sh` / `install.bat`. The package calls the jar directly and writes its own SDK config for each call.

**This folder ships with the package** (about 58 MB). After `composer require`, it is at `vendor/zatca/zatca/SDKs/zatca-einvoicing-sdk-Java-238-R3.4.8` in your app, so you can point the `.env` paths there:

```dotenv
ZATCA_SDK_ROOT=/var/www/your-app/vendor/zatca/zatca/SDKs/zatca-einvoicing-sdk-Java-238-R3.4.8
ZATCA_SDK_EXECUTABLE=/var/www/your-app/vendor/zatca/zatca/SDKs/zatca-einvoicing-sdk-Java-238-R3.4.8/Apps/zatca-einvoicing-sdk-238-R3.4.8.jar
```

The package never writes into this folder, so `composer install` replacing `vendor/` is safe. If you use a deploy layout where the release path changes on each deploy, use the current release path, or copy the folder to a fixed place such as `/var/www/zatca-sdk`. Never put it under `public/`.

## What is in the copy

This is ZATCA's release as unzipped, with these changes:

- `Data/Certificates/cert.pem` and `ec-secp256k1-priv-key.pem` are ZATCA's own **public sample** certificate and key, as shipped. They are for local testing only. Never use them for real invoices. Your real keys are created by `zatca:generate-credentials` and stored encrypted in the database, never here.
- `Configuration/config.json` uses the SDK's relative default paths (`defaults.json`) instead of absolute paths from one machine.
- No `output/` folder, generated keys, CSRs, certificates or signed invoices.

Never commit your own keys, certificates or CSR `.properties` files into this folder.

## License

The ZATCA SDK is licensed under the GNU LGPL v3; see [`LICENSE.txt`](zatca-einvoicing-sdk-Java-238-R3.4.8/LICENSE.txt). It is redistributed here unmodified. The package's own license does not apply to it.

## Adding a new SDK version

1. Unzip the new release into a new folder named after it, next to this one. Never overwrite the old folder.
2. Remove anything that is not ZATCA's: generated keys, certificates, CSRs, output.
3. Follow "Adding a new ZATCA version combination" in [`docs/COMPATIBILITY.md`](../docs/COMPATIBILITY.md).
4. Add a row to the table above with the jar's SHA-256.
