<?php

namespace App\Gallery;

use RuntimeException;

/** A read was stopped on purpose (a user started browsing). The file is not marked: it is read again later. */
class ScanAbortedException extends RuntimeException {}
