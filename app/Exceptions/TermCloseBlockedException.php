<?php

namespace App\Exceptions;

use App\Models\Application;
use DomainException;
use Illuminate\Support\Collection;

/** Thrown by ApplicationWorkflow::closeTerm() while applications are still unfinished (spec §3.1). */
class TermCloseBlockedException extends DomainException
{
    /** @param  Collection<int, Application>  $applications  with `instructor` loaded */
    public function __construct(public readonly Collection $applications)
    {
        parent::__construct(__('app.terms.close_blocked'));
    }
}
