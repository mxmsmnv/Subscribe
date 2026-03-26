<?php

/**
 * ProcessSubscribe
 *
 * Admin UI: manage lists, search/filter, paginate, add/toggle/delete/resend,
 * import CSV, export JSON/CSV.
 *
 * @author Maxim Alex
 */
class ProcessSubscribe extends Process {

	public static function getModuleInfo() {
		return [
			'title'    => 'ProcessSubscribe',
			'version'  => 102,
			'summary'  => 'Admin interface for Subscribe module.',
			'author'   => 'Maxim Alex',
			'requires' => [],
			'autoload' => false,
			'singular' => true,
			'page'     => [
				'name'   => 'subscribe-form',
				'parent' => 'setup',
				'title'  => 'Subscribers',
			],
		];
	}

	const PER_PAGE = 50;

	public function ___install() {
		parent::___install();
		$db = $this->wire('database');

		$db->exec("CREATE TABLE IF NOT EXISTS `subscribe_form_lists` (`id` INT UNSIGNED NOT NULL AUTO_INCREMENT, `name` VARCHAR(255) NOT NULL, `created_at` DATETIME NOT NULL, PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
		$db->exec("CREATE TABLE IF NOT EXISTS `subscribe_form_subscribers` (`id` INT UNSIGNED NOT NULL AUTO_INCREMENT, `email` VARCHAR(255) NOT NULL, `ip` VARCHAR(45) NOT NULL DEFAULT '', `created_at` DATETIME NOT NULL, PRIMARY KEY (`id`), UNIQUE KEY `email` (`email`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
		$db->exec("CREATE TABLE IF NOT EXISTS `subscribe_form_subscriptions` (`id` INT UNSIGNED NOT NULL AUTO_INCREMENT, `subscriber_id` INT UNSIGNED NOT NULL, `list_id` INT UNSIGNED NOT NULL, `status` ENUM('pending','active','unsubscribed') NOT NULL DEFAULT 'pending', `confirm_token` VARCHAR(64) NOT NULL DEFAULT '', `unsub_token` VARCHAR(64) NOT NULL DEFAULT '', `created_at` DATETIME NOT NULL, PRIMARY KEY (`id`), UNIQUE KEY `sub_list` (`subscriber_id`,`list_id`), KEY `list_id` (`list_id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
		$db->exec("CREATE TABLE IF NOT EXISTS `subscribe_form_ratelimit` (`id` INT UNSIGNED NOT NULL AUTO_INCREMENT, `ip` VARCHAR(45) NOT NULL DEFAULT '', `created_at` DATETIME NOT NULL, PRIMARY KEY (`id`), KEY `ip` (`ip`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

		$db->exec("INSERT INTO `subscribe_form_lists` (name, created_at) VALUES ('Default', NOW())");

		$this->createConfirmPages();
	}

	public function ___uninstall() {
		$db = $this->wire('database');
		$db->exec("DROP TABLE IF EXISTS `subscribe_form_subscriptions`");
		$db->exec("DROP TABLE IF EXISTS `subscribe_form_subscribers`");
		$db->exec("DROP TABLE IF EXISTS `subscribe_form_lists`");
		$db->exec("DROP TABLE IF EXISTS `subscribe_form_ratelimit`");

		$pages = $this->wire('pages');
		$templates = $this->wire('templates');
		$fieldgroups = $this->wire('fieldgroups');

		foreach (['subscribe-confirmed', 'subscribe-error'] as $name) {
			$page = $pages->get('/' . $name . '/');
			if ($page->id) $pages->delete($page);
			$t = $templates->get($name);
			if ($t) {
				$fg = $t->fieldgroup;
				$templates->delete($t);
				if ($fg) $fieldgroups->delete($fg);
			}
			// Remove template file
			$filePath = $this->wire('config')->paths->templates . $name . '.php';
			if (file_exists($filePath)) unlink($filePath);
		}

		parent::___uninstall();
	}

	public function init() {
		$db = $this->wire('database');
		try {
			$db->exec("CREATE TABLE IF NOT EXISTS `subscribe_form_lists` (`id` INT UNSIGNED NOT NULL AUTO_INCREMENT, `name` VARCHAR(255) NOT NULL, `created_at` DATETIME NOT NULL, PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
			$db->exec("CREATE TABLE IF NOT EXISTS `subscribe_form_subscribers` (`id` INT UNSIGNED NOT NULL AUTO_INCREMENT, `email` VARCHAR(255) NOT NULL, `ip` VARCHAR(45) NOT NULL DEFAULT '', `created_at` DATETIME NOT NULL, PRIMARY KEY (`id`), UNIQUE KEY `email` (`email`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
			$db->exec("CREATE TABLE IF NOT EXISTS `subscribe_form_subscriptions` (`id` INT UNSIGNED NOT NULL AUTO_INCREMENT, `subscriber_id` INT UNSIGNED NOT NULL, `list_id` INT UNSIGNED NOT NULL, `status` ENUM('pending','active','unsubscribed') NOT NULL DEFAULT 'pending', `confirm_token` VARCHAR(64) NOT NULL DEFAULT '', `unsub_token` VARCHAR(64) NOT NULL DEFAULT '', `created_at` DATETIME NOT NULL, PRIMARY KEY (`id`), UNIQUE KEY `sub_list` (`subscriber_id`,`list_id`), KEY `list_id` (`list_id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
			$db->exec("CREATE TABLE IF NOT EXISTS `subscribe_form_ratelimit` (`id` INT UNSIGNED NOT NULL AUTO_INCREMENT, `ip` VARCHAR(45) NOT NULL DEFAULT '', `created_at` DATETIME NOT NULL, PRIMARY KEY (`id`), KEY `ip` (`ip`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
			try { $db->exec("ALTER TABLE `subscribe_form_subscriptions` ADD COLUMN `unsub_token` VARCHAR(64) NOT NULL DEFAULT '' AFTER `confirm_token`"); } catch (Exception $e) {}
			$check = $db->query("SELECT COUNT(*) FROM `subscribe_form_lists`");
			if ((int) $check->fetchColumn() === 0) {
				$db->exec("INSERT INTO `subscribe_form_lists` (name, created_at) VALUES ('Default', NOW())");
			}
			// Migrate old subscribe_form table
			if ($db->query("SHOW TABLES LIKE 'subscribe_form'")->fetchAll()) {
				$defaultList = $db->query("SELECT id FROM `subscribe_form_lists` ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
				$listId = $defaultList ? (int) $defaultList['id'] : 1;
				$old = $db->query("SELECT * FROM `subscribe_form`")->fetchAll(PDO::FETCH_ASSOC);
				foreach ($old as $row) {
					try {
						$db->prepare("INSERT IGNORE INTO `subscribe_form_subscribers` (email, ip, created_at) VALUES (:e,:i,:c)")->execute([':e'=>$row['email'],':i'=>$row['ip'],':c'=>$row['created_at']]);
						$subId = $db->query("SELECT id FROM `subscribe_form_subscribers` WHERE email=".$db->quote($row['email']))->fetchColumn();
						$st = $row['status'] === 'active' ? 'active' : 'unsubscribed';
						$db->prepare("INSERT IGNORE INTO `subscribe_form_subscriptions` (subscriber_id,list_id,status,confirm_token,unsub_token,created_at) VALUES (:sid,:lid,:st,'','',:c)")->execute([':sid'=>$subId,':lid'=>$listId,':st'=>$st,':c'=>$row['created_at']]);
					} catch (Exception $e) {}
				}
				$db->exec("DROP TABLE `subscribe_form`");
			}
		} catch (Exception $e) {}
	}

	protected function createConfirmPages() {
		$templates   = $this->wire('templates');
		$fieldgroups = $this->wire('fieldgroups');
		$pages       = $this->wire('pages');
		$config      = $this->wire('config');
		$templatesPath = $config->paths->templates;

		$templateFiles = [
			'subscribe-confirmed' => <<<'PHP'
<?php
/**
 * Subscription confirmed page
 * Generated by Subscribe module — feel free to customise.
 */
?><!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title><?= $page->title ?></title>
</head>
<body>
	<h1><?= $page->title ?></h1>
	<p>Your email address has been confirmed. You are now subscribed to our newsletter.</p>
	<p><a href="<?= $pages->get('/')->url ?>">Back to homepage</a></p>
</body>
</html>
PHP,
			'subscribe-error' => <<<'PHP'
<?php
/**
 * Subscription error / unsubscribe page
 * Generated by Subscribe module — feel free to customise.
 */
$unsubscribed = $input->get('unsubscribed') == '1';
?><!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title><?= $page->title ?></title>
</head>
<body>
	<?php if ($unsubscribed): ?>
		<h1>You have been unsubscribed</h1>
		<p>You will no longer receive emails from us.</p>
	<?php else: ?>
		<h1><?= $page->title ?></h1>
		<p>This confirmation link is invalid or has already been used.</p>
	<?php endif; ?>
	<p><a href="<?= $pages->get('/')->url ?>">Back to homepage</a></p>
</body>
</html>
PHP,
		];

		foreach (['subscribe-confirmed', 'subscribe-error'] as $name) {
			// Create template file if it doesn't exist
			$filePath = $templatesPath . $name . '.php';
			if (!file_exists($filePath)) {
				file_put_contents($filePath, $templateFiles[$name]);
			}

			// Create PW template
			if (!$templates->get($name)) {
				$fg = new Fieldgroup();
				$fg->name = $name;
				$fg->add($this->wire('fields')->get('title'));
				$fg->save();
				$t = new Template();
				$t->name = $name;
				$t->fieldgroup = $fg;
				$t->noChildren = 1;
				$t->save();
			}
		}

		// Create pages
		$confirmed = $pages->get('/subscribe-confirmed/');
		if (!$confirmed->id) {
			$p = new Page();
			$p->template = $templates->get('subscribe-confirmed');
			$p->parent   = $pages->get('/');
			$p->name     = 'subscribe-confirmed';
			$p->title    = 'Subscription Confirmed';
			$p->save();
		}

		$error = $pages->get('/subscribe-error/');
		if (!$error->id) {
			$p = new Page();
			$p->template = $templates->get('subscribe-error');
			$p->parent   = $pages->get('/');
			$p->name     = 'subscribe-error';
			$p->title    = 'Subscription Error';
			$p->save();
		}
	}

	public function ___execute() {
		$input  = $this->wire('input');
		$csrf   = $this->wire('session')->CSRF;
		$action = $input->post('action');
		$notice = null;

		// Export
		if ($input->get('export')) {
			$this->handleExport((string) $input->get('export'), (int) $input->get('list'));
		}

		if ($action) {
			$csrf->validate();
			$db = $this->wire('database');

			if ($action === 'add_list') {
				$name = trim((string) $input->post('list_name'));
				if ($name) {
					$db->prepare("INSERT INTO `subscribe_form_lists` (name, created_at) VALUES (:name, :now)")
						->execute([':name' => $name, ':now' => date('Y-m-d H:i:s')]);
				}
				$this->wire('session')->redirect('./');
			}

			if ($action === 'rename_list') {
				$listId = (int) $input->post('list_id');
				$name   = trim((string) $input->post('list_name'));
				if ($listId && $name) {
					$db->prepare("UPDATE `subscribe_form_lists` SET name=:name WHERE id=:id")
						->execute([':name' => $name, ':id' => $listId]);
				}
				$this->wire('session')->redirect('./?list=' . $listId);
			}

			if ($action === 'delete_list') {
				$listId = (int) $input->post('list_id');
				if ($listId) {
					$db->prepare("DELETE FROM `subscribe_form_subscriptions` WHERE list_id=:id")->execute([':id' => $listId]);
					$db->prepare("DELETE FROM `subscribe_form_lists` WHERE id=:id")->execute([':id' => $listId]);
				}
				$this->wire('session')->redirect('./');
			}

			if ($action === 'add') {
				$email  = trim((string) $input->post('email'));
				$listId = (int) $input->post('list_id');
				if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
					$notice = ['type' => 'error', 'text' => $this->_('Invalid email address.')];
				} else {
					$db->prepare("INSERT IGNORE INTO `subscribe_form_subscribers` (email, ip, created_at) VALUES (:e, '', :now)")
						->execute([':e' => $email, ':now' => date('Y-m-d H:i:s')]);
					$subId = (int) $db->query("SELECT id FROM `subscribe_form_subscribers` WHERE email=" . $db->quote($email))->fetchColumn();
					$check = $db->prepare("SELECT id FROM `subscribe_form_subscriptions` WHERE subscriber_id=:sid AND list_id=:lid LIMIT 1");
					$check->execute([':sid' => $subId, ':lid' => $listId]);
					if ($check->fetch()) {
						$notice = ['type' => 'error', 'text' => $this->_('Already in this list.')];
					} else {
						$db->prepare("INSERT INTO `subscribe_form_subscriptions` (subscriber_id, list_id, status, confirm_token, unsub_token, created_at) VALUES (:sid, :lid, 'active', '', '', :now)")
							->execute([':sid' => $subId, ':lid' => $listId, ':now' => date('Y-m-d H:i:s')]);
						$notice = ['type' => 'success', 'text' => $this->_('Subscriber added.')];
					}
				}
			}

			if ($action === 'import') {
				$notice = $this->handleImport((int) $input->post('list_id'));
			}

			if ($action === 'toggle') {
				$id = (int) $input->post('id');
				$db->prepare("UPDATE `subscribe_form_subscriptions` SET status=IF(status='active','unsubscribed','active') WHERE id=:id")->execute([':id' => $id]);
				$this->wire('session')->redirect($this->currentUrl());
			}

			if ($action === 'resend') {
				$id     = (int) $input->post('id');
				$module = $this->wire('modules')->get('Subscribe');
				if ($module) $module->resendConfirmation($id);
				$notice = ['type' => 'success', 'text' => $this->_('Confirmation email resent.')];
			}

			if ($action === 'delete') {
				$id = (int) $input->post('id');
				$db->prepare("DELETE FROM `subscribe_form_subscriptions` WHERE id=:id")->execute([':id' => $id]);
				$this->wire('session')->redirect($this->currentUrl());
			}
		}

		return $this->renderPage($notice);
	}

	protected function handleImport($listId) {
		if (empty($_FILES['csv_file']['tmp_name'])) {
			return ['type' => 'error', 'text' => $this->_('No file uploaded.')];
		}

		$file   = $_FILES['csv_file']['tmp_name'];
		$handle = fopen($file, 'r');
		if (!$handle) return ['type' => 'error', 'text' => $this->_('Could not read file.')];

		$db      = $this->wire('database');
		$added   = 0;
		$skipped = 0;
		$now     = date('Y-m-d H:i:s');
		$header  = null;
		$emailCol = 0;

		while (($row = fgetcsv($handle)) !== false) {
			if (!$header) {
				$header = array_map('strtolower', $row);
				$emailCol = array_search('email', $header);
				if ($emailCol === false) $emailCol = 0;
				continue;
			}

			$email = isset($row[$emailCol]) ? trim($row[$emailCol]) : '';
			if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
				$skipped++;
				continue;
			}

			try {
				$db->prepare("INSERT IGNORE INTO `subscribe_form_subscribers` (email, ip, created_at) VALUES (:e, '', :now)")
					->execute([':e' => $email, ':now' => $now]);
				$subId = (int) $db->query("SELECT id FROM `subscribe_form_subscribers` WHERE email=" . $db->quote($email))->fetchColumn();
				$check = $db->prepare("SELECT id FROM `subscribe_form_subscriptions` WHERE subscriber_id=:sid AND list_id=:lid LIMIT 1");
				$check->execute([':sid' => $subId, ':lid' => $listId]);
				if ($check->fetch()) {
					$skipped++;
				} else {
					$db->prepare("INSERT INTO `subscribe_form_subscriptions` (subscriber_id, list_id, status, confirm_token, unsub_token, created_at) VALUES (:sid, :lid, 'active', '', '', :now)")
						->execute([':sid' => $subId, ':lid' => $listId, ':now' => $now]);
					$added++;
				}
			} catch (Exception $e) {
				$skipped++;
			}
		}

		fclose($handle);
		return ['type' => 'success', 'text' => sprintf($this->_('Import complete: %d added, %d skipped.'), $added, $skipped)];
	}

	protected function handleExport($format, $listId = 0) {
		$db = $this->wire('database');
		if ($listId) {
			$stmt = $db->prepare("SELECT s.email, s.ip, sc.status, sc.created_at FROM `subscribe_form_subscriptions` sc JOIN `subscribe_form_subscribers` s ON s.id=sc.subscriber_id WHERE sc.list_id=:lid ORDER BY sc.created_at DESC");
			$stmt->bindValue(':lid', $listId, PDO::PARAM_INT);
		} else {
			$stmt = $db->prepare("SELECT s.email, s.ip, sc.status, sc.created_at, l.name as list FROM `subscribe_form_subscriptions` sc JOIN `subscribe_form_subscribers` s ON s.id=sc.subscriber_id JOIN `subscribe_form_lists` l ON l.id=sc.list_id ORDER BY sc.created_at DESC");
		}
		$stmt->execute();
		$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

		if ($format === 'json') {
			header('Content-Type: application/json');
			header('Content-Disposition: attachment; filename="subscribers.json"');
			echo json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
			exit;
		}
		if ($format === 'csv') {
			header('Content-Type: text/csv; charset=utf-8');
			header('Content-Disposition: attachment; filename="subscribers.csv"');
			$out = fopen('php://output', 'w');
			if ($rows) fputcsv($out, array_keys($rows[0]));
			foreach ($rows as $row) fputcsv($out, $row);
			fclose($out);
			exit;
		}
	}

	protected function currentUrl() {
		$input  = $this->wire('input');
		$url    = './';
		$params = [];
		if ($input->get('list'))  $params[] = 'list=' . (int) $input->get('list');
		if ($input->get('q'))     $params[] = 'q=' . urlencode((string) $input->get('q'));
		if ($input->get('status')) $params[] = 'status=' . urlencode((string) $input->get('status'));
		if ($input->get('p'))     $params[] = 'p=' . (int) $input->get('p');
		return $url . ($params ? '?' . implode('&', $params) : '');
	}

	protected function renderPage($notice = null) {
		$db     = $this->wire('database');
		$csrf   = $this->wire('session')->CSRF;
		$input  = $this->wire('input');

		$lists = $db->query("SELECT l.*, COUNT(sc.id) as total FROM `subscribe_form_lists` l LEFT JOIN `subscribe_form_subscriptions` sc ON sc.list_id=l.id GROUP BY l.id ORDER BY l.id ASC")->fetchAll(PDO::FETCH_ASSOC);

		$activeList = (int) $input->get('list');
		if (!$activeList && $lists) $activeList = (int) $lists[0]['id'];

		$search     = trim((string) $input->get('q'));
		$filterStatus = trim((string) $input->get('status'));
		$page       = max(1, (int) $input->get('p'));
		$offset     = ($page - 1) * self::PER_PAGE;

		$settingsUrl = $this->wire('config')->urls->admin . 'module/edit?name=Subscribe';

		$out  = '<style>';
		$out .= '.sf-layout{display:flex;gap:24px;align-items:flex-start}';
		$out .= '.sf-sidebar{width:210px;flex-shrink:0}';
		$out .= '.sf-sidebar-list{list-style:none;margin:0;padding:0}';
		$out .= '.sf-sidebar-list li a{display:flex;justify-content:space-between;padding:7px 10px;border-radius:3px;text-decoration:none;color:inherit;font-size:14px}';
		$out .= '.sf-sidebar-list li a:hover{background:#f5f5f5}';
		$out .= '.sf-sidebar-list li a.sf-active{background:#e8f0fe;font-weight:600}';
		$out .= '.sf-count{color:#888;font-size:12px}';
		$out .= '.sf-main{flex:1;min-width:0}';
		$out .= '.sf-table{width:100%;border-collapse:collapse;margin-top:1em}';
		$out .= '.sf-table th{text-align:left;padding:8px 12px;border-bottom:2px solid #e0e0e0;font-weight:600;white-space:nowrap}';
		$out .= '.sf-table td{padding:8px 12px;border-bottom:1px solid #f0f0f0;vertical-align:middle}';
		$out .= '.sf-table tr:hover td{background:#fafafa}';
		$out .= '.sf-status{display:inline-block;padding:2px 8px;border-radius:3px;font-size:12px;font-weight:600}';
		$out .= '.sf-status-active{background:#e6f4ea;color:#1e7e34}';
		$out .= '.sf-status-unsubscribed{background:#fce8e8;color:#c0392b}';
		$out .= '.sf-status-pending{background:#fff3cd;color:#856404}';
		$out .= '.sf-actions form{display:inline}';
		$out .= '.sf-add{display:flex;gap:8px;align-items:center;margin-bottom:1em;flex-wrap:wrap}';
		$out .= '.sf-filters{display:flex;gap:8px;align-items:center;margin-bottom:1em;flex-wrap:wrap}';
		$out .= '.sf-toolbar{display:flex;align-items:center;justify-content:space-between;margin-bottom:.5em;flex-wrap:wrap;gap:8px}';
		$out .= '.sf-export{display:flex;gap:6px}';
		$out .= '.sf-notice{padding:8px 14px;border-radius:3px;margin-bottom:1em;font-size:14px}';
		$out .= '.sf-notice-error{background:#fce8e8;color:#c0392b}';
		$out .= '.sf-notice-success{background:#e6f4ea;color:#1e7e34}';
		$out .= '.sf-pagination{display:flex;gap:4px;margin-top:1em;align-items:center}';
		$out .= '.sf-pagination a,.sf-pagination span{padding:4px 10px;border:1px solid #ddd;border-radius:3px;text-decoration:none;font-size:13px}';
		$out .= '.sf-pagination .sf-active{background:#1a73e8;color:#fff;border-color:#1a73e8}';
		$out .= '.sf-import{margin-top:10px;padding:10px;border:1px dashed #ccc;border-radius:3px;font-size:13px}';
		$out .= '.sf-import label{display:block;margin-bottom:6px;font-weight:600}';
		$out .= '</style>';

		$out .= '<h2>' . $this->_('Subscribers');
		$out .= ' <a href="' . $settingsUrl . '" class="ui-button ui-state-default ui-corner-all" style="font-size:0.6em;vertical-align:middle;text-decoration:none;margin-left:8px"><i class="fa fa-cog"></i> ' . $this->_('Settings') . '</a>';
		$out .= '</h2>';

		if ($notice) {
			$cls = $notice['type'] === 'error' ? 'sf-notice-error' : 'sf-notice-success';
			$out .= '<div class="sf-notice ' . $cls . '">' . htmlspecialchars($notice['text']) . '</div>';
		}

		$out .= '<div class="sf-layout">';

		// Sidebar
		$out .= '<div class="sf-sidebar">';
		$out .= '<strong style="font-size:12px;text-transform:uppercase;color:#888;letter-spacing:.05em">' . $this->_('Lists') . '</strong>';
		$out .= '<ul class="sf-sidebar-list" style="margin-top:8px">';
		foreach ($lists as $list) {
			$active = $activeList == $list['id'] ? ' sf-active' : '';
			$out .= '<li><a href="./?list=' . $list['id'] . '" class="' . trim($active) . '">';
			$out .= htmlspecialchars($list['name']);
			$out .= ' <span class="sf-count">' . (int) $list['total'] . '</span>';
			$out .= '</a></li>';
		}
		$out .= '</ul>';

		// Add list
		$out .= '<form method="post" style="margin-top:12px;display:flex;flex-direction:column;gap:6px">';
		$out .= '<input type="hidden" name="' . $csrf->getTokenName() . '" value="' . $csrf->getTokenValue() . '">';
		$out .= '<input type="hidden" name="action" value="add_list">';
		$out .= '<input type="text" name="list_name" class="uk-input" placeholder="' . $this->_('New list name') . '" required style="font-size:13px">';
		$out .= '<button type="submit" class="ui-button ui-state-default ui-corner-all" style="width:100%">' . $this->_('Add List') . '</button>';
		$out .= '</form>';

		// Rename list
		if ($activeList) {
			$currentListName = '';
			foreach ($lists as $l) { if ($l['id'] == $activeList) { $currentListName = $l['name']; break; } }
			$out .= '<form method="post" style="margin-top:6px;display:flex;flex-direction:column;gap:6px">';
			$out .= '<input type="hidden" name="' . $csrf->getTokenName() . '" value="' . $csrf->getTokenValue() . '">';
			$out .= '<input type="hidden" name="action" value="rename_list">';
			$out .= '<input type="hidden" name="list_id" value="' . $activeList . '">';
			$out .= '<input type="text" name="list_name" class="uk-input" value="' . htmlspecialchars($currentListName) . '" required style="font-size:13px">';
			$out .= '<button type="submit" class="ui-button ui-state-default ui-corner-all" style="width:100%;font-size:12px">' . $this->_('Rename List') . '</button>';
			$out .= '</form>';

			if (count($lists) > 1) {
				$out .= '<form method="post" style="margin-top:6px" onsubmit="return confirm(\'' . $this->_('Delete this list and all its subscriptions?') . '\')">';
				$out .= '<input type="hidden" name="' . $csrf->getTokenName() . '" value="' . $csrf->getTokenValue() . '">';
				$out .= '<input type="hidden" name="action" value="delete_list">';
				$out .= '<input type="hidden" name="list_id" value="' . $activeList . '">';
				$out .= '<button type="submit" class="ui-button ui-state-default ui-corner-all ui-state-error" style="width:100%;font-size:12px">' . $this->_('Delete List') . '</button>';
				$out .= '</form>';
			}

			// Import CSV
			$out .= '<form method="post" enctype="multipart/form-data" class="sf-import">';
			$out .= '<input type="hidden" name="' . $csrf->getTokenName() . '" value="' . $csrf->getTokenValue() . '">';
			$out .= '<input type="hidden" name="action" value="import">';
			$out .= '<input type="hidden" name="list_id" value="' . $activeList . '">';
			$out .= '<label>' . $this->_('Import CSV') . '</label>';
			$out .= '<input type="file" name="csv_file" accept=".csv" style="font-size:12px;margin-bottom:6px;display:block">';
			$out .= '<small style="color:#888;display:block;margin-bottom:6px">' . $this->_('First row must contain column headers. Email column should be named "email".') . '</small>';
			$out .= '<button type="submit" class="ui-button ui-state-default ui-corner-all" style="width:100%;font-size:12px">' . $this->_('Import') . '</button>';
			$out .= '</form>';
		}

		$out .= '</div>'; // sidebar

		// Main
		$out .= '<div class="sf-main">';

		if ($activeList) {
			// Add subscriber
			$out .= '<form method="post" class="sf-add">';
			$out .= '<input type="hidden" name="' . $csrf->getTokenName() . '" value="' . $csrf->getTokenValue() . '">';
			$out .= '<input type="hidden" name="action" value="add">';
			$out .= '<input type="hidden" name="list_id" value="' . $activeList . '">';
			$out .= '<input type="email" name="email" class="uk-input" style="width:260px" placeholder="' . $this->_('email@example.com') . '" required>';
			$out .= '<button type="submit" class="ui-button ui-state-default ui-corner-all">' . $this->_('Add Subscriber') . '</button>';
			$out .= '</form>';

			// Search + filter
			$baseUrl = './?list=' . $activeList;
			$out .= '<form method="get" class="sf-filters">';
			$out .= '<input type="hidden" name="list" value="' . $activeList . '">';
			$out .= '<input type="text" name="q" class="uk-input" style="width:220px" placeholder="' . $this->_('Search email...') . '" value="' . htmlspecialchars($search) . '">';
			$out .= '<select name="status" class="uk-input" style="width:150px">';
			foreach (['' => $this->_('All statuses'), 'active' => $this->_('Active'), 'pending' => $this->_('Pending'), 'unsubscribed' => $this->_('Unsubscribed')] as $val => $label) {
				$sel = $filterStatus === $val ? ' selected' : '';
				$out .= '<option value="' . $val . '"' . $sel . '>' . $label . '</option>';
			}
			$out .= '</select>';
			$out .= '<button type="submit" class="ui-button ui-state-default ui-corner-all">' . $this->_('Filter') . '</button>';
			if ($search || $filterStatus) {
				$out .= '<a href="' . $baseUrl . '" class="ui-button ui-state-default ui-corner-all">' . $this->_('Clear') . '</a>';
			}
			$out .= '</form>';

			// Query subscribers
			$where  = 'sc.list_id = :lid';
			$bind   = [':lid' => $activeList];
			if ($search) {
				$where .= ' AND s.email LIKE :q';
				$bind[':q'] = '%' . $search . '%';
			}
			if ($filterStatus) {
				$where .= ' AND sc.status = :status';
				$bind[':status'] = $filterStatus;
			}

			$countStmt = $db->prepare("SELECT COUNT(*) FROM `subscribe_form_subscriptions` sc JOIN `subscribe_form_subscribers` s ON s.id=sc.subscriber_id WHERE $where");
			$countStmt->execute($bind);
			$total = (int) $countStmt->fetchColumn();
			$pages = ceil($total / self::PER_PAGE);

			$stmt = $db->prepare("SELECT sc.id, s.email, s.ip, sc.status, sc.created_at FROM `subscribe_form_subscriptions` sc JOIN `subscribe_form_subscribers` s ON s.id=sc.subscriber_id WHERE $where ORDER BY sc.created_at DESC LIMIT " . self::PER_PAGE . " OFFSET $offset");
			$stmt->execute($bind);
			$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

			// Toolbar
			$out .= '<div class="sf-toolbar">';
			$out .= '<span style="color:#888;font-size:14px">' . $total . ' ' . $this->_('subscribers') . ($search ? ' &mdash; ' . $this->_('filtered') : '') . '</span>';
			$out .= '<div class="sf-export">';
			$out .= '<a href="' . $baseUrl . '&export=json" class="ui-button ui-state-default ui-corner-all"><i class="fa fa-download"></i> JSON</a>';
			$out .= '<a href="' . $baseUrl . '&export=csv" class="ui-button ui-state-default ui-corner-all"><i class="fa fa-download"></i> CSV</a>';
			$out .= '</div>';
			$out .= '</div>';

			if (!$rows && !$search && !$filterStatus) {
				$out .= '<p>' . $this->_('No subscribers yet.') . '</p>';
			} elseif (!$rows) {
				$out .= '<p>' . $this->_('No subscribers found.') . '</p>';
			} else {
				$out .= '<table class="sf-table AdminDataTable">';
				$out .= '<thead><tr><th>#</th><th>' . $this->_('Email') . '</th><th>' . $this->_('IP') . '</th><th>' . $this->_('Status') . '</th><th>' . $this->_('Date') . '</th><th>' . $this->_('Actions') . '</th></tr></thead><tbody>';

				foreach ($rows as $row) {
					$s = $row['status'];
					$toggleLabel = $s === 'active' ? $this->_('Unsubscribe') : $this->_('Reactivate');

					$out .= '<tr>';
					$out .= '<td>' . (int) $row['id'] . '</td>';
					$out .= '<td>' . htmlspecialchars($row['email']) . '</td>';
					$out .= '<td>' . htmlspecialchars($row['ip']) . '</td>';
					$out .= '<td><span class="sf-status sf-status-' . $s . '">' . ucfirst($s) . '</span></td>';
					$out .= '<td>' . htmlspecialchars($row['created_at']) . '</td>';
					$out .= '<td class="sf-actions">';

					if ($s === 'pending') {
						$out .= '<form method="post">';
						$out .= '<input type="hidden" name="' . $csrf->getTokenName() . '" value="' . $csrf->getTokenValue() . '">';
						$out .= '<input type="hidden" name="action" value="resend">';
						$out .= '<input type="hidden" name="id" value="' . (int) $row['id'] . '">';
						$out .= '<button type="submit" class="ui-button ui-state-default ui-corner-all" style="margin-right:4px"><i class="fa fa-envelope-o"></i> ' . $this->_('Resend') . '</button>';
						$out .= '</form>';
					} else {
						$out .= '<form method="post">';
						$out .= '<input type="hidden" name="' . $csrf->getTokenName() . '" value="' . $csrf->getTokenValue() . '">';
						$out .= '<input type="hidden" name="action" value="toggle">';
						$out .= '<input type="hidden" name="id" value="' . (int) $row['id'] . '">';
						$out .= '<button type="submit" class="ui-button ui-state-default ui-corner-all" style="margin-right:4px">' . $toggleLabel . '</button>';
						$out .= '</form>';
					}

					$out .= '<form method="post" onsubmit="return confirm(\'' . $this->_('Remove from this list?') . '\')">';
					$out .= '<input type="hidden" name="' . $csrf->getTokenName() . '" value="' . $csrf->getTokenValue() . '">';
					$out .= '<input type="hidden" name="action" value="delete">';
					$out .= '<input type="hidden" name="id" value="' . (int) $row['id'] . '">';
					$out .= '<button type="submit" class="ui-button ui-state-default ui-corner-all ui-state-error">' . $this->_('Remove') . '</button>';
					$out .= '</form>';

					$out .= '</td></tr>';
				}
				$out .= '</tbody></table>';

				// Pagination
				if ($pages > 1) {
					$qParams = ($search ? '&q=' . urlencode($search) : '') . ($filterStatus ? '&status=' . urlencode($filterStatus) : '');
					$out .= '<div class="sf-pagination">';
					for ($i = 1; $i <= $pages; $i++) {
						$cls = $i === $page ? ' class="sf-active"' : '';
						$out .= '<a href="' . $baseUrl . $qParams . '&p=' . $i . '"' . $cls . '>' . $i . '</a>';
					}
					$out .= '</div>';
				}
			}
		}

		$out .= '</div></div>'; // main + layout

		return $out;
	}
}
