<?php

function getCurrentPageSlug(): string {
	$page = basename((string)($_SERVER['PHP_SELF'] ?? ''));
	return $page !== '' ? $page : 'index.php';
}

function isCurrentPage(string $pageSlug): bool {
	return getCurrentPageSlug() === $pageSlug;
}

function dashboardJsonEncodeOrFallback($value, string $fallbackJson): string {
	$encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
	return (is_string($encoded) && $encoded !== '') ? $encoded : $fallbackJson;
}

function dashboardBuildPermissionFlags(PDO $pdo, int $userId, array $checks, array $defaults = []): array {
	$flags = $defaults;
	foreach ($checks as $flagKey => $permissionSpec) {
		if (!is_array($permissionSpec) || count($permissionSpec) < 2) {
			$flags[$flagKey] = false;
			continue;
		}

		$resource = (string)$permissionSpec[0];
		$action = (string)$permissionSpec[1];
		$flags[$flagKey] = $userId > 0 ? userHasPermission($pdo, $userId, $resource, $action) : false;
	}

	foreach ($defaults as $flagKey => $defaultValue) {
		if (!array_key_exists($flagKey, $flags)) {
			$flags[$flagKey] = (bool)$defaultValue;
		}
	}

	return $flags;
}
