<?php

declare(strict_types=1);

namespace Qiick\Audit\Erasure;

use LogicException;

/**
 * The audit store or anonymiser cannot honour an organisation boundary.
 *
 * @api
 */
final class OrganisationScopeUnsupportedException extends LogicException {}
