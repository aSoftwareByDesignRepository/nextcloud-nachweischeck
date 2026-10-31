import { acquireSharedInstanceLock } from './shared-instance-lock.mjs'
import { resetE2eUserState } from '../../_shared/e2e/reset-e2e-user-state.mjs'

export default async function globalSetup() {
	await acquireSharedInstanceLock('nachweischeck')
	// Fixture uids resolved the same way as e2e/helpers/auth-guard.js —
	// env override first, fixture default second. The shared reset clears
	// theming leftovers and core/lang forced by l10n render specs.
	resetE2eUserState({
		uids: [
			process.env.E2E_USER || process.env.NACHWEISCHECK_E2E_USER || 'nw_ds_admin',
			process.env.E2E_DENIED_USER || process.env.NACHWEISCHECK_E2E_DENIED_USER || 'nw_ds_denied',
		],
	})
}
