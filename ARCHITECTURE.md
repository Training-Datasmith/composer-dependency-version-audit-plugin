# Architecture: composer-dependency-version-audit-plugin

## Purpose

A Magento/Adobe Commerce Composer plugin that audits dependency version constraints during `composer install` and `composer update`. It warns when direct or transitive dependencies use version constraints that are too broad (e.g., `*` or `>=1.0`) and could allow incompatible package versions to be installed silently.

## Directory Structure

```
src/
  Plugin.php             — Composer plugin: subscribes to install/update events, triggers audit
  Utils/
    Version.php          — Constraint analysis utilities: detects overly permissive ranges

tests/
  Unit/Magento/ComposerDependencyVersionAuditPlugin/
    Plugin_Test.php      — Unit tests for the plugin logic
```

## Key Design Decisions

- **Composer plugin API** — implements `PluginInterface` and `EventSubscriberInterface` from `composer-plugin-api`.
- **Constraint analysis** — `Version` utility inspects version constraint strings and identifies overly broad patterns that could allow major-version drift.
- **Warning-only mode** — the plugin reports problematic constraints as warnings rather than hard failures, preserving backward compatibility with existing Magento projects.

## Extension Points

- Configure severity thresholds or ignored packages via `composer.json` `extra` configuration.

## Dependency Flow

```
Composer install/update event
  └── Plugin::checkDependencyVersionAudit()
        └── Version::isConstraintTooPermissive(constraint)
              → warning output to IO
```
