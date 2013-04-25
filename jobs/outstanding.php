<?php

/**
 * Outstanding premium payments job.
 */

// get the relevant premium and address info
$q = db()->prepare("SELECT p.*,
		a.address, a.currency
	FROM outstanding_premiums AS p
	JOIN addresses AS a ON p.address_id=a.id
	WHERE p.id=?");
$q->execute(array($job['arg_id']));
$address = $q->fetch();
if (!$address) {
	throw new JobException("Cannot find outstanding ID " . $job['arg_id'] . " with a relevant address");
}

// find the most recent balance
$q = db()->prepare("SELECT * FROM address_balances WHERE address_id=? AND is_recent=1");
$q->execute(array($address['address_id']));
$balance = $q->fetch();
if (!$balance) {
	// no balance yet
	crypto_log("No balance retrieved yet.");

} else {

	// is it enough?
	if ($balance['balance'] >= $address['balance']) {
		crypto_log("Sufficient balance found: applying premium status to user " . $address['user_id']);

		// get current user
		$q = db()->prepare("SELECT * FROM users WHERE id=?");
		$q->execute(array($address['user_id']));
		$user = $q->fetch();
		if (!$user) {
			throw new JobException("Could not find user " . $address['user_id']);
		}

		// calculate new expiry date
		$expires = max(strtotime($user['premium_expires']), time());
		crypto_log("Old expiry date: " . db_date($expires));

		$expires = strtotime(db_date($expires) . " +" . $address['months'] . " months +" . $address['years'] . " years");
		crypto_log("New premium expiry date: " . db_date($expires));

		// apply premium data to user account
		$q = db()->prepare("UPDATE users SET is_premium=1, premium_expires=? WHERE id=? LIMIT 1");
		$q->execute(array(db_date($expires), $address['user_id']));

		// update outstanding premium as paid
		$q = db()->prepare("UPDATE outstanding_premiums SET is_paid=1,paid_at=NOW() WHERE id=?");
		$q->execute(array($address['id']));

		// try sending email, if an email address has been registered
		if ($user['email']) {
			send_email($user['email'], ($user['name'] ? $user['name'] : $user['email']), "purchase_payment", array(
				"name" => ($user['name'] ? $user['name'] : $user['email']),
				"amount" => $balance['balance'],
				"currency" => strtoupper($address['currency']),
				"currency_name" => get_currency_name($address['currency']),
				"expires" => db_date($expires),
				"address" => $address['address'],
				"explorer" => get_site_config($currency . '_address_url'),
				"url" => absolute_url(url_for("user")),
			));
		}

	} else {
		crypto_log("Insufficient balance found: " . $balance['balance'] . " (expected " . $address['balance'] . ")");

	}
}