<?php
/**
 * Subscribe module — integration example
 *
 * Shows how to subscribe a user from an order form, contact form,
 * or any other ProcessWire template.
 *
 * The subscribe() method handles everything:
 *   - Validates the email
 *   - Checks rate limit
 *   - Creates subscriber if new
 *   - Sends double opt-in confirmation email
 *   - Returns ['success' => bool, 'message' => string]
 */

// -------------------------------------------------------------------------
// Example 1: Order page — subscribe if checkbox is checked
// -------------------------------------------------------------------------

// In your order template or form handler:
if ($input->post('newsletter') && $input->post('email')) {
	$result = $modules->get('Subscribe')->subscribe(
		$input->post('email')
		// optional second arg: list ID, e.g. 2 for "Customers" list
		// $modules->get('Subscribe')->subscribe($input->post('email'), 2)
	);
	// $result['success'] — bool
	// $result['message'] — localised string
}


// -------------------------------------------------------------------------
// Example 2: Contact form — subscribe with specific list
// -------------------------------------------------------------------------

if ($input->post('subscribe') && $input->post('email')) {
	$subscribe = $modules->get('Subscribe');

	// Find list ID by name if you don't want to hardcode it
	$db = $database;
	$stmt = $db->prepare("SELECT id FROM subscribe_form_lists WHERE name = :name LIMIT 1");
	$stmt->bindValue(':name', 'Newsletter');
	$stmt->execute();
	$list = $stmt->fetch(PDO::FETCH_ASSOC);
	$listId = $list ? (int) $list['id'] : null;

	$result = $subscribe->subscribe($input->post('email'), $listId);
}


// -------------------------------------------------------------------------
// Example 3: Hook — react when someone subscribes (in ready.php or a module)
// -------------------------------------------------------------------------

// In /site/ready.php:
$wire->addHook('Subscribe::subscribed', function(HookEvent $event) {
	$email          = $event->arguments(0);
	$listId         = $event->arguments(1);
	$subscriptionId = $event->arguments(2);

	// Example: log to a PW page, send Telegram notification, etc.
	// $modules->get('TeleWire')->send("New subscriber: $email to list #$listId");
});


// -------------------------------------------------------------------------
// Example 4: Get all active subscribers for a list (e.g. to send a mailing)
// -------------------------------------------------------------------------

$subscribers = $modules->get('Subscribe')->getSubscribers(1); // list ID 1
// Returns array of ['id', 'email', 'ip', 'status', 'created_at']

foreach ($subscribers as $sub) {
	// $sub['email']
}

// Get all statuses:
$all = $modules->get('Subscribe')->getSubscribers(1, 'all');

// Get only pending:
$pending = $modules->get('Subscribe')->getSubscribers(1, 'pending');
