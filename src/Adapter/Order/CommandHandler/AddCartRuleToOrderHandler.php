<?php
/**
 * Copyright since 2007 PrestaShop SA and Contributors
 * PrestaShop is an International Registered Trademark & Property of PrestaShop SA
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is bundled with this package in the file LICENSE.md.
 * It is also available through the world-wide-web at this URL:
 * https://opensource.org/licenses/OSL-3.0
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@prestashop.com so we can send you a copy immediately.
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade PrestaShop to newer
 * versions in the future. If you wish to customize PrestaShop for your
 * needs please refer to https://devdocs.prestashop.com/ for more information.
 *
 * @author    PrestaShop SA and Contributors <contact@prestashop.com>
 * @copyright Since 2007 PrestaShop SA and Contributors
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

namespace PrestaShop\PrestaShop\Adapter\Order\CommandHandler;

use Cart;
use CartRule;
use Configuration;
use Currency;
use OrderCartRule;
use OrderInvoice;
use PrestaShop\PrestaShop\Adapter\Order\AbstractOrderHandler;
use PrestaShop\PrestaShop\Adapter\Order\OrderAmountUpdater;
use PrestaShop\PrestaShop\Core\Domain\CartRule\Exception\InvalidCartRuleDiscountValueException;
use PrestaShop\PrestaShop\Core\Domain\Order\Command\AddCartRuleToOrderCommand;
use PrestaShop\PrestaShop\Core\Domain\Order\CommandHandler\AddCartRuleToOrderHandlerInterface;
use PrestaShop\PrestaShop\Core\Domain\Order\Exception\OrderException;
use PrestaShop\PrestaShop\Core\Domain\Order\OrderDiscountType;
use PrestaShop\PrestaShop\Core\Localization\CLDR\ComputingPrecision;
use PrestaShopException;
use Tools;
use Validate;

/**
 * @internal
 */
final class AddCartRuleToOrderHandler extends AbstractOrderHandler implements AddCartRuleToOrderHandlerInterface
{
    /**
     * @var OrderAmountUpdater
     */
    private $orderAmountUpdater;

    /**
     * @param OrderAmountUpdater $orderAmountUpdater
     */
    public function __construct(OrderAmountUpdater $orderAmountUpdater)
    {
        $this->orderAmountUpdater = $orderAmountUpdater;
    }

    /**
     * {@inheritdoc}
     */
    public function handle(AddCartRuleToOrderCommand $command): void
    {
        $order = $this->getOrder($command->getOrderId());

        $computingPrecision = new ComputingPrecision();
        $currency = new Currency((int) $order->id_currency);
        $precision = $computingPrecision->getPrecision($currency->precision);

        // If the discount is for only one invoice
        $orderInvoice = null;
        if ($order->hasInvoice() && null !== $command->getOrderInvoiceId()) {
            $orderInvoice = new OrderInvoice($command->getOrderInvoiceId()->getValue());
            if (!Validate::isLoadedObject($orderInvoice)) {
                throw new OrderException('Can\'t load Order Invoice object');
            }
        }

        $cart = Cart::getCartByOrderId($order->id);
        $cartRuleObj = new CartRule();
        $cartRuleObj->date_from = date('Y-m-d H:i:s', strtotime('-1 hour', strtotime($order->date_add)));
        $cartRuleObj->date_to = date('Y-m-d H:i:s', strtotime('+1 hour'));
        $cartRuleObj->name[Configuration::get('PS_LANG_DEFAULT')] = $command->getCartRuleName();
        // This a one time cart rule, for a specific user that can only be used once
        $cartRuleObj->id_customer = $cart->id_customer;
        $cartRuleObj->quantity = 1;
        $cartRuleObj->quantity_per_user = 1;
        $cartRuleObj->active = 0;
        $cartRuleObj->highlight = 0;

        if ($command->getCartRuleType() === OrderDiscountType::DISCOUNT_PERCENT) {
            $cartRuleObj->reduction_percent = (float) (string) $command->getDiscountValue();
        } elseif ($command->getCartRuleType() === OrderDiscountType::DISCOUNT_AMOUNT) {
            $discountValueTaxIncluded = (float) (string) $command->getDiscountValue();
            $discountValueTaxExcluded = Tools::ps_round(
                $discountValueTaxIncluded / (1 + ($order->getTaxesAverageUsed() / 100)),
                $precision
            );
            $cartRuleObj->reduction_amount = $discountValueTaxExcluded;
        } elseif ($command->getCartRuleType() === OrderDiscountType::FREE_SHIPPING) {
            $cartRuleObj->free_shipping = 1;
        }

        try {
            if (!$cartRuleObj->add()) {
                throw new OrderException('An error occurred during the CartRule creation');
            }
        } catch (PrestaShopException $e) {
            throw new OrderException('An error occurred during the CartRule creation', 0, $e);
        }

        try {
            // It's important to add the cart rule to the cart Or it will be ignored when cart performs AutoRemove AddAdd
            if (!$cart->addCartRule($cartRuleObj->id)) {
                throw new OrderException('An error occurred while adding CartRule to cart');
            }
        } catch (PrestaShopException $e) {
            throw new OrderException('An error occurred while adding CartRule to cart', 0, $e);
        }

        $this->orderAmountUpdater->update($order, $cart, null !== $orderInvoice);
    }
}
