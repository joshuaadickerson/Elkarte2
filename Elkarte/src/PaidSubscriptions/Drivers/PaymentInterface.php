<?php

namespace Elkarte\PaidSubscriptions\Drivers;

interface PaymentInterface
{
	/**
	 * Validates that we have valid data to work with
	 *
	 * - Returns true/false for whether this gateway thinks the data is intended for it.
	 *
	 * @return boolean
	 */
	public function isValid();

	/**
	 * Validate this is valid for this transaction type.
	 *
	 * - If valid returns the subscription and member IDs we are going to process.
	 * @return array
	 */
	public function precheck();

	/**
	 * Returns if this is a refund.
	 * @return bool
	 */
	public function isRefund();

	/**
	 * Returns if this is a subscription.
	 * @return bool
	 */
	public function isSubscription();

	/**
	 * Returns if this is a normal valid approved payment.
	 *
	 * If a transaction is approved x_response_code will contain a value of 1.
	 * If the card is declined x_response_code will contain a value of 2.
	 * If there was an error the card is expired x_response_code will contain a value of 3.
	 * If the transaction is held for review x_response_code will contain a value of 4.
	 *
	 */
	public function isPayment();

	/**
	 * Returns if this is this is a cancellation transaction
	 *
	 * @return boolean
	 */
	public function isCancellation();

	/**
	 * Retrieve the cost.
	 */
	public function getCost();

	/**
	 * Redirect the user away.
	 */
	public function close();
}