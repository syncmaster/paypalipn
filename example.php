<?php 
		$verified = $this->ipn->verifyIPN();

		if ($verified) {

			$encode = $this->ipn->BlobPack($_POST);
			$user_id = isset($_POST['custom']) ? (int) trim($_POST['custom']) : 0;
			$item_name = isset($_POST['item_name']) ? trim($_POST['item_name']) : '';
			$item_number = isset($_POST['item_number']) ? trim($_POST['item_number']) : '';
			$payment_amount = isset($_POST['mc_gross']) ? trim($_POST['mc_gross']) : '';
			$payment_currency = isset($_POST['mc_currency']) ? trim($_POST['mc_currency']) : '';
			$txn_id = isset($_POST['txn_id']) ? trim($_POST['txn_id']) : '';
			$receiver_email = isset($_POST['receiver_email']) ? trim($_POST['receiver_email']) : '';
			$payer_email = isset($_POST['payer_email']) ? trim($_POST['payer_email']) : '';
			$status = isset($_POST['payment_status']) ? trim($_POST['payment_status']) : '';
			$subscr_id = isset($_POST['subscr_id']) ? trim($_POST['subscr_id']) : '';
			$txn_type = isset($_POST['txn_type']) ? trim($_POST['txn_type']) : '';

			$sql = "SELECT
						`id`,
						`subscription_id`
					FROM `" .TABLE_USERS. "`
					WHERE `id` = '" .$this->db->real_escape_string($user_id). "'
					LIMIT 1;
					";
			if (!$result = $this->db->query($sql)) {
				$this->defaultStatus();
			}

			$user = $result->fetch_assoc();
			$user['id'] = (int) $user['id'];

			$sql = "SELECT
						a.`id`,
						a.`plan_name`,
						a.`price`,
						a.`duration`,
						a.`currency_id`,
						a.`status`,
						b.`code`
					FROM `" .TABLE_PLANS. "` a
					LEFT JOIN `" .TABLE_CURRENCIES. "` b ON
						a.`currency_id` = b.`id`
					WHERE
						a.`status` = 'active'
						AND a.`id` = '" .$this->db->real_escape_string($item_number). "'
					LIMIT 1;
					";
			if (!$result = $this->db->query($sql)) {
				$this->defaultStatus();
			}
			$plan = $result->fetch_assoc();

			if ($status === "Completed"
				&& $user['id'] === $user_id
				&& $payment_currency === $plan['code']
			) {
				$sql = "SELECT `id`
						FROM `" .TABLE_TRANSACTIONS. "`
						WHERE `transactions_id` = '" .$this->db->real_escape_string($txn_id). "'
						LIMIT 1;
						";
				if (!$result = $this->db->query($sql)) {
					$this->defaultStatus();
				}
				$sql = "INSERT INTO `" .TABLE_TRANSACTIONS. "` (
					`user_id`,
					`amount`,
					`transactions_id`,
					`email`,
					`package_id`,
					`package_duration`,
					`package_name`,
					`added`,
					`modified`,
					`expire`,
					`status`,
					`currency`,
					`data`
				) VALUES (
					'" .$this->db->real_escape_string($user_id). "',
					'" .$this->db->real_escape_string($payment_amount). "',
					'" .$this->db->real_escape_string($txn_id). "',
					'" .$this->db->real_escape_string($payer_email). "',
					'" .$this->db->real_escape_string($item_number). "',
					'" .$this->db->real_escape_string($plan['duration']). "',
					'" .$this->db->real_escape_string($item_name). "',
					NOW(),
					NOW(),
					DATE_ADD(NOW(), INTERVAL " .$this->db->real_escape_string($plan['duration']). "),
					'" .$this->db->real_escape_string($status). "',
					'" .$this->db->real_escape_string($plan['currency_id']). "',
					'" .$this->db->real_escape_string($encode). "'
				)
				";
				if (!$this->db->query($sql) || !$this->db->affected_rows) {
					$this->defaultStatus();
				}

				$sql = "UPDATE `" .TABLE_USERS. "` SET
							`subscription_id` = '" .$this->db->real_escape_string($subscr_id). "'
						WHERE `id` = '" .$this->db->real_escape_string($user_id). "'
				";
				if (!$this->db->query($sql) || !$this->db->affected_rows) {
					$this->defaultStatus();
				}

				if ($user['subscription_id'] !== $subscr_id) {
					$sql = "INSERT INTO `".TABLE_SUBSCRIPTIONS."` (
							`id`,
							`subscription_id`,
							`status`,
							`user_id`,
							`plan_id`,
							`added`,
							`modified`
						) VALUES (
							NULL,
							'" . $this->db->real_escape_string($subscr_id) . "',
							'active',
							'" . $this->db->real_escape_string($user['id']) . "',
							'" . $this->db->real_escape_string($plan['id']) . "',
							NOW(),
							NOW()
						)";
					if (!$this->db->query($sql) || !$this->db->affected_rows) {
						// nothing
					}

					if (mb_strlen($user['subscription_id'])) {
						$subStatus = $this->ipn->cancelSubscription($user['subscription_id'])
							? 'inactive'
							: 'pending';

						$sql = "SELECT `id`
								FROM `".TABLE_SUBSCRIPTIONS."`
								WHERE
									`user_id` = '" . $this->db->real_escape_string($user['id']) . "'
									AND `subscription_id` = '" . $this->db->real_escape_string($user['subscription_id']) . "'
								LIMIT 1";
						if (!($result = $this->db->query($sql)) || !$result->num_rows) {
							$this->defaultStatus();
						}

						$subId = $result->fetch_assoc();
						$subId = $subId['id'];

						$sql = "UPDATE `".TABLE_SUBSCRIPTIONS."`
								SET
									`status` = '" . $this->db->real_escape_string($subStatus) . "',
									`modified` = NOW()
								WHERE `id` = '" . $this->db->real_escape_string($subId) . "'
								LIMIT 1";
						if (!$this->db->query($sql) || !$this->db->affected_rows) {
							// nothing to do
						}
					}
				}
			} else if ($txn_type === "subscr_cancel"
					&& $payment_currency === $plan['code']
			){
				$sql = "SELECT `id`
						FROM `" . TABLE_USERS. "`
						WHERE
							`id` = '" . $this->db->real_escape_string($user_id) . "'
							AND `subscription_id` = '" . $this->db->real_escape_string($subscr_id). "'
						LIMIT 1;
				";
				if (($result = $this->db->query($sql)) && $result->num_rows) {
					$sql = "UPDATE `".TABLE_USERS."`
							SET `subscription_id` = ''
							WHERE `id` = '" . $this->db->real_escape_string($user_id) . "'
							LIMIT 1";
					if (!$this->db->query($sql) || !$this->db->affected_rows) {
						// nothing to do
					}
				}

				$sql = "SELECT `id`
						FROM `" . TABLE_SUBSCRIPTIONS. "`
						WHERE
							`user_id` = '" . $this->db->real_escape_string($user_id) . "'
							AND `subscription_id` = '" . $this->db->real_escape_string($subscr_id). "'
							AND `status` <> 'inactive'
						LIMIT 1
				";
				if (($result = $this->db->query($sql)) && $result->num_rows) {


					$subId = $result->fetch_assoc();
					$subId = $subId['id'];

					$sql = "UPDATE `" . TABLE_SUBSCRIPTIONS. "`
							SET
								`status` = 'inactive',
								`modified` = NOW()
							WHERE
								`id` = '" . $this->db->real_escape_string($subId) . "'
							LIMIT 1";
					if (!$this->db->query($sql) || !$this->db->affected_rows) {
						// nothing to do
					}
				}
			}
		}
		header("HTTP/1.1 200 OK");
		