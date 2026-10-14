<?php

namespace Zatca\Tests\Unit;

use Illuminate\Support\Facades\Config;
use Zatca\Enums\ZatcaComplianceDocumentType as Type;
use Zatca\Enums\ZatcaInvoiceStatus as Status;
use Zatca\Enums\ZatcaSubmissionModel;
use Zatca\Exceptions\ZatcaException;
use Zatca\Services\Invoice\XmlGeneratorFactory;
use Zatca\Services\Invoice\XmlGenerators\V2\SimplifiedCreditNoteXmlGenerator;
use Zatca\Services\Invoice\XmlGenerators\V2\SimplifiedDebitNoteXmlGenerator;
use Zatca\Services\Invoice\XmlGenerators\V2\SimplifiedInvoiceXmlGenerator;
use Zatca\Services\Invoice\XmlGenerators\V2\StandardCreditNoteXmlGenerator;
use Zatca\Services\Invoice\XmlGenerators\V2\StandardDebitNoteXmlGenerator;
use Zatca\Services\Invoice\XmlGenerators\V2\StandardInvoiceXmlGenerator;
use Zatca\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The document-type and status vocabulary B2B added before anything writes it
 * (blueprint P16). Every method is pinned for every case, so a Simplified answer that
 * changes, or a new case left out of a table, fails here.
 */
class DocumentVocabularyTest extends TestCase
{
    /** @dataProvider documentTypes */
    #[DataProvider('documentTypes')]
    public function test_document_type_table(
        Type $type,
        string $value,
        string $typeCode,
        string $codeName,
        bool $standard,
        ZatcaSubmissionModel $model,
        string $kind,
        string $label,
    ): void {
        $this->assertSame($value, $type->value);
        $this->assertSame($typeCode, $type->invoiceTypeCode());
        $this->assertSame($codeName, $type->transactionCodeName());
        $this->assertSame($standard, $type->isStandard());
        $this->assertSame($model, $type->submissionModel());
        $this->assertSame($kind === 'invoice', $type->isInvoiceKind());
        $this->assertSame($kind === 'credit', $type->isCreditNoteKind());
        $this->assertSame($kind === 'debit', $type->isDebitNoteKind());
        $this->assertSame($kind !== 'invoice', $type->requiresBillingReference());
        $this->assertSame($label, $type->label());
    }

    /** Codes and code names as in the ZATCA SDK R3.4.8 samples (Data/Samples/{Simplified,Standard}). */
    public static function documentTypes(): array
    {
        $r = ZatcaSubmissionModel::Reporting;
        $c = ZatcaSubmissionModel::Clearance;

        return [
            'simplified invoice' => [Type::Invoice, 'simplified_invoice', '388', '0200000', false, $r, 'invoice', 'simplified invoice'],
            'simplified credit note' => [Type::CreditNote, 'simplified_credit_note', '381', '0200000', false, $r, 'credit', 'simplified credit note'],
            'simplified debit note' => [Type::DebitNote, 'simplified_debit_note', '383', '0200000', false, $r, 'debit', 'simplified debit note'],
            'standard invoice' => [Type::StandardInvoice, 'standard_invoice', '388', '0100000', true, $c, 'invoice', 'standard invoice'],
            'standard credit note' => [Type::StandardCreditNote, 'standard_credit_note', '381', '0100000', true, $c, 'credit', 'standard credit note'],
            'standard debit note' => [Type::StandardDebitNote, 'standard_debit_note', '383', '0100000', true, $c, 'debit', 'standard debit note'],
        ];
    }

    public function test_every_document_type_is_in_the_table(): void
    {
        $this->assertSame(
            array_map(fn (array $row) => $row[0], array_values(self::documentTypes())),
            Type::cases()
        );
    }

    /** @dataProvider statuses */
    #[DataProvider('statuses')]
    public function test_status_table(Status $status, bool $clearance, bool $terminalSuccess, bool $retryable, bool $clearanceRetryable): void
    {
        $this->assertSame($clearance, $status->isClearanceState(), 'isClearanceState');
        $this->assertSame($terminalSuccess, $status->isTerminalSuccess(), 'isTerminalSuccess');
        $this->assertSame($retryable, $status->isRetryable(), 'isRetryable');
        $this->assertSame($clearanceRetryable, $status->isClearanceRetryable(), 'isClearanceRetryable');
    }

    public static function statuses(): array
    {
        // [clearance state, terminal success, reporting-retryable, clearance-retryable]
        return [
            'draft' => [Status::Draft, false, false, false, false],
            'generated' => [Status::Generated, false, false, false, false],
            'pending_reporting' => [Status::PendingReporting, false, false, true, false],
            'reported' => [Status::Reported, false, true, false, false],
            'reported_with_warnings' => [Status::ReportedWithWarnings, false, true, false, false],
            'failed' => [Status::Failed, false, false, true, false],
            'retrying' => [Status::Retrying, false, false, true, false],
            'manual_review' => [Status::ManualReview, false, false, false, false],
            'pending_clearance' => [Status::PendingClearance, true, false, false, true],
            'clearance_retrying' => [Status::ClearanceRetrying, true, false, false, true],
            'clearance_in_doubt' => [Status::ClearanceInDoubt, true, false, false, true],
            'cleared' => [Status::Cleared, true, true, false, false],
            'cleared_with_warnings' => [Status::ClearedWithWarnings, true, true, false, false],
            'clearance_rejected' => [Status::ClearanceRejected, true, false, false, false],
            'clearance_manual_review' => [Status::ClearanceManualReview, true, false, false, false],
        ];
    }

    public function test_every_status_is_in_the_table(): void
    {
        $this->assertSame(
            array_map(fn (array $row) => $row[0], array_values(self::statuses())),
            Status::cases()
        );
    }

    public function test_submission_model_values(): void
    {
        $this->assertSame(['reporting', 'clearance'], array_map(fn ($m) => $m->value, ZatcaSubmissionModel::cases()));
    }

    /** Simplified routing is exactly what it was: the same three classes. */
    public function test_simplified_types_route_to_the_simplified_generators(): void
    {
        $factory = new XmlGeneratorFactory;

        $this->assertInstanceOf(SimplifiedInvoiceXmlGenerator::class, $factory->make());
        $this->assertSame(SimplifiedInvoiceXmlGenerator::class, $factory->make(Type::Invoice)::class);
        $this->assertSame(SimplifiedCreditNoteXmlGenerator::class, $factory->make(Type::CreditNote)::class);
        $this->assertSame(SimplifiedDebitNoteXmlGenerator::class, $factory->make(Type::DebitNote)::class);
    }

    /**
     * @dataProvider standardTypes
     */
    #[DataProvider('standardTypes')]
    public function test_standard_types_resolve_to_their_standard_generators(Type $type): void
    {
        $generator = (new XmlGeneratorFactory)->make($type);

        $this->assertInstanceOf(match ($type) {
            Type::StandardInvoice => StandardInvoiceXmlGenerator::class,
            Type::StandardCreditNote => StandardCreditNoteXmlGenerator::class,
            Type::StandardDebitNote => StandardDebitNoteXmlGenerator::class,
        }, $generator);
    }

    public static function standardTypes(): array
    {
        return [
            'standard invoice' => [Type::StandardInvoice],
            'standard credit note' => [Type::StandardCreditNote],
            'standard debit note' => [Type::StandardDebitNote],
        ];
    }

    /** The profile check still comes first. */
    public function test_an_unsupported_profile_is_still_refused_first(): void
    {
        Config::set('zatca.xml_profile_version', 'V9');

        $this->expectException(ZatcaException::class);
        $this->expectExceptionMessage('Unsupported ZATCA_XML_PROFILE_VERSION [V9]');

        (new XmlGeneratorFactory)->make(Type::StandardInvoice);
    }
}
