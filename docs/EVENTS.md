# VersionVault Events

VersionVault emits domain events so you can hook into lifecycle actions (audit logs, notifications, custom storage, etc.).

## Events

### VersionRecording
Dispatched **before** a version is persisted.

```php
SthiraLabs\VersionVault\Events\VersionRecording
```

**Properties**
- `model` (`Model`) - versioned model
- `diff` (`?array`) - computed diff
- `snapshot` (`?array`) - stored snapshot (if any)
- `action` (`?string`) - action label
- `meta` (`array`) - custom metadata passed by caller

---

### VersionRecorded
Dispatched **after** a version is persisted.

```php
SthiraLabs\VersionVault\Events\VersionRecorded
```

**Properties**
- `model` (`Model`)
- `version` (`SthiraLabs\VersionVault\Models\Version`)

---

### VersionReconstructed
Dispatched after a historical version is reconstructed.

```php
SthiraLabs\VersionVault\Events\VersionReconstructed
```

**Properties**
- `originalModel` (`Model`) - source model
- `versionNumber` (`int`) - reconstructed version
- `reconstructedModel` (`Model`) - hydrated model

---

### VersionRollback
Dispatched after a rollback is completed and recorded.

```php
SthiraLabs\VersionVault\Events\VersionRollback
```

**Properties**
- `model` (`Model`)
- `rolledBackTo` (`int`)
- `rollbackVersionEntry` (`SthiraLabs\VersionVault\Models\Version`)

---

## Listening Example

```php
use Illuminate\Support\Facades\Event;
use SthiraLabs\VersionVault\Events\VersionRecorded;

Event::listen(VersionRecorded::class, function (VersionRecorded $event) {
    // $event->model, $event->version
});
```
