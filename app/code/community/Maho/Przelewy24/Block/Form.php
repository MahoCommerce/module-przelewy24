<?php

/**
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 * @package Maho_Przelewy24
 */

declare(strict_types=1);

class Maho_Przelewy24_Block_Form extends Mage_Payment_Block_Form
{
    #[\Override]
    protected function _construct(): void
    {
        parent::_construct();
        $this->setMethodTitle(Mage::helper('maho_przelewy24')->__('Przelewy24'));
    }
}
