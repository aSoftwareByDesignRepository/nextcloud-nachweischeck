<?php
/** Synced from _shared/agent-auth — do not edit app-side. */

declare(strict_types=1);

/**
 * "AI access (MCP)" settings section — pack partial.
 *
 * Expects $agentAccess from AgentSettingsPayload::payload(). Renders
 * nothing when the user may neither mint nor see tokens (nav-gate, not a
 * disabled state). All IDs/classes use the app prefix (nw).
 *
 * @var \OCP\IL10N $l
 * @var array $agentAccess
 */

$agentAccess = is_array($agentAccess ?? null) ? $agentAccess : [];
if (($agentAccess['visible'] ?? false) !== true) {
	return;
}

$nw_state = [
	'appId' => (string)($agentAccess['appId'] ?? ''),
	'agentHeader' => (string)($agentAccess['agentHeader'] ?? ''),
	'ncUrl' => (string)($agentAccess['ncUrl'] ?? ''),
	'isAdmin' => (bool)($agentAccess['isAdmin'] ?? false),
	'canMint' => (bool)($agentAccess['canMint'] ?? false),
	'selfMintEnabled' => (bool)($agentAccess['selfMintEnabled'] ?? false),
	'tokens' => $agentAccess['tokens'] ?? [],
	'presets' => $agentAccess['presets'] ?? ['read' => [], 'act' => []],
	'allScopes' => $agentAccess['allScopes'] ?? [],
	'scopeLabels' => $agentAccess['scopeLabels'] ?? [],
];
$nw_tokens = is_array($nw_state['tokens']) ? $nw_state['tokens'] : [];
?>
<section class="nw-section nw-agent" id="nw-agent-access" aria-labelledby="nw-agent-h">
	<h2 class="nw-section-title" id="nw-agent-h"><?php p($l->t('AI access (MCP)')); ?></h2>
	<p class="nw-section-intro">
		<?php p($l->t('Connect an AI assistant to this app with a scoped token. The token works only for this app — never for your files, mail, or other apps.')); ?>
	</p>
	<div id="nw-agent-live" class="nw-visually-hidden" aria-live="polite"></div>

	<?php if ($nw_tokens === []): ?>
	<div class="nw-agent-empty" id="nw-agent-empty">
		<p class="nw-agent-empty__text"><?php p($l->t('No AI access yet.')); ?></p>
		<?php if ($nw_state['canMint']): ?>
		<button type="button" class="nw-btn nw-btn--primary" id="nw-agent-connect"><?php p($l->t('Connect your AI')); ?></button>
		<?php endif; ?>
	</div>
	<?php else: ?>
	<div class="nw-table-wrap nw-agent-tablewrap" tabindex="0" role="region" aria-labelledby="nw-agent-h">
		<table class="nw-table nw-table--cards nw-agent-table" id="nw-agent-table">
			<thead>
				<tr>
					<th scope="col"><?php p($l->t('Label')); ?></th>
					<th scope="col"><?php p($l->t('Scopes')); ?></th>
					<th scope="col"><?php p($l->t('Created')); ?></th>
					<th scope="col"><?php p($l->t('Expires')); ?></th>
					<th scope="col"><?php p($l->t('Last used')); ?></th>
					<th scope="col"><span class="nw-visually-hidden"><?php p($l->t('Actions')); ?></span></th>
				</tr>
			</thead>
			<tbody id="nw-agent-tbody">
				<?php foreach ($nw_tokens as $nw_tok): ?>
				<tr data-token-id="<?php p((string)($nw_tok['id'] ?? '')); ?>" data-token-label="<?php p((string)($nw_tok['label'] ?? '')); ?>"<?php if (($nw_tok['state'] ?? '') !== 'active'): ?> class="nw-agent-row--inactive"<?php endif; ?>>
					<td data-cell="<?php p($l->t('Label')); ?>" class="nw-agent-label-cell"><span class="nw-agent-labelcell"><span class="nw-agent-label" title="<?php p((string)($nw_tok['label'] ?? '')); ?>"><?php p((string)($nw_tok['label'] ?? '')); ?></span>
						<?php if (($nw_tok['state'] ?? '') === 'revoked'): ?>
							<span class="nw-badge nw-badge--neutral"><?php p($l->t('Revoked')); ?></span>
						<?php elseif (($nw_tok['state'] ?? '') === 'expired'): ?>
							<span class="nw-badge nw-badge--neutral"><?php p($l->t('Expired')); ?></span>
						<?php else: ?>
							<span class="nw-badge nw-badge--info"><?php p($l->t('Active')); ?></span>
						<?php endif; ?>
						<?php if ($nw_state['isAdmin'] && ($nw_tok['userId'] ?? '') !== ''): ?>
							<span class="nw-agent-owner"><?php p((string)$nw_tok['userId']); ?></span>
						<?php endif; ?></span></td>
					<td data-cell="<?php p($l->t('Scopes')); ?>" class="nw-agent-scopes-cell">
						<span class="nw-agent-scopes">
						<?php foreach (($nw_tok['scopes'] ?? []) as $nw_scope): ?>
							<span class="nw-badge nw-badge--neutral nw-agent-scope" title="<?php p((string)$nw_scope); ?>"><?php p((string)($nw_state['scopeLabels'][$nw_scope] ?? $nw_scope)); ?></span>
						<?php endforeach; ?>
						</span>
					</td>
					<td data-cell="<?php p($l->t('Created')); ?>" data-ts="<?php p((string)($nw_tok['createdAt'] ?? '')); ?>"></td>
					<td data-cell="<?php p($l->t('Expires')); ?>" data-ts="<?php p((string)($nw_tok['expiresAt'] ?? '')); ?>"></td>
					<td data-cell="<?php p($l->t('Last used')); ?>"<?php if (($nw_tok['lastUsedAt'] ?? null) === null): ?> class="nw-agent-never"<?php endif; ?> data-ts="<?php p((string)($nw_tok['lastUsedAt'] ?? '')); ?>"><?php if (($nw_tok['lastUsedAt'] ?? null) === null): ?><?php p($l->t('never')); ?><?php endif; ?></td>
					<td class="nw-row-actions">
						<?php if (($nw_tok['state'] ?? '') === 'active'): ?>
						<button type="button" class="nw-btn nw-btn--ghost nw-agent-rotate" data-token-id="<?php p((string)$nw_tok['id']); ?>"><?php p($l->t('Rotate')); ?></button>
						<button type="button" class="nw-btn nw-btn--danger nw-agent-revoke" data-token-id="<?php p((string)$nw_tok['id']); ?>" data-token-label="<?php p((string)($nw_tok['label'] ?? '')); ?>"><?php p($l->t('Revoke')); ?></button>
						<?php endif; ?>
					</td>
				</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php if ($nw_state['canMint']): ?>
		<div class="nw-agent-actions">
			<button type="button" class="nw-btn nw-btn--primary" id="nw-agent-connect"><?php p($l->t('Connect your AI')); ?></button>
		</div>
		<?php endif; ?>
	</div>
	<?php endif; ?>

	<?php if ($nw_state['isAdmin']): ?>
	<div class="nw-form-row nw-agent-selfmint">
		<input type="hidden" name="agent_self_mint" value="0">
		<label class="nw-checkbox-label" for="nw-agent-selfmint-cb">
			<input type="checkbox" id="nw-agent-selfmint-cb" <?php if ($nw_state['selfMintEnabled']): ?>checked<?php endif; ?>>
			<?php p($l->t('Let app users create their own AI tokens')); ?>
		</label>
		<p class="nw-form-row__hint" id="nw-agent-selfmint-hint"><?php p($l->t('When off, only app administrators can connect AI assistants.')); ?></p>
	</div>
	<?php endif; ?>

	<dialog id="nw-agent-mint-dialog" class="nw-dialog nw-agent-mint-dialog" aria-labelledby="nw-agent-mint-h">
		<div class="nw-dialog__body">
			<h3 id="nw-agent-mint-h"><?php p($l->t('Connect your AI')); ?></h3>
			<form id="nw-agent-mint-form" class="nw-form-stack" novalidate>
				<div class="nw-form-row">
					<label for="nw-agent-label"><?php p($l->t('Label')); ?></label>
					<input type="text" id="nw-agent-label" name="label" maxlength="190" required autocomplete="off" placeholder="<?php p($l->t('Claude on my laptop')); ?>">
					<p class="nw-field-error" id="nw-agent-label-error" hidden></p>
				</div>
				<fieldset class="nw-form-row nw-fieldset">
					<legend><?php p($l->t('What may the AI do?')); ?></legend>
					<div class="nw-choice-cards">
						<label class="nw-choice-card">
							<input type="radio" name="preset" value="read" checked>
							<span class="nw-choice-card__copy">
								<span class="nw-choice-card__title"><?php p($l->t('Read only')); ?></span>
								<span class="nw-choice-card__hint"><?php p($l->t('see your data, never change it')); ?></span>
							</span>
						</label>
						<label class="nw-choice-card">
							<input type="radio" name="preset" value="act">
							<span class="nw-choice-card__copy">
								<span class="nw-choice-card__title"><?php p($l->t('Read + act on my behalf')); ?></span>
								<span class="nw-choice-card__hint"><?php p($l->t('read and make changes — payments and legal stays with you')); ?></span>
							</span>
						</label>
						<label class="nw-choice-card">
							<input type="radio" name="preset" value="custom">
							<span class="nw-choice-card__copy">
								<span class="nw-choice-card__title"><?php p($l->t('Choose exactly')); ?></span>
								<span class="nw-choice-card__hint"><?php p($l->t('pick each permission yourself')); ?></span>
							</span>
						</label>
					</div>
				</fieldset>
				<fieldset class="nw-form-row nw-fieldset" id="nw-agent-custom-wrap" hidden>
					<legend><?php p($l->t('Permissions')); ?></legend>
					<div class="nw-agent-chips" id="nw-agent-chips"></div>
					<p class="nw-field-error" id="nw-agent-scopes-error" hidden></p>
				</fieldset>
				<p class="nw-form-row__hint"><?php p($l->t('Valid for 1 year. The token only reaches this app.')); ?></p>
			</form>
		</div>
		<footer class="nw-dialog__footer">
			<div class="nw-actions">
				<button type="button" class="nw-btn nw-btn--secondary" id="nw-agent-mint-cancel"><?php p($l->t('Cancel')); ?></button>
				<button type="submit" form="nw-agent-mint-form" class="nw-btn nw-btn--primary" id="nw-agent-mint-submit"><?php p($l->t('Create token')); ?></button>
			</div>
		</footer>
	</dialog>

	<dialog id="nw-agent-secret-dialog" class="nw-dialog nw-agent-secret-dialog" aria-labelledby="nw-agent-secret-h">
		<div class="nw-dialog__body">
			<h3 id="nw-agent-secret-h"><?php p($l->t('Your AI is connected')); ?></h3>
			<p class="nw-form-row__hint"><?php p($l->t('Copy this config into your AI client now — the token is shown only once.')); ?></p>
			<div class="nw-form-row">
				<label for="nw-agent-secret"><?php p($l->t('Token')); ?></label>
				<div class="nw-input-group">
					<input type="password" id="nw-agent-secret" readonly autocomplete="off" spellcheck="false">
					<button type="button" class="nw-btn nw-btn--ghost" id="nw-agent-secret-reveal" aria-pressed="false"><?php p($l->t('Show')); ?></button>
					<button type="button" class="nw-btn nw-btn--ghost" id="nw-agent-secret-copy"><?php p($l->t('Copy')); ?></button>
				</div>
			</div>
			<div class="nw-form-row">
				<label for="nw-agent-config"><?php p($l->t('Client config')); ?></label>
				<textarea id="nw-agent-config" class="nw-agent-config" readonly rows="10" spellcheck="false"></textarea>
			</div>
		</div>
		<footer class="nw-dialog__footer">
			<div class="nw-actions">
				<button type="button" class="nw-btn nw-btn--secondary" id="nw-agent-secret-close"><?php p($l->t('Done')); ?></button>
				<button type="button" class="nw-btn nw-btn--primary" id="nw-agent-config-copy"><?php p($l->t('Copy config')); ?></button>
			</div>
		</footer>
	</dialog>

	<dialog id="nw-agent-revoke-dialog" class="nw-dialog" aria-labelledby="nw-agent-revoke-h">
		<div class="nw-dialog__body">
			<h3 id="nw-agent-revoke-h"><?php p($l->t('Revoke token?')); ?></h3>
			<p id="nw-agent-revoke-text"></p>
		</div>
		<footer class="nw-dialog__footer">
			<div class="nw-actions">
				<button type="button" class="nw-btn nw-btn--secondary" id="nw-agent-revoke-cancel"><?php p($l->t('Cancel')); ?></button>
				<button type="button" class="nw-btn nw-btn--danger" id="nw-agent-revoke-confirm"><?php p($l->t('Revoke')); ?></button>
			</div>
		</footer>
	</dialog>

	<script type="application/json" id="nw-agent-state"><?php
		print_unescaped(json_encode($nw_state, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT));
	?></script>
</section>
