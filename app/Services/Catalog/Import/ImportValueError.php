<?php

namespace App\Services\Catalog\Import;

use RuntimeException;

/** A cell that isn't exactly an accepted value. The message is shown to the person importing. */
class ImportValueError extends RuntimeException {}
