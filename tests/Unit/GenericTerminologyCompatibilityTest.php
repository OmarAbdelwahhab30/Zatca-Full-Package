<?php

namespace Zatca\Tests\Unit;

use Zatca\DTOs\ZatcaInvoiceLine;
use Zatca\Enums\ZatcaCreditNoteReason;
use Zatca\Enums\ZatcaDebitNoteReason;
use Zatca\Models\ZatcaCreditNoteLine;
use Zatca\Models\ZatcaDebitNoteLine;
use Zatca\Services\CreditNote\CreditSource;
use Zatca\Services\DebitNote\DebitNoteLineInput;
use Zatca\Tests\TestCase;

/**
 * The package's reasons and line ids were renamed from course-specific names to generic
 * ones. The old PHP names must keep working as deprecated aliases.
 */
class GenericTerminologyCompatibilityTest extends TestCase
{
    public function test_stored_reason_values_are_generic(): void
    {
        $this->assertSame('item_cancellation', ZatcaCreditNoteReason::ItemCancellation->value);
        $this->assertSame('additional_item', ZatcaDebitNoteReason::AdditionalItem->value);
        $this->assertSame(ZatcaCreditNoteReason::ItemCancellation, ZatcaCreditNoteReason::from('item_cancellation'));
        $this->assertSame(ZatcaDebitNoteReason::AdditionalItem, ZatcaDebitNoteReason::from('additional_item'));
    }

    public function test_deprecated_enum_names_are_the_same_cases(): void
    {
        $this->assertSame(ZatcaCreditNoteReason::ItemCancellation, ZatcaCreditNoteReason::CourseCancellation);
        $this->assertSame(ZatcaDebitNoteReason::AdditionalItem, ZatcaDebitNoteReason::AdditionalCourse);
    }

    public function test_deprecated_reason_methods_match_the_new_ones(): void
    {
        foreach (ZatcaCreditNoteReason::cases() as $reason) {
            $this->assertSame($reason->cancelsItems(), $reason->revokesCourseAccess());
        }

        foreach (ZatcaDebitNoteReason::cases() as $reason) {
            $this->assertSame($reason->addsItems(), $reason->grantsCourseAccess());
        }

        $this->assertTrue(ZatcaCreditNoteReason::ItemCancellation->cancelsItems());
        $this->assertFalse(ZatcaCreditNoteReason::PriceAdjustment->cancelsItems());
        $this->assertTrue(ZatcaDebitNoteReason::AdditionalItem->addsItems());
        $this->assertFalse(ZatcaDebitNoteReason::VatAdjustment->addsItems());
    }

    public function test_debit_note_line_input_accepts_item_id_and_the_old_course_id(): void
    {
        $new = new DebitNoteLineInput('Extra charger', 1, 57.50, itemId: 44);
        $old = new DebitNoteLineInput('Extra charger', 1, 57.50, courseId: 44);

        foreach ([$new, $old] as $input) {
            $this->assertSame(44, $input->itemId);
            $this->assertSame(44, $input->courseId);
        }

        $this->assertNull((new DebitNoteLineInput('Fee', 1, 11.50))->itemId);
    }

    public function test_debit_note_line_input_refuses_two_different_ids(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new DebitNoteLineInput('Extra charger', 1, 57.50, itemId: 2, courseId: 1);
    }

    public function test_debit_note_line_input_from_array_reads_either_key(): void
    {
        $row = ['description' => 'Fee', 'quantity' => 1, 'price' => 11.5];

        $this->assertSame(7, DebitNoteLineInput::fromArray($row + ['course_id' => 7])->itemId);
        $this->assertSame(8, DebitNoteLineInput::fromArray($row + ['item_id' => 8])->itemId);
    }

    public function test_credit_source_accepts_item_id_and_the_old_course_id(): void
    {
        $line = new ZatcaInvoiceLine('Extra charger', 1, 50.00, 15.0, 50.00, 7.50);

        $new = new CreditSource($line, 1, 2, 'DN-1', ZatcaDebitNoteReason::AdditionalItem, itemId: 9);
        $old = new CreditSource($line, 1, 2, 'DN-1', ZatcaDebitNoteReason::AdditionalItem, null, 9);

        foreach ([$new, $old] as $source) {
            $this->assertSame(9, $source->itemId);
            $this->assertSame(9, $source->courseId);
        }
    }

    public function test_note_lines_keep_a_read_only_course_id_alias(): void
    {
        $this->assertSame(12, (new ZatcaDebitNoteLine(['item_id' => '12']))->course_id);
        $this->assertSame(13, (new ZatcaCreditNoteLine(['item_id' => 13]))->course_id);
        $this->assertNull((new ZatcaDebitNoteLine(['item_id' => null]))->course_id);
    }
}
