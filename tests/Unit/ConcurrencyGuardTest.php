<?php

declare(strict_types=1);

namespace OCA\NachweisCheck\Tests\Unit;

use OCA\NachweisCheck\Service\ConcurrencyGuard;
use OCP\Lock\ILockingProvider;
use Test\TestCase;

class ConcurrencyGuardTest extends TestCase
{
	private function guard(?ILockingProvider $lp = null): array
	{
		$lp = $lp ?? $this->createMock(ILockingProvider::class);
		return [new ConcurrencyGuard($lp), $lp];
	}

	public function testRunAcquiresAndReleasesExclusiveLock(): void
	{
		[$guard, $lp] = $this->guard();
		$lp->expects($this->once())->method('acquireLock')
			->with('k1', ILockingProvider::LOCK_EXCLUSIVE, 'nachweischeck');
		$lp->expects($this->once())->method('releaseLock')
			->with('k1', ILockingProvider::LOCK_EXCLUSIVE);
		$this->assertSame(42, $guard->run('k1', fn() => 42));
	}

	public function testLockReleasedOnException(): void
	{
		[$guard, $lp] = $this->guard();
		$lp->expects($this->once())->method('releaseLock');
		try {
			$guard->run('k1', function (): void {
				throw new \RuntimeException('boom');
			});
			$this->fail('expected exception');
		} catch (\RuntimeException $e) {
			$this->assertSame('boom', $e->getMessage());
		}
	}

	public function testReentrantRunDoesNotReacquire(): void
	{
		[$guard, $lp] = $this->guard();
		// The provider is NOT reentrant — the guard must not call acquireLock
		// a second time for a nested run on the same key.
		$lp->expects($this->once())->method('acquireLock');
		$lp->expects($this->once())->method('releaseLock');
		$out = $guard->run('k1', function () use ($guard): int {
			return $guard->run('k1', fn(): int => 7);
		});
		$this->assertSame(7, $out);
	}

	public function testDifferentKeysAcquireIndependently(): void
	{
		[$guard, $lp] = $this->guard();
		$lp->expects($this->exactly(2))->method('acquireLock');
		$lp->expects($this->exactly(2))->method('releaseLock');
		$guard->run('a', fn() => 1);
		$guard->run('b', fn() => 2);
	}

	public function testDepthRestoredAfterInnerException(): void
	{
		[$guard, $lp] = $this->guard();
		// Two acquisitions total: the failing run + a clean follow-up must
		// re-acquire (the failed outer run released fully).
		$lp->expects($this->exactly(2))->method('acquireLock');
		$lp->expects($this->exactly(2))->method('releaseLock');
		try {
			$guard->run('k1', function () use ($guard): void {
				$guard->run('k1', function (): void {
					throw new \RuntimeException('inner');
				});
			});
			$this->fail('expected');
		} catch (\RuntimeException) {
		}
		$this->assertSame('ok', $guard->run('k1', fn(): string => 'ok'));
	}

	public function testKeyBuilders(): void
	{
		$this->assertSame('nachweischeck-project-17', ConcurrencyGuard::projectKey(17));
		$this->assertSame('nachweischeck-project-activation', ConcurrencyGuard::activationKey());
	}
}
