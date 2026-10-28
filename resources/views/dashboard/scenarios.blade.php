<div class="card">
    <h2>Run a scenario (real ZATCA {{ strtoupper(app(\Zatca\State\EgsRegistry::class)->primary()->environment->value) }} calls)</h2>
    <p class="muted">Each button creates an order in the database, generates/signs the document with the package, submits it to ZATCA
        (B2C → Reporting, B2B → Clearance) and opens the stored result. Notes are issued against an existing accepted invoice
        (a new one is created automatically if none is eligible). Takes ~5–30 seconds.</p>
    <div class="scen">
        @foreach ($scenarios as $key => [$label, $family])
            <form method="POST" action="{{ route('zatca.dashboard.scenarios.run', $key) }}" onsubmit="this.querySelector('button').disabled=true;this.querySelector('button').textContent='Working…'">
                @csrf
                <button class="{{ $family }}">{{ $loop->iteration }}. {{ $label }}</button>
            </form>
        @endforeach
    </div>
</div>
