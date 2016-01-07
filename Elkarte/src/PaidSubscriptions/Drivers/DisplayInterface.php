<?php

namespace Elkarte\PaidSubscriptions\Drivers;

interface DisplayInterface
{
	/**
	 * Admin settings for the gateway.
	 */
	public function getGatewaySettings();

	/**
	 * Whether this gateway is enabled.
	 *
	 * @return bool
	 */
	public function gatewayEnabled();

	/**
	 * Returns the fields needed for the transaction.
	 *
	 * - Called from Profile-Actions.php to return a unique set of fields for the given gateway
	 *
	 * @param int $unique_id
	 * @param mixed[] $sub_data
	 * @param int $value
	 * @param string $period
	 * @param string $return_url
	 *
	 * @return array
	 */
	public function fetchGatewayFields($unique_id, $sub_data, $value, $period, $return_url);
}