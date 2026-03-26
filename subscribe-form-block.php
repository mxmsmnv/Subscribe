<?php
/**
 * SubscribeForm — newsletter block (Alpine.js + honeypot)
 *
 * Usage:
 *   <?php include __DIR__ . '/../../modules/SubscribeForm/subscribe-form-block.php'; ?>
 */
$endpoint = isset($subscribeEndpoint) ? $subscribeEndpoint : '/?subscribe=1';
?>
<div
	class="mt-4 space-y-4"
	x-data="{
		email: '',
		hp: '',
		message: '',
		success: false,
		loading: false,
		async submit() {
			this.message = '';
			if (!this.email) {
				this.message = 'Please enter your email address.';
				this.success = false;
				return;
			}
			this.loading = true;
			const body = new FormData();
			body.append('email', this.email);
			body.append('website', this.hp);
			try {
				const r = await fetch(<?= htmlspecialchars(json_encode($endpoint), ENT_QUOTES, 'UTF-8') ?>, { method: 'POST', body });
				const data = await r.json();
				this.message = data.message;
				this.success = data.success;
				if (data.success) this.email = '';
			} catch {
				this.message = 'Something went wrong. Please try again.';
				this.success = false;
			} finally {
				this.loading = false;
			}
		}
	}"
>
	<?php // Honeypot: hidden from humans, bots fill it ?>
	<input
		type="text"
		x-model="hp"
		name="website"
		autocomplete="off"
		tabindex="-1"
		aria-hidden="true"
		style="position:absolute;left:-9999px;width:1px;height:1px;opacity:0;"
	>
	<input
		type="email"
		x-model="email"
		@keydown.enter="submit"
		placeholder="<?= htmlspecialchars($footerData['newsletter']['placeholder']) ?>"
		class="newsletter-input w-full px-4 py-3 bg-zinc-900 rounded text-white border border-zinc-800 focus:outline-none focus:bg-base-200 focus:text-black focus:placeholder-base-700"
	>
	<button
		@click="submit"
		:disabled="loading"
		class="bg-main-300 text-black px-6 py-2.5 rounded flex items-center font-medium hover:bg-base-200 transition-colors disabled:opacity-60"
	>
		<span x-text="loading ? '...' : '<?= htmlspecialchars($footerData['newsletter']['button']) ?>'"></span>
		<svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 ml-2" viewBox="0 0 20 20" fill="currentColor">
			<path fill-rule="evenodd" d="M10.293 5.293a1 1 0 011.414 0l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414-1.414L12.586 11H5a1 1 0 110-2h7.586l-2.293-2.293a1 1 0 010-1.414z" clip-rule="evenodd" />
		</svg>
	</button>
	<p
		x-show="message"
		x-text="message"
		:style="success ? 'color:#4ade80' : 'color:#f87171'"
		style="margin-top:8px;font-size:14px;"
		aria-live="polite"
	></p>
</div>