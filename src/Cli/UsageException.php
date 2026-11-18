<?php

declare(strict_types=1);

namespace PinCrack\Cli;

use RuntimeException;

/** Thrown for anything the operator can fix by changing the command line. */
final class UsageException extends RuntimeException
{
}
