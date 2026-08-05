<?php

namespace Zatca\Console\Commands;

use Zatca\Services\Diagnostics\ZatcaInspector;
use Illuminate\Console\Command;

class InspectZatca extends Command
{
    protected $signature = 'zatca:inspect {resource : credentials|state|invoice}
        {--egs=default : EGS identifier}
        {--id= : Invoice id or UUID, required for the invoice resource}';

    protected $description = 'Read-only inspection of ZATCA credentials, EGS state, or a single invoice. Never exposes secrets/keys/XML.';

    public function handle(ZatcaInspector $inspector): int
    {
        $egs = $this->option('egs');

        $data = match ($resource = $this->argument('resource')) {
            'credentials' => $inspector->credentials($egs),
            'state' => $inspector->state($egs),
            'invoice' => $this->inspectInvoice($inspector),
            default => null,
        };

        if ($data === null) {
            $this->error("Unknown resource [{$resource}] or not found.");

            return self::FAILURE;
        }

        $this->line(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }

    private function inspectInvoice(ZatcaInspector $inspector): ?array
    {
        $id = $this->option('id');

        if (! $id) {
            $this->error('--id is required for the invoice resource.');

            return null;
        }

        return $inspector->invoice($id);
    }
}
