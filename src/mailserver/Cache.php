<?php

//declare(strict_types=1);

namespace cryodrift\mailserver;

use cryodrift\fw\FileCache;

/**
 * Minimal cache for mailserver module.
 * Inherit everything from FileCache; configuration is provided via config.php
 */
class Cache extends FileCache
{
}
