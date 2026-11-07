<?php

namespace App\Exceptions;

use App\Models\Hotel;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * v10.6 a screen the person is allowed to open, behind a module that was not bought.
 *
 * v10.3 answered this with a bare 403 and a sentence, which left the reader unable to tell whether
 * the group had never bought the module or the property they are standing in had switched it off —
 * and those need two different people to fix them. The exception carries what is known at the gate
 * (the module, the property, and whether the group still holds it) so the refusal can explain itself.
 *
 * Only ever thrown for somebody who would otherwise have been allowed in: for anyone else the
 * refusal stays an ordinary "no permission", so an unlicensed module is not advertised.
 */
class ModuleNotLicensed extends HttpException
{
    public function __construct(
        public readonly string $module,
        public readonly ?Hotel $property = null,
        public readonly bool $groupHoldsIt = false,
        public readonly ?string $permission = null,
        public readonly ?string $propertyName = null,
    ) {
        parent::__construct(403, 'The '.$module.' module is not licensed here.');
    }
}
