<?php

namespace Zatca\Tests\Unit\Golden;

use DateTimeImmutable;
use Zatca\DTOs\EgsConfig;
use Zatca\DTOs\InvoiceSequenceReservation;
use Zatca\DTOs\ZatcaAllowanceCharge;
use Zatca\DTOs\ZatcaInvoiceData;
use Zatca\DTOs\ZatcaInvoiceLine;
use Zatca\Enums\ZatcaEnvironment;
use Zatca\Services\CreditNote\CreditNoteDataFactory;
use Zatca\Services\DebitNote\DebitNoteDataFactory;
use Zatca\Services\DebitNote\DebitNoteLineInput;
use Zatca\Services\Invoice\XmlGenerators\V2\AbstractSimplifiedUblDocumentXmlGenerator;
use Zatca\Services\Invoice\XmlGenerators\V2\SimplifiedCreditNoteXmlGenerator;
use Zatca\Services\Invoice\XmlGenerators\V2\SimplifiedDebitNoteXmlGenerator;
use Zatca\Services\Invoice\XmlGenerators\V2\SimplifiedInvoiceXmlGenerator;

/**
 * The fixed B2C inputs every golden suite is built from.
 *
 * Shared so the XML fixtures (SimplifiedXmlGoldenTest) and the snapshot fixtures
 * (SnapshotGoldenTest) describe the very same documents: a snapshot restored from
 * its fixture must regenerate the XML fixture of the same name.
 *
 * Every value is fixed — UUIDs, counters, hashes and instants — so the output is
 * byte-comparable across runs and machines.
 */
trait GoldenDocuments
{
    /**
     * @return array<string, array{data: ZatcaInvoiceData, generator: class-string<AbstractSimplifiedUblDocumentXmlGenerator>, egs: EgsConfig, uuid: string, reservation: InvoiceSequenceReservation}>
     */
    public static function goldenDocuments(): array
    {
        $single = self::singleCourseInvoice();
        $bundle = self::bundleInvoice();

        return [
            'invoice_single' => self::invoiceCase($single, self::egs()),
            'invoice_multi_bundle_allowance' => self::invoiceCase($bundle, self::egs(structured: true, arabicName: true)),
            'invoice_no_buyer' => self::invoiceCase(self::singleCourseInvoice(buyerName: null), self::egs()),
            'invoice_escaped_text' => self::invoiceCase(
                new ZatcaInvoiceData(
                    egsIdentifier: 'default',
                    sourceReference: 'order:77&x',
                    buyerName: 'O\'Brien & Sons <Test> "Co"',
                    buyerVatNumber: null,
                    issueDateTime: new DateTimeImmutable('2026-03-15 23:59:59'),
                    currency: 'SAR',
                    lines: [new ZatcaInvoiceLine('Course "Advanced" & <Labs>', 1, 99.99, 15.0, 99.99, 15.00)],
                    totalExclVat: 99.99,
                    totalVat: 15.00,
                    totalInclVat: 114.99,
                ),
                self::egs(structured: true),
            ),
            'credit_full' => self::noteCase(
                SimplifiedCreditNoteXmlGenerator::class,
                (new CreditNoteDataFactory)->make(
                    $single,
                    [0],
                    'CN-2026-000001',
                    'order:42',
                    'Course cancelled before start - full refund',
                    new DateTimeImmutable('2026-01-10 09:15:00'),
                ),
                'cn-uuid-0001',
                12,
            ),
            'credit_partial_repriced' => self::noteCase(
                SimplifiedCreditNoteXmlGenerator::class,
                (new CreditNoteDataFactory)->make(
                    $bundle,
                    [0],
                    'CN-2026-000002',
                    'order:108',
                    'Refund of one course from a discounted bundle',
                    new DateTimeImmutable('2026-02-01 12:00:00'),
                    [0 => 517.50],
                ),
                'cn-uuid-0002',
                13,
            ),
            'credit_price_adjustment_with_dn_lines' => self::noteCase(
                SimplifiedCreditNoteXmlGenerator::class,
                (new CreditNoteDataFactory)->makeAdjustment(
                    $single,
                    new ZatcaInvoiceLine('Course Enrollment - price adjustment', 1, 50.00, 15.0, 50.00, 7.50),
                    'CN-2026-000003',
                    'order:42',
                    'Price corrected after enrolment',
                    new DateTimeImmutable('2026-01-20 16:45:30'),
                    [new ZatcaInvoiceLine('Extra workshop session', 1, 40.00, 15.0, 40.00, 6.00)],
                ),
                'cn-uuid-0003',
                14,
            ),
            'debit_additional_course' => self::noteCase(
                SimplifiedDebitNoteXmlGenerator::class,
                (new DebitNoteDataFactory)->make(
                    $single,
                    [new DebitNoteLineInput('Additional course: Data Analysis', 1, 300.00, 15.0, 300.00, 45.00, null, 55)],
                    'DN-2026-000001',
                    'order:42',
                    'Additional course added to the enrolment',
                    new DateTimeImmutable('2026-01-12 08:00:00'),
                ),
                'dn-uuid-0001',
                15,
            ),
            'debit_price_adjustment' => self::noteCase(
                SimplifiedDebitNoteXmlGenerator::class,
                (new DebitNoteDataFactory)->make(
                    $bundle,
                    [new DebitNoteLineInput('Course A - price increase', 1, 43.48, 15.0, 43.48, 6.52, 0)],
                    'DN-2026-000002',
                    'order:108',
                    'Price increase agreed with the student',
                    new DateTimeImmutable('2026-02-05 10:30:00'),
                ),
                'dn-uuid-0002',
                16,
            ),
        ];
    }

    /** Unsigned XML for one golden case, exactly as the generator emits it. */
    protected static function goldenXml(array $case, ?ZatcaInvoiceData $data = null): string
    {
        return (new $case['generator'])->generate(
            $data ?? $case['data'],
            $case['egs'],
            $case['uuid'],
            $case['reservation'],
        );
    }

    private static function invoiceCase(ZatcaInvoiceData $data, EgsConfig $egs): array
    {
        return [
            'data' => $data,
            'generator' => SimplifiedInvoiceXmlGenerator::class,
            'egs' => $egs,
            'uuid' => 'inv-uuid-'.substr(md5($data->sourceReference), 0, 8),
            'reservation' => new InvoiceSequenceReservation(11, base64_encode(hash('sha256', 'previous-invoice'))),
        ];
    }

    private static function noteCase(string $generator, ZatcaInvoiceData $data, string $uuid, int $icv): array
    {
        return [
            'data' => $data,
            'generator' => $generator,
            'egs' => self::egs(structured: true, arabicName: true),
            'uuid' => $uuid,
            'reservation' => new InvoiceSequenceReservation($icv, base64_encode(hash('sha256', 'previous-'.$icv))),
        ];
    }

    /** One course, VAT added on top — the shape of most B2C checkouts. */
    private static function singleCourseInvoice(?string $buyerName = 'Test Student'): ZatcaInvoiceData
    {
        return new ZatcaInvoiceData(
            egsIdentifier: 'default',
            sourceReference: 'order:42',
            buyerName: $buyerName,
            buyerVatNumber: null,
            issueDateTime: new DateTimeImmutable('2026-01-01 10:00:00'),
            currency: 'SAR',
            lines: [new ZatcaInvoiceLine('Course Enrollment', 1, 200.00, 15.0, 200.00, 30.00)],
            totalExclVat: 200.00,
            totalVat: 30.00,
            totalInclVat: 230.00,
        );
    }

    /** Three courses, two of them in a bundle carrying a document-level allowance. */
    private static function bundleInvoice(): ZatcaInvoiceData
    {
        return new ZatcaInvoiceData(
            egsIdentifier: 'default',
            sourceReference: 'order:108',
            buyerName: 'محمد عبدالله',
            buyerVatNumber: null,
            issueDateTime: new DateTimeImmutable('2026-01-25 14:05:09'),
            currency: 'SAR',
            lines: [
                new ZatcaInvoiceLine('دورة المحاسبة المالية', 1, 500.00, 15.0, 500.00, 75.00),
                new ZatcaInvoiceLine('Excel for Accountants', 1, 300.00, 15.0, 300.00, 45.00),
                new ZatcaInvoiceLine('VAT Fundamentals', 1, 200.00, 15.0, 200.00, 30.00),
            ],
            totalExclVat: 920.00,
            totalVat: 138.00,
            totalInclVat: 1058.00,
            allowanceCharges: [new ZatcaAllowanceCharge('Bundle discount', 80.00, 15.0, 12.00, 'bundle:7', [0, 1])],
        );
    }

    private static function egs(bool $structured = false, bool $arabicName = false): EgsConfig
    {
        return new EgsConfig(
            egsIdentifier: 'default',
            environment: ZatcaEnvironment::Sandbox,
            vatNumber: '399999999900003',
            organizationName: 'Test Org',
            organizationUnitName: 'Test Unit',
            commonName: 'TST-EGS-01',
            serialNumber: '1-TST|2-ASAS|3-uuid',
            organizationIdentifier: '399999999900003',
            countryCode: 'SA',
            invoiceType: '0100',
            locationAddress: 'Test Address',
            businessCategory: 'Education',
            commercialRegistrationNumber: '1010010000',
            companyNameAr: $arabicName ? 'شركة الاختبار للتعليم' : null,
            street: $structured ? 'King Fahd Road' : null,
            district: $structured ? 'Al Olaya' : null,
            buildingNumber: $structured ? '1234' : null,
            city: $structured ? 'Riyadh' : null,
            postalCode: $structured ? '12345' : null,
        );
    }

    /** Line endings are the only difference allowed between a fixture and fresh output. */
    private static function normalise(string $text): string
    {
        return str_replace("\r\n", "\n", $text);
    }
}
