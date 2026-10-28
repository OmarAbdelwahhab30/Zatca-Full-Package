@extends('zatca-dashboard::layout')
@section('title', 'Invoices')
@section('content')
    @include('zatca-dashboard::scenarios')

    <div class="card">
        <h2>Invoice history</h2>
        <table>
            <tr><th>#</th><th>Reference</th><th>Type</th><th>Flow</th><th>EGS</th><th>Total</th><th>Status</th><th>Created</th><th></th></tr>
            @forelse ($invoices as $inv)
                @php
                    $ok = $inv->status->isTerminalSuccess();
                    $bad = in_array($inv->status->value, ['manual_review','clearance_manual_review','clearance_rejected','failed']);
                    $ref = $inv->creditNote?->credit_note_number ?? $inv->debitNote?->debit_note_number ?? $inv->source_reference;
                @endphp
                <tr>
                    <td>{{ $inv->id }}</td>
                    <td>{{ $ref }}</td>
                    <td>{{ $inv->document_type->value }}</td>
                    <td>{{ $inv->submission_model->value }}</td>
                    <td>{{ $inv->egs_identifier }}</td>
                    <td>{{ number_format($inv->total_incl_vat, 2) }} SAR</td>
                    <td><span class="badge {{ $ok ? 'ok' : ($bad ? 'bad' : 'warn') }}">{{ $inv->status->value }}</span></td>
                    <td>{{ $inv->created_at->format('Y-m-d H:i') }}</td>
                    <td><a href="{{ route('zatca.dashboard.invoices.show', $inv) }}">Details</a></td>
                </tr>
            @empty
                <tr><td colspan="9" class="muted">No documents yet — use a button above.</td></tr>
            @endforelse
        </table>
        <div style="margin-top:10px">{{ $invoices->links() }}</div>
    </div>
@endsection
