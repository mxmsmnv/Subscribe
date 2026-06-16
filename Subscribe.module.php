<?php

/**
 * Subscribe
 *
 * Newsletter subscription handler with lists, double opt-in, honeypot,
 * rate limiting, unsubscribe link, resend confirmation, hooks and public API.
 *
 * Tables:
 *   subscribe_form_lists         — named lists
 *   subscribe_form_subscribers   — unique emails
 *   subscribe_form_subscriptions — many-to-many with status/token per subscription
 *   subscribe_form_ratelimit     — IP-based rate limiting
 *
 * @author Maxim Semenov <maxim@smnv.org> (smnv.org)
 */
class Subscribe extends WireData implements Module, ConfigurableModule {

	public static function getModuleInfo() {
		return [
			'title'    => 'Subscribe',
			'version'  => 105,
			'summary'  => 'Newsletter subscription handler with lists, double opt-in, honeypot, rate limiting and unsubscribe link.',
			'author'   => 'Maxim Semenov',
			'href'     => 'https://smnv.org',
			'requires' => ['ProcessSubscribe'],
			'autoload' => true,
			'singular' => true,
		];
	}

	public function init() {
		$this->addHookBefore('ProcessPageView::execute', $this, 'handleRequest');
	}

	// -------------------------------------------------------------------------
	// Public API: subscribe($email, $listId = null)
	// Returns: ['success' => bool, 'message' => string]
	// -------------------------------------------------------------------------
	public function subscribe($email, $listId = null) {
		$email = trim((string) $email);

		if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
			return ['success' => false, 'message' => $this->_('Invalid email address.')];
		}

		$db     = $this->wire('database');
		$listId = $listId ? (int) $listId : $this->getDefaultListId();

		if (!$listId) {
			return ['success' => false, 'message' => $this->_('No subscription list configured.')];
		}

		// Rate limit check
		if (!$this->checkRateLimit()) {
			return ['success' => false, 'message' => $this->_('Too many attempts. Please try again later.')];
		}

		// Get or create subscriber
		$stmt = $db->prepare("SELECT id FROM `subscribe_form_subscribers` WHERE email = :email LIMIT 1");
		$stmt->bindValue(':email', $email);
		$stmt->execute();
		$sub = $stmt->fetch(PDO::FETCH_ASSOC);

		if (!$sub) {
			$stmt = $db->prepare("INSERT INTO `subscribe_form_subscribers` (email, ip, created_at) VALUES (:email, :ip, :now)");
			$stmt->bindValue(':email', $email);
			$stmt->bindValue(':ip', $this->getClientIp());
			$stmt->bindValue(':now', date('Y-m-d H:i:s'));
			$stmt->execute();
			$subscriberId = (int) $db->lastInsertId();
		} else {
			$subscriberId = (int) $sub['id'];
		}

		// Check existing subscription for this list
		$stmt = $db->prepare("SELECT id, status FROM `subscribe_form_subscriptions` WHERE subscriber_id = :sid AND list_id = :lid LIMIT 1");
		$stmt->bindValue(':sid', $subscriberId, PDO::PARAM_INT);
		$stmt->bindValue(':lid', $listId, PDO::PARAM_INT);
		$stmt->execute();
		$existing = $stmt->fetch(PDO::FETCH_ASSOC);

		if ($existing) {
			if ($existing['status'] === 'active') {
				return ['success' => false, 'message' => $this->_('This email is already subscribed.')];
			}
			if ($existing['status'] === 'pending') {
				return ['success' => false, 'message' => $this->_('Please check your inbox and confirm your subscription.')];
			}
			// unsubscribed — re-subscribe
			$confirmToken = $this->generateToken();
			$unsubToken   = $this->generateToken();
			$stmt = $db->prepare("UPDATE `subscribe_form_subscriptions` SET status='pending', confirm_token=:ct, unsub_token=:ut WHERE id=:id");
			$stmt->bindValue(':ct', $confirmToken);
			$stmt->bindValue(':ut', $unsubToken);
			$stmt->bindValue(':id', $existing['id'], PDO::PARAM_INT);
			$stmt->execute();
			$this->sendConfirmEmail($email, $confirmToken, $unsubToken);
			return ['success' => true, 'message' => $this->_('Please check your inbox to confirm your subscription.')];
		}

		// New subscription
		$confirmToken = $this->generateToken();
		$unsubToken   = $this->generateToken();
		$stmt = $db->prepare("INSERT INTO `subscribe_form_subscriptions` (subscriber_id, list_id, status, confirm_token, unsub_token, created_at) VALUES (:sid, :lid, 'pending', :ct, :ut, :now)");
		$stmt->bindValue(':sid', $subscriberId, PDO::PARAM_INT);
		$stmt->bindValue(':lid', $listId, PDO::PARAM_INT);
		$stmt->bindValue(':ct', $confirmToken);
		$stmt->bindValue(':ut', $unsubToken);
		$stmt->bindValue(':now', date('Y-m-d H:i:s'));
		$stmt->execute();

		$subscriptionId = (int) $db->lastInsertId();

		$this->sendConfirmEmail($email, $confirmToken, $unsubToken);

		// Fire hook: subscribed
		$this->subscribed($email, $listId, $subscriptionId);

		return ['success' => true, 'message' => $this->_('Please check your inbox to confirm your subscription.')];
	}

	// Public API: getSubscribers($listId, $status = 'active')
	// Returns array of ['id', 'email', 'ip', 'status', 'created_at']
	public function getSubscribers($listId, $status = 'active') {
		$db   = $this->wire('database');
		$sql  = "SELECT sc.id, s.email, s.ip, sc.status, sc.created_at
				 FROM `subscribe_form_subscriptions` sc
				 JOIN `subscribe_form_subscribers` s ON s.id = sc.subscriber_id
				 WHERE sc.list_id = :lid";
		$bind = [':lid' => (int) $listId];
		if ($status !== 'all') {
			$sql .= " AND sc.status = :status";
			$bind[':status'] = $status;
		}
		$sql .= " ORDER BY sc.created_at DESC";
		$stmt = $db->prepare($sql);
		$stmt->execute($bind);
		return $stmt->fetchAll(PDO::FETCH_ASSOC);
	}

	// Public API: resendConfirmation($subscriptionId)
	public function resendConfirmation($subscriptionId) {
		$db   = $this->wire('database');
		$stmt = $db->prepare("SELECT sc.id, sc.confirm_token, sc.unsub_token, s.email FROM `subscribe_form_subscriptions` sc JOIN `subscribe_form_subscribers` s ON s.id=sc.subscriber_id WHERE sc.id=:id AND sc.status='pending' LIMIT 1");
		$stmt->bindValue(':id', (int) $subscriptionId, PDO::PARAM_INT);
		$stmt->execute();
		$row = $stmt->fetch(PDO::FETCH_ASSOC);

		if (!$row) return false;

		$confirmToken = $this->generateToken();
		$unsubToken   = $this->generateToken();
		$db->prepare("UPDATE `subscribe_form_subscriptions` SET confirm_token=:ct, unsub_token=:ut WHERE id=:id")
			->execute([':ct' => $confirmToken, ':ut' => $unsubToken, ':id' => $row['id']]);

		$this->sendConfirmEmail($row['email'], $confirmToken, $unsubToken);
		return true;
	}

	// -------------------------------------------------------------------------
	// Hooks
	// -------------------------------------------------------------------------

	/**
	 * Hookable: triggered after a new subscription is created.
	 *
	 * Usage in other modules:
	 *   $modules->get('Subscribe')->addHookAfter('subscribed', function($event) {
	 *       $email          = $event->arguments(0);
	 *       $listId         = $event->arguments(1);
	 *       $subscriptionId = $event->arguments(2);
	 *   });
	 *
	 * @param string $email
	 * @param int    $listId
	 * @param int    $subscriptionId
	 */
	public function ___subscribed($email, $listId, $subscriptionId) {
		// intentionally empty — exists only as a hookable method
	}

	public function handleRequest(HookEvent $event) {
		$input = $this->wire('input');

		if ($input->get('subscribe_confirm')) {
			$this->handleConfirm((string) $input->get('subscribe_confirm'));
		}

		if ($input->get('subscribe_unsubscribe')) {
			$this->handleUnsubscribe((string) $input->get('subscribe_unsubscribe'));
		}

		if ($input->get('subscribe') && $input->post('email')) {
			ob_start();
			header('Content-Type: application/json');

			// Honeypot
			if ((string) $input->post('website') !== '') {
				ob_end_clean();
				echo json_encode(['success' => true, 'message' => $this->_('Thank you for subscribing!')]);
				exit;
			}

			$listId = (int) $input->get('subscribe') > 1 ? (int) $input->get('subscribe') : null;
			$result = $this->subscribe((string) $input->post('email'), $listId);
			ob_end_clean();
			echo json_encode($result);
			exit;
		}

		// JSON API: /?subscribe_api=subscribers&list=1&key=xxx
		if ($input->get('subscribe_api')) {
			$this->handleApi(
				(string) $input->get('subscribe_api'),
				(int) $input->get('list'),
				(string) $input->get('status'),
				(string) $input->get('key')
			);
		}
	}

	// -------------------------------------------------------------------------
	// JSON API
	// -------------------------------------------------------------------------
	protected function handleApi($action, $listId, $status, $key) {
		ob_start();
		header('Content-Type: application/json');

		$apiKey = (string) $this->api_key;
		if (!$apiKey || !hash_equals($apiKey, $key)) {
			ob_end_clean();
			http_response_code(403);
			echo json_encode(['error' => 'Invalid API key.']);
			exit;
		}

		$db = $this->wire('database');

		if ($action === 'subscribers') {
			if (!$listId) $listId = $this->getDefaultListId();
			$status = $status ?: 'active';
			$rows = $this->getSubscribers($listId, $status);
			ob_end_clean();
			echo json_encode(['list' => $listId, 'status' => $status, 'total' => count($rows), 'subscribers' => $rows]);
			exit;
		}

		if ($action === 'lists') {
			$rows = $db->query("SELECT l.id, l.name, COUNT(sc.id) as total FROM `subscribe_form_lists` l LEFT JOIN `subscribe_form_subscriptions` sc ON sc.list_id=l.id GROUP BY l.id ORDER BY l.id ASC")->fetchAll(PDO::FETCH_ASSOC);
			ob_end_clean();
			echo json_encode(['lists' => $rows]);
			exit;
		}

		ob_end_clean();
		http_response_code(400);
		echo json_encode(['error' => 'Unknown action. Use: subscribers, lists']);
		exit;
	}

	protected function handleConfirm($token) {
		$token = preg_replace('/[^a-f0-9]/', '', $token);
		if (strlen($token) !== 64) $this->redirectAfterConfirm(false);

		$db   = $this->wire('database');
		$stmt = $db->prepare("SELECT id FROM `subscribe_form_subscriptions` WHERE confirm_token = :token AND status = 'pending' LIMIT 1");
		$stmt->bindValue(':token', $token);
		$stmt->execute();
		$row = $stmt->fetch(PDO::FETCH_ASSOC);

		if (!$row) $this->redirectAfterConfirm(false);

		$db->prepare("UPDATE `subscribe_form_subscriptions` SET status='active', confirm_token='' WHERE id=:id")
			->execute([':id' => $row['id']]);

		$this->redirectAfterConfirm(true);
	}

	protected function handleUnsubscribe($token) {
		$token = preg_replace('/[^a-f0-9]/', '', $token);
		if (strlen($token) !== 64) $this->redirectAfterConfirm(false);

		$db   = $this->wire('database');
		$stmt = $db->prepare("SELECT id FROM `subscribe_form_subscriptions` WHERE unsub_token = :token AND status = 'active' LIMIT 1");
		$stmt->bindValue(':token', $token);
		$stmt->execute();
		$row = $stmt->fetch(PDO::FETCH_ASSOC);

		if (!$row) $this->redirectAfterConfirm(false);

		$db->prepare("UPDATE `subscribe_form_subscriptions` SET status='unsubscribed', unsub_token='' WHERE id=:id")
			->execute([':id' => $row['id']]);

		$pageId = (int) $this->confirm_error_page;
		$pages  = $this->wire('pages');
		$page   = $pageId ? $pages->get($pageId) : $pages->get('/subscribe-error/');
		$url    = ($page && $page->id) ? $page->url . '?unsubscribed=1' : '/?unsubscribed=1';
		$this->wire('session')->redirect($url);
	}

	protected function redirectAfterConfirm($success) {
		$pages = $this->wire('pages');
		if ($success) {
			$pageId = (int) $this->confirm_success_page;
			$page   = $pageId ? $pages->get($pageId) : $pages->get('/subscribe-confirmed/');
		} else {
			$pageId = (int) $this->confirm_error_page;
			$page   = $pageId ? $pages->get($pageId) : $pages->get('/subscribe-error/');
		}
		$url = ($page && $page->id) ? $page->url : ($success ? '/?subscribed=1' : '/?subscribed=0');
		$this->wire('session')->redirect($url);
	}

	// -------------------------------------------------------------------------
	// Email
	// -------------------------------------------------------------------------
	protected function sendConfirmEmail($email, $confirmToken, $unsubToken) {
		$fromEmail  = $this->from_email ?: $this->wire('config')->adminEmail;
		$fromName   = $this->from_name  ?: $this->wire('config')->httpHost;
		$subject    = $this->confirm_subject ?: $this->_('Please confirm your subscription');
		$scheme  = $this->wire('config')->https ? 'https' : 'http';
		$baseUrl = $scheme . '://' . $this->wire('config')->httpHost . '/';

		$confirmUrl = $baseUrl . '?subscribe_confirm=' . $confirmToken;
		$unsubUrl   = $baseUrl . '?subscribe_unsubscribe=' . $unsubToken;

		$bodyHtml = $this->confirm_body_html;
		if ($bodyHtml) {
			$body = str_replace(['{confirm_url}', '{unsub_url}'], [$confirmUrl, $unsubUrl], $bodyHtml);
		} else {
			$body = '<p>' . $this->_('Thank you for subscribing!') . '</p>'
				. '<p><a href="' . $confirmUrl . '">' . $this->_('Click here to confirm your email address') . '</a></p>'
				. '<p><small>' . $confirmUrl . '</small></p>'
				. '<hr style="margin-top:2em;border:none;border-top:1px solid #eee">'
				. '<p style="font-size:12px;color:#999">'
				. $this->_('If you did not sign up for this, you can ignore this email or')
				. ' <a href="' . $unsubUrl . '" style="color:#999">' . $this->_('unsubscribe here') . '</a>.'
				. '</p>';
		}

		$mailerModule = $this->mail_module ?: '';
		$mailer = $mailerModule ? $this->wire('mail')->new($mailerModule) : $this->wire('mail')->new();
		$mailer->to($email)->from($fromEmail, $fromName)->subject($subject)->bodyHTML($body)->send();
	}

	// -------------------------------------------------------------------------
	// Rate limiting
	// -------------------------------------------------------------------------
	protected function checkRateLimit() {
		$ip     = $this->getClientIp();
		$max    = (int) ($this->rate_limit_attempts ?: 3);
		$window = (int) ($this->rate_limit_minutes ?: 10);
		$db     = $this->wire('database');
		$since  = date('Y-m-d H:i:s', time() - $window * 60);

		$db->prepare("DELETE FROM `subscribe_form_ratelimit` WHERE created_at < :since")->execute([':since' => $since]);

		$stmt = $db->prepare("SELECT COUNT(*) FROM `subscribe_form_ratelimit` WHERE ip = :ip AND created_at >= :since");
		$stmt->execute([':ip' => $ip, ':since' => $since]);
		if ((int) $stmt->fetchColumn() >= $max) return false;

		$db->prepare("INSERT INTO `subscribe_form_ratelimit` (ip, created_at) VALUES (:ip, :now)")
			->execute([':ip' => $ip, ':now' => date('Y-m-d H:i:s')]);

		return true;
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------
	protected function getDefaultListId() {
		$stmt = $this->wire('database')->prepare("SELECT id FROM `subscribe_form_lists` ORDER BY id ASC LIMIT 1");
		$stmt->execute();
		$row = $stmt->fetch(PDO::FETCH_ASSOC);
		return $row ? (int) $row['id'] : 0;
	}

	protected function generateToken() {
		return bin2hex(random_bytes(32));
	}

	protected function getClientIp() {
		foreach (['HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $key) {
			if (!empty($_SERVER[$key])) {
				$ip = trim(explode(',', $_SERVER[$key])[0]);
				if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
			}
		}
		return '';
	}

	// -------------------------------------------------------------------------
	// Config
	// -------------------------------------------------------------------------
	public static function getModuleConfigInputfields(array $data) {
		$modules = wire('modules');
		$inputfields = $modules->get('InputfieldWrapper');

		$defaults = [
			'mail_module'          => '',
			'from_email'           => '',
			'from_name'            => '',
			'confirm_subject'      => '',
			'confirm_body_html'    => '',
			'confirm_success_page' => '',
			'confirm_error_page'   => '',
			'rate_limit_attempts'  => 3,
			'rate_limit_minutes'   => 10,
			'api_key'              => '',
		];
		$data = array_merge($defaults, $data);

		// Send method
		$fs = $modules->get('InputfieldFieldset');
		$fs->label = __('Send method');

		$f = $modules->get('InputfieldRadios');
		$f->attr('name', 'mail_module');
		$f->label = __('Mailer');
		$f->description = __('Select which mailer to use for sending emails.');
		$f->notes = __('For more sending options, you can install WireMail modules.');
		$f->addOption('', __('Default (site WireMail setting)'));
		foreach (wire('modules')->find('className^=WireMail') as $m) {
			$name = $m->className();
			if ($name === 'WireMail') continue;
			$f->addOption($name, $name);
		}
		$f->value = $data['mail_module'];
		$fs->add($f);
		$inputfields->add($fs);

		// Confirmation email
		$fs2 = $modules->get('InputfieldFieldset');
		$fs2->label = __('Confirmation email');

		$f = $modules->get('InputfieldEmail');
		$f->attr('name', 'from_email');
		$f->label = __('From Email');
		$f->description = __('Defaults to $config->adminEmail if empty.');
		$f->columnWidth = 50;
		$f->value = $data['from_email'];
		$fs2->add($f);

		$f = $modules->get('InputfieldText');
		$f->attr('name', 'from_name');
		$f->label = __('From Name');
		$f->columnWidth = 50;
		$f->value = $data['from_name'];
		$fs2->add($f);

		$f = $modules->get('InputfieldText');
		$f->attr('name', 'confirm_subject');
		$f->label = __('Subject');
		$f->value = $data['confirm_subject'];
		$fs2->add($f);

		$f = $modules->get('InputfieldTextarea');
		$f->attr('name', 'confirm_body_html');
		$f->label = __('Email body (HTML)');
		$f->description = __('Placeholders: {confirm_url} — confirmation link, {unsub_url} — unsubscribe link. Leave empty to use the default template.');
		$f->notes = __('Example: <p><a href="{confirm_url}">Confirm</a> | <a href="{unsub_url}">Unsubscribe</a></p>');
		$f->rows = 10;
		$f->value = $data['confirm_body_html'];
		$fs2->add($f);

		$inputfields->add($fs2);

		// Redirect pages
		$fs3 = $modules->get('InputfieldFieldset');
		$fs3->label = __('Redirect pages');
		$fs3->description = __('Pages to redirect to after email confirmation. Created automatically on install.');

		$f = $modules->get('InputfieldPageListSelect');
		$f->attr('name', 'confirm_success_page');
		$f->label = __('Confirmation success page');
		$f->description = __('Shown after successful email confirmation. Defaults to /subscribe-confirmed/');
		$f->columnWidth = 50;
		$f->value = $data['confirm_success_page'];
		$fs3->add($f);

		$f = $modules->get('InputfieldPageListSelect');
		$f->attr('name', 'confirm_error_page');
		$f->label = __('Confirmation error page');
		$f->description = __('Shown when token is invalid or expired. Also used for unsubscribe redirect. Defaults to /subscribe-error/');
		$f->columnWidth = 50;
		$f->value = $data['confirm_error_page'];
		$fs3->add($f);

		$inputfields->add($fs3);

		// Rate limiting
		$fs4 = $modules->get('InputfieldFieldset');
		$fs4->label = __('Rate limiting');
		$fs4->description = __('Limit subscription attempts per IP address.');

		$f = $modules->get('InputfieldInteger');
		$f->attr('name', 'rate_limit_attempts');
		$f->label = __('Max attempts');
		$f->description = __('Maximum subscription attempts per IP within the time window.');
		$f->columnWidth = 50;
		$f->value = $data['rate_limit_attempts'];
		$fs4->add($f);

		$f = $modules->get('InputfieldInteger');
		$f->attr('name', 'rate_limit_minutes');
		$f->label = __('Time window (minutes)');
		$f->columnWidth = 50;
		$f->value = $data['rate_limit_minutes'];
		$fs4->add($f);

		$inputfields->add($fs4);

		// API
		$fs5 = $modules->get('InputfieldFieldset');
		$fs5->label = __('JSON API');
		$fs5->description = __('Read-only API for external integrations. Leave the key empty to disable.');

		$f = $modules->get('InputfieldText');
		$f->attr('name', 'api_key');
		$f->label = __('API Key');
		$f->description = __('Secret key required for all API requests. Generate a random string and share it only with trusted integrations.');
		$f->notes = __('Endpoints: `/?subscribe_api=subscribers&list=1&status=active&key=YOUR_KEY` — returns subscribers. `/?subscribe_api=lists&key=YOUR_KEY` — returns all lists.');
		$f->value = $data['api_key'];
		$fs5->add($f);

		$inputfields->add($fs5);

		return $inputfields;
	}
}