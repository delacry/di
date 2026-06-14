<?php declare(strict_types=1);

// Fixtures for AbstractInjectExtension.dependency.phpt — kept in their own file so
// their path differs from the extension's (extension files are tracked anyway).

class InjectDependencyDep
{
}

class InjectDependencyConsumer
{
	#[ProbeInject]
	private InjectDependencyDep $dep;
}
