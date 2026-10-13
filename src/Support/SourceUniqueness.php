<?php

namespace Zatca\Support;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;

/**
 * Finds sales that have ZATCA documents on more than one EGS in the same environment —
 * the case the unique index zatca_inv_env_src_uq forbids from P19 on. Shared by
 * `zatca:check-source-uniqueness` (read-only, run before deploying) and by the migration
 * that adds the index, which refuses to run while any such group exists.
 */
class SourceUniqueness
{
    /** @return list<array{environment: string, source_reference: string, egs: string}> */
    public static function duplicateGroups(?ConnectionInterface $connection = null): array
    {
        $connection ??= DB::connection();

        return $connection->table('zatca_invoices')
            ->select('environment', 'source_reference')
            ->selectRaw('COUNT(*) AS documents')
            ->groupBy('environment', 'source_reference')
            ->havingRaw('COUNT(*) > 1')
            ->orderBy('environment')
            ->orderBy('source_reference')
            ->get()
            ->map(fn ($group) => [
                'environment' => (string) $group->environment,
                'source_reference' => (string) $group->source_reference,
                'egs' => $connection->table('zatca_invoices')
                    ->where('environment', $group->environment)
                    ->where('source_reference', $group->source_reference)
                    ->orderBy('egs_identifier')
                    ->pluck('egs_identifier')
                    ->implode(', '),
            ])
            ->values()
            ->all();
    }
}
