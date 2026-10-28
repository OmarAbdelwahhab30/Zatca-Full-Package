@extends('zatca-dashboard::layout')
@section('title', 'Document '.$invoice->source_reference)
@section('content')
    @php
        $ok = $invoice->status->isTerminalSuccess();
        $note = $invoice->creditNote ?? $invoice->debitNote;
    @endphp
    <p><a href="{{ route('zatca.dashboard.invoices.index') }}">&larr; All documents</a></p>

    <div class="card">
        <h2>{{ $note?->credit_note_number ?? $note?->debit_note_number ?? $invoice->source_reference }}
            <span class="badge {{ $ok ? 'ok' : 'warn' }}">{{ $invoice->status->value }}</span></h2>
        <table>
            <tr><th style="width:220px">Document type</th><td>{{ $invoice->document_type->value }}</td></tr>
            <tr><th>Submission flow</th><td>{{ $invoice->submission_model->value === 'clearance' ? 'Clearance (B2B)' : 'Reporting (B2C)' }}</td></tr>
            <tr><th>EGS / environment</th><td>{{ $invoice->egs_identifier }} / {{ $invoice->environment->value }}</td></tr>
            <tr><th>UUID</th><td>{{ $invoice->uuid }}</td></tr>
            <tr><th>ICV</th><td>{{ $invoice->icv }}</td></tr>
            <tr><th>Invoice hash</th><td style="word-break:break-all">{{ $invoice->invoice_hash }}</td></tr>
            <tr><th>Totals</th><td>Excl. VAT {{ number_format($invoice->total_excl_vat, 2) }} + VAT {{ number_format($invoice->total_vat, 2) }} = <b>{{ number_format($invoice->total_incl_vat, 2) }} SAR</b></td></tr>
            <tr><th>ZATCA status</th><td>{{ $invoice->reporting_status ?? $invoice->clearance_status ?? '—' }}</td></tr>
            <tr><th>Accepted at</th><td>{{ $invoice->acceptedAt()?->format('Y-m-d H:i:s') ?? '—' }}</td></tr>
            <tr><th>Attempts</th><td>{{ $invoice->attempt_count }}</td></tr>
            @if ($note)
                <tr><th>Original invoice</th><td>{{ $note->original_source_reference }} (billing ref {{ $note->billing_reference_id }})</td></tr>
                <tr><th>Reason</th><td>{{ $note->reason }}</td></tr>
            @endif
            @if ($invoice->last_error)
                <tr><th>Last error / warnings</th><td><pre style="max-height:200px">{{ $invoice->last_error }}</pre></td></tr>
            @endif
        </table>
        <div class="row" style="margin-top:12px">
            @if ($invoice->unsigned_xml) <a class="btn sec" href="{{ route('zatca.dashboard.invoices.xml', [$invoice, 'unsigned']) }}">Download unsigned XML</a> @endif
            @if ($invoice->signed_xml_base64) <a class="btn" href="{{ route('zatca.dashboard.invoices.xml', [$invoice, 'signed']) }}">Download signed XML</a> @endif
            @if ($invoice->cleared_xml_base64) <a class="btn" href="{{ route('zatca.dashboard.invoices.xml', [$invoice, 'cleared']) }}">Download ZATCA-cleared XML</a> @endif
        </div>
    </div>

    <div class="card">
        <h2>ZATCA response (sanitized, as stored by the package)</h2>
        <pre>{{ json_encode($invoice->last_zatca_response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
    </div>

    <div class="card">
        <h2>Submission attempts</h2>
        <table>
            <tr><th>#</th><th>HTTP</th><th>Outcome</th><th>When</th></tr>
            @forelse ($invoice->submissionAttempts as $a)
                <tr><td>{{ $a->attempt_number }}</td><td>{{ $a->http_status }}</td><td>{{ $a->outcome ?? $a->status ?? '' }}</td><td>{{ $a->created_at }}</td></tr>
            @empty
                <tr><td colspan="4" class="muted">None recorded.</td></tr>
            @endforelse
        </table>
    </div>
@endsection
