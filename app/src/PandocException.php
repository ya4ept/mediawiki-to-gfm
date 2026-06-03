<?php

declare(strict_types=1);

namespace App;

/**
 * Thrown when the Pandoc binary cannot be located, set up, or fails a conversion.
 *
 * Derived from ryakad/pandoc-php (MIT, Copyright (c) 2013 Ryan Kadwell), which is
 * no longer maintained; vendored and trimmed for this project.
 */
class PandocException extends \RuntimeException
{
}
