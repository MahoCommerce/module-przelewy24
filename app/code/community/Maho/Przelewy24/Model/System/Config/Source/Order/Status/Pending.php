<?php

/**
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 * @package Maho_Przelewy24
 */

declare(strict_types=1);

/**
 * Order statuses belonging to the pending_payment state. Maho core ships no
 * built-in source scoped to this state (its `_new` variant covers STATE_NEW
 * only), and P24 places orders in STATE_PENDING_PAYMENT before the customer
 * returns from the gateway, so we need our own list here.
 */
class Maho_Przelewy24_Model_System_Config_Source_Order_Status_Pending extends Mage_Adminhtml_Model_System_Config_Source_Order_Status
{
    /** @var mixed */
    protected $_stateStatuses = Mage_Sales_Model_Order::STATE_PENDING_PAYMENT;
}
