@extends('zatca-dashboard::layout')
@section('title', 'Onboarding')
@section('content')
    <div class="card">
        <h2>Seller configuration (from <code>.env</code> via the package)</h2>
        @php $c = $devices[0]['config']; @endphp
        <table>
            <tr><th style="width:220px">Legal name (EN / AR)</th><td>{{ $c->organizationName }} / {{ $c->companyNameAr }}</td></tr>
            <tr><th>VAT number</th><td>{{ $c->vatNumber }}</td></tr>
            <tr><th>CR number</th><td>{{ $c->commercialRegistrationNumber }}</td></tr>
            <tr><th>Branch</th><td>{{ $c->organizationUnitName }}</td></tr>
            <tr><th>National address</th><td>{{ $c->oneLineAddress() }}</td></tr>
            <tr><th>Environment</th><td><b>{{ strtoupper($environment) }}</b> — sandbox accepts any OTP (docs: 123345)</td></tr>
        </table>
    </div>

    @foreach ($devices as $d)
        @php
            $prod = collect($d['credentials'])->firstWhere('credential_type', 'production');
            $comp = collect($d['credentials'])->firstWhere('credential_type', 'compliance');
            $done = $prod && $prod['has_certificate'];
        @endphp
        <div class="card">
            <h2>Device <code>{{ $d['egs'] }}</code> ({{ $d['egs'] === config('zatca.primary_egs') ? 'B2C / simplified' : 'B2B / standard' }},
                CSR type {{ $d['config']->invoiceType }})
                <span class="badge {{ $done ? 'ok' : 'warn' }}">{{ $done ? 'onboarding complete' : 'not complete' }}</span></h2>

            <table>
                <tr><th>Credential</th><th>Token</th><th>Certificate</th><th>Private key</th><th>State</th><th>Expires</th></tr>
                @foreach ($d['credentials'] as $cr)
                    <tr><td>{{ $cr['credential_type'] }}</td><td>{{ $cr['has_token'] ? 'stored' : '—' }}</td><td>{{ $cr['has_certificate'] ? 'stored' : '—' }}</td>
                        <td>{{ $cr['has_private_key'] ? 'stored (encrypted)' : '—' }}</td><td>{{ $cr['certificate_state'] }}</td><td>{{ $cr['certificate_expires_at'] ?? '—' }}</td></tr>
                @endforeach
                @if (empty($d['credentials'])) <tr><td colspan="6" class="muted">No credentials yet.</td></tr> @endif
            </table>

            <h3 style="font-size:14px;margin:16px 0 6px">Steps</h3>
            <div class="row">
                <form method="POST" action="{{ route('zatca.dashboard.onboarding.run', [$d['egs'], 'generate']) }}" onsubmit="return confirm('This REPLACES the stored private key and CSR for {{ $d['egs'] }}. Continue?')">@csrf
                    <button class="sec" @disabled($done)>1. Generate key + CSR</button></form>
                <form method="POST" action="{{ route('zatca.dashboard.onboarding.run', [$d['egs'], 'compliance']) }}" class="row">@csrf
                    <input type="text" name="otp" value="123345" size="8" aria-label="OTP">
                    <button>2. Compliance CSID</button></form>
                <form method="POST" action="{{ route('zatca.dashboard.onboarding.run', [$d['egs'], 'test-invoice']) }}">@csrf<button>3a. Check: invoice</button></form>
                <form method="POST" action="{{ route('zatca.dashboard.onboarding.run', [$d['egs'], 'test-credit-note']) }}">@csrf<button>3b. Check: credit note</button></form>
                <form method="POST" action="{{ route('zatca.dashboard.onboarding.run', [$d['egs'], 'test-debit-note']) }}">@csrf<button>3c. Check: debit note</button></form>
                <form method="POST" action="{{ route('zatca.dashboard.onboarding.run', [$d['egs'], 'production']) }}">@csrf<button>4. Production CSID</button></form>
            </div>
            <p class="muted">Generate is disabled once a production certificate exists (re-running replaces the key). Compliance steps need the OTP; each check consumes a compliance counter. The result or error of each action appears in the banner at the top.</p>

            <h3 style="font-size:14px;margin:16px 0 6px">Compliance checks stored by the package</h3>
            <table>
                <tr><th>Document</th><th>Result</th><th>Errors / warnings from ZATCA</th><th>When</th></tr>
                @forelse ($d['compliance_docs'] as $doc)
                    @php $resp = $doc->zatca_response ?? []; @endphp
                    <tr><td>{{ $doc->document_type }}</td>
                        <td><span class="badge {{ strtoupper((string) $doc->reporting_status) === 'PASS' ? 'ok' : (strtoupper((string) $doc->reporting_status) === 'ERROR' ? 'bad' : 'warn') }}">{{ $doc->reporting_status }}</span></td>
                        <td class="muted">{{ \Illuminate\Support\Str::limit(json_encode($resp['validationResults']['warningMessages'] ?? $resp['validationResults']['errorMessages'] ?? [], JSON_UNESCAPED_UNICODE), 220) }}</td>
                        <td>{{ $doc->created_at }}</td></tr>
                @empty
                    <tr><td colspan="4" class="muted">No compliance checks run yet.</td></tr>
                @endforelse
            </table>
        </div>
    @endforeach
@endsection
