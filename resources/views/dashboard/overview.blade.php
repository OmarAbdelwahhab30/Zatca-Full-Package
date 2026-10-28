@extends('zatca-dashboard::layout')
@section('title', 'Overview')
@section('content')
    <div class="grid" style="margin-bottom:18px">
        <div class="stat">Environment<b>{{ strtoupper($environment) }}</b></div>
        <div class="stat">Total documents<b>{{ $total }}</b></div>
        <div class="stat">Successful<b style="color:var(--g)">{{ $successful }}</b></div>
        <div class="stat">Pending<b style="color:var(--y)">{{ $pending }}</b></div>
        <div class="stat">Failed<b style="color:var(--r)">{{ $failed }}</b></div>
        <div class="stat">Credit / Debit notes<b>{{ $creditNotes }} / {{ $debitNotes }}</b></div>
    </div>

    @unless ($demoReady)
        <div class="flash error">The demo tables are missing. Run <code>php artisan migrate</code> (with ZATCA_DASHBOARD_ENABLED=true) before using the scenario buttons.</div>
    @endunless

    @include('zatca-dashboard::scenarios')

    <div class="card">
        <h2>Onboarding &amp; certificates</h2>
        <table>
            <tr><th>Device (EGS)</th><th>Onboarding</th><th>Compliance cert</th><th>Production cert</th><th>Production expires</th><th>ICV</th></tr>
            @foreach ($egsRows as $r)
                <tr>
                    <td><b>{{ $r['egs'] }}</b></td>
                    <td><span class="badge {{ $r['onboarded'] ? 'ok' : 'warn' }}">{{ $r['onboarded'] ? 'Complete' : 'Not onboarded' }}</span></td>
                    <td>{{ $r['compliance']['certificate_state'] ?? '—' }}</td>
                    <td>{{ $r['production']['certificate_state'] ?? '—' }}</td>
                    <td>{{ $r['production']['certificate_expires_at'] ?? '—' }}</td>
                    <td>@foreach ($r['state'] as $s) {{ $s['credential_type'] ?? '' }}: {{ $s['icv'] ?? '?' }}@if(! $loop->last), @endif @endforeach</td>
                </tr>
            @endforeach
        </table>
        <p class="muted">B2B ready (ZATCA_B2B_ENABLED + valid credentials): <b>{{ $b2bReady ? 'yes' : 'no' }}</b>.
            Sample data in DB: {{ $counts['customers'] }} customers, {{ $counts['products'] }} products, {{ $counts['orders'] }} orders.</p>
    </div>
@endsection
