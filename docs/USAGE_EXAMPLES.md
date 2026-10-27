# Usage Examples: Beyond the Basics

The README's [§9 Complete usage examples](../README_SIMPLE.md#create-and-read-invoices) already walks through the core flow end to end — B2C invoice, B2C credit note (including a partial price adjustment), B2C debit note, and the B2B equivalents of each, using a real `OrderInvoiceSource` mapper. **Start there** if you haven't yet.

This page covers patterns that come up once you're integrating the package into a real app: previewing VAT before you commit to a sale, building an admin refund screen, checking on invoices programmatically, and recovering from a stuck one. Every class and method below is quoted from the actual package source — file paths are given so you can verify them yourself.

---

## 1. Previewing VAT before you dispatch anything

Sometimes you want to show a customer their VAT breakdown — on a cart page, a quote, or an order-confirmation screen — *before* any invoice is generated, numbered, or signed. `InvoiceCalculator` (`Zatca\Services\Tax\InvoiceCalculator`) is the same class the package uses internally, and it's safe to call standalone: it does no database writes, no signing, and burns no counter.

```php
use Zatca\Services\Tax\InvoiceCalculator;
use Zatca\DTOs\ZatcaInvoiceInput;
use Zatca\DTOs\ZatcaInvoiceItem;

$preview = app(InvoiceCalculator::class)->calculate(new ZatcaInvoiceInput(
    egsIdentifier: config('zatca.primary_egs'),
    sourceReference: 'preview-only',   // never persisted, this call writes nothing
    buyerName: $cart->customer_name,
    buyerVatNumber: null,
    issueDateTime: now()->toDateTimeImmutable(),
    currency: 'SAR',
    items: [
        new ZatcaInvoiceItem(description: 'Wireless headphones', quantity: 2, price: 172.50),
        new ZatcaInvoiceItem(description: 'USB-C charger', quantity: 1, price: 57.50),
    ],
));

$preview->totalExclVat;   // 350.00
$preview->totalVat;       // 52.50
$preview->totalInclVat;   // 402.50
$preview->lines;          // ZatcaInvoiceLine[] — each with its own net/VAT breakdown
```

This uses the same `ZATCA_VAT_PERCENTAGE` / `ZATCA_VAT_MODE` config and the same rounding rules as a real invoice (see [VAT_GUIDE.md](VAT_GUIDE.md)), so a preview total will always match what the real invoice later calculates — nothing here is duplicated math.

## 2. A non-Eloquent invoice source

The README's examples bind an Eloquent `Order` model. The package resolves that model fresh inside the queue job through `InvoiceSourceResolverContract`, whose default implementation (`Zatca\Services\Invoice\EloquentInvoiceSourceResolver`) only knows how to look up Eloquent models by primary key:

```php
// src/Services/Invoice/EloquentInvoiceSourceResolver.php (shipped default)
public function resolve(InvoiceSourceReference $reference): mixed
{
    if (! class_exists($reference->sourceType) || ! is_a($reference->sourceType, Model::class, true)) {
        return null;
    }

    return $reference->sourceType::find($reference->sourceId);
}
```

If your "order" isn't an Eloquent model — it comes from an external API, a different ORM, or a read model assembled from several tables — bind your own resolver instead:

```php
use Zatca\Contracts\InvoiceSourceResolverContract;
use Zatca\DTOs\InvoiceSourceReference;

class ExternalOrderResolver implements InvoiceSourceResolverContract
{
    public function resolve(InvoiceSourceReference $reference): mixed
    {
        // $reference->sourceType and $reference->sourceId are the two scalar
        // values GenerateZatcaInvoiceJob was dispatched with — look them up however
        // your system actually stores orders.
        return app(OrderApiClient::class)->find($reference->sourceId);
    }
}
```

```php
// app/Providers/AppServiceProvider.php
$this->app->bind(InvoiceSourceResolverContract::class, ExternalOrderResolver::class);
```

Your `InvoiceSourceContract::toInvoiceInput()` implementation then receives whatever `resolve()` returns — it doesn't have to be an Eloquent model.

## 3. A refund screen: eligibility check, issue, and event, together

README §9.3 shows `CreditNoteIssuer::issue()` on its own. A real "Refund" button in an admin panel usually needs to check eligibility first (to decide whether to even show the button), handle the request, and then react once ZATCA actually accepts the note — three steps that are easy to leave disconnected. Here's the three wired together for a controller action:

```php
use Illuminate\Support\Str;
use Zatca\Enums\ZatcaCreditNoteType;
use Zatca\Exceptions\CreditNoteException;
use Zatca\Models\ZatcaInvoice;
use Zatca\Services\CreditNote\CreditNoteEligibilityPolicy;
use Zatca\Services\CreditNote\CreditNoteIssuer;

class RefundOrderController
{
    public function store(Request $request, Order $order)
    {
        $original = ZatcaInvoice::where('source_reference', "INV-{$order->id}")->firstOrFail();

        $verdict = app(CreditNoteEligibilityPolicy::class)->verdict($original);
        if (! $verdict->eligible) {
            return back()->withErrors("Cannot refund: {$verdict->reason}");
        }

        try {
            app(CreditNoteIssuer::class)->issue(
                original: $original,
                type: ZatcaCreditNoteType::Full,
                lineIndexes: $request->input('line_indexes'),
                reason: $request->input('reason'),
                idempotencyKey: $request->input('idempotency_key') ??= (string) Str::uuid(),
                requestedBy: ['type' => 'user', 'id' => $request->user()->id],
            );
        } catch (CreditNoteException $e) {
            // ->reason is a stable code (see TROUBLESHOOTING.md) — safe to switch on
            return back()->withErrors("Refund refused: {$e->getMessage()}");
        }

        return back()->with('status', 'Refund submitted — you will be notified once ZATCA accepts it.');
    }
}
```

```php
// app/Listeners/ProcessApprovedRefund.php
use Zatca\Events\ZatcaCreditNoteAccepted;

class ProcessApprovedRefund
{
    public function handle(ZatcaCreditNoteAccepted $event): void
    {
        $creditNote = $event->creditNote;   // Zatca\Models\ZatcaCreditNote

        // Now — and only now — actually move money and restock:
        app(RefundService::class)->refundOriginalPayment($creditNote);
    }
}
```

Showing or hiding the "Refund" button itself (without submitting anything) only needs the cheaper `allows()` check:

```php
$canRefund = app(CreditNoteEligibilityPolicy::class)->allows($original);
```

### Which lines are even left to credit?

Before building the "select lines to refund" UI, `CreditNoteLedger` (`Zatca\Services\CreditNote\CreditNoteLedger`) tells you exactly which lines are still available, already reserved by an in-flight note, or fully credited:

```php
use Zatca\Services\CreditNote\CreditNoteLedger;

$ledger = app(CreditNoteLedger::class)->forOriginal($original);

$ledger->availableIndexes;        // int[] — line indexes a NEW request may select
$ledger->lineStates;              // [0 => 'credited', 1 => 'available', ...]
$ledger->creditableTotalInclVat;  // the original total, adjusted for any accepted debit notes
```

## 4. A document-status dashboard

`ZatcaInspector` (`Zatca\Services\Diagnostics\ZatcaInspector`, the same class behind `zatca:inspect`) returns plain arrays — convenient for an admin JSON endpoint or Blade view, without touching Eloquent models directly:

```php
use Zatca\Services\Diagnostics\ZatcaInspector;

class ZatcaStatusController
{
    public function show(ZatcaInspector $inspector, string $egs = 'default')
    {
        return [
            'credentials' => $inspector->credentials($egs),  // certificate presence/validity
            'state' => $inspector->state($egs),              // current ICV and hash
        ];
    }

    public function invoice(ZatcaInspector $inspector, string $idOrUuid)
    {
        // Accepts either a numeric id or the invoice's UUID.
        return $inspector->invoice($idOrUuid)
            ?? abort(404);
    }
}
```

`invoice()` returns a flat array — `id`, `egs_identifier`, `environment`, `source_reference`, `uuid`, `icv`, `status`, `reporting_status`, `attempt_count`, `last_error`, `reported_at`, `total_incl_vat` — everything you'd want on a "why did this fail" screen, already safe to display.

For a health-check cron or external monitor, `zatca:status --json` gives the same kind of answer as a shell command:

```bash
php artisan zatca:status --json
```

See [TROUBLESHOOTING.md](TROUBLESHOOTING.md) for reading its output, and README [§13](COMMANDS.md#13-console-commands) for every flag.

## 5. Recovering and regenerating from code

The Artisan commands `zatca:recover` and `zatca:requeue` (README [§13](COMMANDS.md#13-console-commands), [ZATCA_WORKFLOWS.md](ZATCA_WORKFLOWS.md)) are the normal way to fix a stuck document. The two jobs behind the "finish this" and "replace this" actions are also dispatchable directly from your own admin code, if you want a button instead of a terminal command:

```php
use Zatca\Jobs\CompleteZatcaInvoiceGenerationJob;
use Zatca\Services\Generation\DraftCompletionPolicy;

// A draft that never finished signing (e.g. the SDK was unreachable mid-generation):
if (app(DraftCompletionPolicy::class)->allows($invoice)) {
    dispatch(new CompleteZatcaInvoiceGenerationJob(
        zatcaInvoiceId: $invoice->id,
        triggeredBy: ['type' => 'user', 'id' => auth()->id()],
    ));
}
```

```php
use Zatca\Jobs\RegenerateZatcaInvoiceJob;
use Zatca\Services\Regeneration\RegenerationPolicy;

// A signed invoice ZATCA rejected for a content reason — never resend it as-is,
// regenerate a fresh, separately-numbered replacement instead:
if (app(RegenerationPolicy::class)->allows($invoice)) {
    dispatch(new RegenerateZatcaInvoiceJob(
        originalZatcaInvoiceId: $invoice->id,
        triggeredBy: ['type' => 'user', 'id' => auth()->id()],
    ));
}
```

Both policies expose a `verdict()` method (not just `allows()`) if you want to show *why* an action isn't available, the same pattern as `CreditNoteEligibilityPolicy::verdict()` above.

## 6. Reacting to a certificate about to expire

```php
// app/Providers/EventServiceProvider.php
use Zatca\Events\ZatcaCertificateExpiring;

protected $listen = [
    ZatcaCertificateExpiring::class => [\App\Listeners\AlertOnCertificateExpiring::class],
];
```

```php
// app/Listeners/AlertOnCertificateExpiring.php
public function handle(ZatcaCertificateExpiring $event): void
{
    // $event->egsIdentifier, $event->credentialType, $event->state,
    // $event->expiresAt, $event->daysRemaining, $event->hasExpired()
    Mail::to('ops@example.com')->send(new CertificateExpiringMail($event));
}
```

This fires once per state transition (first time a certificate becomes `expiring_soon`, then again when it becomes `expired`) — not once per daily check — so it's safe to wire straight to an email or Slack notification without flooding anyone. See [ZATCA_WORKFLOWS.md](ZATCA_WORKFLOWS.md) for the full certificate lifecycle.
