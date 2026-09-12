<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Application\Imports;

/** Spec §7 import stages: validate (errors only), dry_run (errors + preview, writes nothing), commit. */
enum ImportMode: string
{
    case Validate = 'validate';
    case DryRun = 'dry_run';
    case Commit = 'commit';
}
