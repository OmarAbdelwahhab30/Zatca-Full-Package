<?php

namespace Zatca\Console\Commands;

use Illuminate\Console\Command;
use Zatca\Support\SourceUniqueness;

/**
 * Read-only. Lists sales that have ZATCA documents on more than one EGS in the same
 * environment. Run it on production (or a copy) before deploying the release that adds
 * the one-document-per-sale index: that migration refuses to run while any group exists.
 */
class CheckSourceUniqueness extends Command
{
    protected $signature = 'zatca:check-source-uniqueness';

    protected $description = 'Read-only: list sales that have ZATCA documents on more than one EGS (must be none before the P19 migration).';

    public function handle(): int
    {
        $groups = SourceUniqueness::duplicateGroups();

        if ($groups === []) {
            $this->info('[OK] Every sale has at most one ZATCA document per environment.');

            return self::SUCCESS;
        }

        $this->error('[FAIL] '.count($groups).' sale(s) have documents on more than one EGS. Resolve them before migrating:');
        $this->table(['Environment', 'Source reference', 'EGS'], array_map(fn ($g) => [$g['environment'], $g['source_reference'], $g['egs']], $groups));

        return self::FAILURE;
    }
}
