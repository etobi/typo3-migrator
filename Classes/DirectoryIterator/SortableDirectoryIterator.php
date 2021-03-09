<?php

namespace AppZap\Migrator\DirectoryIterator;

/***************************************************************
 *  Copyright notice
 *
 *  (c) 2017 Andre Wuttig <wuttig@portrino.de>, portrino GmbH
 *
 *  All rights reserved
 *
 *  This script is part of the TYPO3 project. The TYPO3 project is
 *  free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation; either version 3 of the License, or
 *  (at your option) any later version.
 *
 *  The GNU General Public License can be found at
 *  http://www.gnu.org/copyleft/gpl.html.
 *
 *  This script is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  This copyright notice MUST APPEAR in all copies of the script!
 ***************************************************************/

use ArrayIterator;
use ArrayObject;
use DirectoryIterator;
use IteratorAggregate;

/**
 * Class SortableDirectoryIterator
 * @package AppZap\Migrator\DirectoryIterator
 */
class SortableDirectoryIterator implements IteratorAggregate
{
    /**
     * @var ArrayObject
     */
    private $_storage;

    /**
     * SortableDirectoryIterator constructor.
     * @param string $path
     */
    public function __construct($path)
    {
        $this->_storage = new ArrayObject();

        $files = new DirectoryIterator($path);
        /** @var $file DirectoryIterator */
        foreach ($files as $file) {
            if ($file->isDot()) {
                continue;
            }
            $this->_storage->offsetSet($file->getFilename(), $file->getFileInfo());
        }
        $this->_storage->uksort(
            function ($a, $b) {
                return strcmp($a, $b);
            }
        );
    }

    /**
     * @return ArrayIterator
     */
    public function getIterator()
    {
        return $this->_storage->getIterator();
    }
}
